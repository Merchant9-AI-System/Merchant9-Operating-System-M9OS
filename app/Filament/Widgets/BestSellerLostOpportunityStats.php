<?php

namespace App\Filament\Widgets;

use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * CEO Dashboard Phase 1 (D) - header widget pada page StockoutReorder sedia ada (rujuk
 * StockoutReorder::getHeaderWidgets(), satu-satunya perubahan pada page tu). Widget baru,
 * table/filter/export sedia ada di page tu TIDAK diubah.
 *
 * $summary diisi drpd StockoutReorder::getWidgetData() (rujuk App\Filament\Pages\
 * RestockByCategory utk corak sama) - widget ni SENDIRI tak panggil
 * BestSellerLostOpportunityCalculator::summary(), sekadar papar apa yg page hantar, supaya
 * kekal selari dgn julat tarikh (range filter) yg dipilih di jadual induk. #[On(...)] wajib
 * sbb widget ni komponen Livewire berasingan - TIDAK remount automatik bila penapis page
 * berubah (rujuk StockoutReorder::dispatchSummaryRefresh()).
 */
class BestSellerLostOpportunityStats extends StatsOverviewWidget
{
    // use HasWidgetShield;

    protected ?string $pollingInterval = null;

    public array $summary = [];

    #[On('stockout-reorder-summary-updated')]
    public function refreshSummary(array $summary): void
    {
        $this->summary = $summary;
    }

    protected function getStats(): array
    {
        $s = $this->summary;

        if ($s === []) {
            return [
                Stat::make('Total Design Sold Out', '-')
                    ->color('gray'),
            ];
        }

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

        if (! empty($s['top_branches'])) {
            $topBranch = $s['top_branches'][0];
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
