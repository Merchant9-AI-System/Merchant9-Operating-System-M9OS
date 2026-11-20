<?php

namespace App\Http\Controllers;

use App\Enums\JemisysInventoryStatus;
use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use App\Models\InventoryMirror;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use App\Models\RestockBucketSnapshot;
use App\Models\RestockDailyCount;
use App\Models\RestockSnapshot;
use App\Models\RestockVerdictChange;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\RestockAnalysisCalculator;
use App\Support\RestockSnapshotQuery;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
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

    /** Bukan cawangan jualan: tiada butang Request/Transfer di kotak cadangan & tidak disenaraikan sbg sumber/penerima. */
    public const NON_BRANCH_STORES = ['HQ', 'SECURITY'];

    /** Stok minimum (boleh diambil) sebuah cawangan utk dijadikan sumber transfer - 1 piece biasanya stok paparan. */
    public const MIN_SOURCE_STOCK = 2;

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
            // Butang Kembali: dashboard panel admin Filament.
            'dashboardUrl' => Filament::getPanel('admin')->getUrl(),
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
        $movementData = $this->designMovements($code);
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
            // Stok & jualan 3 bulan ikut cawangan x saiz (+ julat berat) - supaya tahu SAIZ/BERAT mana yg ada atau habis.
            'sizes' => $this->designSizes($code),
            // Maklumat tambahan (tidak mengubah stok/Cadangan): piece dlm perjalanan antara cawangan & piece yg dikhaskan.
            'movements' => $movementData['movements'],
            'holds' => $movementData['holds'],
            // Butang "Request" pada kotak cadangan: boleh hantar utk cawangan dipilih? & request aktif sedia ada utk design ini.
            'can_request' => $this->canRequestFor($store),
            'can_transfer' => $this->canTransferFrom($store),
            'requests' => $this->activeRequests($code, $store),
        ]);
    }

    /**
     * Hantar permintaan restock utk satu design terus dari panel: HANYA menambah satu baris pada permintaan cawangan (BranchDemandRequest
     * kekal satu setiap cawangan - rujuk BranchDemandEntryController::store). Kuantiti TIDAK ditentukan di sini - Back Office yg memutuskan -
     * jadi `qty_requested` (wajib, bukan null) diisi pengguna dlm borang (lalai 1 jika tidak dihantar).
     */
    public function storeRequest(Request $request): JsonResponse
    {
        abort_unless($this->canView('design'), 403);

        // Validator manual: app ini memaksa laluan bukan api/* dirender sbg HTML (rujuk bootstrap/app.php), jadi validate() akan MENGALIH, bukan JSON 422.
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:20'],
            'store_code' => ['required', 'string', 'max:20'],
            'size' => ['nullable', 'string', 'max:100'],
            'weight' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', 'in:request,order'],
            'is_critical' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string', 'max:255'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], [
            'qty.integer' => 'Kuantiti mesti nombor bulat.',
            'qty.min' => 'Kuantiti mesti sekurang-kurangnya 1.',
            'qty.max' => 'Kuantiti terlalu besar (maksimum :max).',
            'size.max' => 'Saiz terlalu panjang (maksimum :max aksara).',
            'weight.max' => 'Berat terlalu panjang (maksimum :max aksara).',
            'remark.max' => 'Remark terlalu panjang (maksimum :max aksara).',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $code = trim($data['code']);
        $storeCode = trim($data['store_code']);
        abort_unless($this->canRequestFor($storeCode), 403);

        $snapshot = RestockSnapshot::query()->where('internal_code', $code)->where('store_code', $storeCode)->first();
        abort_if($snapshot === null, 404);

        // Request hanya utk cawangan yg kekurangan stok (Sold Out / Perlu Restock).
        if (! in_array($snapshot->verdict, [RestockAnalysisCalculator::VERDICT_SOLD_OUT, RestockAnalysisCalculator::VERDICT_RESTOCK], true)) {
            return response()->json(['message' => "{$storeCode} tidak memerlukan restock untuk design ini."], 422);
        }

        $store = Store::whereRaw('TRIM(StoreCode) = ?', [$storeCode])->first();
        abort_if($store === null, 404);

        $size = filled($data['size'] ?? null) ? trim($data['size']) : null;
        $weight = filled($data['weight'] ?? null) ? trim($data['weight']) : null;

        $duplicate = collect($this->activeRequests($code, $storeCode))->contains(
            fn (array $line) => mb_strtolower((string) $line['size']) === mb_strtolower((string) $size) && (string) $line['weight'] === (string) $weight,
        );

        if ($duplicate) {
            return response()->json(['message' => 'Permintaan yang sama untuk design, saiz dan berat ini sudah ada dan masih diproses.'], 422);
        }

        $demand = BranchDemandRequest::where('store_code', $store->StoreCode)->latest()->first();
        $isFirstEver = $demand === null;

        if ($isFirstEver) {
            $demand = BranchDemandRequest::create([
                'store_code' => $store->StoreCode,
                'submitted_by_name' => Auth::user()->name,
            ]);
        }

        $line = $demand->lines()->create([
            'internal_code' => $code,
            'source_type' => BranchDemandRequestLine::SOURCE_CATALOG,
            'item_desc' => $snapshot->description,
            'qty_requested' => (int) ($data['qty'] ?? 1),
            'remark' => filled($data['remark'] ?? null) ? trim($data['remark']) : null,
            'size' => $size,
            'weight' => $weight,
            'category_name' => $this->categoryNames()[$snapshot->category_code] ?? $snapshot->category_code,
            // Kritikal diutamakan; permintaan "order" (tiada cawangan boleh menyumbang) bermula di peringkat Order.
            'fulfillment_status' => match (true) {
                ! empty($data['is_critical']) => BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL,
                ($data['type'] ?? 'request') === 'order' => BranchDemandRequestLine::FULFILLMENT_ORDER,
                default => BranchDemandRequestLine::FULFILLMENT_REQUESTED,
            },
        ]);

        $demand->notifyReviewers(1, $isFirstEver);

        return response()->json([
            'message' => "Permintaan {$demand->request_number} dihantar untuk {$storeCode}.",
            'request_number' => $demand->request_number,
            'line' => $this->requestLineData($line),
        ], 201);
    }

    /** Pengguna boleh bertindak (Request/Transfer) bagi cawangan ini? Bukan HQ/Security; pengguna ber-cawangan hanya utk cawangan sendiri. */
    private function canRequestFor(string $storeCode): bool
    {
        $user = Auth::user();

        if ($user === null || $storeCode === '' || $this->isNonBranchStore($storeCode) || ! $this->canView('design')) {
            return false;
        }

        $own = trim((string) $user->store_code);

        return $own === '' || $own === $storeCode;
    }

    /** Sama seperti canRequestFor() + kebenaran Shield `Create:StockTransfer` (super_admin dianggap ada). */
    private function canTransferFrom(string $storeCode): bool
    {
        $user = Auth::user();

        return $this->canRequestFor($storeCode) && ($user->isSuperAdmin() || $user->can('Create:StockTransfer'));
    }

    /**
     * Baris permintaan cawangan utk design ini yg masih diproses (belum Dah Delivery/Rearrange/Item Not Available & tidak ditolak).
     *
     * @return list<array{id: int, qty: int, size: ?string, weight: ?string, status: string, label: string, remark: ?string, created_at: string}>
     */
    private function activeRequests(string $code, string $storeCode): array
    {
        if ($storeCode === '') {
            return [];
        }

        return BranchDemandRequestLine::query()
            ->where('internal_code', $code)
            ->where('line_status', '!=', BranchDemandRequestLine::STATUS_REJECTED)
            ->whereNotIn('fulfillment_status', BranchDemandRequestLine::FULFILLMENT_TERMINAL)
            ->whereHas('request', fn ($query) => $query->whereRaw('TRIM(store_code) = ?', [$storeCode]))
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (BranchDemandRequestLine $line) => $this->requestLineData($line))
            ->values()
            ->all();
    }

    /**
     * Mencipta StockTransfer (notifikasi kepada cawangan asal, destinasi & manager dihantar oleh StockTransfer::notifyBranches) dlm dua arah:
     * `give` = cawangan Overstock memindahkan stoknya kpd cawangan yg kekurangan; `request` = cawangan yg kekurangan (Sold Out / Perlu Restock)
     * meminta daripada cawangan lain yg Stok Cukup / Overstock (min. MIN_SOURCE_STOCK boleh diambil). Kuantiti dihadkan kepada stok yg boleh
     * diambil di cawangan asal (stok tolak piece yg sedang dihantar keluar). Saiz/berat disimpan dlm nota (jadual stock_transfers tiada lajurnya).
     */
    public function storeTransfer(Request $request): JsonResponse
    {
        abort_unless($this->canView('design'), 403);

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:20'],
            'from_store' => ['required', 'string', 'max:20'],
            'to_store' => ['required', 'string', 'max:20', 'different:from_store'],
            'direction' => ['nullable', 'in:give,request'],
            'qty' => ['required', 'integer', 'min:1'],
            'size' => ['nullable', 'string', 'max:100'],
            'weight' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'to_store.different' => 'Cawangan penerima mesti berlainan daripada cawangan asal.',
            'qty.required' => 'Kuantiti perlu diisi.',
            'qty.integer' => 'Kuantiti mesti nombor bulat.',
            'qty.min' => 'Kuantiti mesti sekurang-kurangnya 1.',
            'size.max' => 'Saiz terlalu panjang (maksimum :max aksara).',
            'weight.max' => 'Berat terlalu panjang (maksimum :max aksara).',
            'notes.max' => 'Nota terlalu panjang (maksimum :max aksara).',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $code = trim($data['code']);
        $from = trim($data['from_store']);
        $to = trim($data['to_store']);

        $requesting = ($data['direction'] ?? 'give') === 'request';

        // give: pengguna bertindak bagi cawangan asal; request: bagi cawangan penerima (yg meminta). HQ/Security tidak terlibat.
        abort_unless($this->canTransferFrom($requesting ? $to : $from) && ! $this->isNonBranchStore($requesting ? $from : $to), 403);

        $snapshots = RestockSnapshot::query()->where('internal_code', $code)->whereIn('store_code', [$from, $to])->get()->keyBy('store_code');
        abort_if(! $snapshots->has($from) || ! $snapshots->has($to), 404);

        // Cawangan yg kehabisan stok (Sold Out) boleh juga meminta daripada cawangan Perlu Restock yg masih ada stok - tetapi cawangan itu
        // dibiarkan sekurang-kurangnya 1 piece (supaya tidak turut menjadi Sold Out).
        $requesterEmpty = $snapshots[$to]->verdict === RestockAnalysisCalculator::VERDICT_SOLD_OUT;
        $donorVerdicts = match (true) {
            ! $requesting => [RestockAnalysisCalculator::VERDICT_OVERSTOCK],
            $requesterEmpty => [RestockAnalysisCalculator::VERDICT_OK, RestockAnalysisCalculator::VERDICT_OVERSTOCK, RestockAnalysisCalculator::VERDICT_RESTOCK],
            default => [RestockAnalysisCalculator::VERDICT_OK, RestockAnalysisCalculator::VERDICT_OVERSTOCK],
        };

        if (! in_array($snapshots[$from]->verdict, $donorVerdicts, true)) {
            return response()->json(['message' => $requesting ? "{$from} tidak boleh menyumbang stok kepada {$to}." : "Hanya cawangan Overstock boleh memindahkan stok, dan {$from} bukan Overstock."], 422);
        }

        if (! in_array($snapshots[$to]->verdict, [RestockAnalysisCalculator::VERDICT_SOLD_OUT, RestockAnalysisCalculator::VERDICT_RESTOCK], true)) {
            return response()->json(['message' => "{$to} tidak memerlukan stok tambahan."], 422);
        }

        $available = $this->transferableStock($code, $from, $snapshots[$from]->stock);

        if ($requesting && $available < self::MIN_SOURCE_STOCK) {
            return response()->json(['message' => "{$from} tidak mempunyai stok lebih untuk disumbangkan."], 422);
        }

        $takeable = $available - ($snapshots[$from]->verdict === RestockAnalysisCalculator::VERDICT_RESTOCK ? 1 : 0);

        if ($data['qty'] > $takeable) {
            return response()->json(['message' => "Stok yang boleh dipindahkan dari {$from} hanya {$takeable} piece."], 422);
        }

        $fromStore = Store::whereRaw('TRIM(StoreCode) = ?', [$from])->first();
        $toStore = Store::whereRaw('TRIM(StoreCode) = ?', [$to])->first();
        abort_if($fromStore === null || $toStore === null, 404);

        $detail = collect([
            filled($data['size'] ?? null) ? 'Saiz '.trim($data['size']) : null,
            filled($data['weight'] ?? null) ? 'Berat '.trim($data['weight']).'g' : null,
        ])->filter()->implode(' | ');

        $notes = collect([$detail !== '' ? $detail : null, filled($data['notes'] ?? null) ? trim($data['notes']) : null])->filter()->implode(' - ');

        $transfer = StockTransfer::create([
            'internal_code' => $code,
            'item_desc' => $snapshots[$from]->description,
            'category_code' => $snapshots[$from]->category_code,
            'from_store' => $fromStore->StoreCode,
            'to_store' => $toStore->StoreCode,
            'qty' => $data['qty'],
            'requested_by' => Auth::user()->name,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        return response()->json([
            'message' => "Transfer {$transfer->transfer_number} dicipta: {$from} ke {$to} ({$data['qty']} piece).",
            'transfer_number' => $transfer->transfer_number,
        ], 201);
    }

    /** Stok yg boleh diambil dari sebuah cawangan: stok tolak piece yg sedang dihantar keluar (transit & loan transit). */
    private function transferableStock(string $code, string $store, int $stock): int
    {
        $outgoing = collect($this->designMovements($code)['movements'])
            ->where('from_store', $store)
            ->sum('qty');

        return max(0, $stock - (int) $outgoing);
    }

    private function isNonBranchStore(string $storeCode): bool
    {
        return in_array(strtoupper(trim($storeCode)), self::NON_BRANCH_STORES, true);
    }

    /** @return array{id: int, qty: int, size: ?string, weight: ?string, status: string, label: string, remark: ?string, created_at: string} */
    private function requestLineData(BranchDemandRequestLine $line): array
    {
        return [
            'id' => $line->id,
            'qty' => (int) $line->qty_requested,
            'size' => $line->size,
            'weight' => $line->weight,
            'status' => $line->fulfillment_status,
            'label' => $line->fulfillment_label,
            'remark' => $line->remark,
            'created_at' => $line->created_at?->toDateString() ?? '',
        ];
    }

    /**
     * Drill-down paparan Saiz/Berat: design dlm satu bucket (Kategori + Cawangan + bucket), terjual bulan ini tertinggi dahulu, dihantar
     * DESIGNS_LIMIT setiap kali (`offset` = bilangan yg sudah dimuat; butang "Muat Lagi"). Senarai penuh dikira SEKALI (lambat - memuat piece
     * setiap design) & di-cache mengikut snapshot, jadi halaman seterusnya serta-merta.
     */
    public function designs(Request $request): JsonResponse
    {
        $view = (string) $request->query('view');
        abort_unless(in_array($view, ['size', 'weight'], true) && $this->canView($view), 403);

        $category = trim((string) $request->query('category'));
        $store = trim((string) $request->query('store'));
        $bucket = trim((string) $request->query('bucket'));
        abort_if($category === '' || $store === '' || $bucket === '', 422);

        $key = 'restock_analysis:bucket_designs_v2:'.md5(json_encode([$view, $category, $store, $bucket, RestockSnapshot::query()->max('computed_at')]));

        /** @var list<array<string, mixed>> $all */
        $all = Cache::remember($key, now()->addHours(6), fn () => $this->bucketDesigns($view, $category, $store, $bucket));

        $offset = max(0, (int) $request->query('offset', 0));
        $page = array_slice($all, $offset, self::DESIGNS_LIMIT);

        // Gambar dicari utk halaman ini sahaja (bukan semua design dlm bucket) - satu pertanyaan kecil.
        $images = $this->imageUrls(collect(array_column($page, 'internal_code')));

        return response()->json([
            'total' => count($all),
            'offset' => $offset,
            'designs' => array_map(fn (array $row) => $row + ['image_url' => $images->get($row['internal_code'])], $page),
        ]);
    }

    /**
     * Design dlm satu bucket (Kategori + Cawangan + bucket saiz/berat), diagregat DI DATABASE (GROUP BY design) - bukan memuat setiap piece
     * kategori ke PHP spt RestockAnalysisCalculator::designsForSizeBucket() (utk bucket besar cth. Rantai Tangan, berpuluh ribu piece).
     * Disusun: terjual bulan ini tertinggi, kemudian jumlah terjual, kemudian kod.
     *
     * @return list<array{internal_code: string, description: ?string, vendor_name: ?string, current_stock: int, pieces_sold: int, sold_this_month: int}>
     */
    private function bucketDesigns(string $view, string $category, string $store, string $bucket): array
    {
        $isSize = $view === 'size';
        $bucketSql = $isSize ? 'JewelSize' : RestockAnalysisCalculator::weightBucketSqlCase();

        $query = InventoryPiece::query()
            ->realVendor()
            ->where('CategoryCode', $category)
            ->where('StoreCode', $store)
            ->selectRaw(
                "TRIM(InternalCode) as code, {$bucketSql} as raw_bucket, MAX(TRIM(Description)) as description, MAX(VendorCode) as vendor_code, ".
                'SUM(QtyOnHand) as stock, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as sold_month',
                [now()->startOfMonth()],
            )
            ->groupByRaw("TRIM(InternalCode), {$bucketSql}");

        if (! $isSize) {
            // Berat: bucket ditapis terus dlm SQL. Saiz: label dinormalkan dlm PHP (sizeLabel(): "16.0" = "16"), jadi ditapis di bawah.
            $query->whereRaw("{$bucketSql} = ?", [$bucket]);
        }

        $vendorNames = Vendor::query()->get()->mapWithKeys(fn ($v) => [trim((string) $v->VendorCode) => trim((string) $v->Description)]);

        return $query->toBase()->get()
            ->filter(fn ($row) => ! $isSize || RestockAnalysisCalculator::sizeLabel($row->raw_bucket) === $bucket)
            ->groupBy('code')
            ->map(function ($rows, $code) use ($vendorNames) {
                $first = $rows->first();
                $vendorCode = trim((string) $first->vendor_code);

                return [
                    'internal_code' => (string) $code,
                    'description' => $first->description,
                    'vendor_name' => $vendorNames[$vendorCode] ?? ($vendorCode !== '' ? $vendorCode : null),
                    'current_stock' => (int) $rows->sum('stock'),
                    'pieces_sold' => (int) $rows->sum('sold'),
                    'sold_this_month' => (int) $rows->sum('sold_month'),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['sold_this_month'], $b['pieces_sold'], $a['internal_code']] <=> [$a['sold_this_month'], $a['pieces_sold'], $b['internal_code']])
            ->values()
            ->all();
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
     * Stok semasa & jualan 3 bulan sebuah design ikut cawangan x saiz, dengan julat berat (gram) piece dlm kumpulan itu. Hanya kumpulan yg masih
     * ada stok atau ada jualan 3 bulan terkini (kumpulan lama tanpa kedua-duanya tak relevan utk keputusan restock). Saiz yg sama label
     * digabung (sizeLabel(): "16.0" = "16"); disusun ikut cawangan kemudian saiz menaik.
     *
     * `weight_buckets` = julat berat (label sama spt bucket Ikut Berat) yg dimiliki piece dlm kumpulan itu, utk membuka senarai design bucket berat.
     *
     * @return list<array{store_code: string, size: string, stock: int, sold_3m: int, weight_min: ?float, weight_max: ?float, weight_buckets: list<string>}>
     */
    private function designSizes(string $code): array
    {
        $weightSql = RestockAnalysisCalculator::weightBucketSqlCase();

        $rows = InventoryPiece::query()
            ->realVendor()
            ->where(fn ($q) => $q->where('InternalCode', $code)->orWhere('InternalCode', $code.' '))
            ->selectRaw(
                "TRIM(StoreCode) as store, JewelSize as raw_size, {$weightSql} as weight_bucket, SUM(QtyOnHand) as stock, MIN(GoldWeight) as weight_min, ".
                'MAX(GoldWeight) as weight_max, SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as sold_3m',
                [now()->subMonths(RestockAnalysisCalculator::TREND_MONTHS)],
            )
            ->groupByRaw("TRIM(StoreCode), JewelSize, {$weightSql}")
            ->toBase()
            ->get();

        $weightOrder = array_flip(RestockAnalysisCalculator::WEIGHT_LABELS);

        return $rows
            ->groupBy(fn ($row) => $row->store.'|'.RestockAnalysisCalculator::sizeLabel($row->raw_size))
            ->map(function ($group) use ($weightOrder) {
                $first = $group->first();
                $min = $group->min('weight_min');
                $max = $group->max('weight_max');

                return [
                    'store_code' => (string) $first->store,
                    'size' => RestockAnalysisCalculator::sizeLabel($first->raw_size),
                    'stock' => (int) $group->sum('stock'),
                    'sold_3m' => (int) $group->sum('sold_3m'),
                    'weight_min' => $min === null ? null : round((float) $min, 2),
                    'weight_max' => $max === null ? null : round((float) $max, 2),
                    'weight_buckets' => $group->pluck('weight_bucket')->unique()->sortBy(fn ($label) => $weightOrder[$label] ?? PHP_INT_MAX)->values()->all(),
                ];
            })
            ->filter(fn (array $row) => $row['stock'] > 0 || $row['sold_3m'] > 0)
            ->sortBy([
                ['store_code', 'asc'],
                fn (array $a, array $b) => (is_numeric($a['size']) ? (float) $a['size'] : INF) <=> (is_numeric($b['size']) ? (float) $b['size'] : INF),
            ])
            ->values()
            ->all();
    }

    /**
     * Piece sebuah design yg sedang BERGERAK antara cawangan, dan piece yg dikhaskan. MAKLUMAT TAMBAHAN sahaja - stok (QtyOnHand) dan Cadangan
     * tidak diubah: piece transit masih dikira sbg stok cawangan PENGHANTAR (StoreCode), spt dalam data JEMiSys.
     *
     * - `transit` (Status 5): dari StoreCode ke TransferToStore.
     * - `loan_transit` (Status 7 Loan Transit, 8 Loan Return Transit): dari StoreCode ke LoanToStore (TransferToStore jika LoanToStore kosong).
     * - `holds`: bilangan piece beserta stok yg ditanda Reserve / Booked / Loan, ikut cawangan.
     *
     * @return array{movements: list<array{type: string, from_store: string, to_store: ?string, size: string, qty: int, weight_min: ?float, weight_max: ?float}>, holds: array<string, array{reserved: int, booked: int, loaned: int}>}
     */
    private function designMovements(string $code): array
    {
        $base = InventoryPiece::query()
            ->realVendor()
            ->where(fn ($q) => $q->where('InternalCode', $code)->orWhere('InternalCode', $code.' '))
            ->where('QtyOnHand', '>', 0);

        $loanStatuses = [JemisysInventoryStatus::LoanTransit->value, JemisysInventoryStatus::LoanReturnTransit->value];
        $destination = "COALESCE(NULLIF(TRIM(TransferToStore), ''), NULLIF(TRIM(LoanToStore), ''))";
        $destinationLoan = "COALESCE(NULLIF(TRIM(LoanToStore), ''), NULLIF(TRIM(TransferToStore), ''))";

        $movements = (clone $base)
            ->whereIn('Status', [JemisysInventoryStatus::Transit->value, ...$loanStatuses])
            ->selectRaw(
                "TRIM(StoreCode) as from_store, TRIM(Status) as status, CASE WHEN TRIM(Status) = '5' THEN {$destination} ELSE {$destinationLoan} END as to_store, ".
                'JewelSize as raw_size, SUM(QtyOnHand) as qty, MIN(GoldWeight) as weight_min, MAX(GoldWeight) as weight_max',
            )
            ->groupByRaw("TRIM(StoreCode), TRIM(Status), CASE WHEN TRIM(Status) = '5' THEN {$destination} ELSE {$destinationLoan} END, JewelSize")
            ->toBase()
            ->get()
            ->groupBy(fn ($row) => $row->status.'|'.$row->from_store.'|'.$row->to_store.'|'.RestockAnalysisCalculator::sizeLabel($row->raw_size))
            ->map(function ($group) use ($loanStatuses) {
                $first = $group->first();
                $min = $group->min('weight_min');
                $max = $group->max('weight_max');

                return [
                    'type' => in_array($first->status, $loanStatuses, true) ? 'loan_transit' : 'transit',
                    'from_store' => (string) $first->from_store,
                    'to_store' => $first->to_store === null ? null : (string) $first->to_store,
                    'size' => RestockAnalysisCalculator::sizeLabel($first->raw_size),
                    'qty' => (int) $group->sum('qty'),
                    'weight_min' => $min === null ? null : round((float) $min, 2),
                    'weight_max' => $max === null ? null : round((float) $max, 2),
                ];
            })
            ->sortBy([['type', 'asc'], ['from_store', 'asc'], ['to_store', 'asc'], fn (array $a, array $b) => (is_numeric($a['size']) ? (float) $a['size'] : INF) <=> (is_numeric($b['size']) ? (float) $b['size'] : INF)])
            ->values()
            ->all();

        $holds = (clone $base)
            ->selectRaw(
                'TRIM(StoreCode) as store, SUM(CASE WHEN Reserve <> 0 THEN 1 ELSE 0 END) as reserved, '.
                'SUM(CASE WHEN Booked <> 0 THEN 1 ELSE 0 END) as booked, SUM(CASE WHEN Loan <> 0 THEN 1 ELSE 0 END) as loaned',
            )
            ->groupByRaw('TRIM(StoreCode)')
            ->toBase()
            ->get()
            ->filter(fn ($row) => $row->reserved > 0 || $row->booked > 0 || $row->loaned > 0)
            ->mapWithKeys(fn ($row) => [(string) $row->store => ['reserved' => (int) $row->reserved, 'booked' => (int) $row->booked, 'loaned' => (int) $row->loaned]])
            ->all();

        return ['movements' => $movements, 'holds' => $holds];
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
