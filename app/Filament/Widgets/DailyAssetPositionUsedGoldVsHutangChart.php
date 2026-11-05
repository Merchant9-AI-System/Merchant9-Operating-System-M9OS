<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Used Gold tersedia vs Hutang Supplier (gram) - rujuk DailyAssetPositionCalculator::usedGoldVsHutangTrend().
 */
class DailyAssetPositionUsedGoldVsHutangChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Used Gold Tersedia vs Hutang Supplier (gram)';

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
        $trend = DailyAssetPositionCalculator::usedGoldVsHutangTrend($this->daysForFilter());

        return [
            'datasets' => [
                [
                    'label' => 'Used Gold at HQ tanpa YS/KIV (g tulen)',
                    'data' => $trend->pluck('used_gold')->all(),
                    'borderColor' => '#1D9E75',
                    'backgroundColor' => '#1D9E75',
                    'spanGaps' => true,
                ],
                [
                    'label' => 'Hutang Supplier (g)',
                    'data' => $trend->pluck('supplier_hutang')->all(),
                    'borderColor' => '#D85A30',
                    'backgroundColor' => '#D85A30',
                    'spanGaps' => true,
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
        $trend = DailyAssetPositionCalculator::usedGoldVsHutangTrend($this->daysForFilter());

        if ($trend->isEmpty()) {
            return 'Tiada rekod Daily Asset Position atau Physical Gold Report Approved dlm tempoh ni.';
        }

        $used = $trend->pluck('used_gold')->filter(fn ($v) => $v !== null)->last();
        $hutang = $trend->pluck('supplier_hutang')->filter(fn ($v) => $v !== null)->last();

        $text = 'Used Gold (tanpa baris YS/KIV) drpd Physical Gold Report Approved (tak harian), hutang drpd rekod harian accountant.';

        if ($used !== null && $hutang !== null) {
            $text .= ' Terkini: Used Gold '.number_format($used, 2).'g vs hutang '.number_format($hutang, 2).'g ('
                .($used >= $hutang ? 'cukup' : 'kurang '.number_format($hutang - $used, 2).'g').').';
        }

        return $text;
    }
}
