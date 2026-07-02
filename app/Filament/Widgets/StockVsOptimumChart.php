<?php

namespace App\Filament\Widgets;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Support\OrderRecommendationCalculator;
use App\Support\SalesVelocityHelper;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

/**
 * Bar chart Stok Semasa vs Stok Optimum (target) per Kategori - 100% drpd data JEMiSys
 * sebenar. target_stock guna formula sama spt OrderRecommendationCalculator (velocity x
 * TARGET_COVER_MONTHS), diagregat pada peringkat Kategori (merentas semua cawangan).
 */
class StockVsOptimumChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'Stok Semasa vs Stok Optimum (per Kategori)';

    protected function getData(): array
    {
        $data = Cache::remember('stock_vs_optimum_by_category', 3600, function () {
            return retry(6, function () {
                $salesWindowDays = SalesVelocityHelper::salesWindowDays();

                $grp = InventoryPiece::query()
                    ->realVendor()
                    ->selectRaw('CategoryCode, '.
                        'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as pieces_sold, '.
                        'SUM(QtyOnHand) as current_stock')
                    ->groupBy('CategoryCode')
                    ->get();

                $categoryNames = Category::pluck('Description', 'CategoryCode');

                return $grp->map(function ($r) use ($salesWindowDays, $categoryNames) {
                    $velocity = SalesVelocityHelper::velocity((int) $r->pieces_sold, $salesWindowDays);
                    $target = SalesVelocityHelper::targetStock($velocity, OrderRecommendationCalculator::TARGET_COVER_MONTHS);

                    return [
                        'label' => $categoryNames[$r->CategoryCode] ?? $r->CategoryCode,
                        'stock' => (int) $r->current_stock,
                        'target' => $target,
                    ];
                })->sortByDesc('stock')->take(12)->values()->toArray();
            }, 800);
        });

        return [
            'datasets' => [
                [
                    'label' => 'Stok Semasa',
                    'data' => array_column($data, 'stock'),
                    'backgroundColor' => '#7F77DD',
                ],
                [
                    'label' => 'Stok Optimum (Target)',
                    'data' => array_column($data, 'target'),
                    'backgroundColor' => '#1D9E75',
                ],
            ],
            'labels' => array_column($data, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        return 'Stok optimum = velocity jualan/bulan x 1.5 bulan sasaran cover (formula sama spt Cadangan Beli). Top 12 kategori ikut stok semasa.';
    }
}
