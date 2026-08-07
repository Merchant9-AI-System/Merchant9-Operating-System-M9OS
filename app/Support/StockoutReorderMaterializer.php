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

        // Baldi tempoh (rujuk StockoutReorderCandidate::RANGE_DAYS) dikira SEKALI di sini
        // (satu pass sahaja atas 481K baris, sama query yg sudahpun di-scan utk sold_count
        // overall) - bukan agregat berasingan setiap julat, supaya menambah julat baru tak
        // tambah kos scan jemisys_inventory_mirror langsung.
        $now = now();
        $cutoffs = collect(StockoutReorderCandidate::RANGE_DAYS)
            ->filter()
            ->mapWithKeys(fn (int $days, string $range) => [
                StockoutReorderCandidate::soldCountColumnFor($range) => $now->copy()->subDays($days),
            ]);

        InventoryPiece::query()
            ->realVendor()
            ->select(array_merge([
                'InternalCode',
                DB::raw('TRIM(VendorCode) as VendorCode'),
                DB::raw('TRIM(StoreCode) as StoreCode'),
                DB::raw('MAX(Description) as Description'),
                DB::raw('MAX(CategoryCode) as CategoryCode'),
                DB::raw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold_count'),
                DB::raw('SUM(QtyOnHand) as qty_on_hand'),
                DB::raw('MAX(SalesDate) as last_sale_date'),
            ], $cutoffs->map(fn ($cutoff, $column) => DB::raw(
                "SUM(CASE WHEN SalesDate >= '{$cutoff->toDateTimeString()}' THEN 1 ELSE 0 END) as {$column}"
            ))->values()->all()))
            ->groupBy('InternalCode', DB::raw('TRIM(VendorCode)'), DB::raw('TRIM(StoreCode)'))
            ->toBase()
            ->cursor()
            ->each(function ($r) use (&$buffer, &$total, $cutoffs) {
                $row = [
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

                foreach ($cutoffs->keys() as $column) {
                    $row[$column] = (int) $r->{$column};
                }

                $buffer[] = $row;
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
     * Satu baris per (InternalCode, range_bucket) LAYAK - dikira per julat (rujuk
     * StockoutReorderCandidate::RANGE_DAYS) drpd stockout_reorder_candidates yg BARU
     * dimaterialize di atas (~131.8K baris, jadual tempatan kecil), BUKAN scan semula 481K baris
     * jemisys_inventory_mirror sekali per julat - materializeCandidates() dah kira sold_count_Xd
     * setiap baldi dlm SATU pass, jadi GROUP BY/HAVING kat sini murah walau diulang 6x (sekali
     * setiap julat).
     *
     * GROUP BY InternalCode SAHAJA (drpd stockout_reorder_candidates, warisan drpd
     * jemisys_inventory_mirror) TIDAK menyatukan variasi padding whitespace mengekor (cth.
     * "6018" vs "6018 ") - disahkan production hasilkan baris x2 utk kod sama lepas trim()
     * (kolasi lajur ni bukan PAD SPACE-insensitive spt disangka). trim() di sini NORMALKAN kedua
     * variasi jadi kunci sama - itu punca conflict, bukan bug - insertOrIgnore() (bukan insert())
     * jadi net keselamatan supaya baris pendua (lepas trim) senyap diabaikan, bukan crash.
     */
    private static function materializeQualifyingDesigns(): void
    {
        StockoutReorderQualifyingDesign::truncate();

        foreach (StockoutReorderCandidate::RANGE_DAYS as $range => $days) {
            $soldCountColumn = StockoutReorderCandidate::soldCountColumnFor($range);
            $buffer = [];

            StockoutReorderCandidate::query()
                ->select('InternalCode')
                ->groupBy('InternalCode')
                ->havingRaw("SUM({$soldCountColumn}) >= 3 AND SUM(qty_on_hand) = 0")
                ->toBase()
                ->cursor()
                ->each(function ($r) use (&$buffer, $range) {
                    $buffer[] = ['InternalCode' => trim($r->InternalCode), 'range_bucket' => $range, 'synced_at' => now()];

                    if (count($buffer) >= self::INSERT_CHUNK_SIZE) {
                        StockoutReorderQualifyingDesign::insertOrIgnore($buffer);
                        $buffer = [];
                    }
                });

            if ($buffer !== []) {
                StockoutReorderQualifyingDesign::insertOrIgnore($buffer);
            }
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
