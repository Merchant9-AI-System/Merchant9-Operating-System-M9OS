<?php

namespace App\Support;

use App\Models\InventoryMirror;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cadangan restock silang Kategori x Cawangan, per Saiz ATAU per Berat - 100% drpd data
 * JEMiSys sebenar (TblInventory), TIADA pergantungan pada PO/GRN/data diisi manual.
 *
 * Guna formula velocity/target_stock SAMA spt OrderRecommendationCalculator (SalesVelocityHelper)
 * supaya konsisten merentas sistem, tapi pada peringkat Kategori+Cawangan+Saiz/Berat (bukan
 * VendorCode+InternalCode) - jawapan "apa perlu restock, kategori/cawangan/saiz mana".
 */
class RestockAnalysisCalculator
{
    public const TARGET_COVER_MONTHS = OrderRecommendationCalculator::TARGET_COVER_MONTHS;

    public const MIN_SAMPLE = 3;

    public const WEIGHT_BINS = [0, 1, 2, 3, 5, 10, 20, 50, PHP_INT_MAX];

    public const WEIGHT_LABELS = ['0-1g', '1-2g', '2-3g', '3-5g', '5-10g', '10-20g', '20-50g', '50g+'];

    public const VERDICT_SOLD_OUT = 'Perlu Restock (Sold Out)';

    public const VERDICT_RESTOCK = 'Perlu Restock';

    public const VERDICT_OK = 'Stok Cukup';

    public const VERDICT_OVERSTOCK = 'Overstock';

    public const VERDICT_NO_DATA = 'Data Tak Cukup';

    /** Tempoh "3 bulan terkini" utk Jualan/Bulan - atas permintaan (bukan sejarah penuh). */
    public const TREND_MONTHS = 3;

    public const PERIOD_1_WEEK = '1w';

    public const PERIOD_1_MONTH = '1m';

    public const PERIOD_3_MONTHS = '3m';

    public const PERIOD_6_MONTHS = '6m';

    public const PERIOD_9_MONTHS = '9m';

    public const PERIOD_1_YEAR = '1y';

    /** "Semua" - jumlah (pieces_received/pieces_sold) TIADA sempadan tarikh langsung, dipakai
     * RestockByWeight/RestockBySize/RestockByCategory drpd penapis "Tempoh" disorok (rujuk
     * dokblok finalize()/computeByCategoryPerBranch() - velocity/verdict KEKAL guna tetingkap
     * TREND_MONTHS, bukan span "semua" ni, elak velocity runtuh hampir sifar). SENGAJA TIADA
     * label dlm PERIOD_LABELS (const tsb turut dikongsi BranchFocus/SupplierPerformance yg
     * penapis Tempoh MASIH aktif - elak "Semua" muncul sbg pilihan baharu di situ jugak). */
    public const PERIOD_ALL = 'all';

    public const DEFAULT_PERIOD = self::PERIOD_3_MONTHS;

    /** @var array<string, string> */
    public const PERIOD_LABELS = [
        self::PERIOD_1_WEEK => '1 Minggu',
        self::PERIOD_1_MONTH => '1 Bulan',
        self::PERIOD_3_MONTHS => '3 Bulan',
        self::PERIOD_6_MONTHS => '6 Bulan',
        self::PERIOD_9_MONTHS => '9 Bulan',
        self::PERIOD_1_YEAR => '1 Tahun',
    ];

    /** Titik mula tempoh trend (Jualan/Bulan, pieces_received/sold) bagi satu pilihan "Tempoh". */
    public static function trendStartForPeriod(string $period): Carbon
    {
        return match ($period) {
            self::PERIOD_1_WEEK => now()->subWeek(),
            self::PERIOD_1_MONTH => now()->subMonth(),
            self::PERIOD_6_MONTHS => now()->subMonths(6),
            self::PERIOD_9_MONTHS => now()->subMonths(9),
            self::PERIOD_1_YEAR => now()->subYear(),
            self::PERIOD_ALL => Carbon::createFromTimestamp(0),
            default => now()->subMonths(self::TREND_MONTHS),
        };
    }

    public static function bySize(string $period = self::DEFAULT_PERIOD): Collection
    {
        return collect(Cache::rememberForever("restock_by_size:{$period}", function () use ($period) {
            return retry(6, fn () => static::computeBySize($period)->toArray(), 800);
        }));
    }

