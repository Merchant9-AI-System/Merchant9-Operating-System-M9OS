<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use Filament\Widgets\ChartWidget;

class DailyAssetPositionSupplierChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'Supplier Hutang / Overpaid Trend';

    public static function canView(): bool
    {
        return (bool) config('dashboard.ceo_features.daily_asset_position', true);
    }

    protected function getData(): array
    {
        $trend = DailyAssetPositionCalculator::trend(30);

        return [
            'datasets' => [
                [
                    'label' => 'Supplier Hutang (g)',
                    'data' => $trend->pluck('supplier_hutang')->all(),
                    'borderColor' => '#D85A30',
                    'backgroundColor' => '#D85A30',
                ],
                [
                    'label' => 'Supplier Overpaid (g)',
                    'data' => $trend->pluck('supplier_overpaid')->all(),
                    'borderColor' => '#1D9E75',
                    'backgroundColor' => '#1D9E75',
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
