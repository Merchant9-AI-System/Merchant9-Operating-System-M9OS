<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Trend harga emas JEMiSys (GoldRateByHolderHist) - baca sahaja, live ke 'jemisys'.
 *
 * Fakta data (disahkan 8 Okt 2026): 520k baris, TIADA indeks (heap), lajur TransDate (tarikh sahaja),
 * StoreCode, ClassCode (kelas ketulenan: 375, 585, 750, 916, 916B, 916Ptg, 950, 999, 9999, PAUN, perak, kulit),
 * BuyRate/SellRate (RM per gram - kadar beli/jual kedai) & CurrCost (kos semasa per gram), CreatedDate (masa
 * kemas kini). BANYAK baris sehari bagi satu (cawangan, kelas) - kadar dikemas kini sepanjang hari (TTDI 9 kali pada 7 Okt),
 * jadi "penutup hari" = baris dgn CreatedDate TERLEWAT hari itu (merentas cawangan yg dipilih). SellRate 0 = tiada kadar jual
 * ditetapkan utk kelas itu (bukan harga RM0) - dilayan sbg "tiada".
 *
 * Keselamatan query (rujuk AI_AGENTS_PLAN.md): tapisan tarikh wajib, WITH (NOLOCK), kelas disenarai putih (parameter),
 * had kelas & julat, had masa. Jadual tanpa indeks -> setiap query mengimbas ~520k baris (pantas bila cache panas; ~13s sejuk).
 */
class GoldRateAnalytics
{
    public const MAX_CLASSES = 5;

    /** Julat maksimum utk granulariti harian (titik/kelas ~ 92); mingguan/bulanan ikut had umum 366 hari. */
    public const MAX_DAILY_RANGE_DAYS = 92;

    public const TIMEOUT_SECONDS = 60;

    private const CONNECTION = 'jemisys';

    /**
     * @param  array<int, string>  $classCodes
     * @param  'day'|'week'|'month'  $groupBy
     * @return array<string, mixed>
     */
    public function trend(string $dateFrom, string $dateTo, array $classCodes, ?string $storeCode, string $groupBy): array
    {
        SalesAnalytics::assertDateRange($dateFrom, $dateTo);

        $classCodes = self::normaliseClassCodes($classCodes);

        if ($groupBy === 'day' && Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) > self::MAX_DAILY_RANGE_DAYS) {
            throw new InvalidArgumentException('group_by=day hanya untuk julat sehingga '.self::MAX_DAILY_RANGE_DAYS.' hari - guna week atau month untuk julat lebih panjang.');
        }

        $from = Carbon::parse($dateFrom)->toDateString();
        $toExclusive = Carbon::parse($dateTo)->addDay()->toDateString();
        $store = $storeCode ? strtoupper(trim($storeCode)) : null;

        $placeholders = implode(',', array_fill(0, count($classCodes), '?'));
        $bindings = [$from, $toExclusive, ...$classCodes];
        $storeFilter = '';

        if ($store) {
            $storeFilter = ' AND UPPER(RTRIM(StoreCode)) = ?';
            $bindings[] = $store;
        }

        $partition = 'PARTITION BY CONVERT(char(10), TransDate, 23), RTRIM(ClassCode)';

