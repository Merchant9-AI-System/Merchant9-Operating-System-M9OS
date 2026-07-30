<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Baki "Used Gold" drpd sistem LUARAN berasingan (endpoint PHP legasi di
 * merchant9.kedaiemas.my - BUKAN sebahagian m9os, backend MySQL + n8n/Google Drive sendiri).
 * "Total Closing" = jumlah baki tutup semua karat bulan berkenaan, guna 916_net (bukan
 * 916_gross/916_batu - net dah netkan berat batu drpd gross, elak kira karat 916 dua kali) -
 * disahkan pengguna.
 */
class UsedGoldBalanceProvider
{
    protected const ENDPOINT = 'https://merchant9.kedaiemas.my/internal/ApiUsedGoldBalance.php';

    /** 916_gross & 916_batu SENGAJA tak masuk - 916_net dah wakili karat 916 (gross - batu). */
    protected const KARATS_FOR_TOTAL = ['9999', '999', '950', '925', '916_net', '800', '750', '585', '375'];

    /**
     * Null bermaksud endpoint luaran tak dpt dicapai/gagal - BUKAN sifar (jgn papar RM0/0g palsu).
     * Cache pendek (15 minit, bukan rememberForever) sbb baki ni berubah sepanjang bulan bila
     * transaksi baru dimasukkan drpd sistem luaran tsb - TIDAK ada event utk kita flush bila ia
     * berubah, jadi TTL pendek sahaja cara kita nampak kemas kini dlm masa munasabah. Kegagalan
     * (null) sengaja TIDAK dibalut sentinel spt BookGoldBalanceProvider - di sini kita MAHU cubaan
     * seterusnya cuba fetch semula secepat mungkin (bukan cache "gagal" selama 15 minit jua).
     */
    public static function totalClosing(?string $month = null): ?float
    {
        $month ??= now()->format('Y-m');

        return Cache::remember("used_gold_balance_total_closing:{$month}", now()->addMinutes(15), function () use ($month) {
            $closing = static::fetchClosing($month);

            if ($closing === null) {
                return null;
            }

            return round(collect(self::KARATS_FOR_TOTAL)->sum(fn ($karat) => (float) ($closing[$karat] ?? 0)), 3);
        });
    }

    /** @return array<string, float>|null */
    protected static function fetchClosing(string $month): ?array
    {
        try {
            $response = Http::timeout(15)->get(self::ENDPOINT, ['month' => $month]);

            if (! $response->successful() || ! $response->json('success')) {
                return null;
            }

            return $response->json('closing');
        } catch (\Throwable $e) {
            Log::warning("UsedGoldBalanceProvider: gagal fetch closing utk {$month} - {$e->getMessage()}");

            return null;
        }
    }
}
