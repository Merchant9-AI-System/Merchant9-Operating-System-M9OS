<?php

namespace App\Http\Controllers;

use App\Support\RawInertiaProp;
use App\Support\SupplierLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

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
 * Akses: role sama dgn Back Office Actions (manager, ceo, super_admin) - kumpulan menu Procurement.
 */
class SupplierLookupController extends Controller
{
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

    protected function authorizeLookup(): void
    {
        abort_unless(Auth::user()?->hasRole(BackOfficeActionsController::ROLES), 403);
    }
}
