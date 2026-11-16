<?php

namespace App\Http\Controllers;

use App\Support\RawInertiaProp;
use App\Support\RemoteThumbnails;
use App\Support\SupplierLookup;
use App\Support\SupplierLookupExport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Halaman Inertia+Vue "Supplier Lookup" (Procurement): sell-through setiap design daripada SATU supplier dlm tempoh - Total
 * Amik/Buy, Total Sold, Total Available & Percentage (rujuk App\Support\SupplierLookup utk definisi).
 *
 * Senarai TIDAK dipaparkan sehingga pengguna memilih supplier (autocomplete kod/nama vendor -> suggestions()). Hanya supplier &
 * tempoh yg menggerakkan permintaan ke server (?supplier=&days=): `result` (semua design supplier itu, kod + Amik/Sold setiap
 * cawangan) dihantar SEKALI, DITANGGUH supaya cangkerang halaman terpapar dgn skeleton sementara agregat dikira (pertama kali
 * sahaja; selepas itu di-cache & dipanaskan `supplier-lookup:warm`). Tapisan cawangan, susunan & halaman berlaku sepenuhnya di
 * pelayar (rujuk resources/js/lib/supplierLookup.ts) - serta-merta tanpa rangkaian. Nickname & imej dimuat utk baris sehalaman
 * sahaja melalui details().
 *
 * Eksport (export() = Excel, print() = halaman cetak/Simpan sebagai PDF, corak sama dgn Senarai Restock) mencerminkan apa yg pengguna
 * nampak: pelayar menghantar supplier, tempoh, cawangan & susunan semasa, dan SEMUA baris (bukan satu halaman) dieksport dgn tajuk
 * lajur yg sebijik sama dgn jadual (SupplierLookup::COLUMN_LABELS).
 *
 * Akses: role sama dgn Back Office Actions (manager, ceo, super_admin) - kumpulan menu Procurement.
 */
class SupplierLookupController extends Controller
{
    /** Belanjawan masa (saat) memuat turun gambar utk satu eksport Excel; selebihnya dilengkapkan oleh eksport seterusnya (cache). */
    private const IMAGE_FETCH_BUDGET_SECONDS = 100.0;

    public function index(Request $request): Response
    {
        $this->authorizeLookup();

        $filters = [
            'supplier' => mb_substr(trim((string) $request->query('supplier', '')), 0, 20),
            // days = tempoh STOCK IN (PurchDate, Total Amik); sales_days = tempoh JUALAN (SalesDate, Total Sold) - dua tempoh bebas.
            'days' => SupplierLookup::normalizeDays($request->query('days')),
            'sales_days' => SupplierLookup::normalizeDays($request->query('sales_days')),
        ];

        $hasSupplier = $filters['supplier'] !== '';

        return Inertia::render('SupplierLookup/Index', [
            'filters' => $filters,
            // Nama supplier terpilih utk medan carian (kod sahaja disimpan dlm URL).
            'supplier' => $hasSupplier
                ? ['code' => $filters['supplier'], 'name' => SupplierLookup::supplierName($filters['supplier']) ?? $filters['supplier']]
                : null,
            'periodOptions' => collect(SupplierLookup::PERIOD_DAYS)
                ->map(fn (int $days) => ['value' => (string) $days, 'label' => $days.' hari terakhir'])
                ->all(),
            // RawInertiaProp: ribuan design - elak Inertia menelusuri setiap nilai (rujuk dokblok kelas itu).
            // `demand` (Branch Requested) dikira SEGAR setiap permintaan (query kecil, tidak dicache) supaya mengikut status BO semasa.
            'result' => Inertia::defer(function () use ($hasSupplier, $filters) {
                $designs = $hasSupplier ? SupplierLookup::forDays($filters['days'], $filters['sales_days'])->designs($filters['supplier']) : [];

                return new RawInertiaProp([
                    'searched' => $hasSupplier,
                    'designs' => $designs,
                    // Kategori yg ada pd design supplier ini (kod => nama) utk penapis kategori.
                    'categories' => SupplierLookup::categoryNames(array_values(array_unique(array_filter(array_column($designs, 'category'))))),
                    'demand' => SupplierLookup::demandFor($designs),
                ]);
            }),
        ]);
    }

    /**
     * Halaman butiran SATU design (butang "Lihat" pd jadual): carousel semua gambar, maklumat asas (saiz, berat, supplier), stok mengikut
     * cawangan & senarai piece. Gambar DITANGGUH (kikisan laman web boleh lambat) - carousel papar skeleton dahulu.
     */
    public function design(Request $request, string $code): Response
    {
        $this->authorizeLookup();

        $detail = SupplierLookup::designDetail($code);

        abort_if($detail === null, 404);

        return Inertia::render('SupplierLookup/Design', [
            'design' => $detail,
            // Supplier yg dicari di jadual (?supplier=MG): dipaparkan dahulu dlm senarai supplier; yg lain dikumpul dlm lencana "+N".
            'focusSupplier' => mb_substr(trim((string) $request->query('supplier', '')), 0, 20) ?: null,
            'images' => Inertia::defer(fn () => SupplierLookup::designImages($detail['code'])),
        ]);
    }

    /**
     * Cadangan autocomplete (supplier ikut kod/nama) - JSON, pantas (tidak bergantung pd agregat tempoh).
     */
    public function suggestions(Request $request): JsonResponse
    {
        $this->authorizeLookup();

        return response()->json(SupplierLookup::suggestions(mb_substr(trim((string) $request->query('q', '')), 0, 60)));
    }

