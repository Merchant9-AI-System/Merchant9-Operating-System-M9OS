<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Facades\Cache;

/**
 * CEO Dashboard Phase 1 (C) - Capital Locked Trend summary cards. SENGAJA guna cache key
 * SAMA ('capital_aging_buckets') & logik bucket SAMA spt App\Filament\Widgets\CapitalAgingChart
 * (widget tu TIDAK diubah) - supaya nombor kedua-dua widget sentiasa konsisten walaupun kod
 * berasingan (tiada risiko sentuh widget chart sedia ada).
 *
 * Tiada jadual snapshot sejarah (cth. daily/monthly inventory_snapshots) wujud dlm app ni lagi -
 * trend bulan-ke-bulan TIDAK boleh dikira drpd data sedia ada. Papar mesej jelas, JANGAN anggar.
 */
class CapitalAgingCalculator
{
    public const HAS_HISTORICAL_DATA = false;

    /** @return array<string, array{value: float, weight: float}> */
    public static function buckets(): array
    {
        return Cache::remember('capital_aging_buckets', 3600, function () {
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
