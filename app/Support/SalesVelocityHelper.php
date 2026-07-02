<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;

/**
 * Helper dikongsi utk kira "tempoh jualan" (sales window) & velocity - logik ni disahkan
 * 100% padan Python procurement_report.py selepas debug teliti (rujuk memori projek).
 *
 * AMARAN PENTING (jgn ulang bug): Carbon::diffInDays() pulang FLOAT, WAJIB (int) cast
 * supaya padan Python pandas Timedelta.days (sentiasa int/floor). Round guna
 * PHP_ROUND_HALF_EVEN (banker's rounding) utk padan pandas .round().
 */
class SalesVelocityHelper
{
    /** Bilangan hari dlm tempoh jualan (window_start..as_of) - dikira drpd data realVendor() sahaja. */
    public static function salesWindowDays(): int
    {
        $window = InventoryPiece::query()->realVendor()
            ->selectRaw('MIN(SalesDate) as window_start, MAX(SalesDate) as as_of')
            ->first();

        if (! $window->window_start || ! $window->as_of) {
            return 1;
        }

        $asOf = \Carbon\Carbon::parse($window->as_of);
        $windowStart = \Carbon\Carbon::parse($window->window_start);

        return max((int) $windowStart->diffInDays($asOf), 1);
    }

    /** velocity = pieces_sold / (sales_window_days / 30), bundar 2dp (banker's rounding). */
    public static function velocity(int $piecesSold, int $salesWindowDays): float
    {
        return round($piecesSold / ($salesWindowDays / 30), 2, PHP_ROUND_HALF_EVEN);
    }

    public static function sellThroughRate(int $piecesSold, int $piecesReceived): float
    {
        return $piecesReceived > 0 ? round($piecesSold / $piecesReceived, 3, PHP_ROUND_HALF_EVEN) : 0.0;
    }

    /** target_stock (bulat) = velocity * target_cover_months, banker's rounding sepadan pandas .round(0). */
    public static function targetStock(float $velocity, float $targetCoverMonths): int
    {
        return (int) round($velocity * $targetCoverMonths, 0, PHP_ROUND_HALF_EVEN);
    }
}
