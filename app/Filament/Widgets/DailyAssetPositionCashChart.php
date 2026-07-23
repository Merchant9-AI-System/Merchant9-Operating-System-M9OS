<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;

class DailyAssetPositionCashChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Daily Available Cash Trend';

    protected function getData(): array
    {
        $trend = DailyAssetPositionCalculator::trend(30);

        return [
            'datasets' => [
                [
                    'label' => 'Available Cash (RM)',
                    'data' => $trend->pluck('available_cash')->all(),
                    'borderColor' => '#1D9E75',
                    'backgroundColor' => '#1D9E75',
                ],
                [
                    'label' => 'Cash For GB (RM)',
                    'data' => $trend->pluck('cash_for_gb')->all(),
                    'borderColor' => '#B45309',
                    'backgroundColor' => '#B45309',
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