    public static function byWeight(string $period = self::DEFAULT_PERIOD): Collection
    {
        return collect(Cache::rememberForever("restock_by_weight:{$period}", function () use ($period) {
            return retry(6, fn () => static::computeByWeight($period)->toArray(), 800);
        }));
    }

    protected static function computeBySize(string $period = self::DEFAULT_PERIOD): Collection
    {
        // JewelSize (TEXT, ~280 nilai unik - jauh lebih kecil drpd GoldWeight berterusan) -
        // kumpul RAW dulu dlm SQL (selamat, bukan combinatorial explosion spt berat), kemudian
        // normalize label (sizeLabel()) & gabung semula dlm PHP - sepadan pendekatan Python
        // analytics.py _size_label() (buang trailing ".0", "(tiada)" utk kosong).
        $trendStart = static::trendStartForPeriod($period);
        // Tetingkap velocity TETAP (TREND_MONTHS/3 bulan) - berasingan drpd $trendStart di atas
        // (yg jadi TIADA sempadan bila $period=PERIOD_ALL) - rujuk dokblok PERIOD_ALL & finalize().
        $velocityWindowStart = now()->subMonths(self::TREND_MONTHS);

        $raw = InventoryPiece::query()
            ->realVendor()
            ->selectRaw('CategoryCode, StoreCode, JewelSize, '.
                'SUM(CASE WHEN PurchDate >= ? THEN 1 ELSE 0 END) as pieces_received, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold_velocity_window, '.
                'SUM(QtyOnHand) as current_stock', [$trendStart, $trendStart, $velocityWindowStart])
            ->groupBy('CategoryCode', 'StoreCode', 'JewelSize')
            ->get();

        $merged = $raw->groupBy(fn ($r) => $r->CategoryCode.'|'.$r->StoreCode.'|'.static::sizeLabel($r->JewelSize))
            ->map(function ($rows) {
                $first = $rows->first();

                return (object) [
                    'CategoryCode' => $first->CategoryCode,
                    'StoreCode' => $first->StoreCode,
                    'bucket' => static::sizeLabel($first->JewelSize),
                    'pieces_received' => $rows->sum('pieces_received'),
                    'pieces_sold' => $rows->sum('pieces_sold'),
                    'pieces_sold_velocity_window' => $rows->sum('pieces_sold_velocity_window'),
                    'current_stock' => $rows->sum('current_stock'),
                ];
            })->values();

        return static::finalize($merged, $period);
    }

    /**
     * Senarai design (InternalCode) individu di dalam satu bucket Kategori+Cawangan+Saiz - utk
     * jawab "yang perlu restock tu, design MANA sebenarnya" (rujuk BySize row punya gap, yang
     * cuma tunjuk kategori/cawangan/saiz, bukan design tertentu). TIDAK di-cache (rememberForever)
     * spt bySize() sbb ini drill-down on-demand per baris, bukan senarai penuh.
     */
    public static function designsForSizeBucket(string $categoryCode, string $storeCode, string $bucket): Collection
    {
        $monthStart = now()->startOfMonth();
        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        $grouped = InventoryPiece::query()
            ->realVendor()
            ->where('CategoryCode', $categoryCode)
            ->where('StoreCode', $storeCode)
            ->get(['InternalCode', 'VendorCode', 'Description', 'JewelSize', 'QtyOnHand', 'SalesDate'])
            ->filter(fn ($r) => static::sizeLabel($r->JewelSize) === $bucket)
            ->groupBy('InternalCode');

        $imageUrls = static::imageUrlsFor($grouped->keys());

        return $grouped
            ->map(function ($group) use ($monthStart, $vendorNames, $imageUrls) {
                $first = $group->first();
                $piecesSold = $group->filter(fn ($r) => $r->SalesDate !== null)->count();
                $soldThisMonth = $group->filter(fn ($r) => $r->SalesDate !== null && $r->SalesDate->greaterThanOrEqualTo($monthStart))->count();

                return [
                    'internal_code' => $first->InternalCode,
                    'description' => $first->Description,
                    'vendor_name' => $vendorNames[$first->VendorCode] ?? $first->VendorCode,
                    'image_url' => $imageUrls->get(trim((string) $first->InternalCode)),
                    'current_stock' => (int) $group->sum('QtyOnHand'),
                    'pieces_sold' => $piecesSold,
                    'sold_this_month' => $soldThisMonth,
                ];
            })
            ->sortByDesc('sold_this_month')
            ->values();
    }

