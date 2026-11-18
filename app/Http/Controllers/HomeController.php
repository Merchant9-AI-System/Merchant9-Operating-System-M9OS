<?php

namespace App\Http\Controllers;

use App\Support\HomeSummary\BranchSalesInsights;
use App\Support\HomeSummary\BranchSection;
use App\Support\HomeSummary\ChecklistFeed;
use App\Support\HomeSummary\HomeAccess;
use App\Support\HomeSummary\HomeLayout;
use App\Support\HomeSummary\HomeModules;
use App\Support\HomeSummary\HomeSummary;
use App\Support\HomeSummary\HqDirectionFeed;
use App\Support\HomeSummary\MonthlyTargetFeed;
use App\Support\HomeSummary\RearrangeFeed;
use App\Support\HomeSummary\StaffRankingFeed;
use App\Support\HomeSummary\StoreScope;
use App\Support\HomeSummary\UniversalRankingFeed;
use App\Support\HomeSummary\UniversalSummaryFeed;
use App\Support\HomeSummary\UniversalTargetFeed;
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
    public function __invoke(Request $request, HomeSummary $summary, BranchSalesInsights $insights, HqDirectionFeed $directions, MonthlyTargetFeed $targets, StaffRankingFeed $ranking, RearrangeFeed $rearrange, ChecklistFeed $checklist, UniversalSummaryFeed $uSummary, UniversalTargetFeed $uTarget, UniversalRankingFeed $uRanking): Response|RedirectResponse
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
        // Modul yang dipasang utk pengguna ini (susun atur per peranan di halaman Filament "Halaman Home" - HomeLayout).
        // Hanya modul yang dipasang dikira/dihantar (jimat query JEMiSys). Modul skop cawangan = cawangan pengguna sendiri.
        $modules = HomeLayout::forUser($user);
        $enabled = array_column($modules, 'key');
        $has = fn (string $key): bool => in_array($key, $enabled, true);
        $store = BranchSection::storeOf($user);
        $hasBranch = $store !== '' && collect($modules)->contains(fn (array $m) => $m['scope'] === HomeModules::SCOPE_BRANCH);
        // Skop cawangan modul universal (null = semua cawangan; senarai = dipilih / cawangan sendiri).
        $storesOf = fn (string $key): ?array => collect($modules)->firstWhere('key', $key)['stores'] ?? null;
        $sectionKeys = array_values(array_intersect($enabled, array_keys(HomeModules::sectionClasses())));
        $period = BranchSalesInsights::normalizePeriod($request->query('period'));
        $rankingPeriod = StaffRankingFeed::normalizePeriod($request->query('ranking_period'));

        return Inertia::render('Home/Index', [
            'date' => $date->toDateString(),
            'dateLabel' => $date->locale('ms')->translatedFormat('l, j F Y'),
            'links' => $links,
            'sections' => $sectionKeys === [] ? [] : Inertia::defer(fn () => $summary->sectionsFor($user, $date, $sectionKeys)),
            // Metadata modul terpasang (urutan = urutan paparan) - tab, ikon dan urutan panel dibina daripada ini.
            'homeModules' => array_map(fn (array $m) => array_intersect_key($m, array_flip(['key', 'title', 'icon', 'kind', 'tab', 'scope'])) + ['scope_label' => $m['scope'] === HomeModules::SCOPE_FLEX ? StoreScope::label($m['stores']) : null], $modules),
            'hasBranch' => $hasBranch,
            'requestUrl' => route('branch-demand.create'),
            'periods' => collect(BranchSalesInsights::periods())->map(fn (array $p, string $value) => ['value' => $value, 'label' => $p['label']])->values()->all(),
            'period' => $period,
            'branchOverview' => $has('stock') ? Inertia::defer(fn () => $insights->overview($store, $period), 'branch-overview') : null,
            // Arahan HQ utk cawangan ini (belum diakui, atau diakui hari ini sahaja) - rujuk HqDirectionFeed.
            'hqDirections' => $has('hq_directions') ? Inertia::defer(fn () => $directions->forStore($store), 'hq-directions') : null,
            // Sasaran bulan semasa vs jualan sebenar (RM & gram) cawangan ini - rujuk MonthlyTargetFeed.
            'monthlyTarget' => $has('target') ? Inertia::defer(fn () => $targets->forStore($store), 'monthly-target') : null,
            // Ranking jualan staf cawangan ini (bulan ini / bulan lepas via ?ranking_period=) - rujuk StaffRankingFeed.
            'rankingPeriod' => $rankingPeriod,
            'staffRanking' => $has('ranking') ? Inertia::defer(fn () => $ranking->forStore($store, $rankingPeriod), 'staff-ranking') : null,
            // Transfer yg cawangan ini perlu hantar / bakal terima, disemak silang dgn cermin JEMiSys - rujuk RearrangeFeed.
            'rearrange' => $has('rearrange') ? Inertia::defer(fn () => $rearrange->forStore($store), 'rearrange') : null,
            // Daily Checklist cawangan ini (hari ini, waktu Malaysia) - rujuk ChecklistFeed.
            // Modul universal (skop cawangan ditetapkan pentadbir): ringkasan, sasaran dan ranking staf banyak cawangan.
            'universalSummary' => $has('u_summary') ? Inertia::defer(fn () => $uSummary->forStores($storesOf('u_summary')), 'u-summary') : null,
            'universalTarget' => $has('u_target') ? Inertia::defer(fn () => $uTarget->forStores($storesOf('u_target')), 'u-target') : null,
            'universalRanking' => $has('u_ranking') ? Inertia::defer(fn () => $uRanking->forStores($storesOf('u_ranking'), $rankingPeriod), 'u-ranking') : null,
            'universalStock' => $has('u_stock') ? Inertia::defer(fn () => $insights->overviewFor(StoreScope::stores($storesOf('u_stock')), $period) + ['scope_label' => StoreScope::label($storesOf('u_stock'))], 'u-stock') : null,
            'checklist' => $has('checklist') ? Inertia::defer(fn () => $checklist->forStore($store), 'checklist') : null,
        ]);
    }
}
