<?php

namespace App\Http\Controllers;

use App\Support\HomeSummary\BranchSection;
use App\Support\HomeSummary\HomeAccess;
use App\Support\HomeSummary\HomeSummary;
use App\Support\HomeSummary\YesterdaySnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman pertama selepas log masuk: ringkasan semalam mengikut peranan pengguna (rujuk
 * App\Support\HomeSummary). `sections` ialah deferred prop - rangka halaman terpapar serta-merta, ringkasan
 * (yang mungkin perlu query JEMiSys kali pertama sehari) menyusul dgn skeleton.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, HomeSummary $summary): Response|RedirectResponse
    {
        $user = $request->user();

        // Peranan pengguna ditutup oleh super_admin (halaman "Halaman Home") -> terus ke dashboard admin.
        if (! HomeAccess::enabledFor($user)) {
            return redirect('/admin');
        }

        $date = YesterdaySnapshot::date();

        // `inertia` = halaman Inertia (navigasi SPA via <Link>); selainnya (cth. panel Filament) pautan biasa.
        $links = [['label' => 'Dashboard', 'href' => '/admin', 'inertia' => false]];

        if ($user->hasRole(BranchSection::ROLE)) {
            array_unshift($links, ['label' => 'Permintaan Stok Cawangan', 'href' => route('branch-demand.create'), 'inertia' => true]);
        }

        // Supplier Lookup: peranan sama dgn Back Office Actions (rujuk SupplierLookupController::authorizeLookup).
        if ($user->hasRole(BackOfficeActionsController::ROLES)) {
            array_splice($links, count($links) - 1, 0, [['label' => 'Supplier Lookup', 'href' => route('supplier-lookup.index'), 'inertia' => true]]);
        }

        return Inertia::render('Home/Index', [
            'date' => $date->toDateString(),
            'dateLabel' => $date->locale('ms')->translatedFormat('l, j F Y'),
            'links' => $links,
            'sections' => Inertia::defer(fn () => $summary->sectionsFor($user, $date)),
        ]);
    }
}
