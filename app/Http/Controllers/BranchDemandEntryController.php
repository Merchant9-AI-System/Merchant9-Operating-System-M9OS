<?php

namespace App\Http\Controllers;

use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\User;
use App\Support\MerchantWebsiteSearch;
use App\Support\ProductImageFetcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permukaan Inertia+Vue+shadcn-vue BERASINGAN drpd Filament utk kemasukan Branch Demand -
 * satu skrin ringkas khusus staf cawangan (matlamat "bawah 3 minit"), TIADA LOGIN - staf
 * pilih cawangan terus dlm borang (bukan drpd Auth::user()->store_code), model/notifikasi
 * yg SAMA dgn App\Filament\Resources\BranchDemandRequests (rujuk
 * BranchDemandRequest::notifyReviewers() yg dikongsi dua2 permukaan). HQ review & Module D
 * (cadangan allocation) KEKAL di Filament - halaman ni cuma gantikan borang submission staf
 * cawangan sahaja.
 *
 * NOTA PENTING: `Illuminate\Foundation\Http\Middleware\TrimStrings` (middleware global 'web')
 * trim SEMUA input string, jadi nilai StoreCode yg dihantar client SENTIASA tiba tertrim
 * walaupun jemisys_store_mirror.StoreCode sendiri CHAR bertepi lebar tetap (cth.
 * "DAMAI               "). Jadi kita hantar kod TERTRIM ke frontend (bukan cuba kekalkan
 * padding - ia tetap akan hilang bila round-trip balik ke server), dan SENTIASA guna
 * resolveStore() (padanan TRIM()) utk cari balik rekod Store sebenar drpd input tertrim,
 * bukan exact-match terus pd StoreCode.
 */
class BranchDemandEntryController extends Controller
{
    /** Tempoh sah utk cadangan restock - kunci dihantar drpd dropdown RestockSuggestions.vue. */
    protected const RESTOCK_PERIODS = ['1w', '1m', '3m', '6m', '1y'];

    /** Kategori "bar" - dikecualikan drpd cadangan restock bila berat >100g (rujuk restockSuggestions()). */
    protected const BAR_CATEGORY_CODES = ['BAR', 'GB', 'SILBAR'];

    /**
     * Jenis emas/perak (ClassCode) - padanan via LIKE '%kunci%' sbb ClassCode data JEMiSys
     * bercelaru (cth. "916", "916B", "916Ptg", "EMAS 916" - semua kunci "916"). Ditapis KONGSI
     * (rujuk applyProductFilters()) - digunakan oleh carian umum (search()) DAN cadangan
     * restock (restockSuggestions()), rujuk Create.vue - satu keadaan tapisan sahaja utk kedua2.
     */
    protected const GOLD_TYPES = ['999', '916', '750', '585', '375', '925'];

    /** Julat berat (gram) utk checkbox filter - [min, max] inklusif kedua-dua hujung. */
    protected const WEIGHT_RANGES = [
        'w_0_5' => [0, 5],
        'w_5_10' => [5, 10],
        'w_10_20' => [10, 20],
        'w_20_50' => [20, 50],
        'w_50_100' => [50, 100],
        'w_100_plus' => [100, 999999],
    ];

    /** Julat saiz (JewelSize, ditafsir sbg nombor) utk checkbox filter - [min, max] inklusif. */
    protected const SIZE_RANGES = [
        's_0_10' => [0, 10],
        's_10_15' => [10, 15],
        's_15_20' => [15, 20],
        's_20_plus' => [20, 999999],
    ];

    public function create(Request $request): Response
    {
        $initialStoreCode = null;

        if ($code = $request->query('store_code')) {
            $initialStoreCode = trim((string) Store::whereRaw('TRIM(StoreCode) = ?', [trim($code)])->value('StoreCode') ?? '') ?: null;
        }

        return Inertia::render('BranchDemand/Create', [
            'stores' => $this->storesForSelect(),
            'categories' => $this->categoriesForSelect(),
            'initialStoreCode' => $initialStoreCode,
        ]);
    }

