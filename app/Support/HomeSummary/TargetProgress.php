<?php

namespace App\Support\HomeSummary;

use Illuminate\Support\Carbon;

/**
 * Kemajuan sasaran bulanan (M07) - pengiraan tulen tanpa pangkalan data/JEMiSys, jadi mudah diuji.
 *
 * "Sebenar" ialah jualan sehingga $through (hari LENGKAP terakhir, iaitu semalam - hari ini belum habis supaya tak
 * menjejaskan purata). Hari berlalu = tarikh $through dlm bulan itu; baki hari = hari dlm bulan - hari berlalu
 * (termasuk hari ini). Ramalan = purata harian x hari dlm bulan (garis lurus; belum mengambil kira musim/hujung minggu).
 * Bulan lalu (through melepasi hujung bulan): semua hari dikira, baki hari 0 -> keputusan akhir.
 */
class TargetProgress
{
    /**
     * @param  array{rm: float, grams: float}  $target
     * @param  array{rm: float, grams: float}  $actual
     * @return array{days_in_month: int, days_elapsed: int, days_left: int, rm: array<string, float|bool|null>, grams: array<string, float|bool|null>}
     */
    public static function compute(array $target, array $actual, Carbon $monthStart, Carbon $through): array
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $daysInMonth = $monthStart->daysInMonth;
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        $elapsed = match (true) {
            $through->copy()->startOfDay()->lt($monthStart) => 0,
            $through->copy()->startOfDay()->gte($monthEnd) => $daysInMonth,
            default => $through->day,
        };

        $left = max($daysInMonth - $elapsed, 0);

        return [
            'days_in_month' => $daysInMonth,
            'days_elapsed' => $elapsed,
            'days_left' => $left,
            'rm' => self::metric((float) $target['rm'], (float) $actual['rm'], $elapsed, $left, $daysInMonth),
            'grams' => self::metric((float) $target['grams'], (float) $actual['grams'], $elapsed, $left, $daysInMonth),
        ];
    }

    /** @return array<string, float|bool|null> */
    private static function metric(float $target, float $actual, int $elapsed, int $left, int $daysInMonth): array
    {
        $remaining = max($target - $actual, 0.0);
        $runRate = $elapsed > 0 ? $actual / $elapsed : null;
        $forecast = $runRate !== null ? $runRate * $daysInMonth : null;

        return [
            'target' => round($target, 2),
            'actual' => round($actual, 2),
            'pct' => $target > 0 ? round($actual / $target * 100, 1) : null,
            'remaining' => round($remaining, 2),
            'run_rate' => $runRate !== null ? round($runRate, 2) : null,
            // Purata sehari yg diperlukan utk capai sasaran - null bila tiada baki hari (bulan sudah tamat).
            'needed_per_day' => $left > 0 ? round($remaining / $left, 2) : null,
            'forecast' => $forecast !== null ? round($forecast, 2) : null,
            'forecast_pct' => $forecast !== null && $target > 0 ? round($forecast / $target * 100, 1) : null,
            'on_track' => $forecast !== null && $target > 0 ? $forecast >= $target : null,
        ];
    }
}
