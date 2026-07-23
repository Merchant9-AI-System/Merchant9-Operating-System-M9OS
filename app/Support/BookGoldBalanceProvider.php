<?php

namespace App\Support;

use App\Models\DailyAssetPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "Book gold balance" utk modul Physical Gold Balance - TIADA "Digital Gold Account Model"
 * berasingan wujud dlm sistem ni (disahkan drpd penyiasatan penuh), DailyAssetPosition
 * (ledger dikeyin accountant) ialah analog sebenar yg paling hampir, jadi ia digunakan terus
 * sbg sumber book balance - bukan sumber fiksyen baru.
 */
class BookGoldBalanceProvider
{
    /**
     * Null (bukan 0) bila tiada rekod DailyAssetPosition utk tarikh berkenaan - "belum tersedia",
     * BUKAN sifar. Dibalut dgn sentinel 'available' semasa caching (bukan pulangkan null terus
     * drpd closure) sbb Cache::remember()/rememberForever() anggap nilai null tersimpan sbg
     * cache MISS pd bacaan seterusnya (punca bug asal ProductImageFetcher sesi ni) - closure
     * akan re-run setiap kali kalau kita biarkan null terus jadi nilai cache.
     */
    public static function forDate(string|Carbon $date): ?array
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $cached = Cache::rememberForever(
            "book_gold_balance:{$dateString}",
            fn () => retry(6, fn () => static::computeSentinel($dateString), 800)
        );

        return $cached['available'] ? $cached['data'] : null;
    }

    /** @return array{available: bool, data: array{entry_date: Carbon, net_weight: float, closing_stock: float, source_id: int}|null} */
    protected static function computeSentinel(string $dateString): array
    {
        $entry = DailyAssetPosition::where('entry_date', $dateString)->first();

        if (! $entry) {
            return ['available' => false, 'data' => null];
        }

        return [
            'available' => true,
            'data' => [
                'entry_date' => $entry->entry_date,
                'net_weight' => (float) $entry->net_weight,
                'closing_stock' => (float) $entry->closing_stock,
                'source_id' => $entry->id,
            ],
        ];
    }
}
