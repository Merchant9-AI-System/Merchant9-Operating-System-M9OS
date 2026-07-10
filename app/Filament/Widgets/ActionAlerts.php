<?php

namespace App\Filament\Widgets;

use App\Models\Jemisys\InventoryPiece;
use App\Support\RearrangeCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Amaran tindakan segera. Stockout count guna cache/query sama definisi spt InventoryKpiStats
 * (retry+cache sendiri, consistent dgn widget lain) - bukan baca cache widget lain scr rapuh.
 */
class ActionAlerts extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $stockoutCount = Cache::rememberForever('action_alerts_stockout_count', function () {
            return retry(6, function () {
                // havingRaw kena ulang expression penuh, bukan alias 'sold'/'stock' - SQLite/MySQL
                // benarkan HAVING rujuk alias SELECT, tapi SQL Server tak (throw "Invalid column name").
                return InventoryPiece::realVendor()
                    ->selectRaw('InternalCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, SUM(QtyOnHand) as stock')
                    ->groupBy('InternalCode')
                    ->havingRaw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) >= 3 AND SUM(QtyOnHand) = 0')
                    ->get()
                    ->count();
            }, 800);
        });

        $rearrangeCount = RearrangeCalculator::recommendations()->count();

        return [
            Stat::make('Best-seller Sold Out', (string) $stockoutCount)
                ->description('Design pernah laku tapi kini stok=0')
                ->color('danger'),

            Stat::make('Design Perlu Rearrange', (string) $rearrangeCount)
                ->description('Cadangan pindah stok antara cawangan')
                ->color('warning'),
        ];
    }
}
