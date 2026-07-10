<?php

namespace App\Filament\Widgets;

use App\Support\BestSellerLostOpportunityCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * CEO Dashboard Phase 1 (D) - header widget pada page StockoutReorder sedia ada (rujuk
 * StockoutReorder::getHeaderWidgets(), satu-satunya perubahan pada page tu). Widget baru,
 * table/filter/export sedia ada di page tu TIDAK diubah.
 */
class BestSellerLostOpportunityStats extends StatsOverviewWidget
{
    // use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $s = BestSellerLostOpportunityCalculator::summary();

        $stats = [
            Stat::make('Total Design Sold Out', (string) $s['total_count'])
                ->description('Best-seller (pernah jual >=3) kini stok=0')
                ->color('danger'),
        ];

        if ($s['estimated_lost_revenue'] !== null) {
            $stats[] = Stat::make('Estimated Lost Revenue', 'RM '.number_format($s['estimated_lost_revenue'], 0))
                ->description("Anggaran konservatif: 1 unit x purata harga jualan sejarah, {$s['priced_design_count']} drpd {$s['total_count']} design ada data harga (rule-based suggestion)")
                ->color('warning');
        } else {
            $stats[] = Stat::make('Estimated Lost Revenue', 'Data tidak mencukupi')
                ->description('Tiada data SalesAmount sejarah utk design terlibat - papar bilangan sahaja, tiada anggaran direka.')
                ->color('gray');
        }

        if ($s['top_branches']->isNotEmpty()) {
            $topBranch = $s['top_branches']->first();
            $stats[] = Stat::make('Cawangan Paling Terjejas', $topBranch['store_code'])
                ->description($topBranch['past_sales'].' jualan sejarah bagi design yg kini sold out')
                ->color('warning');
        } else {
            $stats[] = Stat::make('Cawangan Paling Terjejas', 'Tiada data')
                ->color('gray');
        }

        return $stats;
    }
}