        $rows = SqlServerQueryTimeout::run(self::CONNECTION, fn () => DB::connection(self::CONNECTION)->select(
            "SELECT d, cls, buy_close, sell_close, cost_close, buy_min, buy_max, cost_min, cost_max, updates FROM (
                SELECT CONVERT(char(10), TransDate, 23) AS d,
                       RTRIM(ClassCode) AS cls,
                       BuyRate AS buy_close, SellRate AS sell_close, CurrCost AS cost_close,
                       MIN(BuyRate) OVER ({$partition}) AS buy_min, MAX(BuyRate) OVER ({$partition}) AS buy_max,
                       MIN(CurrCost) OVER ({$partition}) AS cost_min, MAX(CurrCost) OVER ({$partition}) AS cost_max,
                       COUNT(*) OVER ({$partition}) AS updates,
                       ROW_NUMBER() OVER ({$partition} ORDER BY CreatedDate DESC, StoreCode) AS rn
                FROM GoldRateByHolderHist WITH (NOLOCK)
                WHERE TransDate >= ? AND TransDate < ? AND RTRIM(ClassCode) IN ({$placeholders}){$storeFilter}
            ) AS x
            WHERE rn = 1
            ORDER BY cls, d",
            $bindings,
        ), self::TIMEOUT_SECONDS);

        return [
            'class_codes' => $classCodes,
            'store_code' => $store,
            'group_by' => $groupBy,
            'classes' => self::fromRows(array_map(fn ($r) => [
                'date' => (string) $r->d,
                'class_code' => trim((string) $r->cls),
                'buy_close' => (float) $r->buy_close,
                'sell_close' => (float) $r->sell_close,
                'cost_close' => (float) $r->cost_close,
                'buy_min' => (float) $r->buy_min,
                'buy_max' => (float) $r->buy_max,
                'cost_min' => (float) $r->cost_min,
                'cost_max' => (float) $r->cost_max,
                'updates' => (int) $r->updates,
            ], $rows), $groupBy),
        ];
    }

    /**
     * Kelas dibersihkan & dihadkan: huruf/nombor/ruang sahaja (kod sebenar cth. "916", "916Ptg", "960 Silver"), unik, maks MAX_CLASSES.
     *
     * @param  array<int, string>  $classCodes
     * @return array<int, string>
     */
    public static function normaliseClassCodes(array $classCodes): array
    {
        $codes = collect($classCodes)
            ->map(fn ($c) => trim((string) $c))
            ->filter(fn (string $c) => $c !== '')
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            throw new InvalidArgumentException('Sekurang-kurangnya satu class_code diperlukan (cth. "916").');
        }

        if ($codes->count() > self::MAX_CLASSES) {
            throw new InvalidArgumentException('Maksimum '.self::MAX_CLASSES.' class_code sekali panggil.');
        }

        foreach ($codes as $code) {
            if (! preg_match('/^[A-Za-z0-9 ]{1,20}$/', $code)) {
                throw new InvalidArgumentException("class_code tidak sah: {$code}");
            }
        }

        return $codes->all();
    }

    /**
     * Logik tanpa database (boleh diuji terus): kumpul baris harian (penutup + julat intrahari) kepada hari/minggu/bulan
     * & kira ringkasan perubahan setiap kelas.
     *
     * @param  array<int, array{date: string, class_code: string, buy_close: float, sell_close: float, cost_close: float, buy_min: float, buy_max: float, cost_min: float, cost_max: float, updates: int}>  $daily
     * @return array<int, array<string, mixed>>
     */
    public static function fromRows(array $daily, string $groupBy): array
    {
        $byClass = [];

        foreach ($daily as $row) {
            $byClass[$row['class_code']][] = $row;
        }

        $classes = [];

        foreach ($byClass as $class => $rows) {
            usort($rows, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

            $periods = [];

            foreach ($rows as $row) {
                $key = match ($groupBy) {
                    'week' => Carbon::parse($row['date'])->startOfWeek()->toDateString(),
                    'month' => substr($row['date'], 0, 7),
                    default => $row['date'],
                };

                if (! isset($periods[$key])) {
                    $periods[$key] = ['period' => $key, 'days' => 0, 'updates' => 0, 'buy_lows' => [], 'buy_highs' => [], 'cost_lows' => [], 'cost_highs' => []];
                }

                // Baris disusun menaik -> yg terakhir menimpa = penutup tempoh.
                $periods[$key]['buy_close'] = self::rate($row['buy_close']);
                $periods[$key]['sell_close'] = self::rate($row['sell_close']);
                $periods[$key]['cost_close'] = self::rate($row['cost_close']);
                $periods[$key]['days']++;
                $periods[$key]['updates'] += $row['updates'];
                $periods[$key]['buy_lows'][] = $row['buy_min'];
                $periods[$key]['buy_highs'][] = $row['buy_max'];
                $periods[$key]['cost_lows'][] = $row['cost_min'];
                $periods[$key]['cost_highs'][] = $row['cost_max'];
            }

            $points = array_values(array_map(function (array $p) {
                $buyClose = $p['buy_close'];
                $costClose = $p['cost_close'];

                return [
                    'period' => $p['period'],
                    'days_with_rates' => $p['days'],
                    'updates' => $p['updates'],
                    'buy_close' => $buyClose,
                    'sell_close' => $p['sell_close'],
                    'cost_close' => $costClose,
                    'buy_low' => self::rate(self::minPositive($p['buy_lows'])),
                    'buy_high' => self::rate(max($p['buy_highs'])),
                    'cost_low' => self::rate(self::minPositive($p['cost_lows'])),
                    'cost_high' => self::rate(max($p['cost_highs'])),
                    // Beza kos semasa & kadar beli (RM/gram) - jurang antara kos dan harga kedai membeli.
                    'cost_minus_buy' => $buyClose !== null && $costClose !== null ? round($costClose - $buyClose, 2) : null,
                ];
            }, $periods));

            $classes[] = [
                // (string): kunci tatasusunan bernombor ('916') bertukar jadi int dlm PHP - kod kelas mesti kekal teks.
                'class_code' => (string) $class,
                'points' => $points,
                'summary' => self::summarise($points),
            ];
        }

        return $classes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     * @return array<string, mixed>
     */
    private static function summarise(array $points): array
    {
        $first = $points[0] ?? null;
        $last = $points[count($points) - 1] ?? null;

        $change = function (?float $from, ?float $to): array {
            if ($from === null || $to === null || $from <= 0) {
                return ['change' => null, 'change_pct' => null];
            }

            return ['change' => round($to - $from, 2), 'change_pct' => round(($to - $from) / $from * 100, 2)];
        };

        return [
            'first_period' => $first['period'] ?? null,
            'last_period' => $last['period'] ?? null,
            'buy' => ['first' => $first['buy_close'] ?? null, 'last' => $last['buy_close'] ?? null] + $change($first['buy_close'] ?? null, $last['buy_close'] ?? null),
            'cost' => ['first' => $first['cost_close'] ?? null, 'last' => $last['cost_close'] ?? null] + $change($first['cost_close'] ?? null, $last['cost_close'] ?? null),
            'buy_period_high' => $points ? max(array_column($points, 'buy_high')) : null,
            'buy_period_low' => $points ? self::minPositive(array_column($points, 'buy_low')) : null,
            'cost_period_high' => $points ? max(array_column($points, 'cost_high')) : null,
            'cost_period_low' => $points ? self::minPositive(array_column($points, 'cost_low')) : null,
        ];
    }

    /** 0 (atau negatif) = tiada kadar ditetapkan -> null. */
    private static function rate(float|int|null $value): ?float
    {
        return $value !== null && $value > 0 ? round((float) $value, 2) : null;
    }

    /**
     * @param  array<int, float|int|null>  $values
     */
    private static function minPositive(array $values): ?float
    {
        $positive = array_filter($values, fn ($v) => $v !== null && $v > 0);

        return $positive ? (float) min($positive) : null;
    }
}
