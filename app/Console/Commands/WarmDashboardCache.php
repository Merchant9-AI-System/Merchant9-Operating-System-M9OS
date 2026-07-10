<?php

namespace App\Console\Commands;

use App\Filament\Widgets\ActionAlerts;
use App\Filament\Widgets\CapitalAgingChart;
use App\Filament\Widgets\GoldVsIdealByBranch;
use App\Filament\Widgets\InventoryKpiStats;
use App\Filament\Widgets\StockVsOptimumChart;
use App\Support\BestSellerLostOpportunityCalculator;
use App\Support\BranchFocusCalculator;
use App\Support\BranchHealthCalculator;
use App\Support\CeoActionCentreCalculator;
use App\Support\DailyAssetPositionCalculator;
use App\Support\OrderRecommendationCalculator;
use App\Support\RearrangeCalculator;
use App\Support\RestockAnalysisCalculator;
use App\Support\StockRearrangementRecommender;
use App\Support\SupplierPerformanceCalculator;
use App\Support\SupplierScorecardCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Pre-warm semua cache dashboard (widget + Rearrange) supaya first-load pengguna tak
 * terkena "cold cache" bertembung dgn lock sementara Windows (cth. antivirus scan selepas
 * jemisys.db ditulis semula via load_data.py, atau selepas `cache:clear`/`config:clear`).
 *
 * JALANKAN LEPAS: load_data.py --replace, `artisan cache:clear`, atau restart server.
 */
#[Signature('app:warm-dashboard-cache')]
#[Description('Pre-warm cache dashboard (KPI/Aging/GoldVsIdeal/Stockout/Rearrange) - jalankan lepas `php artisan cache:clear`.')]
class WarmDashboardCache extends Command
{
    public function handle(): int
    {
        $tasks = [
            'KPI Stats' => fn () => (new \ReflectionMethod(InventoryKpiStats::class, 'getStats'))->invoke(new InventoryKpiStats),
            'Capital Aging' => fn () => (new \ReflectionMethod(CapitalAgingChart::class, 'getData'))->invoke(new CapitalAgingChart),
            'Gold vs Ideal' => fn () => (new \ReflectionMethod(GoldVsIdealByBranch::class, 'getData'))->invoke(new GoldVsIdealByBranch),
            'Rearrange' => fn () => RearrangeCalculator::recommendations(),
            'Order Recommendation' => fn () => OrderRecommendationCalculator::recommendations(),
            'Supplier Scorecard' => fn () => SupplierScorecardCalculator::scorecard(),
            'Action Alerts' => fn () => (new \ReflectionMethod(ActionAlerts::class, 'getStats'))->invoke(new ActionAlerts),
            'Restock by Size' => fn () => RestockAnalysisCalculator::bySize(),
            'Restock by Weight' => fn () => RestockAnalysisCalculator::byWeight(),
            'Supplier Performance (JEMiSys)' => fn () => SupplierPerformanceCalculator::performance(),
            'Branch Focus' => fn () => BranchFocusCalculator::focus(),
            'Stock vs Optimum' => fn () => (new \ReflectionMethod(StockVsOptimumChart::class, 'getData'))->invoke(new StockVsOptimumChart),
            'Best Seller Lost Opportunity' => fn () => BestSellerLostOpportunityCalculator::summary(),
            'Branch Health' => fn () => BranchHealthCalculator::rows(),
            'CEO Action Centre' => fn () => CeoActionCentreCalculator::alerts(),
            'Stock Rearrangement Recommendation' => fn () => StockRearrangementRecommender::recommendations(),
            'Daily Asset Position Reconciliation' => fn () => DailyAssetPositionCalculator::reconciliation(),
        ];

        foreach ($tasks as $label => $fn) {
            $start = microtime(true);
            try {
                $fn();
                $ms = round((microtime(true) - $start) * 1000);
                $this->info("  {$label}: OK ({$ms}ms)");
            } catch (\Throwable $e) {
                $this->error("  {$label}: GAGAL - {$e->getMessage()}");
            }
        }

        $this->info('Selesai pre-warm cache dashboard.');

        return self::SUCCESS;
    }
}
