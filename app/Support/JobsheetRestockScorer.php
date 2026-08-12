<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Skor cadangan restock per DESIGN (InternalCode) yg terpapar pd carian Jobsheet Lookup - rujuk
 * JobsheetLookupController & JobsheetLookup/Index.vue lajur "Cadangan". Gabungkan 4 isyarat
 * (arahan manager, bukan RestockAnalysisCalculator asal yg jawab "kategori/cawangan/bucket
 * MANA", ni jawab "design/keping INI perlu restock ke, kenapa"):
 *
 * 1. Stok Habis (Sold Out) - current_stock design=0 TAPI pernah jual (SalesDate wujud dlm tempoh
 *    trend) - bukan "tak pernah jual direct" (design langsung x popular pun akan current_stock=0
 *    kalau tak pernah repeat order, tapi itu bukan "habis stok" dlm erti mendesak).
 * 2. Understock - SEBARANG SATU drpd 3 grain (skor terkumpul, BUKAN pilih satu):
 *    a. Jumlah stok SELURUH Kategori+Bucket Berat (sepadan RestockByWeight) < 3
 *    b. Jumlah stok SELURUH Kategori+Bucket Saiz (sepadan RestockBySize) < 2
 *    c. Jumlah stok design INI sahaja (semua cawangan) < 3
 * 3. Design Paling Laku (Hot Selling) - velocity_per_month design ni dlm 20% teratas MERENTASI
 *    kategori design tsb SAHAJA (yg pernah jual, elak design x pernah jual skewkan persentil).
 * 4. Cawangan Jualan Tertinggi - cawangan piece/baris INI ialah cawangan #1 jualan design ni
 *    (isyarat "letak stok baharu SINI", bukan skor keseluruhan design - beza ikut baris/cawangan).
 *
 * Setiap design turut bawa `target_branches` - StoreCode (cth. "PERLING", "WM", "TTDI") bagi
 * TARGET_BRANCH_LIMIT cawangan PALING LAKU design tsb (susun menurun ikut jualan tempoh trend) -
 * ini jawab soalan sebenar manager "patut hantar stok ni ke MANA", bukan skor semata-mata.
 * StoreCode digunakan TERUS (bukan Store::Description - lajur tu generik "KEDAI EMAS MERCHANT9"
 * utk hampir semua cawangan, tak berguna sbg nama).
 *
 * Skor 0-100 (kumulatif, BUKAN "padanan pertama menang") - rujuk POINTS. Julat masa SAMA dgn
 * RestockAnalysisCalculator::TREND_MONTHS (3 bulan) utk konsisten dgn seluruh sistem.
 *
 * Statistik SELURUH KATEGORI (bucket berat/saiz + ambang hot-selling) di-CACHE PER KATEGORI
 * (Cache::rememberForever, dijana semula bila Cache::flush() drpd SyncJemisysMirrors - sama
 * corak spt RestockAnalysisCalculator::byCategory()/byWeight()) - kategori popular (cth.
 * "RANTAI TANGAN", 145K+ keping) disahkan EXPLAIN pakai FULL TABLE SCAN (bukan index) bila
 * padanan >~30% jadual, jadi query mentah boleh ambil 10-40+ saat SETIAP kategori - caching
 * bermakna HANYA lawatan PERTAMA setiap kategori kena tanggung kos ni (padan tepat dgn corak
 * "kategori besar mahal" yg RestockAnalysisCalculator::byCategory() dokblok sendiri amaran).
 */
class JobsheetRestockScorer
{
    public const REASON_SOLD_OUT = 'sold_out';

    public const REASON_UNDERSTOCK_WEIGHT = 'understock_weight';

    public const REASON_UNDERSTOCK_SIZE = 'understock_size';

    public const REASON_UNDERSTOCK_DESIGN = 'understock_design';

    public const REASON_HOT_SELLING = 'hot_selling';

    public const REASON_TOP_BRANCH = 'top_branch';

    /** Ambang understock per grain - rujuk keputusan pengguna ("<3 Kategori+Berat", "<2
     * Kategori+Saiz", "<3 design individu") - SEMUA tiga dikira, bukan pilih satu. */
    public const UNDERSTOCK_WEIGHT_BUCKET_MAX = 3;

    public const UNDERSTOCK_SIZE_BUCKET_MAX = 2;

    public const UNDERSTOCK_DESIGN_MAX = 3;

    /** Persentil velocity utk "Design Paling Laku" - 80 bermaksud 20% TERATAS. */
    public const HOT_SELLING_PERCENTILE = 80;

    /** Bilangan cawangan teratas (ikut jualan) dibawa dlm `target_branches` setiap design. */
    public const TARGET_BRANCH_LIMIT = 3;

    /** @var array<string, int> */
    public const POINTS = [
        self::REASON_SOLD_OUT => 35,
        self::REASON_UNDERSTOCK_WEIGHT => 15,
        self::REASON_UNDERSTOCK_SIZE => 15,
        self::REASON_UNDERSTOCK_DESIGN => 15,
        self::REASON_HOT_SELLING => 10,
        self::REASON_TOP_BRANCH => 10,
    ];

    /** @var array<string, string> */
    public const REASON_LABELS = [
        self::REASON_SOLD_OUT => 'Stok Habis',
        self::REASON_UNDERSTOCK_WEIGHT => 'Understock (Kategori+Berat)',
        self::REASON_UNDERSTOCK_SIZE => 'Understock (Kategori+Saiz)',
        self::REASON_UNDERSTOCK_DESIGN => 'Understock (Design)',
        self::REASON_HOT_SELLING => 'Design Paling Laku',
        self::REASON_TOP_BRANCH => 'Cawangan Jualan Tertinggi',
    ];

    /**
     * @param  Collection<int, array{internal_code: ?string, store_code: ?string}>  $rows  baris carian Jobsheet Lookup (internal_code + store_code SETIAP baris)
     * @return array<string, array{score: int, verdict: string, verdict_color: string, reasons: array<int, string>, current_stock: int, target_branches: array<int, string>}> keyed by "internal_code|store_code" (skor boleh beza ikut cawangan - rujuk REASON_TOP_BRANCH; target_branches SAMA utk semua baris SATU design)
     */
    public static function scoreRows(Collection $rows): array
    {
        $codes = $rows->pluck('internal_code')->filter()->map(fn ($c) => trim($c))->unique()->values();

        if ($codes->isEmpty()) {
            return [];
        }

        $trendStart = RestockAnalysisCalculator::trendStartForPeriod(RestockAnalysisCalculator::DEFAULT_PERIOD);
        $trendWindowDays = max((int) $trendStart->diffInDays(now()), 1);

        // 1. Agregat SETIAP design (semua cawangan) - stok semasa + jualan dlm tempoh trend.
        $perDesign = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->selectRaw('InternalCode, MAX(CategoryCode) as CategoryCode, MAX(JewelSize) as JewelSize, '.
                'MAX(GoldWeight) as GoldWeight, SUM(QtyOnHand) as current_stock, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold', [$trendStart])
            ->groupBy('InternalCode')
            ->toBase()
            ->get()
            ->keyBy(fn ($r) => trim((string) $r->InternalCode));

        // 2. Jualan SETIAP design PER CAWANGAN (utk REASON_TOP_BRANCH) - baris terjual sahaja.
        $perBranchSold = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->whereNotNull('SalesDate')
            ->where('SalesDate', '>=', $trendStart)
            ->selectRaw('InternalCode, StoreCode, COUNT(*) as sold')
            ->groupBy('InternalCode', 'StoreCode')
            ->toBase()
            ->get()
            ->groupBy(fn ($r) => trim((string) $r->InternalCode));

        $topBranchByDesign = $perBranchSold->map(fn ($rowsForDesign) => trim((string) $rowsForDesign->sortByDesc('sold')->first()->StoreCode));

        $targetBranchesByDesign = $perBranchSold->map(fn ($rowsForDesign) => $rowsForDesign->sortByDesc('sold')
            ->take(self::TARGET_BRANCH_LIMIT)
            ->map(fn ($r) => trim((string) $r->StoreCode))
            ->values()
            ->all());

        // 3. Statistik SELURUH KATEGORI (bucket berat/saiz + ambang hot-selling) - satu per
        // KATEGORI, di-CACHE (rujuk categoryStats() & dokblok kelas - kategori popular mahal).
        $categoryCodes = $perDesign->pluck('CategoryCode')->filter()->map(fn ($c) => trim($c))->unique()->values();
        $statsByCategory = $categoryCodes->mapWithKeys(fn ($cat) => [$cat => self::categoryStats($cat, $trendStart, $trendWindowDays)]);

        $result = [];

        foreach ($rows as $row) {
            $internalCode = filled($row['internal_code'] ?? null) ? trim($row['internal_code']) : null;
            $storeCode = filled($row['store_code'] ?? null) ? trim($row['store_code']) : null;
            $key = "{$internalCode}|{$storeCode}";

            $d = $internalCode ? $perDesign->get($internalCode) : null;

            if (! $d) {
                $result[$key] = ['score' => 0, 'verdict' => null, 'verdict_color' => 'gray', 'reasons' => [], 'current_stock' => 0, 'target_branches' => []];

                continue;
            }

            $currentStock = (int) $d->current_stock;
            $piecesSold = (int) $d->pieces_sold;
            $categoryCode = trim((string) $d->CategoryCode);
            $stats = $statsByCategory->get($categoryCode, ['weight_buckets' => [], 'size_buckets' => [], 'hot_selling_threshold' => PHP_FLOAT_MAX]);
            $weightBucket = RestockAnalysisCalculator::weightBucket($d->GoldWeight);
            $sizeBucket = RestockAnalysisCalculator::sizeLabel($d->JewelSize);
            $velocity = SalesVelocityHelper::velocity($piecesSold, $trendWindowDays);
            $topBranch = $topBranchByDesign->get($internalCode);

            $reasons = [];

            if ($currentStock === 0 && $piecesSold > 0) {
                $reasons[] = self::REASON_SOLD_OUT;
            }

            if (($stats['weight_buckets'][$weightBucket] ?? 0) < self::UNDERSTOCK_WEIGHT_BUCKET_MAX) {
                $reasons[] = self::REASON_UNDERSTOCK_WEIGHT;
            }

            if (($stats['size_buckets'][$sizeBucket] ?? 0) < self::UNDERSTOCK_SIZE_BUCKET_MAX) {
                $reasons[] = self::REASON_UNDERSTOCK_SIZE;
            }

            if ($currentStock < self::UNDERSTOCK_DESIGN_MAX) {
                $reasons[] = self::REASON_UNDERSTOCK_DESIGN;
            }

            if ($piecesSold > 0 && $velocity >= $stats['hot_selling_threshold']) {
                $reasons[] = self::REASON_HOT_SELLING;
            }

            if ($storeCode && $topBranch && $storeCode === $topBranch) {
                $reasons[] = self::REASON_TOP_BRANCH;
            }

            $score = array_sum(array_map(fn ($reason) => self::POINTS[$reason], $reasons));

            $result[$key] = [
                'score' => $score,
                'verdict' => self::verdictFor($score),
                'verdict_color' => self::verdictColorFor($score),
                'reasons' => array_map(fn ($reason) => self::REASON_LABELS[$reason], $reasons),
                'current_stock' => $currentStock,
                'target_branches' => $targetBranchesByDesign->get($internalCode, []),
            ];
        }

        return $result;
    }

    /**
     * Bucket berat/saiz + ambang persentil hot-selling SATU KATEGORI - di-CACHE selama-lamanya
     * (rujuk dokblok kelas), SATU kali sahaja per kategori tak kira berapa job sheet/carian
     * sentuh kategori tsb kemudian.
     *
     * @return array{weight_buckets: array<string, int>, size_buckets: array<string, int>, hot_selling_threshold: float}
     */
    protected static function categoryStats(string $categoryCode, Carbon $trendStart, int $trendWindowDays): array
    {
        return Cache::rememberForever("jobsheet_restock_category_stats:{$categoryCode}", function () use ($categoryCode, $trendStart, $trendWindowDays) {
            $weightCaseExpr = RestockAnalysisCalculator::weightBucketSqlCase();

            $weightBuckets = InventoryPiece::query()
                ->realVendor()
                ->where('CategoryCode', $categoryCode)
                ->selectRaw("{$weightCaseExpr} as bucket, SUM(QtyOnHand) as stock")
                ->groupBy(DB::raw($weightCaseExpr))
                ->toBase()
                ->get()
                ->mapWithKeys(fn ($r) => [$r->bucket => (int) $r->stock])
                ->all();

            $sizeBuckets = InventoryPiece::query()
                ->realVendor()
                ->where('CategoryCode', $categoryCode)
                ->selectRaw('JewelSize, SUM(QtyOnHand) as stock')
                ->groupBy('JewelSize')
                ->toBase()
                ->get()
                ->groupBy(fn ($r) => RestockAnalysisCalculator::sizeLabel($r->JewelSize))
                ->map(fn ($rowsForBucket) => (int) $rowsForBucket->sum('stock'))
                ->all();

            $velocities = InventoryPiece::query()
                ->realVendor()
                ->where('CategoryCode', $categoryCode)
                ->selectRaw('InternalCode, SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold', [$trendStart])
                ->groupBy('InternalCode')
                ->havingRaw('pieces_sold > 0')
                ->toBase()
                ->get()
                ->map(fn ($r) => SalesVelocityHelper::velocity((int) $r->pieces_sold, $trendWindowDays))
                ->sort()
                ->values();

            return [
                'weight_buckets' => $weightBuckets,
                'size_buckets' => $sizeBuckets,
                'hot_selling_threshold' => self::percentile($velocities, self::HOT_SELLING_PERCENTILE),
            ];
        });
    }

    public static function verdictFor(int $score): ?string
    {
        return match (true) {
            $score >= 60 => 'Restock Segera',
            $score >= 30 => 'Cadangan Restock',
            $score > 0 => 'Pantau',
            default => null,
        };
    }

    public static function verdictColorFor(int $score): string
    {
        return match (true) {
            $score >= 60 => 'danger',
            $score >= 30 => 'warning',
            $score > 0 => 'info',
            default => 'gray',
        };
    }

    /** Persentil mudah (nearest-rank) atas koleksi bernombor TERSUSUN menaik. */
    protected static function percentile(Collection $sortedValues, int $percentile): float
    {
        if ($sortedValues->isEmpty()) {
            return PHP_FLOAT_MAX;
        }

        $index = (int) ceil(($percentile / 100) * $sortedValues->count()) - 1;
        $index = max(0, min($index, $sortedValues->count() - 1));

        return (float) $sortedValues->values()->get($index);
    }
}
