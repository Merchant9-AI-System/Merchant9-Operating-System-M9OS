<?php

namespace App\Http\Controllers;

use App\Models\InventoryMirror;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use App\Models\RestockBucketSnapshot;
use App\Models\RestockDailyCount;
use App\Models\RestockSnapshot;
use App\Models\RestockVerdictChange;
use App\Models\User;
use App\Support\RestockAnalysisCalculator;
use App\Support\RestockSnapshotQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Inertia+Vue "Restock Analysis" (kumpulan menu Analisis JEMiSys): membantu CEO, Back Office & ketua cawangan MEMAHAMI produk
 * mereka setiap hari - bukan alat membuat pesanan (Back Office yg menentukan kuantiti).
 *
 * Sumber data = jadual ringkasan yg dibina selepas segerak mirror harian (rujuk App\Support\RestockSnapshotMaterializer): restock_snapshots
 * (design x cawangan) & restock_bucket_snapshots (saiz/berat). Penapis, susunan & halaman dilakukan di database (RestockSnapshotQuery),
 * jadi halaman pantas & tidak memuat ribuan baris ke pelayar.
 *
 * Paparan: `design` (senarai design perlu perhatian - muka depan), `summary` (ringkasan cawangan & kategori), `size`/`weight` (analisis
 * bucket). Akses = kebenaran Shield: design & summary guna View:RestockByCategory, size View:RestockBySize, weight View:RestockByWeight;
 * super_admin dianggap ada semua. Peranan lalai: ceo mula di Ringkasan, selebihnya di Senarai Design.
 *
 * Cawangan lalai = cawangan pengguna sendiri (boleh ditukar; `store=all` = semua). Cadangan lalai = Sold Out + Perlu Restock
 * (`verdict=all` = semua). Semua keadaan penapis ada dlm URL supaya boleh dikongsi.
 */
class RestockAnalysisController extends Controller
{
    /** Paparan => kebenaran Shield. */
    public const VIEW_PERMISSIONS = [
        'design' => 'View:RestockByCategory',
        'summary' => 'View:RestockByCategory',
        'size' => 'View:RestockBySize',
        'weight' => 'View:RestockByWeight',
    ];

    public const PER_PAGE_OPTIONS = [25, 50, 100, 200];

    public const DEFAULT_PER_PAGE = 50;

    /** Bilangan design maksimum dlm panel drill-down bucket (disusun Terjual Bulan Ini tertinggi dahulu). */
    public const DESIGNS_LIMIT = 50;

    /** Bilangan kategori teratas (ikut kekurangan) dlm paparan Ringkasan. */
    public const SUMMARY_CATEGORY_LIMIT = 25;

    /** Cadangan lalai bila `verdict` tiada dlm URL. */
    private const DEFAULT_VERDICTS = [
        RestockAnalysisCalculator::VERDICT_SOLD_OUT,
        RestockAnalysisCalculator::VERDICT_RESTOCK,
    ];

