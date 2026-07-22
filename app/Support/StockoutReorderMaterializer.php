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
 *
 * cursor() + buffer (BUKAN toBase()->get() tunggal) utk main/repair rows - ->get() tetap simpan
 * SELURUH result set (~131.8K baris) dlm SATU array PHP serentak; cukup besar utk exhaust
 * memory_limit 512M pd server production (disahkan) walaupun toBase() dah elak overhead hidrat
 * Eloquent. cursor() stream satu baris pd satu masa drpd DB (guna PDO unbuffered/generator),
 * jadi memory yg dipegang PHP kekal ~saiz buffer (500 baris) tanpa mengira jumlah keseluruhan -
 * sama pattern spt App\Jobs\SyncJemisysMirrors::syncInventory() yg dah wujud dlm codebase ni.
 * Senarai qualifying designs turut dipisah jadi query LANGSUNG (GROUP BY InternalCode sahaja,
 * bukan derive drpd $rows dlm PHP) - lebih ringan memori. NAMUN kolasi lajur InternalCode
 * jemisys_inventory_mirror TIDAK PAD SPACE-insensitive spt disangka pd mulanya (disahkan
 * production: GROUP BY InternalCode sahaja MASIH keluarkan baris berasingan utk variasi padding
 * mengekor cth. "6018" vs "6018 ") - rujuk materializeQualifyingDesigns() utk insertOrIgnore()
 * sbg net keselamatan lepas trim() normalize kedua kpd kunci sama.
 */
class StockoutReorderMaterializer
{
    private const INSERT_CHUNK_SIZE = 500;

    public static function materialize(): int
    {
        $total = static::materializeCandidates();
        static::materializeQualifyingDesigns();
        static::materializeRepairStock();

        return $total;
    }

    private static function materializeCandidates(): int
    {
        StockoutReorderCandidate::truncate();

        $buffer = [];
        $total = 0;

        InventoryPiece::query()
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
            ->toBase()
            ->cursor()
            ->each(function ($r) use (&$buffer, &$total) {
                $buffer[] = [
                    'InternalCode' => $r->InternalCode,
                    'VendorCode' => $r->VendorCode,
                    'StoreCode' => $r->StoreCode,
                    'Description' => $r->Description,
                    'CategoryCode' => $r->CategoryCode,
                    'sold_count' => (int) $r->sold_count,
                    'qty_on_hand' => (int) $r->qty_on_hand,
                    'last_sale_date' => $r->last_sale_date,
                    'synced_at' => now(),
                ];
                $total++;

                if (count($buffer) >= self::INSERT_CHUNK_SIZE) {
                    StockoutReorderCandidate::insert($buffer);
                    $buffer = [];
                }
            });

        if ($buffer !== []) {
            StockoutReorderCandidate::insert($buffer);
        }

        return $total;
    }

    /**
     * Query BERASINGAN drpd materializeCandidates() - GROUP BY InternalCode SAHAJA (bukan
     * derive drpd baris (InternalCode,VendorCode,StoreCode) dlm memori PHP). NAMUN: MySQL GROUP
     * BY InternalCode SAHAJA TIDAK menyatukan variasi padding whitespace mengekor (cth.
     * "6018" vs "6018 ") - disahkan production hasilkan baris x2 utk kod sama lepas trim()
     * (kolasi lajur ni bukan PAD SPACE-insensitive spt disangka). trim() di sini NORMALKAN kedua
     * variasi jadi kunci sama - itu punca conflict, bukan bug - insertOrIgnore() (bukan insert())
     * jadi net keselamatan supaya baris pendua (lepas trim) senyap diabaikan, bukan crash.
     */
    private static function materializeQualifyingDesigns(): void
    {
        StockoutReorderQualifyingDesign::truncate();

        $buffer = [];

        InventoryPiece::query()
            ->realVendor()
            ->select('InternalCode')
            ->groupBy('InternalCode')
            ->havingRaw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) >= 3 AND SUM(QtyOnHand) = 0')
            ->toBase()
            ->cursor()
            ->each(function ($r) use (&$buffer) {
                $buffer[] = ['InternalCode' => trim($r->InternalCode), 'synced_at' => now()];

                if (count($buffer) >= self::INSERT_CHUNK_SIZE) {
                    StockoutReorderQualifyingDesign::insertOrIgnore($buffer);
                    $buffer = [];
                }
            });

        if ($buffer !== []) {
            StockoutReorderQualifyingDesign::insertOrIgnore($buffer);
        }
    }

    private static function materializeRepairStock(): void
    {
        StockoutReorderRepairStock::truncate();

        $buffer = [];

        InventoryPiece::query()
            ->whereRaw("TRIM(VendorCode) = '.'")
            ->select([
                'InternalCode',
                DB::raw('TRIM(StoreCode) as StoreCode'),
                DB::raw('SUM(QtyOnHand) as repair_qty'),
            ])
            ->groupBy('InternalCode', DB::raw('TRIM(StoreCode)'))
            ->toBase()
            ->cursor()
            ->each(function ($r) use (&$buffer) {
                $buffer[] = [
                    'InternalCode' => $r->InternalCode,
                    'StoreCode' => $r->StoreCode,
                    'repair_qty' => (int) $r->repair_qty,
                    'synced_at' => now(),
                ];

                if (count($buffer) >= self::INSERT_CHUNK_SIZE) {
                    StockoutReorderRepairStock::insert($buffer);
                    $buffer = [];
                }
            });

        if ($buffer !== []) {
            StockoutReorderRepairStock::insert($buffer);
        }
    }
}
