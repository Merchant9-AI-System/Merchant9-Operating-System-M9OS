<?php

namespace App\Filament\Widgets;

use App\Support\CapitalAgingCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class CapitalAgingChart extends ChartWidget
{
    use HasWidgetShield;

    // Data guna Cache::rememberForever() - hanya berubah lepas sync/warm eksplisit, BUKAN
    // saban 5 saat (lalai Filament). Poll saban 5s sia-sia & bebankan server tanpa faedah.
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Modal Terikut Umur Stok';

    // protected string | array | int $columnSpan = 'full';

    protected function getData(): array
    {
        $buckets = CapitalAgingCalculator::buckets();

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
