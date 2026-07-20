<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\StockoutReorderCandidate;
use Illuminate\Support\Facades\DB;

/**
 * Kira agregat StockoutReorder sekali di sini (dipanggil dari SyncJemisysMirrors selepas
 * InventoryPiece disegerak) & simpan hasil ke stockout_reorder_candidates - App\Filament\Pages\
 * StockoutReorder baca terus drpd jadual kecil ni, BUKAN agregat 481K baris setiap page
 * load/filter/sort/paginate (rujuk nota di StockoutReorder - realVendor() padan 91% baris,
 * jadi tiada index boleh percepatkan agregat live).
 *
 * Grain: SATU baris setiap (InternalCode, VendorCode) - BUKAN satu baris setiap design.
 * Ambang "sold_count>=3 AND qty_on_hand=0" TIDAK ditapis di sini lagi (rujuk
 * StockoutReorderCandidate::candidateQuery()) - dikira semula secara LIVE di request-time supaya
 * exclude/include vendor boleh ubah sold_count & kelayakan design secara interaktif tanpa perlu
 * agregat 481K baris jemisys_inventory_mirror setiap kali (~39.8K baris pd grain ni, jauh lebih
 * kecil & pantas utk GROUP BY/HAVING live).
 */
class StockoutReorderMaterializer
{
    public static function materialize(): int
    {
        // Item repair (VendorCode='.') dikecualikan terus drpd realVendor(), jadi stok repair
        // TIDAK pernah dikira dlm SUM(QtyOnHand)=0 di bawah - design boleh nampak "stok=0" walhal
        // sebenarnya ada 1 piece repair di stor. Kira berasingan di sini SEKADAR utk paparan info
        // (rujuk StockoutReorder::table() lajur 'repair_qty_on_hand') - TIDAK ubah kelayakan calon.
        $repairStock = InventoryPiece::query()
            ->whereRaw("TRIM(VendorCode) = '.'")
            ->select('InternalCode', DB::raw('SUM(QtyOnHand) as repair_qty'))
            ->groupBy('InternalCode')
            ->pluck('repair_qty', 'InternalCode');

        $rows = InventoryPiece::query()
            ->realVendor()
            ->select([
                'InternalCode',
                DB::raw('TRIM(VendorCode) as VendorCode'),
                DB::raw('MAX(Description) as Description'),
                DB::raw('MAX(CategoryCode) as CategoryCode'),
                DB::raw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold_count'),
                DB::raw('SUM(QtyOnHand) as qty_on_hand'),
                DB::raw('MAX(SalesDate) as last_sale_date'),
            ])
            ->groupBy('InternalCode', DB::raw('TRIM(VendorCode)'))
            ->get()
            ->map(fn ($r) => [
                'InternalCode' => $r->InternalCode,
                'VendorCode' => $r->VendorCode,
                'Description' => $r->Description,
                'CategoryCode' => $r->CategoryCode,
                'repair_qty_on_hand' => (int) ($repairStock[$r->InternalCode] ?? 0),
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

        return count($rows);
    }
}
