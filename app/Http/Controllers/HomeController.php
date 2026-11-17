<?php

namespace App\Http\Controllers;

use App\Support\HomeSummary\BranchSalesInsights;
use App\Support\HomeSummary\BranchSection;
use App\Support\HomeSummary\ChecklistFeed;
use App\Support\HomeSummary\HomeAccess;
use App\Support\HomeSummary\HomeSummary;
use App\Support\HomeSummary\HqDirectionFeed;
use App\Support\HomeSummary\MonthlyTargetFeed;
use App\Support\HomeSummary\RearrangeFeed;
use App\Support\HomeSummary\StaffRankingFeed;
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
    public function __invoke(Request $request, HomeSummary $summary, BranchSalesInsights $insights, HqDirectionFeed $directions, MonthlyTargetFeed $targets, StaffRankingFeed $ranking, RearrangeFeed $rearrange, ChecklistFeed $checklist): Response|RedirectResponse
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

        // Jualan & stok cawangan (Terlaris + Perlu restock dalam satu kad): ketua cawangan yang ada store_code
        // sahaja. Tempoh dipilih melalui ?period= (nilai tak sah jatuh ke lalai). Deferred dalam kumpulan
        // berasingan supaya tak menyekat ringkasan.
        $store = $user->hasRole(BranchSection::ROLE) ? BranchSection::storeOf($user) : '';
        $hasBranch = $store !== '';
        $period = BranchSalesInsights::normalizePeriod($request->query('period'));
        $rankingPeriod = StaffRankingFeed::normalizePeriod($request->query('ranking_period'));

        return Inertia::render('Home/Index', [
            'date' => $date->toDateString(),
            'dateLabel' => $date->locale('ms')->translatedFormat('l, j F Y'),
            'links' => $links,
            'sections' => Inertia::defer(fn () => $summary->sectionsFor($user, $date)),
            'hasBranch' => $hasBranch,
            'requestUrl' => route('branch-demand.create'),
            'periods' => collect(BranchSalesInsights::periods())->map(fn (array $p, string $value) => ['value' => $value, 'label' => $p['label']])->values()->all(),
            'period' => $period,
            'branchOverview' => $hasBranch ? Inertia::defer(fn () => $insights->overview($store, $period), 'branch-overview') : null,
            // Arahan HQ utk cawangan ini (belum diakui, atau diakui hari ini sahaja) - rujuk HqDirectionFeed.
            'hqDirections' => $hasBranch ? Inertia::defer(fn () => $directions->forStore($store), 'hq-directions') : null,
            // Sasaran bulan semasa vs jualan sebenar (RM & gram) cawangan ini - rujuk MonthlyTargetFeed.
            'monthlyTarget' => $hasBranch ? Inertia::defer(fn () => $targets->forStore($store), 'monthly-target') : null,
            // Ranking jualan staf cawangan ini (bulan ini / bulan lepas via ?ranking_period=) - rujuk StaffRankingFeed.
            'rankingPeriod' => $rankingPeriod,
            'staffRanking' => $hasBranch ? Inertia::defer(fn () => $ranking->forStore($store, $rankingPeriod), 'staff-ranking') : null,
            // Transfer yg cawangan ini perlu hantar / bakal terima, disemak silang dgn cermin JEMiSys - rujuk RearrangeFeed.
            'rearrange' => $hasBranch ? Inertia::defer(fn () => $rearrange->forStore($store), 'rearrange') : null,
            // Daily Checklist cawangan ini (hari ini, waktu Malaysia) - rujuk ChecklistFeed.
            'checklist' => $hasBranch ? Inertia::defer(fn () => $checklist->forStore($store), 'checklist') : null,
        ]);
    }
}
