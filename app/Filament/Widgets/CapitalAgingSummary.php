<?php

namespace App\Filament\Widgets;

use App\Support\CapitalAgingCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * CEO Dashboard Phase 1 (C) - kad ringkasan DI BAWAH chart "Modal Terikut Umur Stok" sedia ada
 * (CapitalAgingChart, widget itu TIDAK diubah). Rujuk CapitalAgingCalculator (cache key sama
 * dgn chart sedia ada supaya nombor konsisten).
 */
class CapitalAgingSummary extends StatsOverviewWidget
{
    use HasWidgetShield;
    
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $summary = CapitalAgingCalculator::summary();
        $buckets = $summary['buckets'];

        $fmt = fn (float $v) => 'RM '.number_format($v, 0);

        return [
            Stat::make('Stok 0-3 Bulan', $fmt($buckets['0-3 bln']['value'] ?? 0)),
            Stat::make('Stok 3-6 Bulan', $fmt($buckets['3-6 bln']['value'] ?? 0)),
            Stat::make('Stok 6-12 Bulan', $fmt($buckets['6-12 bln']['value'] ?? 0)),
            Stat::make('Stok >12 Bulan', $fmt($buckets['>12 bln (Dead)']['value'] ?? 0))
                ->color('danger'),
            Stat::make('Dead Stock (RM)', $fmt($summary['dead_value']))
                ->description('Trend bulan-ke-bulan tidak tersedia lagi - tiada data snapshot sejarah.')
                ->color('danger'),
            Stat::make('Dead Stock (%)', number_format($summary['dead_pct'], 1).'%')
                ->description('% drpd jumlah nilai stok semasa (onHand).')
                ->color($summary['dead_pct'] >= 15 ? 'danger' : 'gray'),
        ];
    }
}
