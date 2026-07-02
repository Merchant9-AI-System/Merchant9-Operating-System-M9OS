<?php

namespace App\Filament\Widgets;

use App\Models\Jemisys\InventoryPiece;
use App\Support\RearrangeCalculator;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Amaran tindakan segera. Stockout count guna cache/query sama definisi spt InventoryKpiStats
 * (retry+cache sendiri, consistent dgn widget lain) - bukan baca cache widget lain scr rapuh.
 */
class ActionAlerts extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $stockoutCount = Cache::remember('action_alerts_stockout_count', 3600, function () {
            return retry(6, function () {
                return InventoryPiece::realVendor()
                    ->selectRaw('InternalCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, SUM(QtyOnHand) as stock')
                    ->groupBy('InternalCode')
                    ->havingRaw('sold >= 3 AND stock = 0')
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
