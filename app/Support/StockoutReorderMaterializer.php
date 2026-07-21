<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\StockoutReorderCandidate;
use App\Models\StockoutReorderQualifyingDesign;
use App\Models\StockoutReorderRepairStock;
use Illuminate\Support\Facades\DB;

/**
 * Kira agregat StockoutReorder sekali di sini (dipanggil dari SyncJemisysMirrors selepas
 * InventoryPiece disegerak) & simpan hasil ke stockout_reorder_candidates - App\Filament\Pages\
 * StockoutReorder baca terus drpd jadual kecil ni, BUKAN agregat 481K baris setiap page
 * load/filter/sort/paginate (rujuk nota di StockoutReorder - realVendor() padan 91% baris,
 * jadi tiada index boleh percepatkan agregat live).
 *
 * Grain: SATU baris setiap (InternalCode, VendorCode, StoreCode) - BUKAN satu baris setiap
 * design. Ambang "sold_count>=3 AND qty_on_hand=0" TIDAK ditapis di sini lagi (rujuk
 * StockoutReorderCandidate::candidateQuery()) - dikira semula secara LIVE di request-time supaya
 * exclude/include vendor/cawangan boleh ubah sold_count & kelayakan design secara interaktif
 * tanpa perlu agregat 481K baris jemisys_inventory_mirror setiap kali (~131.8K baris pd grain
 * ni, jauh lebih kecil & pantas utk GROUP BY/HAVING live).
 *
 * Stok repair (VendorCode='.') disimpan BERASINGAN di stockout_reorder_repair_stock, grain
 * (InternalCode, StoreCode) - repair item tiada vendor sebenar, tapi tetap perlu dikecualikan
 * ikut cawangan (rujuk StockoutReorderCandidate::candidateQuery()).
 *
 * stockout_reorder_qualifying_designs (jadual kecil unik-key, InternalCode PK) turut diisi di
 * sini - senarai calon layak ikut definisi LALAI (semua vendor/cawangan, tiada exclude), SATU-
 * SATUNYA tujuan ialah sumber semi-join murah bagi App\Support\BestSellerLostOpportunityCalculator
 * (dashboard CEO cached forever, tidak perlukan exclude interaktif) - rujuk migration
 * create_stockout_reorder_qualifying_designs_table utk sejarah kenapa stockout_reorder_candidates
 * [grain per-vendor-per-cawangan] tak lagi sesuai utk tujuan ni selepas re-grain (GROUP BY/HAVING
 * live sbg subquery JOIN ke jemisys_inventory_mirror [481K baris] ambil 55+ saat).
 */
class StockoutReorderMaterializer
{
    public static function materialize(): int
    {
        $rows = InventoryPiece::query()
            ->realVendor()
            ->select([
                'InternalCode',
                DB::raw('TRIM(VendorCode) as VendorCode'),
                DB::raw('TRIM(StoreCode) as StoreCode'),
                DB::raw('MAX(Description) as Description'),
                DB::raw('MAX(CategoryCode) as CategoryCode'),
                DB::raw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold_count'),
                DB::raw('SUM(QtyOnHand) as qty_on_hand'),
                DB::raw('MAX(SalesDate) as last_sale_date'),
            ])
            ->groupBy('InternalCode', DB::raw('TRIM(VendorCode)'), DB::raw('TRIM(StoreCode)'))
            // toBase() - elak overhead hidrat setiap 131.8K baris jadi Model InventoryPiece
            // penuh (146 lajur, casts, dll) - kita cuma perlu attribute mentah utk map() di
            // bawah, bukan ciri Eloquent. Punca sebenar "memory exhausted" bila cuba get()
            // Eloquent-hydrated pd grain (VendorCode,StoreCode) yg 3x lebih besar drpd asal.
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'InternalCode' => $r->InternalCode,
                'VendorCode' => $r->VendorCode,
                'StoreCode' => $r->StoreCode,
                'Description' => $r->Description,
                'CategoryCode' => $r->CategoryCode,
                'sold_count' => (int) $r->sold_count,
                'qty_on_hand' => (int) $r->qty_on_hand,
                'last_sale_date' => $r->last_sale_date,
                'synced_at' => now(),
            ])
            ->all();

        StockoutReorderCandidate::truncate();

        // insert() satu batch gergasi boleh lebihi had placeholder statement disediakan MySQL
        // (1390 "too many placeholders") bila baris banyak - chunk 500 baris x 9 lajur = selamat.
        foreach (array_chunk($rows, 500) as $chunk) {
            StockoutReorderCandidate::insert($chunk);
        }

        // Senarai InternalCode layak ikut definisi LALAI (semua vendor/cawangan) - dikira
        // terus drpd $rows yg dah ada dlm memori (bukan query DB tambahan). Jadual kecil
        // unik-key ni SEMATA-MATA sumber semi-join murah utk
        // BestSellerLostOpportunityCalculator (rujuk migration create_stockout_reorder_
        // qualifying_designs_table utk sejarah kenapa stockout_reorder_candidates [grain
        // per-vendor-per-cawangan] tak lagi sesuai utk tujuan ni selepas re-grain).
        // groupBy() guna closure trim() (bukan 'InternalCode' terus) - MySQL GROUP BY
        // InternalCode di atas guna PAD SPACE collation (ruang mengekor diabaikan bila banding),
        // jadi baris utk SATU design boleh keluar dgn variasi padding berbeza (cth. "6018" &
        // "6018 ") merentasi sub-group VendorCode/StoreCode berlainan - PHP groupBy() banding
        // byte-tepat, jadi tanpa trim() ni jadi 2 kunci berlainan yg lepas insert jadi
        // "Duplicate entry" (PK InternalCode jadual ni turut guna PAD SPACE MySQL).
        $qualifyingCodes = collect($rows)
            ->groupBy(fn (array $r) => trim((string) $r['InternalCode']))
            ->filter(fn ($group) => $group->sum('sold_count') >= 3 && $group->sum('qty_on_hand') === 0)
            ->keys()
            ->map(fn ($code) => ['InternalCode' => $code, 'synced_at' => now()])
            ->all();

        StockoutReorderQualifyingDesign::truncate();

        foreach (array_chunk($qualifyingCodes, 500) as $chunk) {
            StockoutReorderQualifyingDesign::insert($chunk);
        }

        $repairRows = InventoryPiece::query()
            ->whereRaw("TRIM(VendorCode) = '.'")
            ->select([
                'InternalCode',
                DB::raw('TRIM(StoreCode) as StoreCode'),
                DB::raw('SUM(QtyOnHand) as repair_qty'),
            ])
            ->groupBy('InternalCode', DB::raw('TRIM(StoreCode)'))
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'InternalCode' => $r->InternalCode,
                'StoreCode' => $r->StoreCode,
                'repair_qty' => (int) $r->repair_qty,
                'synced_at' => now(),
            ])
            ->all();

        StockoutReorderRepairStock::truncate();

        foreach (array_chunk($repairRows, 500) as $chunk) {
            StockoutReorderRepairStock::insert($chunk);
        }

        return count($rows);
    }
}
