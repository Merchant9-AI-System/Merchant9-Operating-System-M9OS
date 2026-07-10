<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * CEO Dashboard Phase 1 (C) - Capital Locked Trend summary cards. Cache key
 * ('capital_aging_buckets') dikongsi dgn App\Filament\Widgets\CapitalAgingChart - widget tu
 * panggil buckets() terus (bukan lagi salin logik berasingan) supaya nombor kedua-dua tempat
 * sentiasa konsisten drpd SATU sumber, bukan dua kopi kod yg boleh terpisah lain kelak.
 *
 * Tiada jadual snapshot sejarah (cth. daily/monthly inventory_snapshots) wujud dlm app ni lagi -
 * trend bulan-ke-bulan TIDAK boleh dikira drpd data sedia ada. Papar mesej jelas, JANGAN anggar.
 */
class CapitalAgingCalculator
{
    public const HAS_HISTORICAL_DATA = false;

    /** @var array<string, array{0: ?int, 1: ?int}> */
    private const RANGES = [
        '0-3 bln' => [null, 90],
        '3-6 bln' => [90, 180],
        '6-12 bln' => [180, 365],
        '>12 bln (Dead)' => [365, null],
    ];

    /** @return array<string, array{value: float, weight: float}> */
    public static function buckets(): array
    {
        return Cache::rememberForever('capital_aging_buckets', function () {
            return retry(6, function () {
                // SATU query CASE-bucketed (bukan 4 clone + 8 sum() berasingan) - kesemua 4
                // umur & 2 metrik (TotalCost/GoldWeight) dikira serentak dlm satu table scan,
                // bukan 8 scan berasingan atas 481K baris setiap cache refresh/jam.
                [$selectSql, $bindings] = static::buildBucketSelectSql();

                $row = InventoryPiece::onHand()->realVendor()
                    ->selectRaw($selectSql, $bindings)
                    ->first();

                $out = [];
                foreach (array_keys(self::RANGES) as $i => $label) {
                    $out[$label] = [
                        'value' => (float) ($row->{"value_{$i}"} ?? 0),
                        'weight' => (float) ($row->{"weight_{$i}"} ?? 0) / 1000,
                    ];
                }

                return $out;
            }, 800);
        });
    }

    /** @return array{0: string, 1: array<int, Carbon>} */
    private static function buildBucketSelectSql(): array
    {
        $today = now();
        $selects = [];
        $bindings = [];

        foreach (array_values(self::RANGES) as $i => [$minDays, $maxDays]) {
            $conditions = [];
            $bucketBindings = [];

            if ($minDays !== null) {
                $conditions[] = 'PurchDate <= ?';
                $bucketBindings[] = $today->copy()->subDays($minDays);
            }

            if ($maxDays !== null) {
                $conditions[] = 'PurchDate > ?';
                $bucketBindings[] = $today->copy()->subDays($maxDays);
            }

            $when = implode(' AND ', $conditions);
            $selects[] = "SUM(CASE WHEN {$when} THEN TotalCost ELSE 0 END) as value_{$i}";
            $selects[] = "SUM(CASE WHEN {$when} THEN GoldWeight ELSE 0 END) as weight_{$i}";

            // $when digunakan DUA kali (value_i & weight_i) - setiap placeholder "?" nya
            // muncul dua kali dlm SQL akhir, jadi bindings kena diulang jugak (bukan sekali)
            // supaya kiraan "?" sepadan kiraan binding, ikut turutan.
            array_push($bindings, ...$bucketBindings, ...$bucketBindings);
        }

        return [implode(', ', $selects), $bindings];
    }

    /** @return array{buckets: array, total_value: float, dead_value: float, dead_pct: float} */
    public static function summary(): array
    {
        $buckets = static::buckets();
        $totalValue = array_sum(array_column($buckets, 'value'));
        $deadValue = $buckets['>12 bln (Dead)']['value'] ?? 0.0;
        $deadPct = $totalValue > 0 ? round(($deadValue / $totalValue) * 100, 1) : 0.0;

        return [
            'buckets' => $buckets,
            'total_value' => $totalValue,
            'dead_value' => $deadValue,
            'dead_pct' => $deadPct,
        ];
    }
}
