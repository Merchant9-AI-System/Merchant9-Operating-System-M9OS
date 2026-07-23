<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * CEO Dashboard - 10 kad ringkasan drpd modul "Daily Company Asset Position" (data dikeyin
 * accountant, jadual sendiri). Boleh dimatikan via .env CEO_DAILY_ASSET_POSITION_ENABLED=false
 * (config/dashboard.php). Widget baru, TIADA kesan pada widget dashboard lain.
 */
class DailyAssetPositionSummary extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $s = DailyAssetPositionCalculator::summary();

        if ($s === null) {
            return [
                Stat::make('Daily Asset Position', 'Tiada data lagi')
                    ->description('Accountant belum key-in sebarang rekod Daily Asset Position.')
                    ->color('gray'),
            ];
        }

        $diffPct = $s['jemisys_vs_accountant_diff_pct'];

        return [
            Stat::make('Yesterday Sales Weight', number_format($s['yesterday_sales_weight'], 3).' g'),
            Stat::make('Closing Stock Weight', number_format($s['closing_stock_weight'], 3).' g'),
            Stat::make('Net Weight', number_format($s['net_weight'], 3).' g'),
            Stat::make('Total Cash / Bank', 'RM '.number_format($s['total_cash_bank'], 2)),
            Stat::make('Available Cash', 'RM '.number_format($s['available_cash'], 2)),
            Stat::make('Cash For GB', 'RM '.number_format($s['cash_for_gb'], 2)),
            Stat::make('Supplier Hutang', number_format($s['supplier_hutang'], 3).' g')
                ->color($s['supplier_hutang'] > 0 ? 'warning' : 'gray'),
            Stat::make('Supplier Overpaid', number_format($s['supplier_overpaid'], 3).' g')
                ->color($s['supplier_overpaid'] > 0 ? 'warning' : 'gray'),
            Stat::make('Stock Movement Difference', number_format($s['stock_movement_difference'], 3).' g')
                ->color($s['stock_movement_difference'] > 0.005 ? 'danger' : 'success'),
            Stat::make('Loss From Melting', number_format($s['loss_from_melting'], 3).' g')
                ->color($s['loss_from_melting'] > 0 ? 'warning' : 'gray'),
            Stat::make('Jemisys vs Accountant Difference', $diffPct !== null ? number_format($diffPct, 2).'%' : 'Data tidak mencukupi')
                ->description('Closing stock - rujuk widget Reconciliation utk butiran penuh')
                ->color(match (true) {
                    $diffPct === null => 'gray',
                    $diffPct >= (float) config('dashboard.daily_asset_position.reconciliation_red_pct', 5.0) => 'danger',
                    $diffPct >= (float) config('dashboard.daily_asset_position.reconciliation_yellow_pct', 2.0) => 'warning',
                    default => 'success',
                }),
        ];
    }
}
