<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Baki "Used Gold" drpd sistem LUARAN berasingan (API kedaiemas, URL dlm config services.kedaiemas -
 * BUKAN sebahagian m9os; dikira drpd jadual transactions/transaction_items mereka, bukan lagi
 * Google Sheet/n8n). Endpoint perlukan header X-Internal-Token (config services.kedaiemas).
 * "Total Closing" = jumlah baki tutup semua karat bulan berkenaan, guna 916_net (bukan
 * 916_gross/916_batu - net dah netkan berat batu drpd gross, elak kira karat 916 dua kali) -
 * disahkan pengguna.
 */
class UsedGoldBalanceProvider
{
    /** 916_gross & 916_batu SENGAJA tak masuk - 916_net dah wakili karat 916 (gross - batu). */
    protected const KARATS_FOR_TOTAL = ['9999', '999', '950', '916_net', '800', '750', '585', '375'];

    /**
     * Kod karat API luar => kod ketulenan m9os (App\Models\PhysicalGoldPurity) - "800" API lama
     * ialah "835" m9os, SEKADAR label berbeza antara dua sistem, BUKAN karat berbeza (disahkan
     * pengguna). "916_net" (bukan 916_gross/916_batu) utk "916" - rujuk nota KARATS_FOR_TOTAL.
     */
    protected const KARAT_TO_PURITY_CODE = [
        '9999' => '9999',
        '999' => '999',
        '950' => '950',
        '916_net' => '916',
        '800' => '835',
        '750' => '750',
        '585' => '585',
        '375' => '375',
    ];

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
        $closing = static::cachedClosing($month ??= now()->format('Y-m'));

        if ($closing === null) {
            return null;
        }

        return round(collect(self::KARATS_FOR_TOTAL)->sum(fn ($karat) => (float) ($closing[$karat] ?? 0)), 3);
    }

    /**
     * Pecahan baki closing ikut kod ketulenan m9os (utk "Tarik Data Used Gold" - Used Gold at HQ)
     * - rujuk KARAT_TO_PURITY_CODE utk padanan kod. Null bermaksud endpoint luar gagal (SAMA
     * makna spt totalClosing() - BUKAN sifar).
     *
     * @return array<string, float>|null kod ketulenan m9os => baki closing (g)
     */
    public static function closingByPurity(?string $month = null): ?array
    {
        $closing = static::cachedClosing($month ??= now()->format('Y-m'));

        if ($closing === null) {
            return null;
        }

        $result = [];

        foreach (self::KARAT_TO_PURITY_CODE as $karat => $purityCode) {
            $result[$purityCode] = (float) ($closing[$karat] ?? 0);
        }

        return $result;
    }

    /** @return array<string, float>|null */
    protected static function cachedClosing(string $month): ?array
    {
        return Cache::remember("used_gold_balance_closing:{$month}", now()->addMinutes(15), fn () => static::fetchClosing($month));
    }

    /** @return array<string, float>|null */
    protected static function fetchClosing(string $month): ?array
    {
        $url = (string) config('services.kedaiemas.used_gold_balance_url');
        $token = (string) config('services.kedaiemas.internal_token');

        if ($url === '' || $token === '') {
            Log::warning('UsedGoldBalanceProvider: KEDAIEMAS_USED_GOLD_BALANCE_URL / KEDAIEMAS_INTERNAL_TOKEN belum diset.');

            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Internal-Token' => $token])
                ->acceptJson()
                ->get($url, ['month' => $month]);

            if (! $response->successful() || ! $response->json('success')) {
                return null;
            }

            // Tiada baris opening utk bulan tsb di kedaiemas (ug_records) -> baki closing tak boleh dipercayai
            // (opening dikira 0), jadi layan sbg "tiada data" (null), bukan papar angka salah.
            if ($response->json('opening_found') === false) {
                Log::warning("UsedGoldBalanceProvider: kedaiemas tiada baris opening (ug_records) utk {$month}.");

                return null;
            }

            return $response->json('closing');
        } catch (\Throwable $e) {
            Log::warning("UsedGoldBalanceProvider: gagal fetch closing utk {$month} - {$e->getMessage()}");

            return null;
        }
    }
}
