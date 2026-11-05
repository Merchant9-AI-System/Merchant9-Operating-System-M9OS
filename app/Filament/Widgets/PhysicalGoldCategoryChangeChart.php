<?php

namespace App\Filament\Widgets;

use App\Support\PhysicalGoldReconciliationCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Perubahan (gram) setiap kategori Physical Gold berbanding laporan Approved pertama dlm tempoh -
 * menjawab "apa yang turun". Rujuk PhysicalGoldReconciliationCalculator::categoryTrend().
 */
class PhysicalGoldCategoryChangeChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Physical Gold - Perubahan Setiap Kategori vs Opening (gram)';

    public ?string $filter = 'thirty_days';

    /** @var array<int, string> */
    private const COLORS = ['#1E3A8A', '#B45309', '#1D9E75', '#D85A30', '#7C3AED', '#6B7280'];

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
        $trend = PhysicalGoldReconciliationCalculator::categoryTrend($this->daysForFilter());

        $names = $trend->flatMap(fn (array $r) => array_keys($r['changes']))->unique()->values();

        $datasets = $names->map(fn (string $name, int $i) => [
            'label' => $name,
            'data' => $trend->map(fn (array $r) => $r['changes'][$name] ?? 0.0)->all(),
            'borderColor' => self::COLORS[$i % count(self::COLORS)],
            'backgroundColor' => self::COLORS[$i % count(self::COLORS)],
        ])->all();

        $datasets[] = [
            'label' => 'JUMLAH Physical Net Pure Gold',
            'data' => $trend->pluck('total_change')->all(),
            'borderColor' => '#FBBF24',
            'backgroundColor' => '#FBBF24',
            'borderWidth' => 4,
        ];

        return [
            'datasets' => $datasets,
            'labels' => $trend->pluck('date')->map(fn (string $d) => Carbon::parse($d)->format('d/m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        $trend = PhysicalGoldReconciliationCalculator::categoryTrend($this->daysForFilter());

        if ($trend->count() < 2) {
            return 'Perlu sekurang-kurangnya 2 Physical Gold Report Approved dlm tempoh utk lihat perubahan.';
        }

        $latest = $trend->last();
        $worst = collect($latest['changes'])->sort()->take(1);
        $name = $worst->keys()->first();
        $value = $worst->first();

        $text = 'Opening = laporan Approved pertama dlm tempoh (0g).';

        if ($value !== null && $value < 0) {
            $text .= " Kategori paling turun: {$name} (-".number_format(abs($value), 2).'g).';
        }

        return $text;
    }
}
