<?php

namespace App\Filament\Widgets;

use App\Support\DailyHedgeFigure;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Hedge harian (gram tulen) = (berat jualan semua cawangan x 0.935) - (berat buyback TI+TC x 0.900),
 * rujuk App\Support\DailyHedgeFigure & config dashboard.hedge. Bar = hedge; garis = jualan & buyback tulen.
 * Query LIVE ke JEMiSys (cache 15 minit) - gagal = mesej, bukan ralat.
 */
class DailyHedgeChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Hedge Harian (gram tulen): Jualan - Buyback';

    public ?string $filter = 'thirty_days';

    protected function getFilters(): ?array
    {
        return [
            'week' => '1 Minggu',
            'thirty_days' => '30 Hari',
            'ninety_days' => '90 Hari',
        ];
    }

    protected function daysForFilter(): int
    {
        return match ($this->filter) {
            'week' => 7,
            'ninety_days' => 90,
            default => 30,
        };
    }

    /** @return Collection<int, array<string, float|string>>|null null = JEMiSys tak dapat dicapai. */
    protected function series(): ?Collection
    {
        try {
            return app(DailyHedgeFigure::class)->dailySeries(
                now()->subDays($this->daysForFilter() - 1)->toDateString(),
                now()->toDateString(),
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function getData(): array
    {
        $series = $this->series() ?? collect();

        return [
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Hedge (g tulen)',
                    'data' => $series->pluck('hedge_pure_weight')->all(),
                    'backgroundColor' => '#B45309',
                    'borderColor' => '#B45309',
                    'order' => 2,
                ],
                [
                    'type' => 'line',
                    'label' => 'Jualan x '.config('dashboard.hedge.sales_purity').' (g)',
                    'data' => $series->pluck('sales_pure_weight')->all(),
                    'borderColor' => '#1D9E75',
                    'backgroundColor' => '#1D9E75',
                    'order' => 1,
                ],
                [
                    'type' => 'line',
                    'label' => 'Buyback x '.config('dashboard.hedge.buyback_purity').' (g)',
                    'data' => $series->pluck('buyback_pure_weight')->all(),
                    'borderColor' => '#D85A30',
                    'backgroundColor' => '#D85A30',
                    'order' => 1,
                ],
            ],
            'labels' => $series->pluck('date')->map(fn (string $d) => Carbon::parse($d)->format('d/m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        $series = $this->series();

        if ($series === null) {
            return 'Data jualan/buyback tidak dapat diambil drpd JEMiSys sekarang - cuba lagi sebentar.';
        }

        if ($series->isEmpty()) {
            return 'Tiada jualan atau buyback dlm tempoh ni.';
        }

        $latest = $series->last();
        $isToday = $latest['date'] === now()->toDateString();

        return 'Hedge = jualan (semua cawangan) x '.config('dashboard.hedge.sales_purity').' - buyback (TI+TC) x '.config('dashboard.hedge.buyback_purity')
            .'. Terkini ('.Carbon::parse($latest['date'])->format('d/m').($isToday ? ', hari ini - separa' : '').'): '
            .number_format($latest['hedge_pure_weight'], 2).'g (jualan '.number_format($latest['sales_weight'], 2)
            .'g, buyback '.number_format($latest['buyback_weight'], 2).'g).';
    }
}
