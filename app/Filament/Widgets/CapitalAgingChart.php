<?php

namespace App\Filament\Widgets;

use App\Models\Jemisys\InventoryPiece;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class CapitalAgingChart extends ChartWidget
{
    use HasWidgetShield;
    
    protected static bool $isLazy = false;

    protected ?string $heading = 'Modal Terikut Umur Stok';

    protected function getData(): array
    {
        // retry() toleransi lock sementara (cth. antivirus scan selepas jemisys.db ditulis semula).
        $buckets = Cache::remember('capital_aging_buckets', 3600, function () {
            return retry(6, function () {
                $q = InventoryPiece::onHand()->realVendor();
                $today = now();

                $ranges = [
                    '0-3 bln' => [null, 90],
                    '3-6 bln' => [90, 180],
                    '6-12 bln' => [180, 365],
                    '>12 bln (Dead)' => [365, null],
                ];

                $out = [];
                foreach ($ranges as $label => [$minDays, $maxDays]) {
                    $sub = clone $q;
                    if ($minDays !== null) {
                        $sub->where('PurchDate', '<=', $today->copy()->subDays($minDays));
                    }
                    if ($maxDays !== null) {
                        $sub->where('PurchDate', '>', $today->copy()->subDays($maxDays));
                    }
                    $out[$label] = [
                        'value' => (float) $sub->sum('TotalCost'),
                        'weight' => (float) $sub->sum('GoldWeight') / 1000,
                    ];
                }

                return $out;
            }, 800);
        });

        return [
            'datasets' => [
                [
                    'label' => 'Nilai (RM)',
                    'data' => array_map(fn ($b) => round($b['value'], 0), array_values($buckets)),
                    'backgroundColor' => ['#1D9E75', '#9FE1CB', '#F0997B', '#D85A30'],
                ],
            ],
            'labels' => array_keys($buckets),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        $buckets = Cache::get('capital_aging_buckets');
        $deadValue = $buckets['>12 bln (Dead)']['value'] ?? null;

        if ($deadValue !== null && $deadValue == 0) {
            return '⚠️ Dead stock (>12 bln) papar 0 - ini kemungkinan artifak data windowed (rujuk '.
                'DATA_EXPORT_SPEC.md). Minta snapshot penuh JEMiSys untuk analisis umur yang tepat.';
        }

        return 'Modal (kos) dikelompokkan ikut umur stok sejak tarikh beli.';
    }
}