    public function requests(Request $request): Response
    {
        $data = $request->validate([
            'store_code' => ['nullable', 'string'],
        ]);

        $store = filled($data['store_code'] ?? null) ? $this->resolveStore($data['store_code']) : null;

        $requests = collect();

        if ($store) {
            $requests = BranchDemandRequest::where('store_code', $store->StoreCode)
                ->with('lines')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (BranchDemandRequest $r) => [
                    'id' => $r->id,
                    'request_number' => $r->request_number,
                    'status' => $r->status,
                    'submitted_by' => $r->submitted_by_label,
                    'submitted_at' => $r->submitted_at?->format('d/m/Y H:i'),
                    'notes' => $r->notes,
                    'lines' => $r->lines->map(fn ($l) => [
                        'internal_code' => $l->internal_code,
                        'item_desc' => $l->item_desc,
                        'source_type' => $l->source_type,
                        'image_url' => $l->image_url,
                        'qty_requested' => $l->qty_requested,
                        'remark' => $l->remark,
                        'size' => $l->size,
                        'weight' => $l->weight !== null ? (float) $l->weight : null,
                        'category_name' => $l->category_name,
                        'qty_approved' => $l->qty_approved,
                        'line_status' => $l->line_status,
                        'fulfillment_status' => $l->fulfillment_status,
                        'fulfillment_label' => $l->fulfillment_label,
                    ]),
                ])
                ->values();
        }

