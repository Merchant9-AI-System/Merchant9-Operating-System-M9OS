<?php

namespace App\Filament\Widgets;

use App\Support\PhysicalGoldReconciliationCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Beza (gram) Physical Balance & Book Balance berbanding opening tempoh - jelas berapa gram sebenarnya
 * naik/turun sejak permulaan tempoh. Rujuk PhysicalGoldReconciliationCalculator::differenceFromOpening().
 */
class PhysicalGoldDifferenceFromOpeningChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Beza Physical Gold & Book Balance vs Opening (gram)';

    public ?string $filter = 'thirty_days';

    protected function getFilters(): ?array
    {
        return [
            'week' => '1 Minggu',
            'thirty_days' => '30 Hari',
            'ninety_days' => '90 Hari',
            'six_months' => '6 Bulan',
        ];
    }

    protected function daysForFilter(): int
    {
        return match ($this->filter) {
            'week' => 7,
            'ninety_days' => 90,
            'six_months' => 182,
            default => 30,
        };
    }

    protected function getData(): array
    {
        $trend = PhysicalGoldReconciliationCalculator::differenceFromOpening($this->daysForFilter());

        return [
            'datasets' => [
                [
                    'label' => 'Physical Gold vs opening (g)',
                    'data' => $trend->pluck('physical_change')->all(),
                    'borderColor' => '#B45309',
                    'backgroundColor' => '#B45309',
                    'spanGaps' => true,
                ],
                [
                    'label' => 'Book Balance vs opening (g)',
                    'data' => $trend->pluck('book_change')->all(),
                    'borderColor' => '#1D9E75',
                    'backgroundColor' => '#1D9E75',
                    'spanGaps' => false,
                ],
            ],
            'labels' => $trend->pluck('date')->map(fn (string $d) => Carbon::parse($d)->format('d/m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        $trend = PhysicalGoldReconciliationCalculator::differenceFromOpening($this->daysForFilter());

        if ($trend->isEmpty()) {
            return 'Tiada rekod Daily Asset Position atau Physical Gold Report Approved dlm tempoh ni.';
        }

        $physical = $trend->pluck('physical_change')->filter(fn ($v) => $v !== null)->last();
        $book = $trend->pluck('book_change')->filter(fn ($v) => $v !== null)->last();

        $format = fn (?float $v): string => $v === null ? '-' : ($v >= 0 ? '+' : '-').number_format(abs($v), 2).'g';

        return 'Opening = rekod pertama yg ada dlm tempoh (0g). Terkini: Physical '.$format($physical).', Book '.$format($book).'. Jurang = tiada rekod tarikh tsb.';
    }
}
