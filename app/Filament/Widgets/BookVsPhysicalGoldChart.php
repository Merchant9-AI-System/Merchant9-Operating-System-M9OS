<?php

namespace App\Filament\Widgets;

use App\Support\PhysicalGoldReconciliationCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Banding Book Balance (DailyAssetPosition.net_weight) vs Physical Balance (Physical Net Pure
 * Gold drpd PhysicalGoldReport Approved) merentasi tempoh - rujuk App\Support\
 * PhysicalGoldReconciliationCalculator::bookVsPhysicalTrend() utk logik jurang/tarikh sebenar.
 */
class BookVsPhysicalGoldChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Book Balance vs Physical Balance Gold';

    public ?string $filter = 'thirty_days';

    protected function getFilters(): ?array
    {
        return [
            'week' => '1 Minggu',
            'thirty_days' => '30 Hari',
            'ninety_days' => '90 Hari',
            'six_months' => '6 Bulan',
            'year' => '1 Tahun',
        ];
    }

    protected function daysForFilter(): int
    {
        return match ($this->filter) {
            'week' => 7,
            'ninety_days' => 90,
            'six_months' => 182,
            'year' => 365,
            default => 30,
        };
    }

    protected function getData(): array
    {
        $trend = PhysicalGoldReconciliationCalculator::bookVsPhysicalTrend($this->daysForFilter());

        return [
            'datasets' => [
                [
                    'label' => 'Book Balance (Daily Asset Position)',
                    'data' => $trend->pluck('book_net_weight')->all(),
                    'borderColor' => '#1D9E75',
                    'backgroundColor' => '#1D9E75',
                    'spanGaps' => false,
                ],
                [
                    'label' => 'Physical Balance (Gold Report)',
                    'data' => $trend->pluck('physical_net_pure_gold')->all(),
                    'borderColor' => '#B45309',
                    'backgroundColor' => '#B45309',
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
        return PhysicalGoldReconciliationCalculator::bookVsPhysicalTrend($this->daysForFilter())->isEmpty()
            ? 'Tiada rekod Daily Asset Position atau Physical Gold Report Approved dlm tempoh ni.'
            : 'Jurang pd carta bermaksud tiada rekod utk tarikh tsb (bukan sifar).';
    }
}