    /**
     * Sama spt designsForSizeBucket() tapi bucket ikut Berat Emas (GoldWeight) - utk RestockByWeight.
     */
    public static function designsForWeightBucket(string $categoryCode, string $storeCode, string $bucket): Collection
    {
        $monthStart = now()->startOfMonth();
        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        $grouped = InventoryPiece::query()
            ->realVendor()
            ->where('CategoryCode', $categoryCode)
            ->where('StoreCode', $storeCode)
            ->get(['InternalCode', 'VendorCode', 'Description', 'GoldWeight', 'QtyOnHand', 'SalesDate'])
            ->filter(fn ($r) => static::weightBucket($r->GoldWeight) === $bucket)
            ->groupBy('InternalCode');

        $imageUrls = static::imageUrlsFor($grouped->keys());

        return $grouped
            ->map(function ($group) use ($monthStart, $vendorNames, $imageUrls) {
                $first = $group->first();
                $piecesSold = $group->filter(fn ($r) => $r->SalesDate !== null)->count();
                $soldThisMonth = $group->filter(fn ($r) => $r->SalesDate !== null && $r->SalesDate->greaterThanOrEqualTo($monthStart))->count();

                return [
                    'internal_code' => $first->InternalCode,
                    'description' => $first->Description,
                    'vendor_name' => $vendorNames[$first->VendorCode] ?? $first->VendorCode,
                    'image_url' => $imageUrls->get(trim((string) $first->InternalCode)),
                    'current_stock' => (int) $group->sum('QtyOnHand'),
                    'pieces_sold' => $piecesSold,
                    'sold_this_month' => $soldThisMonth,
                ];
            })
            ->sortByDesc('sold_this_month')
            ->values();
    }

    /**
     * Lookup kelompok jemisys_inventory_mirror.image_url bagi banyak InternalCode - dikongsi
     * antara semua drill-down "Lihat Design" (byCategoryPerBranch, designsForWeightBucket,
     * designsForSizeBucket, designsForFocus) supaya SATU query, bukan satu per InternalCode.
     *
     * @param  Collection<int, string>  $internalCodes
     * @return Collection<string, ?string>
     */
    public static function imageUrlsFor(Collection $internalCodes): Collection
    {
        return InventoryMirror::query()
            ->whereIn('InternalCode', $internalCodes->unique())
            ->get(['InternalCode', 'image_url'])
            ->mapWithKeys(fn ($m) => [trim((string) $m->InternalCode) => $m->image_url]);
    }

    /**
     * Cadangan restock per DESIGN (InternalCode) terus - bukan bucket Kategori+Cawangan+Saiz/Berat
     * spt bySize()/byWeight() (design cuma nampak via drill-down "Lihat Design" di situ). Skop
     * WAJIB satu CategoryCode (dipanggil hanya bila pengguna dah pilih kategori - rujuk
     * RestockByCategory page).
     *
     * Cache rememberForever PER KATEGORI (bukan skip cache spt draf asal - terbukti salah:
     * kategori besar cth. "CINCIN EMAS" (6298 design) ambil 5-7 saat SETIAP query GROUP BY, dan
     * halaman ni panggil byCategory() berkali-kali setiap load - tanpa cache jadi 20-30 saat
     * setiap tapis/muat semula). Dijana semula bila Cache::flush() drpd SyncJemisysMirrors,
     * sama spt bySize()/byWeight().
     */
    public static function byCategory(string $categoryCode): Collection
    {
        return collect(Cache::rememberForever("restock_by_category:{$categoryCode}", function () use ($categoryCode) {
            return retry(6, fn () => static::computeByCategory($categoryCode)->toArray(), 800);
        }));
    }