        return Inertia::render('BranchDemand/RequestList', [
            'stores' => $this->storesForSelect(),
            'storeCode' => $store ? trim($store->StoreCode) : null,
            'requests' => $requests,
        ]);
    }

    /** Senarai cawangan utk Select (HQ/SECURITY dikecualikan - bukan cawangan runcit sebenar). */
    protected function storesForSelect()
    {
        // reject() lepas get() (bukan whereNotIn('StoreCode', ...)) - StoreCode CHAR
        // berpadding, whereNotIn takkan padan 'HQ'/'SECURITY' bersih dgn nilai DB berpadding
        // (isu sama yg dibincang di resolveStore() bawah).
        return Store::orderBy('StoreCode')->get()
            ->reject(fn ($s) => in_array(trim($s->StoreCode), ['HQ', 'SECURITY'], true))
            ->map(fn ($s) => [
                'code' => trim($s->StoreCode),
                'label' => trim($s->StoreCode),
            ])
            ->values();
    }

    /**
     * Kategori utk checkbox tapisan - HANYA kategori yg ADA inventori sebenar (bukan SEMUA 88
     * baris Category, kebanyakannya kod pentadbiran/kewangan tanpa stok fizikal langsung -
     * cth. "Admin Fee", "Credit Note", "Insurance" - disahkan terus x wujud dlm InventoryPiece).
     * Disenaraikan SEKALI di create() (bukan per-carian), jarang berubah.
     */
    protected function categoriesForSelect()
    {
        $usedCodes = InventoryPiece::query()
            ->distinct()
            ->pluck('CategoryCode')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique();

        return Category::whereIn('CategoryCode', $usedCodes)
            ->orderBy('Description')
            ->get(['CategoryCode', 'Description'])
            ->map(fn ($c) => [
                'value' => trim($c->CategoryCode),
                'label' => $c->Description,
            ])
            ->values();
    }

    /**
     * Tapisan jenis emas/berat/saiz/kategori KONGSI antara search() & restockSuggestions()
     * (rujuk Create.vue - satu keadaan checkbox dikongsi utk carian umum DAN cadangan restock).
     * Diguna pakai atas query InventoryPiece SEDIA ADA (mutasi terus, bukan return baharu).
     */
    protected function applyProductFilters(Builder $query, array $data): void
    {
        if (! empty($data['category_codes'])) {
            // TRIM() wajib - CategoryCode CHAR(20) berpadding (rujuk nota padding berulang
            // di fail ni) - whereIn dgn kod tertrim TAK PERNAH padan tanpa TRIM lajur DB.
            $query->whereRaw(
                'TRIM(CategoryCode) IN ('.implode(',', array_fill(0, count($data['category_codes']), '?')).')',
                $data['category_codes']
            );
        }

        if (! empty($data['gold_types'])) {
            $query->where(function ($q) use ($data) {
                foreach ($data['gold_types'] as $type) {
                    $q->orWhere('ClassCode', 'like', "%{$type}%");
                }
            });
        }

        if (! empty($data['weight_ranges'])) {
            $query->where(function ($q) use ($data) {
                foreach ($data['weight_ranges'] as $key) {
                    [$min, $max] = self::WEIGHT_RANGES[$key];
                    $q->orWhereBetween('GoldWeight', [$min, $max]);
                }
            });
        }

        if (! empty($data['size_ranges'])) {
            // JewelSize VARCHAR bercelaru (cth. "10.5T", "100PC") DAN CHAR bertepi (cth. "10 " -
            // ada ruang kosong ekor, isu padding sama yg berulang dgn StoreCode/CategoryCode) -
            // TRIM() dulu sblm REGEXP (tanpanya, penambat "$" REGEXP x pernah padan sbb rentetan
            // x tamat sejurus lepas nombor) DAN sblm CAST (elak amaran/salah padan julat).
            $query->whereRaw("TRIM(JewelSize) REGEXP '^[0-9]+(\\\\.[0-9]+)?$'")
                ->where(function ($q) use ($data) {
                    foreach ($data['size_ranges'] as $key) {
                        [$min, $max] = self::SIZE_RANGES[$key];
                        $q->orWhereRaw('CAST(TRIM(JewelSize) AS DECIMAL(10,2)) BETWEEN ? AND ?', [$min, $max]);
                    }
                });
        }
    }

    /** @return array<string, array> peraturan validasi tapisan kongsi - digunakan search() & restockSuggestions(). */
    protected function productFilterRules(): array
    {
        return [
            'gold_types' => ['nullable', 'array'],
            'gold_types.*' => ['string', 'in:'.implode(',', self::GOLD_TYPES)],
            'weight_ranges' => ['nullable', 'array'],
            'weight_ranges.*' => ['string', 'in:'.implode(',', array_keys(self::WEIGHT_RANGES))],
            'size_ranges' => ['nullable', 'array'],
            'size_ranges.*' => ['string', 'in:'.implode(',', array_keys(self::SIZE_RANGES))],
            // TIADA senarai "in:" statik (tak spt gold_types/weight_ranges/size_ranges) - kod
            // kategori DINAMIK drpd DB (rujuk categoriesForSelect()), diguna via whereRaw
            // berparameter (?) di applyProductFilters() - selamat drpd SQL injection walaupun
            // tiada whitelist di sini.
            'category_codes' => ['nullable', 'array'],
            'category_codes.*' => ['string', 'max:20'],
        ];
    }

    /**
     * Carian kod design/keterangan/kategori - DIPECAHKAN kpd beberapa query KECIL berasingan
     * (bukan SATU query OR merentasi 4 lajur berbeza) sbb OR merentasi lajur x sepadan
     * (InternalCode/InventoryCode/Description/CategoryCode) memaksa MySQL buat FULL INDEX SCAN
     * jemisys_inventory_mirror (488k+ baris) - disahkan via EXPLAIN (type=index, scan seluruh
     * index InternalCode utk setiap carian). Setiap query kecil di bawah plih SATU index
     * (InternalCode/InventoryCode/CategoryCode range/ref scan pantas, Description via FULLTEXT -
     * rujuk migration add_search_indexes_to_jemisys_inventory_mirror_table), hasil DIGABUNG
     * (dedupe by InternalCode) dlm PHP - jumlah kos jauh lebih rendah drpd satu scan besar.
     *
     * Imej DIBAWA BALIK dlm dropdown (per staf punya keperluan visual) - had keputusan dikecilkan
     * drpd 20 ke 8 utk kekalkan kos ProductImageFetcher (~800ms/kod bila cache sejuk) terkawal.
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2'],
            'store_code' => ['required', 'string'],
            ...$this->productFilterRules(),
        ]);

        $store = $this->resolveStore($data['store_code']);
        $search = trim($data['q']);
        $noDash = str_replace('-', '', $search);
        $limit = 8;

        $codePrefixes = array_unique(array_filter([$search, $noDash]));

        // 1a) InternalCode awalan - SENDIRI (BUKAN OR dgn InventoryCode dlm query sama). Disahkan
        // via EXPLAIN+timing sebenar: OR merentasi InternalCode+InventoryCode dlm SATU where()
        // gagalkan pemilihan index utk KEDUA-DUA (MySQL pilih type=ALL, full scan 456k+ baris,
        // walaupun kedua2 lajur ada index sendiri) - query berasingan plih index betul (type=range).
        $internalCodeMatches = InventoryPiece::query()
            ->where(function ($q) use ($codePrefixes) {
                foreach ($codePrefixes as $prefix) {
                    $q->orWhere('InternalCode', 'like', "{$prefix}%");
                }
            })
            ->tap(fn ($q) => $this->applyProductFilters($q, $data))
            ->distinct()
            ->limit($limit)
            ->pluck('InternalCode');

        // 1b) InventoryCode awalan - HANYA utk carian >=5 aksara. Diukur sebenar: carian pendek
        // (2-3 aksara, cth. "CE"/"RT0") pd InventoryCode makan 300ms-1.5s WALAUPUN ada index -
        // sbb InventoryCode (kod SEKETUL fizikal, satu per piece) x berkumpul ikut awalan macam
        // InternalCode (kod DESIGN, ramai piece kongsi 1 kod), jadi awalan pendek amat tak
        // selektif (boleh padan ratusan ribu baris). Pd >=5 aksara turun ke ~50ms, selamat.
        // Realiti guna: staf cari InventoryCode bila baca kod PENUH drpd tag fizikal (cth.
        // "RT0026132"), bukan cuba teka drpd 2-3 aksara pertama - jadi had ni tak jejaskan
        // kegunaan sebenar, cuma elak kos scan mahal utk carian pendek yg jarang berlaku.
        $inventoryCodeMatches = collect();
        if (mb_strlen($search) >= 5) {
            $inventoryCodeMatches = InventoryPiece::query()
                ->where(function ($q) use ($codePrefixes) {
                    foreach ($codePrefixes as $prefix) {
                        if (mb_strlen($prefix) >= 5) {
                            $q->orWhere('InventoryCode', 'like', "{$prefix}%");
                        }
                    }
                })
                ->tap(fn ($q) => $this->applyProductFilters($q, $data))
                ->distinct()
                ->limit($limit)
                ->pluck('InternalCode');
        }

        $codeMatches = $internalCodeMatches->merge($inventoryCodeMatches);

        // Had "batch mentah" utk 2) & 3) bawah - kategori/keterangan yg amat luas (cth. "cincin"
        // padan hampir semua design cincin) buatkan ->distinct()->limit(8) TERPAKSA materialize
        // SEMUA baris padan dulu (Extra: "Using temporary") sblm limit - diukur sebenar: ~500ms
        // utk carian luas, drpd ~25ms tanpa distinct. TAPI limit() mentah SAHAJA (tanpa distinct)
        // pun x cukup - baris fizikal cenderung berkumpul ikut InternalCode yg SAMA (banyak piece
        // share 1 design), jadi LIMIT 20/200/1000 mentah selalunya cuma bagi 2-5 InternalCode unik
        // sahaja (diuji sebenar). 2000 baris mentah (~70ms) beri hasil unik yg cukup sambil kekal
        // jauh lebih pantas drpd distinct-forced-materialize - dedupe/limit sebenar dibuat dlm PHP.
        $rawBatch = 2000;

        // 2) Kategori - nama kategori mengandungi carian (jadual Category KECIL, <100 baris,
        // pluck terus tak berat), padan design via index CategoryCode (ref scan).
        $matchingCategoryCodes = Category::where('Description', 'like', "%{$search}%")->pluck('CategoryCode');
        $categoryMatches = $matchingCategoryCodes->isEmpty()
            ? collect()
            : InventoryPiece::whereIn('CategoryCode', $matchingCategoryCodes)
                ->tap(fn ($q) => $this->applyProductFilters($q, $data))
                ->limit($rawBatch)->pluck('InternalCode')->unique();

        // 3) Keterangan produk - FULLTEXT (bukan LIKE '%x%' yg mesti full table scan tanpa index).
        // innodb_ft_min_token_size lalai = 3 - carian < 3 aksara TAK PADAN langsung via FULLTEXT,
        // jadi skip terus utk carian 2 aksara (elak query yg confirm kosong tapi tetap kos scan).
        $descriptionMatches = collect();
        if (mb_strlen($search) >= 3) {
            $descriptionMatches = InventoryPiece::whereRaw(
                'MATCH(Description) AGAINST (? IN BOOLEAN MODE)',
                [$search.'*']
            )
                ->tap(fn ($q) => $this->applyProductFilters($q, $data))
                ->limit($rawBatch)->pluck('InternalCode')->unique();
        }

        $internalCodes = $codeMatches->merge($categoryMatches)->merge($descriptionMatches)
            ->unique()
            ->take($limit)
            ->values();

        if ($internalCodes->isEmpty()) {
            return response()->json([]);
        }

        // Satu query metadata (Description/CategoryCode) + SATU query stok (bukan N query
        // berasingan setiap baris) utk keseluruhan set kod yg dijumpai - IN() pd InternalCode
        // (indexed) utk kedua-duanya.
        $meta = InventoryPiece::whereIn('InternalCode', $internalCodes)
            ->selectRaw('InternalCode, MAX(Description) as Description, MAX(CategoryCode) as CategoryCode, MAX(JewelSize) as JewelSize, MAX(GoldWeight) as GoldWeight')
            ->groupBy('InternalCode')
            ->get()
            ->keyBy(fn ($row) => trim($row->InternalCode));

        $stockByCode = InventoryPiece::whereIn('InternalCode', $internalCodes)
            ->where('StoreCode', $store->StoreCode)
            ->onHand()
            ->selectRaw('InternalCode, COUNT(*) as stock')
            ->groupBy('InternalCode')
            ->pluck('stock', 'InternalCode')
            ->mapWithKeys(fn ($stock, $code) => [trim($code) => (int) $stock]);

        $categoryNames = Category::pluck('Description', 'CategoryCode');

        return response()->json($internalCodes->map(function ($code) use ($meta, $stockByCode, $categoryNames) {
            $trimmedCode = trim($code);
            $row = $meta->get($trimmedCode);

            return [
                'internal_code' => $trimmedCode,
                'description' => $row?->Description ?? '',
                'category_name' => $categoryNames[$row?->CategoryCode ?? ''] ?? '',
                'current_stock' => $stockByCode[$trimmedCode] ?? 0,
                'size' => filled($row?->JewelSize) ? trim((string) $row->JewelSize) : null,
                'weight' => $row?->GoldWeight !== null ? (float) $row->GoldWeight : null,
                'image_url' => ProductImageFetcher::firstImageUrlFor($trimmedCode),
            ];
        })->values());
    }

    /** Imej SATU design - guna bebas drpd search() (cth. paparan lain yg cuma ada kod, tiada hasil carian). */
    public function productImage(Request $request)
    {
        $data = $request->validate([
            'internal_code' => ['required', 'string'],
        ]);

        return response()->json([
            'image_url' => ProductImageFetcher::firstImageUrlFor($data['internal_code']),
        ]);
    }

    /**
     * Carian FALLBACK di laman web merchant9.com - utk nickname/gaya tak formal yg staf biasa
     * guna (cth. "COCO PASIR") yg TIADA langsung dlm search() dalaman (rujuk MerchantWebsiteSearch
     * punya dokblok penuh utk sebab). Hasil TIADA InternalCode - staf pilih drpd sini akan
     * ditambah sbg line 'source_type'=web (rujuk store()), HQ padankan ke stok sebenar semasa
     * semakan.
     */
    public function searchWebsite(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2'],
        ]);

        return response()->json(MerchantWebsiteSearch::search($data['q']));
    }

    /**
     * Muat naik gambar rujukan SATU item (staf cawangan tak jumpa item dlm katalog/laman web,
     * pilih ambil/muat naik gambar sendiri sbg rujukan). Disimpan di disk 'public', URL terus
     * ditetapkan sbg BranchDemandRequestLine::image_url (medan SAMA dgn imej katalog/laman web,
     * rujuk store()) - TIADA lajur berasingan, gambar staf muat naik menggantikan rujukan visual
     * line tsb terus.
     */
    public function uploadImage(Request $request)
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $path = $data['image']->store('branch-demand-uploads', 'public');

        return response()->json([
            'image_url' => Storage::disk('public')->url($path),
        ]);
    }

    /**
     * Design paling banyak terjual DI CAWANGAN terpilih dlm tempoh PILIHAN staf sendiri
     * (dropdown 1 minggu/1 bulan/3 bulan/6 bulan/1 tahun), ditapis lanjut ikut jenis emas/perak,
     * julat berat, julat saiz - keadaan checkbox tapisan DIKONGSI dgn search() (rujuk
     * applyProductFilters(), Create.vue punya satu keadaan tapisan utk kedua2 permukaan).
     *
     * Peraturan wajib (SENTIASA aktif, bukan checkbox pilihan):
     * - Kecualikan bar emas/perak (BAR/GB/SILBAR) yg berat >100g - bar besar ni transaksi khas,
     *   bukan utk permintaan stok rutin cawangan via borang ringkas ni.
     * - Setiap item WAJIB ada saiz (JewelSize) & berat (GoldWeight) yg terisi DAN imej produk -
     *   design yg tiada mana2 drpd tiga ni tak berguna dipaparkan (staf x dpt nilai visual/fizikal
     *   sblm minta stok).
     *
     * Imej TAK BOLEH disemak terus dlm SQL (scrape luaran merchant9.com via ProductImageFetcher,
     * rujuk nota performance kelas tsb) - ambil BATCH mentah lebih drpd limit (90 drpd 30 sasaran),
     * lepas tu iterate SATU-SATU (guna cache 1-hari sedia ada) sehingga cukup 30 ATAU batch habis -
     * ELAK semak kesemua 90 tanpa henti awal (boleh sampai puluhan saat kalau semua cache sejuk).
     */
    public function restockSuggestions(Request $request)
    {
        $data = $request->validate([
            'store_code' => ['required', 'string'],
            'period' => ['nullable', 'string', 'in:'.implode(',', self::RESTOCK_PERIODS)],
            ...$this->productFilterRules(),
        ]);

        $store = $this->resolveStore($data['store_code']);

        $since = match ($data['period'] ?? '3m') {
            '1w' => now()->subWeek(),
            '1m' => now()->subMonth(),
            '6m' => now()->subMonths(6),
            '1y' => now()->subYear(),
            default => now()->subMonths(3),
        };

        $query = InventoryPiece::query()
            ->realVendor()
            ->where('StoreCode', $store->StoreCode)
            ->whereNotNull('SalesDate')
            ->where('SalesDate', '>=', $since)
            ->whereNotNull('JewelSize')->where('JewelSize', '!=', '')
            ->whereNotNull('GoldWeight')->where('GoldWeight', '>', 0)
            ->where(function ($q) {
                // TRIM() wajib - CategoryCode CHAR(20) berpadding (cth. "BAR                 "),
                // whereNotIn dgn literal tak berpadding TAK PERNAH padan tanpa TRIM (isu padding
                // sama yg berulang - StoreCode/JewelSize/dll - rujuk nota lain dlm fail ni).
                $q->whereRaw('TRIM(CategoryCode) NOT IN (?, ?, ?)', self::BAR_CATEGORY_CODES)
                    ->orWhere('GoldWeight', '<=', 100);
            });

        $this->applyProductFilters($query, $data);

        $limit = 30;
        $rawCandidates = $query
            ->selectRaw('InternalCode, MAX(Description) as Description, MAX(CategoryCode) as CategoryCode, MAX(JewelSize) as JewelSize, MAX(GoldWeight) as GoldWeight, COUNT(*) as qty_sold')
            ->groupBy('InternalCode')
            ->orderByDesc('qty_sold')
            ->limit($limit * 3)
            ->get();

        $categoryNames = Category::pluck('Description', 'CategoryCode');
        $results = collect();

        foreach ($rawCandidates as $row) {
            if ($results->count() >= $limit) {
                break;
            }

            $imageUrl = ProductImageFetcher::firstImageUrlFor($row->InternalCode);

            if (! $imageUrl) {
                continue;
            }

            $stock = InventoryPiece::where('InternalCode', $row->InternalCode)
                ->where('StoreCode', $store->StoreCode)
                ->onHand()
                ->count();

            $results->push([
                'internal_code' => trim($row->InternalCode),
                'description' => $row->Description,
                'category_name' => $categoryNames[$row->CategoryCode ?? ''] ?? '',
                'current_stock' => $stock,
                'qty_sold' => (int) $row->qty_sold,
                'size' => trim((string) $row->JewelSize),
                'weight' => (float) $row->GoldWeight,
                'image_url' => $imageUrl,
            ]);
        }

        return response()->json($results->values());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_code' => ['required', 'string'],
            'submitted_by_name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            // 'catalog' (lalai) perlukan internal_code SEBENAR; 'web' (cadangan carian laman web
            // merchant9.com) & 'upload' (staf langkau carian/dua2 carian TIADA hasil, muat naik
            // gambar sendiri - rujuk SOURCE_UPLOAD) TIADA kod boleh dipercayai - dibenarkan
            // kosong, HQ padankan ke stok sebenar semasa semakan. 'upload' WAJIB ada keterangan
            // & gambar sendiri (rujuk uploadImage()) - tiada apa2 lain utk HQ rujuk.
            'lines.*.source_type' => ['nullable', 'string', 'in:'.implode(',', [
                BranchDemandRequestLine::SOURCE_CATALOG,
                BranchDemandRequestLine::SOURCE_WEB,
                BranchDemandRequestLine::SOURCE_UPLOAD,
            ])],
            'lines.*.internal_code' => ['required_if:lines.*.source_type,'.BranchDemandRequestLine::SOURCE_CATALOG, 'nullable', 'string'],
            'lines.*.item_desc' => ['required_if:lines.*.source_type,'.BranchDemandRequestLine::SOURCE_UPLOAD, 'nullable', 'string'],
            'lines.*.image_url' => ['required_if:lines.*.source_type,'.BranchDemandRequestLine::SOURCE_UPLOAD, 'nullable', 'string', 'max:500'],
            'lines.*.qty_requested' => ['required', 'integer', 'min:1'],
            'lines.*.remark' => ['nullable', 'string', 'max:255'],
            'lines.*.size' => ['nullable', 'string', 'max:20'],
            'lines.*.weight' => ['nullable', 'numeric', 'min:0'],
            'lines.*.category_name' => ['nullable', 'string', 'max:255'],
            // Toggle "Kritikal" staf cawangan - TIADA lajur berasingan, cuma menentukan
            // fulfillment_status AWAL line (rujuk BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL).
            'lines.*.is_critical' => ['nullable', 'boolean'],
        ]);

        $store = $this->resolveStore($data['store_code']);

        $branchDemandRequest = BranchDemandRequest::create([
            'store_code' => $store->StoreCode,
            'submitted_by_name' => $data['submitted_by_name'],
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['lines'] as $line) {
            $branchDemandRequest->lines()->create([
                'internal_code' => $line['internal_code'] ?? null,
                'source_type' => $line['source_type'] ?? BranchDemandRequestLine::SOURCE_CATALOG,
                'image_url' => $line['image_url'] ?? null,
                'item_desc' => $line['item_desc'] ?? null,
                'qty_requested' => $line['qty_requested'],
                'remark' => $line['remark'] ?? null,
                'size' => $line['size'] ?? null,
                'weight' => $line['weight'] ?? null,
                'category_name' => $line['category_name'] ?? null,
                'fulfillment_status' => ! empty($line['is_critical'])
                    ? BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL
                    : BranchDemandRequestLine::FULFILLMENT_REQUESTED,
            ]);
        }

        $branchDemandRequest->notifyReviewers();

        // Selepas hantar, bawa terus ke senarai permintaan cawangan tsb supaya boleh nampak
        // status permintaan baharu tu terus (bukan balik ke borang kosong).
        $user = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first();

        Notification::make()
            ->title("Permintaan cawangan baharu: {$branchDemandRequest->request_number}")
            ->body("Permintaan cawangan baru {$branchDemandRequest->request_number} telah dihantar.")
            ->icon('heroicon-s-bell')
            ->actions([
                Action::make('gotoPage')
                    ->label('Semak')
                    ->url(route('filament.admin.resources.branch-demand-requests.view', ['record' => $branchDemandRequest->getKey()]))
                    ->button(),
            ])
            ->sendToDatabase($user);

        return redirect()->route('branch-demand.requests', ['store_code' => trim($store->StoreCode)])
            ->with('success', "Permintaan {$branchDemandRequest->request_number} dihantar - {$branchDemandRequest->lines()->count()} item.");
    }

    /** Cari rekod Store sebenar (StoreCode berpadding) drpd kod tertrim yg tiba drpd client. */
    protected function resolveStore(string $trimmedCode): Store
    {
        $store = Store::whereRaw('TRIM(StoreCode) = ?', [$trimmedCode])->first();

        if (! $store) {
            throw ValidationException::withMessages(['store_code' => 'Cawangan tidak sah.']);
        }

        return $store;
    }
}