    public function index(Request $request): Response
    {
        $views = $this->allowedViews();
        abort_if($views === [], 403);

        $filters = $this->filters($request, $views);
        $scope = $filters['view'] === 'summary' ? 'design' : $filters['view'];

        $updatedAt = RestockSnapshot::query()->max('computed_at');

        $props = [
            'filters' => $filters,
            'viewOptions' => collect($views)->map(fn (string $view) => ['value' => $view, 'label' => $this->viewLabel($view)])->values(),
            'categories' => $this->categoryOptions(),
            'stores' => RestockSnapshot::query()->distinct()->orderBy('store_code')->pluck('store_code')
                ->map(fn (string $code) => ['value' => $code, 'label' => $code])->values(),
            'verdicts' => $this->verdicts(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'updatedAt' => $updatedAt,
            'changes' => $this->changes($filters),
            'summary' => $this->remember('summary_v2', $updatedAt, [$scope, $filters['category'], $filters['store'], $filters['q']], fn () => $this->summary($filters)),
            'rows' => null,
            'breakdown' => null,
            'trend' => null,
            'curve' => null,
        ];

        if ($filters['view'] === 'summary') {
            $props['breakdown'] = $this->remember('breakdown', $updatedAt, [$filters['category'], $filters['store'], $filters['q']], fn () => $this->breakdown($filters));
            $props['trend'] = $this->trend($filters);
        } elseif ($scope === 'design') {
            $props['rows'] = $this->designRows($filters);
        } else {
            $props['rows'] = $this->bucketRows($filters);
            $props['curve'] = $this->curve($filters);
        }

        return Inertia::render('RestockAnalysis/Index', $props);
    }

    /**
     * Panel kefahaman SATU design: stok & jualan di setiap cawangan (calon transfer = cawangan berlebihan), sebab Cadangan, dan jualan
     * 12 bulan terkini (cawangan dipilih berbanding cawangan lain).
     */
    public function design(Request $request): JsonResponse
    {
        abort_unless($this->canView('design'), 403);

        $code = trim((string) $request->query('code'));
        $store = trim((string) $request->query('store'));
        abort_if($code === '', 422);

        $rows = RestockSnapshot::query()->where('internal_code', $code)->orderBy('store_code')->get();
        abort_if($rows->isEmpty(), 404);

        $first = $rows->first();
        $vendorName = Vendor::query()->get()->first(fn ($v) => trim((string) $v->VendorCode) === $first->vendor_code)?->Description;

        return response()->json([
            'code' => $code,
            'description' => $first->description,
            'category_code' => $first->category_code,
            'category_name' => $this->categoryNames()[$first->category_code] ?? $first->category_code,
            'vendor_name' => $vendorName !== null ? trim((string) $vendorName) : $first->vendor_code,
            'image_url' => $this->imageUrls(collect([$code]))->get($code),
            'selected_store' => $store,
            'computed_at' => $first->computed_at,
            'stores' => $rows->map(fn (RestockSnapshot $row) => [
                'store_code' => $row->store_code,
                'stock' => $row->stock,
                'sold_1w' => $row->sold_1w,
                'sold_1m' => $row->sold_1m,
                'sold_3m' => $row->sold_3m,
                'sold_6m' => $row->sold_6m,
                'sold_1y' => $row->sold_1y,
                'velocity_per_month' => $row->velocity_per_month,
                'target_stock' => $row->target_stock,
                'gap' => $row->gap,
                'cover_months' => $row->cover_months,
                'verdict' => $row->verdict,
                'last_sold_at' => $row->last_sold_at?->toDateString(),
                // Calon transfer: cawangan cukup/berlebihan yg stoknya melebihi paras disyorkan.
                'surplus' => in_array($row->verdict, [RestockAnalysisCalculator::VERDICT_OK, RestockAnalysisCalculator::VERDICT_OVERSTOCK], true)
                    ? max(0, $row->stock - $row->target_stock)
                    : 0,
            ])->values(),
            'monthly' => $this->monthlySales($code, $store),
        ]);
    }

    /**
     * Drill-down paparan Saiz/Berat: design dlm satu bucket (Kategori + Cawangan + bucket), terjual bulan ini tertinggi dahulu.
     */
    public function designs(Request $request): JsonResponse
    {
        $view = (string) $request->query('view');
        abort_unless(in_array($view, ['size', 'weight'], true) && $this->canView($view), 403);

        $category = trim((string) $request->query('category'));
        $store = trim((string) $request->query('store'));
        $bucket = trim((string) $request->query('bucket'));
        abort_if($category === '' || $store === '' || $bucket === '', 422);

        $all = $view === 'size'
            ? RestockAnalysisCalculator::designsForSizeBucket($category, $store, $bucket)
            : RestockAnalysisCalculator::designsForWeightBucket($category, $store, $bucket);

        return response()->json([
            'total' => $all->count(),
            'designs' => $all->take(self::DESIGNS_LIMIT)->values(),
        ]);
    }

    /** Item menu Filament: nampak jika pengguna ada sekurang-kurangnya satu paparan. */
    public static function canAccess(?User $user): bool
    {
        return self::viewsFor($user) !== [];
    }

    /**
     * @param  array{view: string, category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool, sort: list<array{key: string, dir: string}>, per_page: int, page: int}  $filters
     * @return array{data: list<array<string, mixed>>, total: int, page: int, last_page: int, per_page: int}
     */
    private function designRows(array $filters): array
    {
        $query = RestockSnapshotQuery::applySort(RestockSnapshotQuery::designs($filters), $filters['sort'], 'design');
        $paginator = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page']);

        $categoryNames = $this->categoryNames();
        $vendorNames = Vendor::query()->get()->mapWithKeys(fn ($v) => [trim((string) $v->VendorCode) => trim((string) $v->Description)]);
        $images = $this->imageUrls($paginator->getCollection()->pluck('internal_code'));

        return $this->pageOf($paginator, fn (RestockSnapshot $row) => [
            'internal_code' => $row->internal_code,
            'store_code' => $row->store_code,
            'category_code' => $row->category_code,
            'category_name' => $categoryNames[$row->category_code] ?? $row->category_code,
            'description' => $row->description,
            'vendor_name' => $vendorNames[$row->vendor_code] ?? $row->vendor_code,
            'image_url' => $images->get($row->internal_code),
            'stock' => $row->stock,
            'sold_1w' => $row->sold_1w,
            'sold_1m' => $row->sold_1m,
            'sold_3m' => $row->sold_3m,
            'cover_months' => $row->cover_months,
            'target_stock' => $row->target_stock,
            'gap' => $row->gap,
            'verdict' => $row->verdict,
            'last_sold_at' => $row->last_sold_at?->toDateString(),
        ]);
    }

    /**
     * @param  array{view: string, category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool, sort: list<array{key: string, dir: string}>, per_page: int, page: int}  $filters
     * @return array{data: list<array<string, mixed>>, total: int, page: int, last_page: int, per_page: int}
     */
    private function bucketRows(array $filters): array
    {
        $query = RestockSnapshotQuery::applySort(RestockSnapshotQuery::buckets($filters['view'], $filters), $filters['sort'], 'bucket');
        $paginator = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page']);

        $categoryNames = $this->categoryNames();

        return $this->pageOf($paginator, fn (RestockBucketSnapshot $row) => [
            'category_code' => $row->category_code,
            'category_name' => $categoryNames[$row->category_code] ?? $row->category_code,
            'store_code' => $row->store_code,
            'bucket' => $row->bucket,
            'stock' => $row->stock,
            'sold_1w' => $row->sold_1w,
            'sold_1m' => $row->sold_1m,
            'sold_3m' => $row->sold_3m,
            'cover_months' => $row->cover_months,
            'target_stock' => $row->target_stock,
            'gap' => $row->gap,
            'verdict' => $row->verdict,
        ]);
    }

    /**
     * @template T
     *
     * @param  LengthAwarePaginator<int, T>  $paginator
     * @param  callable(T): array<string, mixed>  $map
     * @return array{data: list<array<string, mixed>>, total: int, page: int, last_page: int, per_page: int}
     */
    private function pageOf(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => $paginator->getCollection()->map($map)->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    /**
     * Agregat (ringkasan, pecahan) hanya berubah bila snapshot dibina semula - di-cache mengikut masa snapshot + skop penapis (Cache::flush()
     * selepas segerak harian turut membersihkannya).
     *
     * @param  array<int, mixed>  $scope
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function remember(string $name, ?string $updatedAt, array $scope, callable $callback): array
    {
        return Cache::remember('restock_analysis:'.$name.':'.md5(json_encode([$updatedAt, $scope])), now()->addHours(6), $callback);
    }

    /**
     * Kad ringkasan: bilangan mengikut Cadangan + jumlah kekurangan (pcs) dlm skop penapis (kecuali Cadangan).
     *
     * @param  array{view: string, category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool, sort: list<array{key: string, dir: string}>, per_page: int, page: int}  $filters
     * @return array{counts: array<string, int>, shortfalls: array<string, int>, shortfall: int, total: int}
     */
    private function summary(array $filters): array
    {
        $base = in_array($filters['view'], ['size', 'weight'], true)
            ? RestockSnapshotQuery::buckets($filters['view'], $filters, withVerdict: false)
            : RestockSnapshotQuery::designs($filters, withVerdict: false);

        $rows = $base->selectRaw('verdict, COUNT(*) as total, SUM(CASE WHEN gap > 0 THEN gap ELSE 0 END) as shortfall')->groupBy('verdict')->get();

        return [
            'counts' => $rows->mapWithKeys(fn ($r) => [$r->verdict => (int) $r->total])->all(),
            // Kekurangan (pcs) setiap Cadangan - kad ringkasan menunjukkan "Kurang N pcs" bagi Sold Out & Perlu Restock.
            'shortfalls' => $rows->mapWithKeys(fn ($r) => [$r->verdict => (int) $r->shortfall])->all(),
            'shortfall' => (int) $rows->sum('shortfall'),
            'total' => (int) $rows->sum('total'),
        ];
    }

    /**
     * Perubahan Cadangan pada snapshot terkini (dlm skop cawangan/kategori): berapa yg baharu Sold Out / Perlu Restock.
     *
     * @param  array{view: string, category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool, sort: list<array{key: string, dir: string}>, per_page: int, page: int}  $filters
     * @return array{at: ?string, sold_out: int, restock: int}
     */
    private function changes(array $filters): array
    {
        $latest = RestockSnapshotQuery::latestChangeAt();

        if ($latest === null) {
            return ['at' => null, 'sold_out' => 0, 'restock' => 0];
        }

        $counts = RestockVerdictChange::query()
            ->where('changed_at', $latest)
            ->when($filters['store'] !== [], fn ($q) => $q->whereIn('store_code', $filters['store']))
            ->when($filters['category'] !== [], fn ($q) => $q->whereIn('category_code', $filters['category']))
            ->selectRaw('to_verdict, COUNT(*) as total')
            ->groupBy('to_verdict')
            ->pluck('total', 'to_verdict');

        return [
            'at' => $latest,
            'sold_out' => (int) ($counts[RestockAnalysisCalculator::VERDICT_SOLD_OUT] ?? 0),
            'restock' => (int) ($counts[RestockAnalysisCalculator::VERDICT_RESTOCK] ?? 0),
        ];
    }

    /**
     * Paparan Ringkasan (lensa CEO): kekurangan mengikut cawangan & kategori teratas.
     *
     * @param  array{view: string, category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool, sort: list<array{key: string, dir: string}>, per_page: int, page: int}  $filters
     * @return array{stores: list<array<string, mixed>>, categories: list<array<string, mixed>>}
     */
    private function breakdown(array $filters): array
    {
        $select = 'COUNT(*) as total, '.
            'SUM(CASE WHEN verdict = ? THEN 1 ELSE 0 END) as sold_out, '.
            'SUM(CASE WHEN verdict = ? THEN 1 ELSE 0 END) as restock, '.
            'SUM(CASE WHEN gap > 0 THEN gap ELSE 0 END) as shortfall';
        $bindings = [RestockAnalysisCalculator::VERDICT_SOLD_OUT, RestockAnalysisCalculator::VERDICT_RESTOCK];

        $stores = RestockSnapshotQuery::designs($filters, withVerdict: false)
            ->selectRaw("store_code as code, {$select}", $bindings)->groupBy('store_code')->orderByDesc('shortfall')->get();

        $categoryNames = $this->categoryNames();
        $categories = RestockSnapshotQuery::designs($filters, withVerdict: false)
            ->selectRaw("category_code as code, {$select}", $bindings)->groupBy('category_code')->orderByDesc('shortfall')
            ->limit(self::SUMMARY_CATEGORY_LIMIT)->get();

        $format = fn ($row, ?string $label = null) => [
            'code' => $row->code,
            'label' => $label ?? $row->code,
            'total' => (int) $row->total,
            'sold_out' => (int) $row->sold_out,
            'restock' => (int) $row->restock,
            'shortfall' => (int) $row->shortfall,
        ];

        return [
            'stores' => $stores->map(fn ($row) => $format($row))->values()->all(),
            'categories' => $categories->map(fn ($row) => $format($row, $categoryNames[$row->code] ?? $row->code))->values()->all(),
        ];
    }

    /** Tempoh (hari) carta trend. */
    public const TREND_DAYS = 30;

    /** Bilangan bucket maksimum dlm lengkung saiz/berat (yg paling laris) - kategori seperti Rantai Tangan ada ratusan saiz. */
    public const CURVE_LIMIT = 24;

    /**
     * Trend harian Sold Out & Perlu Restock (jumlah cawangan dlm skop penapis) - sejarah bermula dari hari kiraan mula direkod.
     *
     * @param  array{view: string, category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool, sort: list<array{key: string, dir: string}>, per_page: int, page: int}  $filters
     * @return list<array{date: string, sold_out: int, restock: int}>
     */
    private function trend(array $filters): array
    {
        return RestockDailyCount::query()
            ->where('counted_on', '>=', now()->subDays(self::TREND_DAYS - 1)->toDateString())
            ->when($filters['store'] !== [], fn ($q) => $q->whereIn('store_code', $filters['store']))
            ->selectRaw('counted_on, SUM(sold_out) as sold_out, SUM(restock) as restock')
            ->groupBy('counted_on')
            ->orderBy('counted_on')
            ->toBase()
            ->get()
            ->map(fn ($row) => ['date' => substr((string) $row->counted_on, 0, 10), 'sold_out' => (int) $row->sold_out, 'restock' => (int) $row->restock])
            ->all();
    }

    /**
     * Lengkung saiz/berat satu kategori: stok berbanding jualan 3 bulan bagi setiap bucket (jumlah cawangan dlm skop). Hanya bila TEPAT satu
     * kategori dipilih (bucket kategori berlainan tak bermakna jika dicampur).
     *
     * @param  array{view: string, category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool, sort: list<array{key: string, dir: string}>, per_page: int, page: int}  $filters
     * @return ?array{data: list<array{bucket: string, stock: int, sold_3m: int}>, total: int}
     */
    private function curve(array $filters): ?array
    {
        if (count($filters['category']) !== 1) {
            return null;
        }

        $rows = RestockBucketSnapshot::query()
            ->where('kind', $filters['view'])
            ->where('category_code', $filters['category'][0])
            ->when($filters['store'] !== [], fn ($q) => $q->whereIn('store_code', $filters['store']))
            ->selectRaw('bucket, SUM(stock) as stock, SUM(sold_3m) as sold_3m')
            ->groupBy('bucket')
            ->toBase()
            ->get()
            ->map(fn ($row) => ['bucket' => (string) $row->bucket, 'stock' => (int) $row->stock, 'sold_3m' => (int) $row->sold_3m]);

        // Saiz: ikut nombor (bukan teks); berat: ikut tertib julat; "(tiada)" sentiasa terakhir.
        $weightOrder = array_flip(RestockAnalysisCalculator::WEIGHT_LABELS);
        $rank = fn (string $bucket): float => match (true) {
            $filters['view'] === 'weight' => (float) ($weightOrder[$bucket] ?? PHP_INT_MAX),
            is_numeric($bucket) => (float) $bucket,
            default => (float) PHP_INT_MAX,
        };

        // Hadkan kepada bucket paling laris (kemudian disusun semula ikut saiz/berat) supaya paksi tidak sesak.
        $top = $rows->sortByDesc(fn (array $row) => [$row['sold_3m'], $row['stock']])->take(self::CURVE_LIMIT);

        return [
            'data' => $top->sortBy(fn (array $row) => $rank($row['bucket']))->values()->all(),
            'total' => $rows->count(),
        ];
    }

    /**
     * Jualan 12 bulan terkini satu design: cawangan dipilih berbanding cawangan lain (bulan tanpa jualan = 0).
     *
     * @return list<array{month: string, store: int, others: int}>
     */
    private function monthlySales(string $code, string $store): array
    {
        $start = now()->subMonths(11)->startOfMonth();

        $sales = InventoryPiece::query()
            ->realVendor()
            ->where(fn ($q) => $q->where('InternalCode', $code)->orWhere('InternalCode', $code.' '))
            ->whereNotNull('SalesDate')
            ->where('SalesDate', '>=', $start)
            ->selectRaw("DATE_FORMAT(SalesDate, '%Y-%m') as month, TRIM(StoreCode) as store, COUNT(*) as total")
            ->groupByRaw("DATE_FORMAT(SalesDate, '%Y-%m'), TRIM(StoreCode)")
            ->toBase()
            ->get()
            ->groupBy('month');

        return collect(range(0, 11))->map(function (int $offset) use ($start, $sales, $store) {
            $month = $start->copy()->addMonths($offset)->format('Y-m');
            $rows = $sales->get($month, collect());
            $selected = (int) $rows->where('store', $store)->sum('total');

            return ['month' => $month, 'store' => $selected, 'others' => (int) $rows->sum('total') - $selected];
        })->all();
    }

    /**
     * Pilihan Kategori drpd data sebenar (snapshot). Nama yg sama (tanpa kira huruf) pd kod berbeza - cth. "Gold Bar" (BAR) & "GOLD BAR" (GB)
     * - diberi kod dlm label supaya boleh dibezakan.
     *
     * @return list<array{value: string, label: string}>
     */
    private function categoryOptions(): array
    {
        $names = $this->categoryNames();

        $categories = RestockSnapshot::query()->distinct()->orderBy('category_code')->pluck('category_code')
            ->filter()
            ->map(fn (string $code) => ['value' => $code, 'label' => $names[$code] ?? $code])
            ->sortBy(fn (array $category) => mb_strtolower($category['label']))
            ->values();

        $duplicates = $categories->countBy(fn (array $category) => mb_strtolower($category['label']))->filter(fn (int $count) => $count > 1);

        return $categories
            ->map(fn (array $category) => $duplicates->has(mb_strtolower($category['label']))
                ? ['value' => $category['value'], 'label' => $category['label'].' ('.$category['value'].')']
                : $category)
            ->all();
    }

    /**
     * URL gambar bagi senarai kod design: SATU baris setiap design (kumpul dlm SQL). RestockAnalysisCalculator::imageUrlsFor() memuat setiap
     * piece - design emas jongkong ada ribuan piece sama, menjadikannya lambat utk satu halaman.
     *
     * @param  Collection<int, string>  $codes
     * @return Collection<string, string>
     */
    private function imageUrls(Collection $codes): Collection
    {
        if ($codes->isEmpty()) {
            return collect();
        }

        return InventoryMirror::query()
            ->whereIn('InternalCode', $codes->unique()->values())
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->groupBy('InternalCode')
            ->selectRaw('InternalCode, MAX(image_url) as image_url')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [trim((string) $row->InternalCode) => $row->image_url]);
    }

    /** @return Collection<string, string> kod kategori (trim) => nama */
    private function categoryNames(): Collection
    {
        return Category::query()->get()->mapWithKeys(fn ($c) => [trim((string) $c->CategoryCode) => trim((string) $c->Description)]);
    }

    /**
     * @param  list<string>  $views
     * @return array{view: string, category: list<string>, store: list<string>, verdict: list<string>, q: string, new: bool, sort: list<array{key: string, dir: string}>, per_page: int, page: int}
     */
    private function filters(Request $request, array $views): array
    {
        $requested = (string) $request->query('view');
        $view = in_array($requested, $views, true) ? $requested : $this->defaultView($views);

        // Cawangan: tiada parameter = cawangan pengguna sendiri; `all` / kosong = semua cawangan.
        $store = $request->has('store') ? $this->codes($request->query('store')) : $this->codes(Auth::user()?->store_code);

        // Cadangan: tiada parameter = Sold Out + Perlu Restock; `all` / kosong = semua.
        $verdict = $request->has('verdict')
            ? array_values(array_intersect($this->verdicts(), (array) $request->query('verdict')))
            : self::DEFAULT_VERDICTS;

        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $allowedSorts = in_array($view, ['size', 'weight'], true) ? RestockSnapshotQuery::BUCKET_SORTS : RestockSnapshotQuery::DESIGN_SORTS;

        return [
            'view' => $view,
            'category' => $this->codes($request->query('category')),
            'store' => $store,
            'verdict' => $verdict,
            'q' => Str::limit(trim((string) $request->query('q')), 60, ''),
            'new' => $request->boolean('new'),
            'sort' => RestockSnapshotQuery::parseSorts((string) $request->query('sort'), $allowedSorts),
            'per_page' => in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE,
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }

    /**
     * Senarai kod bersih drpd parameter skalar atau tatasusunan; `all` = tiada tapisan.
     *
     * @return list<string>
     */
    private function codes(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->map(fn ($code) => Str::limit(trim((string) $code), 20, ''))
            ->reject(fn (string $code) => $code === '' || $code === 'all')
            ->unique()
            ->values()
            ->all();
    }

    /** @param  list<string>  $views */
    private function defaultView(array $views): string
    {
        // CEO mula di ringkasan; peranan lain di senarai design.
        $preferred = Auth::user()?->hasRole('ceo') ? 'summary' : 'design';

        return in_array($preferred, $views, true) ? $preferred : $views[0];
    }

    /**
     * @return list<string>
     */
    private static function viewsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::VIEW_PERMISSIONS),
            fn (string $view) => $user->isSuperAdmin() || $user->can(self::VIEW_PERMISSIONS[$view]),
        ));
    }

    /** @return list<string> */
    private function allowedViews(): array
    {
        return self::viewsFor(Auth::user());
    }

    private function canView(string $view): bool
    {
        return in_array($view, $this->allowedViews(), true);
    }

    private function viewLabel(string $view): string
    {
        return [
            'design' => 'Senarai Design',
            'summary' => 'Ringkasan',
            'size' => 'Ikut Saiz',
            'weight' => 'Ikut Berat',
        ][$view];
    }

    /** @return list<string> */
    private function verdicts(): array
    {
        return [
            RestockAnalysisCalculator::VERDICT_SOLD_OUT,
            RestockAnalysisCalculator::VERDICT_RESTOCK,
            RestockAnalysisCalculator::VERDICT_OK,
            RestockAnalysisCalculator::VERDICT_OVERSTOCK,
            RestockAnalysisCalculator::VERDICT_NO_DATA,
        ];
    }
}