    protected static function computeByCategory(string $categoryCode): Collection
    {
        $trendStart = now()->subMonths(self::TREND_MONTHS);

        // Per (design, cawangan) - utk cari cawangan paling "urgent" (stok=0, jualan sejarah
        // tertinggi) sepadan corak StockRearrangementRecommender.
        $perBranch = InventoryPiece::query()
            ->realVendor()
            ->where('CategoryCode', $categoryCode)
            ->selectRaw('InternalCode, StoreCode, SUM(QtyOnHand) as stock, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold_all_time')
            ->groupBy('InternalCode', 'StoreCode')
            ->get()
            ->groupBy('InternalCode');

        $perDesign = InventoryPiece::query()
            ->realVendor()
            ->where('CategoryCode', $categoryCode)
            ->selectRaw('InternalCode, MAX(Description) as Description, MAX(JewelSize) as JewelSize, '.
                'MAX(GoldWeight) as GoldWeight, MAX(VendorCode) as VendorCode, '.
                'SUM(CASE WHEN PurchDate >= ? THEN 1 ELSE 0 END) as pieces_received, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(QtyOnHand) as current_stock', [$trendStart, $trendStart])
            ->groupBy('InternalCode')
            ->get();

        $categoryNames = Category::pluck('Description', 'CategoryCode');
        $vendorNames = Vendor::pluck('Description', 'VendorCode');
        $trendWindowDays = max((int) $trendStart->diffInDays(now()), 1);
        $categoryName = $categoryNames[$categoryCode] ?? $categoryCode;

        return $perDesign->map(function ($r) use ($perBranch, $vendorNames, $trendWindowDays, $categoryCode, $categoryName) {
            $piecesReceived = (int) $r->pieces_received;
            $piecesSold = (int) $r->pieces_sold;
            $currentStock = (int) $r->current_stock;

            $velocity = SalesVelocityHelper::velocity($piecesSold, $trendWindowDays);
            $targetStock = SalesVelocityHelper::targetStock($velocity, self::TARGET_COVER_MONTHS);

            $verdict = match (true) {
                $piecesReceived < self::MIN_SAMPLE => self::VERDICT_NO_DATA,
                $currentStock === 0 && $velocity > 0 => self::VERDICT_SOLD_OUT,
                $currentStock < $targetStock => self::VERDICT_RESTOCK,
                $targetStock > 0 && $currentStock > $targetStock * 2 => self::VERDICT_OVERSTOCK,
                default => self::VERDICT_OK,
            };

            // Cawangan paling urgent utk design ni: stok=0 DAN pernah jual paling banyak -
            // sepadan corak StockRearrangementRecommender ("sold out, jualan sejarah tertinggi").
            // Null bermaksud tiada cawangan sold-out (semua ada stok) - bukan "cawangan pertama".
            $urgentBranch = ($perBranch->get($r->InternalCode) ?? collect())
                ->filter(fn ($b) => (int) $b->stock === 0)
                ->sortByDesc('sold_all_time')
                ->first();

            // Pecahan stok per cawangan (termasuk cawangan stok=0 - bukan "tiada rekod", sekadar
            // habis) - guna baris $perBranch yg SAMA (bukan query tambahan), cawangan yg design
            // ni PERNAH ada rekod inventori sahaja (bukan cross-join semua cawangan syarikat).
            $stockByBranch = ($perBranch->get($r->InternalCode) ?? collect())
                ->sortBy('StoreCode')
                ->mapWithKeys(fn ($b) => [trim((string) $b->StoreCode) => (int) $b->stock])
                ->all();

            return [
                'internal_code' => $r->InternalCode,
                'description' => $r->Description,
                'category_code' => $categoryCode,
                'category_name' => $categoryName,
                'vendor_name' => $vendorNames[$r->VendorCode] ?? $r->VendorCode,
                'size' => static::sizeLabel($r->JewelSize),
                'weight' => (float) $r->GoldWeight,
                'weight_bucket' => static::weightBucket($r->GoldWeight),
                'current_stock' => $currentStock,
                'stock_by_branch' => $stockByBranch,
                'pieces_sold' => $piecesSold,
                'velocity_per_month' => $velocity,
                'target_stock' => $targetStock,
                'gap' => $targetStock - $currentStock,
                'verdict' => $verdict,
                'urgent_branch' => $urgentBranch?->StoreCode,
                'urgent_branch_sold' => $urgentBranch ? (int) $urgentBranch->sold_all_time : null,
            ];
        })->sortByDesc('gap')->values();
    }

