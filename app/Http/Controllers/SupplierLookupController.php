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
            'days' => SupplierLookup::normalizeDays($request->query('days')),
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
            'result' => Inertia::defer(fn () => new RawInertiaProp([
                'searched' => $hasSupplier,
                'designs' => $hasSupplier ? SupplierLookup::forDays($filters['days'])->designs($filters['supplier']) : [],
            ])),
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
        $rows = SupplierLookup::forDays($params['days'])->exportRows($params['supplier'], $params['branches'], $params['sort'], $params['dir']);
        $branchMode = $params['branches'] !== [];

        $imageUrls = array_values(array_unique(array_filter(array_column($rows, 'image_url'))));
        $thumbnails = app(RemoteThumbnails::class)->paths($imageUrls, self::IMAGE_FETCH_BUDGET_SECONDS);

        $supplierName = SupplierLookup::supplierName($params['supplier']) ?? $params['supplier'];
        $heading = 'Supplier Lookup - '.$supplierName.' ('.$params['supplier'].') - '.$params['days'].' hari terakhir - '
            .($branchMode ? 'Cawangan: '.implode(', ', $params['branches']) : 'Semua cawangan').' - '.now()->format('d/m/Y H:i');

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
        $rows = SupplierLookup::forDays($params['days'])->exportRows($params['supplier'], $params['branches'], $params['sort'], $params['dir']);

        return view('supplier-lookup.print', [
            'supplierCode' => $params['supplier'],
            'supplierName' => SupplierLookup::supplierName($params['supplier']) ?? $params['supplier'],
            'days' => $params['days'],
            'branches' => $params['branches'],
            'branchMode' => $params['branches'] !== [],
            'sortLabel' => SupplierLookup::COLUMN_LABELS[$params['sort']].', '.($params['dir'] === 'desc' ? 'menurun' : 'menaik'),
            'labels' => SupplierLookup::COLUMN_LABELS,
            'rows' => array_slice($rows, 0, SupplierLookup::PRINT_MAX_ROWS),
            'total' => count($rows),
            'maxRows' => SupplierLookup::PRINT_MAX_ROWS,
            'generatedBy' => (string) Auth::user()->name,
        ]);
    }

    /**
     * Parameter eksport yg sama dgn keadaan halaman (?supplier=&days=&branches[]=&sort=&dir=). Supplier wajib.
     *
     * @return array{supplier: string, days: int, branches: list<string>, sort: string, dir: string}
     */
    protected function exportParameters(Request $request): array
    {
        $supplier = mb_substr(trim((string) $request->query('supplier', '')), 0, 20);

        abort_if($supplier === '', 422, 'Pilih supplier dahulu.');

        return [
            'supplier' => $supplier,
            'days' => SupplierLookup::normalizeDays($request->query('days')),
            'branches' => collect((array) $request->query('branches', []))
                ->filter(fn ($branch) => is_string($branch) && $branch !== '')
                ->map(fn (string $branch) => mb_substr(trim($branch), 0, 20))
                ->unique()->take(20)->values()->all(),
            'sort' => SupplierLookup::normalizeSort($request->query('sort')),
            'dir' => SupplierLookup::normalizeDirection($request->query('dir')),
        ];
    }

    protected function authorizeLookup(): void
    {
        abort_unless(Auth::user()?->hasRole(BackOfficeActionsController::ROLES), 403);
    }
}