    /**
     * Nickname, imej & gambar rujukan bagi kod yg kelihatan sehalaman (?codes[]=...), maksimum SupplierLookup::MAX_DETAIL_CODES.
     */
    public function details(Request $request): JsonResponse
    {
        $this->authorizeLookup();

        return response()->json(['details' => SupplierLookup::details((array) $request->query('codes', []))]);
    }

    /**
     * Excel (.xlsx) dgn GAMBAR SEBENAR terbenam (Laravel Excel / PhpSpreadsheet, rujuk SupplierLookupExport): semua baris mengikut supplier,
     * tempoh, cawangan & susunan semasa. Gambar dimuat turun serentak & dicache sbg thumbnail (RemoteThumbnails) dgn belanjawan masa -
     * baris yg gambarnya belum sempat dimuat dikosongkan (dinyatakan pd baris tajuk) & dilengkapkan oleh eksport seterusnya.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorizeLookup();

        // Memuat turun ratusan gambar pada eksport pertama boleh melebihi had masa lalai.
        set_time_limit(300);

        $params = $this->exportParameters($request);
        $rows = SupplierLookup::forDays($params['days'], $params['sales_days'])->exportRows($params['supplier'], $params['branches'], $params['sorts'], $params['categories']);
        $branchMode = $params['branches'] !== [];

        $imageUrls = array_values(array_unique(array_filter(array_column($rows, 'image_url'))));
        $thumbnails = app(RemoteThumbnails::class)->paths($imageUrls, self::IMAGE_FETCH_BUDGET_SECONDS);

        $supplierName = SupplierLookup::supplierName($params['supplier']) ?? $params['supplier'];
        $heading = 'Supplier Lookup - '.$supplierName.' ('.$params['supplier'].') - '.'stock in '.$params['days'].' hari - jualan '.$params['sales_days'].' hari - '
            .($branchMode ? 'Cawangan: '.implode(', ', $params['branches']) : 'Semua cawangan')
            .($params['categories'] !== [] ? ' - Kategori: '.implode(', ', $params['categories']) : '')
            .' - '.now()->format('d/m/Y H:i');

        if (count($thumbnails) < count($imageUrls)) {
            $heading .= ' - gambar dibenamkan: '.count($thumbnails).' daripada '.count($imageUrls).' (eksport semula untuk melengkapkan)';
        }

        return Excel::download(
            new SupplierLookupExport($heading, $rows, $branchMode, $thumbnails),
            'Supplier-Lookup-'.Str::slug($params['supplier']).'-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    /**
     * Halaman cetak (Cetak / Simpan sebagai PDF) - jadual yg sama dgn imej; dihadkan kepada SupplierLookup::PRINT_MAX_ROWS baris pertama
     * (setiap baris ada gambar) - Excel utk senarai penuh.
     */
    public function print(Request $request): View
    {
        $this->authorizeLookup();

        $params = $this->exportParameters($request);
        $rows = SupplierLookup::forDays($params['days'], $params['sales_days'])->exportRows($params['supplier'], $params['branches'], $params['sorts'], $params['categories']);

        return view('supplier-lookup.print', [
            'supplierCode' => $params['supplier'],
            'supplierName' => SupplierLookup::supplierName($params['supplier']) ?? $params['supplier'],
            'days' => $params['days'],
            'salesDays' => $params['sales_days'],
            'branches' => $params['branches'],
            'branchMode' => $params['branches'] !== [],
            'categories' => $params['categories'],
            'sortLabel' => collect($params['sorts'])
                ->map(fn (array $sort): string => SupplierLookup::COLUMN_LABELS[$sort['key']].' '.($sort['dir'] === 'desc' ? 'menurun' : 'menaik'))
                ->implode(', '),
            'labels' => SupplierLookup::COLUMN_LABELS,
            'rows' => array_slice($rows, 0, SupplierLookup::PRINT_MAX_ROWS),
            'total' => count($rows),
            'maxRows' => SupplierLookup::PRINT_MAX_ROWS,
            'generatedBy' => (string) Auth::user()->name,
        ]);
    }

    /**
     * Parameter eksport yg sama dgn keadaan halaman (?supplier=&days=&branches[]=&sort=percent:desc,buy:desc). Supplier wajib. Bentuk lama
     * (sort=percent&dir=desc) masih diterima.
     *
     * @return array{supplier: string, days: int, sales_days: int, branches: list<string>, categories: list<string>, sorts: list<array{key: string, dir: string}>}
     */
    protected function exportParameters(Request $request): array
    {
        $supplier = mb_substr(trim((string) $request->query('supplier', '')), 0, 20);

        abort_if($supplier === '', 422, 'Pilih supplier dahulu.');

        return [
            'supplier' => $supplier,
            'days' => SupplierLookup::normalizeDays($request->query('days')),
            'sales_days' => SupplierLookup::normalizeDays($request->query('sales_days')),
            'branches' => collect((array) $request->query('branches', []))
                ->filter(fn ($branch) => is_string($branch) && $branch !== '')
                ->map(fn (string $branch) => mb_strtoupper(mb_substr(trim($branch), 0, 20)))
                ->unique()->take(20)->values()->all(),
            'categories' => collect((array) $request->query('categories', []))
                ->filter(fn ($category) => is_string($category) && $category !== '')
                ->map(fn (string $category) => mb_strtoupper(mb_substr(trim($category), 0, 20)))
                ->unique()->take(50)->values()->all(),
            'sorts' => SupplierLookup::parseSorts($request->query('sort'), $request->query('dir')),
        ];
    }

    protected function authorizeLookup(): void
    {
        abort_unless(Auth::user()?->hasRole(BackOfficeActionsController::ROLES), 403);
    }
}