    /**
     * Sama spt byWeight()/bySize() (grain Kategori x Cawangan x [bucket]) tapi bucket DIGANTI
     * terus dgn InternalCode - jawab "kod design MANA perlu restock, di cawangan MANA" secara
     * terus tanpa drill-down berasingan (bandingkan byCategory() yg gabung semua cawangan jadi
     * SATU baris setiap design via stock_by_branch - method ni PECAHKAN balik jadi satu baris
     * setiap (design, cawangan), sepadan corak paparan RestockByWeight/RestockBySize).
     *
     * Cache rememberForever PER KATEGORI + TEMPOH (kunci cache sertakan $period - tempoh
     * berlainan = trend window berlainan, TIDAK boleh kongsi cache sesama).
     */
    public static function byCategoryPerBranch(string $categoryCode, string $period = self::DEFAULT_PERIOD): Collection
    {
        return collect(Cache::rememberForever("restock_by_category_per_branch:{$categoryCode}:{$period}", function () use ($categoryCode, $period) {
            return retry(6, fn () => static::computeByCategoryPerBranch($categoryCode, $period)->toArray(), 800);
        }));
    }

    protected static function computeByCategoryPerBranch(string $categoryCode, string $period = self::DEFAULT_PERIOD): Collection
    {
        $trendStart = static::trendStartForPeriod($period);
        // Tetingkap velocity TETAP - rujuk nota sama dlm computeBySize() & dokblok PERIOD_ALL/finalize().
        $velocityWindowStart = now()->subMonths(self::TREND_MONTHS);

        // toBase()->get() - GROUP BY (InternalCode, StoreCode) dlm SATU kategori terpilih sahaja
        // (skop jauh lebih kecil drpd 490K baris penuh), tapi kekal toBase() sbg amalan selamat
        // (rujuk fix OOM RearrangeCalculator/StockRearrangementRecommender hari ni - casts model
        // tak terpakai pd lajur alias agregat spt ni, toBase() tiada beza tingkah laku).
        $raw = InventoryPiece::query()
            ->realVendor()
            ->where('CategoryCode', $categoryCode)
            ->selectRaw('InternalCode, StoreCode, MAX(Description) as Description, '.
                'SUM(CASE WHEN PurchDate >= ? THEN 1 ELSE 0 END) as pieces_received, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold_velocity_window, '.
                'SUM(QtyOnHand) as current_stock', [$trendStart, $trendStart, $velocityWindowStart])
            ->groupBy('InternalCode', 'StoreCode')
            ->toBase()
            ->get();

        $trendWindowDays = max((int) $trendStart->diffInDays(now()), 1);
        // PERIOD_ALL - rujuk dokblok finalize() (velocity WAJIB kekal tetingkap TREND_MONTHS
        // sebenar, bukan span "semua"/epoch).
        $useFixedVelocityWindow = $period === self::PERIOD_ALL;
        $velocityWindowDays = max((int) $velocityWindowStart->diffInDays(now()), 1);
        $categoryName = Category::where('CategoryCode', $categoryCode)->value('Description') ?? $categoryCode;

        // Foto produk hidup di jadual berasingan (jemisys_inventory_mirror.image_url, diisi oleh
        // SyncMerchantNicknamesAndImages) - bukan InventoryPiece (grain keping/stok). Rujuk
        // imageUrlsFor() - satu query kelompok utk semua InternalCode dlm kategori ni.
        $imageUrls = static::imageUrlsFor($raw->pluck('InternalCode'));

        return $raw->map(function ($r) use ($trendWindowDays, $useFixedVelocityWindow, $velocityWindowDays, $categoryCode, $categoryName, $imageUrls) {
            $piecesReceived = (int) $r->pieces_received;
            $piecesSold = (int) $r->pieces_sold;
            $currentStock = (int) $r->current_stock;

            $velocityPiecesSold = $useFixedVelocityWindow ? (int) $r->pieces_sold_velocity_window : $piecesSold;
            $velocityDays = $useFixedVelocityWindow ? $velocityWindowDays : $trendWindowDays;

            $velocity = SalesVelocityHelper::velocity($velocityPiecesSold, $velocityDays);
            $targetStock = SalesVelocityHelper::targetStock($velocity, self::TARGET_COVER_MONTHS);

            $verdict = match (true) {
                $piecesReceived < self::MIN_SAMPLE => self::VERDICT_NO_DATA,
                $currentStock === 0 && $velocity > 0 => self::VERDICT_SOLD_OUT,
                $currentStock < $targetStock => self::VERDICT_RESTOCK,
                $targetStock > 0 && $currentStock > $targetStock * 2 => self::VERDICT_OVERSTOCK,
                default => self::VERDICT_OK,
            };

            $internalCode = trim((string) $r->InternalCode);

            return [
                'category_code' => $categoryCode,
                'category_name' => $categoryName,
                'store_code' => trim((string) $r->StoreCode),
                'internal_code' => $internalCode,
                'description' => $r->Description,
                'image_url' => $imageUrls->get($internalCode),
                'pieces_received' => $piecesReceived,
                'pieces_sold' => $piecesSold,
                'current_stock' => $currentStock,
                'velocity_per_month' => $velocity,
                'target_stock' => $targetStock,
                'gap' => $targetStock - $currentStock,
                'verdict' => $verdict,
            ];
        })->sortByDesc('gap')->values();
    }

