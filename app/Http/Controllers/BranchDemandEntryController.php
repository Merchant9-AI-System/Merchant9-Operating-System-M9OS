<?php

namespace App\Http\Controllers;

use App\Models\BranchDemandRequest;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Support\ProductImageFetcher;
use Illuminate\Http\Request;
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
    public function create(Request $request): Response
    {
        $initialStoreCode = null;

        if ($code = $request->query('store_code')) {
            $initialStoreCode = trim((string) Store::whereRaw('TRIM(StoreCode) = ?', [trim($code)])->value('StoreCode') ?? '') ?: null;
        }

        return Inertia::render('BranchDemand/Create', [
            'stores' => $this->storesForSelect(),
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
                        'qty_requested' => $l->qty_requested,
                        'qty_approved' => $l->qty_approved,
                        'line_status' => $l->line_status,
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

    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2'],
            'store_code' => ['required', 'string'],
        ]);

        $store = $this->resolveStore($data['store_code']);
        $search = trim($data['q']);

        // Padan design code (InternalCode) ATAU kod fizikal seketul (InventoryCode - cth. tag/label
        // pd barang sebenar, "RT0026132" - BEZA drpd InternalCode "RTGPXX00K10G") ATAU keterangan
        // produk ATAU nama kategori - staf mungkin baca terus drpd tag fizikal, bukan semestinya
        // tahu InternalCode design. groupBy('InternalCode') di bawah kekal betul walaupun padan
        // via InventoryCode - hasil tetap di paras DESIGN (bukan seketul), sbb permintaan stok
        // memang pada paras design, bukan seketul fizikal tertentu.
        //
        // Turut padan versi TANPA sengkang ("RT-026132" -> "RT026132") - staf kadang taip sengkang
        // sbg pemisah visual yg TIADA dlm data sebenar - SEBAGAI TAMBAHAN (bukan ganti) carian asal,
        // sbb 7000+ InventoryCode sedia ada MEMANG ada sengkang sebenar dlm datanya.
        $noDash = str_replace('-', '', $search);
        $matchingCategoryCodes = Category::where('Description', 'like', "%{$search}%")->pluck('CategoryCode');

        $results = InventoryPiece::query()
            ->where(function ($q) use ($search, $noDash, $matchingCategoryCodes) {
                $q->where('InternalCode', 'like', "{$search}%")
                    ->orWhere('InventoryCode', 'like', "{$search}%")
                    ->orWhere('Description', 'like', "%{$search}%")
                    ->orWhereIn('CategoryCode', $matchingCategoryCodes);

                if ($noDash !== $search) {
                    $q->orWhere('InternalCode', 'like', "{$noDash}%")
                        ->orWhere('InventoryCode', 'like', "{$noDash}%");
                }
            })
            ->selectRaw('InternalCode, MAX(Description) as Description, MAX(CategoryCode) as CategoryCode')
            ->groupBy('InternalCode')
            ->limit(20)
            ->get();

        $categoryNames = Category::pluck('Description', 'CategoryCode');

        return response()->json($results->map(function ($row) use ($store, $categoryNames) {
            $stock = InventoryPiece::where('InternalCode', $row->InternalCode)
                ->where('StoreCode', $store->StoreCode)
                ->onHand()
                ->count();

            return [
                'internal_code' => trim($row->InternalCode),
                'description' => $row->Description,
                'category_name' => $categoryNames[$row->CategoryCode ?? ''] ?? '',
                'current_stock' => $stock,
                'image_url' => ProductImageFetcher::firstImageUrlFor($row->InternalCode),
            ];
        })->values());
    }

    /**
     * Design paling banyak terjual 3 bulan lepas DI CAWANGAN terpilih - cadangan restock.
     * Ambil 30 (bukan 10) - panel depan (RestockSuggestions.vue) papar dlm senarai skrol
     * setinggi ~10 baris, jadi 30 di sini bermakna ada lagi utk diskrol, bukan terhad 10 sahaja.
     */
    public function restockSuggestions(Request $request)
    {
        $data = $request->validate([
            'store_code' => ['required', 'string'],
        ]);

        $store = $this->resolveStore($data['store_code']);
        $since = now()->subMonths(3);

        $topSelling = InventoryPiece::query()
            ->realVendor()
            ->where('StoreCode', $store->StoreCode)
            ->whereNotNull('SalesDate')
            ->where('SalesDate', '>=', $since)
            ->selectRaw('InternalCode, MAX(Description) as Description, MAX(CategoryCode) as CategoryCode, COUNT(*) as qty_sold')
            ->groupBy('InternalCode')
            ->orderByDesc('qty_sold')
            ->limit(30)
            ->get();

        $categoryNames = Category::pluck('Description', 'CategoryCode');

        return response()->json($topSelling->map(function ($row) use ($store, $categoryNames) {
            $stock = InventoryPiece::where('InternalCode', $row->InternalCode)
                ->where('StoreCode', $store->StoreCode)
                ->onHand()
                ->count();

            return [
                'internal_code' => trim($row->InternalCode),
                'description' => $row->Description,
                'category_name' => $categoryNames[$row->CategoryCode ?? ''] ?? '',
                'current_stock' => $stock,
                'qty_sold_3m' => (int) $row->qty_sold,
                'image_url' => ProductImageFetcher::firstImageUrlFor($row->InternalCode),
            ];
        })->values());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_code' => ['required', 'string'],
            'submitted_by_name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.internal_code' => ['required', 'string'],
            'lines.*.item_desc' => ['nullable', 'string'],
            'lines.*.qty_requested' => ['required', 'integer', 'min:1'],
        ]);

        $store = $this->resolveStore($data['store_code']);

        $branchDemandRequest = BranchDemandRequest::create([
            'store_code' => $store->StoreCode,
            'submitted_by_name' => $data['submitted_by_name'],
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['lines'] as $line) {
            $branchDemandRequest->lines()->create([
                'internal_code' => $line['internal_code'],
                'item_desc' => $line['item_desc'] ?? null,
                'qty_requested' => $line['qty_requested'],
            ]);
        }

        $branchDemandRequest->notifyReviewers();

        // Selepas hantar, bawa terus ke senarai permintaan cawangan tsb supaya boleh nampak
        // status permintaan baharu tu terus (bukan balik ke borang kosong).
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
