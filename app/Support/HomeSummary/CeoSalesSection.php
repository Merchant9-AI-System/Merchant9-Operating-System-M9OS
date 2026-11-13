<?php

namespace App\Support\HomeSummary;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Jualan semalam utk CEO: jualan (dgn trend 8 hari & perbandingan purata 7 hari), nilai jualan, hedge (dgn trend)
 * dan ranking cawangan.
 */
class CeoSalesSection extends CeoSectionBase
{
    public function key(): string
    {
        return 'ceo_sales';
    }

    public function title(): string
    {
        return 'Jualan Semalam';
    }

    public function build(User $user, Carbon $date): array
    {
        $report = $this->snapshot->report($date);
        $series = $this->snapshot->series($date);
        $storeSeries = $this->snapshot->storeSeries($date);

        $items = $report === null
            ? [
                self::unavailable('sales', 'Jualan semalam'),
                self::unavailable('sales_value', 'Nilai jualan'),
                self::unavailable('hedge', 'Hedge semalam'),
            ]
            : [
                self::salesItem($report, $series, $date),
                self::salesValueItem($report),
                self::hedgeItem($report, $series),
            ];

        $result = ['items' => self::markHero($items)];

        if ($report !== null && $storeSeries !== null) {
            $result['ranking'] = self::storeRanking($report, $storeSeries, $date->toDateString());
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  Collection<int, array<string, float|string>>|null  $series
     * @return array<string, mixed>
     */
    private static function salesItem(array $report, ?Collection $series, Carbon $date): array
    {
        $weight = (float) $report['sales_weight'];
        $average = $series === null ? null : self::previousAverageWeight($series, $date->toDateString());

        $comparison = null;
        $delta = null;
        $tone = 'default';

        if ($average !== null && $average > 0) {
            $pct = ($weight - $average) / $average * 100;
            $tone = $pct >= 0 ? 'good' : 'warn';
            // Cip delta (cth. "+1.3%") di sebelah nombor; hint kekal memuatkan perbandingan penuh.
            $delta = ['text' => ($pct >= 0 ? '+' : '-').number_format(abs($pct), 1).'%', 'tone' => $tone];
            $comparison = ($pct >= 0 ? '+' : '-').number_format(abs($pct), 1).'% vs purata 7 hari ('.number_format($average, 2).' g)';
        }

        return [
            'key' => 'sales',
            'label' => 'Jualan semalam',
            'value' => number_format((float) $report['totals']['qty']).' pcs · '.number_format($weight, 2).' g',
            'hint' => $comparison,
            'tone' => $tone,
            'delta' => $delta,
            'trend' => self::trend($series, 'sales_weight'),
            'trend_labels' => self::trendLabels($series),
        ];
    }

    /** @param  array<string, mixed>  $report */
    private static function salesValueItem(array $report): array
    {
        $gpPct = $report['totals']['gp_pct'];

        return [
            'key' => 'sales_value',
            'label' => 'Nilai jualan',
            'value' => 'RM'.number_format((float) $report['totals']['total_price'], 2),
            'hint' => $gpPct !== null ? 'GP '.number_format((float) $gpPct, 1).'%' : null,
            'tone' => 'default',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  Collection<int, array<string, float|string>>|null  $series
     * @return array<string, mixed>
     */
    private static function hedgeItem(array $report, ?Collection $series): array
    {
        return [
            'key' => 'hedge',
            'label' => 'Hedge semalam',
            'value' => number_format((float) $report['hedge_pure_weight'], 2).' g tulen',
            'hint' => 'Jualan '.number_format((float) $report['sales_weight'], 2).' g x '.$report['sales_purity']
                .' − buyback '.number_format((float) $report['buyback_weight'], 2).' g x '.$report['buyback_purity'],
            'tone' => 'default',
            'trend' => self::trend($series, 'hedge_pure_weight'),
            'trend_labels' => self::trendLabels($series),
        ];
    }

    /**
     * Nilai siri harian utk sparkline - kosong kalau kurang 2 titik (tiada trend utk dilukis).
     *
     * @param  Collection<int, array<string, float|string>>|null  $series
     * @return array<int, float>
     */
    private static function trend(?Collection $series, string $field): array
    {
        if ($series === null || $series->count() < 2) {
            return [];
        }

        return $series->sortBy('date')->pluck($field)->map(fn ($v) => round((float) $v, 2))->values()->all();
    }

    /**
     * Label tarikh (d/m) bagi setiap titik trend - selari dgn trend(); kosong kalau kurang 2 titik.
     *
     * @param  Collection<int, array<string, float|string>>|null  $series
     * @return array<int, string>
     */
    private static function trendLabels(?Collection $series): array
    {
        if ($series === null || $series->count() < 2) {
            return [];
        }

        return $series->sortBy('date')->pluck('date')->map(fn ($d) => Carbon::parse($d)->format('d/m'))->values()->all();
    }

    /**
     * Purata berat jualan (g) bagi sehingga 7 hari SEBELUM tarikh itu - hari tanpa jualan/buyback tak disenaraikan
     * dlm siri, jadi ia dikira ikut hari yang ada. Null kalau tiada hari sebelumnya.
     *
     * @param  Collection<int, array<string, float|string>>  $series
     */
    public static function previousAverageWeight(Collection $series, string $date): ?float
    {
        $previous = $series->filter(fn (array $r) => $r['date'] < $date)->sortBy('date')->take(-7);

        return $previous->isEmpty() ? null : (float) $previous->avg('sales_weight');
    }

    /**
     * Ranking cawangan semalam ikut berat jualan, dgn perbandingan purata 7 hari SENDIRI setiap cawangan.
     *
     * @param  array<string, mixed>  $report  DailyHedgeFigure::forDate()
     * @param  Collection<int, array{date: string, store: string, sales_weight: float}>  $storeSeries
     * @return array{title: string, rows: array<int, array<string, mixed>>}
     */
    public static function storeRanking(array $report, Collection $storeSeries, string $date): array
    {
        $yesterday = [];
        foreach ($report['stores'] as $store => $types) {
            $yesterday[(string) $store] = [
                'weight' => (float) collect($types)->sum('gold_weight'),
                'price' => (float) collect($types)->sum('total_price'),
            ];
        }

        $history = $storeSeries->filter(fn (array $r) => $r['date'] < $date)->groupBy('store');
        $stores = array_values(array_unique([...array_keys($yesterday), ...$history->keys()->all()]));

        $rows = collect($stores)->map(function (string $store) use ($yesterday, $history) {
            $weight = $yesterday[$store]['weight'] ?? 0.0;
            $price = $yesterday[$store]['price'] ?? 0.0;
            $average = $history->has($store) ? (float) $history[$store]->sortBy('date')->take(-7)->avg('sales_weight') : null;
            $pct = $average !== null && $average > 0 ? ($weight - $average) / $average * 100 : null;

            return [
                'weight' => $weight,
                'label' => $store,
                'value' => number_format($weight, 2).' g',
                'sub' => 'RM'.number_format($price, 0).($pct !== null ? ' · '.($pct >= 0 ? '+' : '-').number_format(abs($pct), 1).'% vs purata 7 hari' : ''),
                'tone' => $pct === null ? 'default' : ($pct >= 0 ? 'good' : 'warn'),
            ];
        })->sortByDesc('weight')->values();

        $max = (float) $rows->max('weight');

        return [
            'title' => 'Ranking cawangan semalam',
            'rows' => $rows->map(fn (array $row) => [
                'label' => $row['label'],
                'value' => $row['value'],
                'sub' => $row['sub'],
                'tone' => $row['tone'],
                'bar' => $max > 0 ? (int) round($row['weight'] / $max * 100) : 0,
            ])->all(),
        ];
    }
}
