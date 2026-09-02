<?php

namespace App\Support;

use App\Models\DailyAssetPosition;
use App\Models\PhysicalGoldReport;
use Illuminate\Support\Collection;

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
        $variance = round($physicalNetPureGold - $book['net_weight'], 2);
        $variancePct = static::safePercentage($variance, $book['net_weight'], $physicalNetPureGold);

        // Sekunder/informational: banding vs closing_stock mentah (sebelum pelarasan supplier) -
        // TIADA gred warna berasingan, sekadar rujukan tambahan (arahan eksplisit pengguna: "both").
        $varianceVsClosing = round($physicalNetPureGold - $book['closing_stock'], 2);
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

    /**
     * Siri Book Balance (DailyAssetPosition.net_weight) vs Physical Balance (Physical Net Pure
     * Gold drpd laporan Approved sahaja) - satu baris per tarikh yg ADA sekurang-kurangnya satu
     * sumber, nilai yg tiada kekal null (jurang carta, bukan 0 palsu atau bawa-ke-hadapan).
     *
     * @return Collection<int, array{date: string, book_net_weight: float|null, physical_net_pure_gold: float|null}>
     */
    public static function bookVsPhysicalTrend(int $days): Collection
    {
        $since = now()->subDays($days)->startOfDay();

        $bookByDate = DailyAssetPosition::query()
            ->where('entry_date', '>=', $since)
            ->get()
            ->keyBy(fn (DailyAssetPosition $r) => $r->entry_date->toDateString())
            ->map(fn (DailyAssetPosition $r) => (float) $r->net_weight);

        $physicalByDate = PhysicalGoldReport::query()
            ->where('status', PhysicalGoldReport::STATUS_APPROVED)
            ->where('report_date', '>=', $since)
            ->with('lines.category')
            ->get()
            ->keyBy(fn (PhysicalGoldReport $r) => $r->report_date->toDateString())
            ->map(fn (PhysicalGoldReport $r) => PhysicalGoldReportCalculator::netPureWeight($r));

        return $bookByDate->keys()
            ->merge($physicalByDate->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $date) => [
                'date' => $date,
                'book_net_weight' => $bookByDate->get($date),
                'physical_net_pure_gold' => $physicalByDate->get($date),
            ]);
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
