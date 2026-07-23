<?php

namespace App\Filament\Widgets;

use App\Support\PhysicalGoldReconciliationCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * CEO Dashboard - ringkasan Gold Reconciliation drpd laporan Physical Gold Balance TERKINI
 * yg Approved. Boleh dimatikan via .env CEO_PHYSICAL_GOLD_BALANCE_ENABLED=false
 * (config/dashboard.php). Widget baru, TIADA kesan pada widget dashboard lain.
 */
class PhysicalGoldBalanceSummary extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $s = PhysicalGoldReconciliationCalculator::latestSummary();

        if ($s === null) {
            return [
                Stat::make('Physical Gold Balance', 'Tiada laporan diluluskan lagi')
                    ->description('Belum ada Physical Gold Report yg diluluskan.')
                    ->color('gray'),
            ];
        }

        $isPending = $s['status'] === PhysicalGoldReconciliationCalculator::STATUS_PENDING;

        return [
            Stat::make('Physical Net Pure Gold', number_format($s['physical_net_pure_gold'], 4).' g'),

            Stat::make('Book Net Weight', $isPending ? 'Book Balance Pending' : number_format($s['book_net_weight'], 4).' g')
                ->description('Sumber: DailyAssetPosition.net_weight')
                ->color($isPending ? 'gray' : 'success'),

            Stat::make('Book Closing Stock', $isPending ? 'Book Balance Pending' : number_format($s['book_closing_stock'], 4).' g')
                ->description('Rujukan tambahan (sebelum pelarasan supplier)')
                ->color('gray'),

            Stat::make('Gold Variance', $isPending ? 'Belum tersedia' : number_format($s['variance'], 4).' g')
                ->description($isPending ? '-' : ($s['variance'] > 0 ? 'Lebihan fizikal' : ($s['variance'] < 0 ? 'Kekurangan fizikal' : 'Sepadan')))
                ->color(match (true) {
                    $isPending => 'gray',
                    $s['status'] === PhysicalGoldReconciliationCalculator::STATUS_RED => 'danger',
                    $s['status'] === PhysicalGoldReconciliationCalculator::STATUS_YELLOW => 'warning',
                    default => 'success',
                }),

            Stat::make('Variance %', $isPending ? '-' : number_format($s['variance_pct'], 2).'%')
                ->color(match (true) {
                    $isPending => 'gray',
                    $s['status'] === PhysicalGoldReconciliationCalculator::STATUS_RED => 'danger',
                    $s['status'] === PhysicalGoldReconciliationCalculator::STATUS_YELLOW => 'warning',
                    default => 'success',
                }),

            Stat::make('Daily Physical Movement', $s['day_on_day_movement'] === null ? 'Tiada data sebelum ini' : number_format($s['day_on_day_movement'], 4).' g')
                ->description('Berbanding laporan Approved sebelumnya'),
        ];
    }
}
