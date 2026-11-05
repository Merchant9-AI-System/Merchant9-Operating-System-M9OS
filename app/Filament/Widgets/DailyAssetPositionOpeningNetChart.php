<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;

/**
 * Opening Stock tolak Hutang Supplier (gram) vs Net Weight penutup - rujuk
 * DailyAssetPositionCalculator::openingNetOfHutangTrend().
 */
class DailyAssetPositionOpeningNetChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Opening Stock tolak Hutang vs Net Weight (gram)';

    protected function getData(): array
    {
        $trend = DailyAssetPositionCalculator::openingNetOfHutangTrend(30);

        return [
            'datasets' => [
                [
                    'label' => 'Opening Stock - Hutang (g)',
                    'data' => $trend->pluck('opening_net')->all(),
                    'borderColor' => '#6B7280',
                    'backgroundColor' => '#6B7280',
                    'borderDash' => [6, 4],
                ],
                [
                    'label' => 'Net Weight penutup (g)',
                    'data' => $trend->pluck('net_weight')->all(),
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
        $trend = DailyAssetPositionCalculator::openingNetOfHutangTrend(30);

        if ($trend->isEmpty()) {
            return 'Tiada rekod Book Balance Asset Position dikeyin.';
        }

        $change = $trend->last()['change'];

        return 'Opening - hutang + overpaid (guna hutang rekod hari tsb). Pergerakan bersih hari terkini: '
            .($change >= 0 ? '+' : '-').number_format(abs($change), 2).'g.';
    }
}
