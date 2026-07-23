<?php

namespace App\Support;

use App\Models\PhysicalGoldReport;

/**
 * Banding Physical Net Pure Gold (App\Support\PhysicalGoldReportCalculator) vs book balance
 * (App\Support\BookGoldBalanceProvider) - dua metrik ditunjuk berasingan (variance vs
 * net_weight, DAN vs closing_stock) mengikut arahan eksplisit pengguna ("both").
 */
class PhysicalGoldReconciliationCalculator
{
    public const STATUS_GREEN = 'green';

    public const STATUS_YELLOW = 'yellow';

    public const STATUS_RED = 'red';

    public const STATUS_PENDING = 'pending';

    /** @return array<string, mixed> */
    public static function reconcile(PhysicalGoldReport $report): array
    {
        $physicalNetPureGold = PhysicalGoldReportCalculator::netPureWeight($report);
        $book = BookGoldBalanceProvider::forDate($report->report_date);
        $dayOnDayMovement = PhysicalGoldReportCalculator::dayOnDayMovement($report);

        if ($book === null) {
            return [
                'physical_net_pure_gold' => $physicalNetPureGold,
                'book_net_weight' => null,
                'book_closing_stock' => null,
                'book_status' => 'unavailable',
                'variance' => null,
                'variance_pct' => null,
                'variance_vs_closing_stock' => null,
                'variance_vs_closing_stock_pct' => null,
                'day_on_day_movement' => $dayOnDayMovement,
                'status' => self::STATUS_PENDING,
            ];
        }

        // Utama: banding vs net_weight (sudah netkan supplier hutang/overpaid, sepadan dgn
        // kategori SUPPLIER_OUTSTANDING modul ni yg turut dilipat kedlm physical net pure gold).
        $variance = round($physicalNetPureGold - $book['net_weight'], 4);
        $variancePct = static::safePercentage($variance, $book['net_weight'], $physicalNetPureGold);

        // Sekunder/informational: banding vs closing_stock mentah (sebelum pelarasan supplier) -
        // TIADA gred warna berasingan, sekadar rujukan tambahan (arahan eksplisit pengguna: "both").
        $varianceVsClosing = round($physicalNetPureGold - $book['closing_stock'], 4);
        $varianceVsClosingPct = static::safePercentage($varianceVsClosing, $book['closing_stock'], $physicalNetPureGold);

        return [
            'physical_net_pure_gold' => $physicalNetPureGold,
            'book_net_weight' => $book['net_weight'],
            'book_closing_stock' => $book['closing_stock'],
            'book_status' => 'available',
            'variance' => $variance,
            'variance_pct' => $variancePct,
            'variance_vs_closing_stock' => $varianceVsClosing,
            'variance_vs_closing_stock_pct' => $varianceVsClosingPct,
            'day_on_day_movement' => $dayOnDayMovement,
            'status' => static::classify($variancePct),
        ];
    }

    public static function latestSummary(): ?array
    {
        $latest = PhysicalGoldReportCalculator::latestApproved();

        if (! $latest) {
            return null;
        }

        return static::reconcile($latest);
    }

    /** Peratusan bertanda (kekal arah surplus/kekurangan) - selamat drpd bahagi-dgn-sifar. */
    protected static function safePercentage(float $diff, float $bookValue, float $physicalValue): float
    {
        $denominator = abs($bookValue) > 0.0 ? abs($bookValue) : max(abs($physicalValue), 1.0);

        return round(($diff / $denominator) * 100, 2);
    }

    protected static function classify(float $variancePct): string
    {
        $absPct = abs($variancePct);
        $yellow = (float) config('dashboard.physical_gold_balance.reconciliation_yellow_pct', 2.0);
        $red = (float) config('dashboard.physical_gold_balance.reconciliation_red_pct', 5.0);

        return match (true) {
            $absPct >= $red => self::STATUS_RED,
            $absPct >= $yellow => self::STATUS_YELLOW,
            default => self::STATUS_GREEN,
        };
    }
}
