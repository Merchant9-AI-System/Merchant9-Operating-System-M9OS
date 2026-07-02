<?php

namespace App\Filament\Widgets;

use App\Models\Jemisys\InventoryPiece;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class InventoryKpiStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        // Cache 10 minit - scan penuh inventori (39k+ baris), sama pendekatan spt app.py _cached().
        // retry() toleransi lock sementara (cth. antivirus scan selepas jemisys.db ditulis semula).
        $m = Cache::remember('inventory_kpi_stats', 3600, function () {
            return retry(6, function () {
                $q = InventoryPiece::onHand()->realVendor();

                return [
                    'value' => (clone $q)->sum('TotalCost'),
                    'weight_kg' => (clone $q)->sum('GoldWeight') / 1000,
                    'dead_value' => (clone $q)->where('PurchDate', '<', now()->subDays(365))->sum('TotalCost'),
                    'total_value' => (clone $q)->sum('TotalCost'),
                    // TIADA physicalStore() di sini - sepadan definisi asal (disahkan): sold-out
                    // dikira merentas SEMUA saluran (fizikal + web), lain daripada Rearrange yang
                    // sengaja kecualikan web (kerana rearrange ialah logik pindah cawangan FIZIKAL).
                    'stockout_proven' => InventoryPiece::realVendor()
                        ->selectRaw('InternalCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, SUM(QtyOnHand) as stock')
                        ->groupBy('InternalCode')
                        ->havingRaw('sold >= 3 AND stock = 0')
                        ->get()
                        ->count(),
                ];
            }, 800);
        });

        $deadPct = $m['total_value'] > 0 ? ($m['dead_value'] / $m['total_value']) * 100 : 0;

        return [
            Stat::make('Nilai Stok', 'RM '.number_format($m['value'] / 1_000_000, 2).'j')
                ->description('Modal dalam stok (kos)')
                ->color('primary'),

            Stat::make('Emas Dipegang', number_format($m['weight_kg'], 1).' kg')
                ->description('Berat emas stok semasa')
                ->color('warning'),

            Stat::make('Dead Stock', number_format($deadPct, 1).'%')
                ->description('RM '.number_format($m['dead_value'] / 1_000_000, 2).'j terikat (>12 bln)')
                ->color($deadPct > 15 ? 'danger' : ($deadPct > 5 ? 'warning' : 'success')),

            Stat::make('Best-seller Sold Out', (string) $m['stockout_proven'])
                ->description('Design pernah laku (>=3) tapi stok=0')
                ->color($m['stockout_proven'] > 0 ? 'danger' : 'success'),
        ];
    }
}
