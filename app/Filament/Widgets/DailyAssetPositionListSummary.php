<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan atas page senarai Daily Asset Position sendiri (bukan CEO Dashboard - rujuk
 * widget CEO berasingan di app/Filament/Widgets/DailyAssetPosition*.php utk dashboard utama).
 */
class DailyAssetPositionListSummary extends StatsOverviewWidget
{
    // use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $summary = DailyAssetPositionCalculator::summary();

        if ($summary === null) {
            return [
                Stat::make('Rekod Terkini', 'Tiada data lagi')
                    ->description('Sila key-in rekod Daily Asset Position pertama.')
                    ->color('gray'),
            ];
        }

        return [
            Stat::make('Tarikh Terkini', $summary['entry_date']->format('d/m/Y')),
            Stat::make('Closing Stock', number_format($summary['closing_stock_weight'], 3).' g'),
            Stat::make('Net Weight', number_format($summary['net_weight'], 3).' g'),
            Stat::make('Available Cash', 'RM '.number_format($summary['available_cash'], 2)),
            Stat::make('Supplier Hutang', number_format($summary['supplier_hutang'], 3).' g')
                ->color($summary['supplier_hutang'] > 0 ? 'warning' : 'gray'),
            Stat::make('Supplier Overpaid', number_format($summary['supplier_overpaid'], 3).' g')
                ->color($summary['supplier_overpaid'] > 0 ? 'warning' : 'gray'),
            Stat::make('Stock Movement Difference', number_format($summary['stock_movement_difference'], 3).' g')
                ->description('Beza closing stock dikeyin vs formula (Opening + In - Out)')
                ->color($summary['stock_movement_difference'] > 0.005 ? 'danger' : 'success'),
        ];
    }
}