    /**
     * Sejarah jualan SATU design (InternalCode), pecah ikut bulan x Saiz/Berat x Cawangan - jawab
     * "item ni terjual size/berat/cawangan mana, bila" (satu InternalCode secara teori satu
     * saiz/berat, tapi keping fizikal individu ada variasi kecil - berat khususnya - jadi
     * pecahan ni beri isyarat sebenar, bukan andaian seragam). TIDAK cache - drill-down on-demand
     * per baris (sepadan designsForSizeBucket()).
     *
     * @return Collection<int, array{month: string, size: string, weight_bucket: string, store_code: string, qty_sold: int}>
     */
    public static function designSalesHistory(string $internalCode, int $months = 12): Collection
    {
        $since = now()->subMonths($months)->startOfMonth();

        return InventoryPiece::query()
            ->realVendor()
            ->where('InternalCode', $internalCode)
            ->whereNotNull('SalesDate')
            ->where('SalesDate', '>=', $since)
            ->get(['SalesDate', 'JewelSize', 'GoldWeight', 'StoreCode'])
            ->groupBy(fn ($r) => $r->SalesDate->format('Y-m'))
            ->flatMap(function ($rows, $month) {
                return $rows->groupBy(fn ($r) => static::sizeLabel($r->JewelSize).'|'.static::weightBucket($r->GoldWeight).'|'.trim((string) $r->StoreCode))
                    ->map(function ($group, $key) use ($month) {
                        [$size, $weightBucket, $storeCode] = explode('|', $key);

                        return [
                            'month' => $month,
                            'size' => $size,
                            'weight_bucket' => $weightBucket,
                            'store_code' => $storeCode,
                            'qty_sold' => $group->count(),
                        ];
                    });
            })
            ->sortByDesc('month')
            ->values();
    }

    public static function sizeLabel(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '(tiada)';
        }
        $s = trim((string) $value);
        if (is_numeric($s)) {
            $f = (float) $s;

            return $f == (int) $f ? (string) (int) $f : (string) $f;
        }

