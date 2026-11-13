<?php

namespace App\Support\HomeSummary;

use App\Support\DailyHedgeFigure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Data jualan/buyback semalam (JEMiSys, LIVE) utk halaman ringkasan selepas log masuk - dikongsi semua
 * seksyen & semua pengguna, jadi query JEMiSys berjalan SEKALI sehari (cache), bukan setiap pengguna buka
 * halaman. Kegagalan (JEMiSys tak dapat dicapai) -> null (bukan 0), dan TIDAK dicache supaya cubaan
 * seterusnya cuba lagi.
 */
class YesterdaySnapshot
{
    /** TTL cache - cukup panjang utk satu hari kerja; data semalam tak berubah selepas segerak pagi. */
    private const TTL_HOURS = 6;

    /** "Semalam" ikut waktu Malaysia - config app.timezone ialah UTC (tanpa ini tersilap hari pada waktu pagi). */
    public static function date(): Carbon
    {
        return Carbon::now('Asia/Kuala_Lumpur')->subDay()->startOfDay();
    }

    /**
     * Laporan jualan & buyback satu hari (DailyHedgeFigure::forDate) - null kalau JEMiSys gagal.
     *
     * @return array<string, mixed>|null
     */
    public function report(Carbon $date): ?array
    {
        return $this->remember('report:'.$date->toDateString(), fn () => app(DailyHedgeFigure::class)->forDate($date->toDateString()));
    }

    /**
     * Siri harian 8 hari berakhir pada $date (7 hari sebelum + hari itu) utk banding dgn purata - null kalau gagal.
     *
     * @return Collection<int, array<string, float|string>>|null
     */
    public function series(Carbon $date): ?Collection
    {
        $rows = $this->remember(
            'series:'.$date->toDateString(),
            fn () => app(DailyHedgeFigure::class)->dailySeries($date->copy()->subDays(7)->toDateString(), $date->toDateString())->all(),
        );

        return $rows === null ? null : collect($rows);
    }

    /**
     * Berat jualan setiap cawangan, 8 hari berakhir pada $date - null kalau gagal.
     *
     * @return Collection<int, array{date: string, store: string, sales_weight: float}>|null
     */
    public function storeSeries(Carbon $date): ?Collection
    {
        $rows = $this->remember(
            'store_series:'.$date->toDateString(),
            fn () => app(DailyHedgeFigure::class)->storeSeries($date->copy()->subDays(7)->toDateString(), $date->toDateString())->all(),
        );

        return $rows === null ? null : collect($rows);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T|null
     */
    private function remember(string $key, \Closure $callback): mixed
    {
        $cacheKey = 'home_summary:'.$key;

        try {
            return Cache::remember($cacheKey, now()->addHours(self::TTL_HOURS), $callback);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
