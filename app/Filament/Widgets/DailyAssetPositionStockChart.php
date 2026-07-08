<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;

class DailyAssetPositionStockChart extends ChartWidget
{
    use HasWidgetShield;
    
    protected static bool $isLazy = false;

    protected ?string $heading = 'Daily Closing Stock & Sales Trend';

    protected function getData(): array
    {
        $trend = DailyAssetPositionCalculator::trend(30);

        return [
            'datasets' => [
                [
                    'label' => 'Closing Stock (g)',
                    'data' => $trend->pluck('closing_stock')->all(),
                    'borderColor' => '#7F77DD',
                    'backgroundColor' => '#7F77DD',
                ],
                [
                    'label' => 'Sales (g)',
                    'data' => $trend->pluck('sales')->all(),
                    'borderColor' => '#D85A30',
                    'backgroundColor' => '#D85A30',
                ],
            ],
            'labels' => $trend->pluck('entry_date')->map(fn ($d) => $d->format('d/m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        return DailyAssetPositionCalculator::trend(30)->isEmpty()
            ? 'Trend tidak tersedia lagi - tiada rekod Daily Asset Position dikeyin.'
            : '30 rekod terkini yg dikeyin accountant.';
    }
}