        return $s;
    }

    protected static function computeByWeight(string $period = self::DEFAULT_PERIOD): Collection
    {
        // Bucket berat DALAM SQL (CASE WHEN) sebelum GROUP BY - elak kumpul ikut GoldWeight
        // mentah (float berterusan) yg cipta beribu kumpulan tak perlu (punca OOM sblm ni).
        $caseExpr = static::weightBucketSqlCase();

        // GROUP BY kena ulang $caseExpr penuh, bukan alias 'bucket' - SQLite/MySQL benarkan
        // GROUP BY rujuk alias SELECT, tapi SQL Server tak (throw "Invalid column name").
        $trendStart = static::trendStartForPeriod($period);
        // Tetingkap velocity TETAP - rujuk nota sama dlm computeBySize() & dokblok PERIOD_ALL/finalize().
        $velocityWindowStart = now()->subMonths(self::TREND_MONTHS);

        $raw = InventoryPiece::query()
            ->realVendor()
            ->selectRaw("CategoryCode, StoreCode, {$caseExpr} as bucket, ".
                'SUM(CASE WHEN PurchDate >= ? THEN 1 ELSE 0 END) as pieces_received, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold_velocity_window, '.
                'SUM(QtyOnHand) as current_stock', [$trendStart, $trendStart, $velocityWindowStart])
            ->groupBy('CategoryCode', 'StoreCode', DB::raw($caseExpr))
            ->get();

        return static::finalize($raw, $period);
    }

    /** public - dikongsi dgn JobsheetRestockScorer (bucket berat DLM SQL, elak tarik baris
     * mentah utk bucket dlm PHP - rujuk dokblok kelas tsb). */
    public static function weightBucketSqlCase(): string
    {
        $cases = [];
        foreach (self::WEIGHT_LABELS as $i => $label) {
            $min = self::WEIGHT_BINS[$i];
            $max = self::WEIGHT_BINS[$i + 1];
            $upper = $max === PHP_INT_MAX ? '' : " AND GoldWeight < {$max}";
            $cases[] = "WHEN GoldWeight >= {$min}{$upper} THEN '{$label}'";
        }
        $whens = implode(' ', $cases);

        return "CASE WHEN GoldWeight IS NULL THEN '(tiada)' {$whens} ELSE '50g+' END";
    }

    /** Versi PHP (bukan SQL) bagi weightBucketSqlCase() - guna WEIGHT_BINS/LABELS sama, utk ujian/konsistensi. */
    public static function weightBucket(mixed $grams): string
    {
        if ($grams === null) {
            return '(tiada)';
        }
        $g = (float) $grams;
        foreach (self::WEIGHT_LABELS as $i => $label) {
            $max = self::WEIGHT_BINS[$i + 1];
            if ($g >= self::WEIGHT_BINS[$i] && ($max === PHP_INT_MAX || $g < $max)) {
                return $label;
            }
        }

        return '50g+';
    }

    protected static function finalize(Collection $raw, string $period = self::DEFAULT_PERIOD): Collection
    {
        $trendStart = static::trendStartForPeriod($period);
        $trendWindowDays = max((int) $trendStart->diffInDays(now()), 1);
        $categoryNames = Category::pluck('Description', 'CategoryCode');

        // PERIOD_ALL - pieces_received/pieces_sold (jumlah dipaparkan) TIADA sempadan tarikh,
        // TAPI velocity/target_stock/verdict WAJIB kekal guna tetingkap TREND_MONTHS sebenar
        // (bukan span "semua"/epoch ni) - kalau tidak, velocity runtuh hampir sifar (dibahagi
        // puluhan ribu hari), rosakkan cadangan restock. Rujuk pieces_sold_velocity_window
        // (dikira berasingan dlm computeBySize()/computeByWeight()) & dokblok PERIOD_ALL.
        $useFixedVelocityWindow = $period === self::PERIOD_ALL;
        $velocityWindowDays = max((int) now()->subMonths(self::TREND_MONTHS)->diffInDays(now()), 1);

        $out = $raw->map(function ($r) use ($trendWindowDays, $categoryNames, $useFixedVelocityWindow, $velocityWindowDays) {
            $piecesReceived = (int) $r->pieces_received;
            $piecesSold = (int) $r->pieces_sold;
            $currentStock = (int) $r->current_stock;

            $velocityPiecesSold = $useFixedVelocityWindow ? (int) $r->pieces_sold_velocity_window : $piecesSold;
            $velocityDays = $useFixedVelocityWindow ? $velocityWindowDays : $trendWindowDays;

            $velocity = SalesVelocityHelper::velocity($velocityPiecesSold, $velocityDays);
            $targetStock = SalesVelocityHelper::targetStock($velocity, self::TARGET_COVER_MONTHS);

            if ($piecesReceived < self::MIN_SAMPLE) {
                $verdict = self::VERDICT_NO_DATA;
            } elseif ($currentStock === 0 && $velocity > 0) {
                $verdict = self::VERDICT_SOLD_OUT;
            } elseif ($currentStock < $targetStock) {
                $verdict = self::VERDICT_RESTOCK;
            } elseif ($targetStock > 0 && $currentStock > $targetStock * 2) {
                $verdict = self::VERDICT_OVERSTOCK;
            } else {
                $verdict = self::VERDICT_OK;
            }

            return [
                'category_code' => $r->CategoryCode,
                'category_name' => $categoryNames[$r->CategoryCode] ?? $r->CategoryCode,
                'store_code' => $r->StoreCode,
                'bucket' => $r->bucket,
                'pieces_received' => $piecesReceived,
                'pieces_sold' => $piecesSold,
                'current_stock' => $currentStock,
                'velocity_per_month' => $velocity,
                'target_stock' => $targetStock,
                'gap' => $targetStock - $currentStock,
                'verdict' => $verdict,
            ];
        });

        return $out->sortByDesc('gap')->values();
    }
}
