<?php

namespace App\Support;

use App\Models\DailyAssetPosition;
use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Ringkasan & reconciliation utk modul "Daily Company Asset Position" (data dikeyin accountant,
 * jadual sendiri - BUKAN JEMiSys). Reconciliation di sini SEKADAR baca & banding (read-only) -
 * TIADA pelarasan automatik inventori JEMiSys, TIADA post accounting entry (rujuk arahan asal).
 *
 * NOTA JUJUR: JEMiSys tiada konsep snapshot harian (cuma "stok skrg"), jadi mapping di bawah
 * ialah proksi terbaik yg boleh (usaha munasabah), bukan padanan tepat 100%:
 * - "Sales weight" JEMiSys = SUM(GoldWeight) WHERE SalesDate = tarikh berkenaan
 * - "New stock" JEMiSys    = SUM(GoldWeight) WHERE PurchDate = tarikh berkenaan
 * - "Closing stock" JEMiSys = jumlah stok ON HAND SEKARANG (bukan snapshot pd tarikh tsb) -
 *   hanya bermakna bila dibanding dgn rekod accountant TERKINI, bukan tarikh lampau.
 */
class DailyAssetPositionCalculator
{
    public const STATUS_GREEN = 'green';

    public const STATUS_YELLOW = 'yellow';

    public const STATUS_RED = 'red';

    public static function latestEntry(): ?DailyAssetPosition
    {
        return DailyAssetPosition::query()->orderByDesc('entry_date')->first();
    }

    /** @return array<string, mixed>|null */
    public static function summary(): ?array
    {
        $latest = static::latestEntry();
        if (! $latest) {
            return null;
        }

        $yesterday = DailyAssetPosition::where('entry_date', now()->subDay()->toDateString())->first() ?? $latest;

        $stockMovementDifference = round(abs((float) $latest->closing_stock - $latest->calculateClosingStock()), 3);
        $totalCashBank = round(
            (float) $latest->ambank_balance + (float) $latest->affin_balance
            + (float) $latest->cash + (float) $latest->affin_rm,
            2
        );

        $reconciliation = static::reconciliation();
        $overallDiffPct = $reconciliation['closing_stock']['diff_pct'] ?? null;

        return [
            'entry_date' => $latest->entry_date,
            'yesterday_sales_weight' => (float) $yesterday->sales,
            'closing_stock_weight' => (float) $latest->closing_stock,
            'net_weight' => (float) $latest->net_weight,
            'total_cash_bank' => $totalCashBank,
            'available_cash' => (float) $latest->available_cash,
            'supplier_hutang' => (float) $latest->supplier_hutang,
            'supplier_overpaid' => (float) $latest->supplier_overpaid,
            'stock_movement_difference' => $stockMovementDifference,
            'loss_from_melting' => (float) $latest->loss_from_melting,
            'jemisys_vs_accountant_diff_pct' => $overallDiffPct,
        ];
    }

    /**
     * Siri harian (menaik ikut tarikh) utk chart trend - N hari kebelakangan drpd rekod terkini.
     *
     * @return Collection<int, array{entry_date: Carbon, closing_stock: float, sales: float, available_cash: float, supplier_hutang: float, supplier_overpaid: float}>
     */
    public static function trend(int $days = 30): Collection
    {
        return DailyAssetPosition::query()
            ->orderByDesc('entry_date')
            ->limit($days)
            ->get()
            ->sortBy('entry_date')
            ->values()
            ->map(fn (DailyAssetPosition $r) => [
                'entry_date' => $r->entry_date,
                'closing_stock' => (float) $r->closing_stock,
                'sales' => (float) $r->sales,
                'available_cash' => (float) $r->available_cash,
                'supplier_hutang' => (float) $r->supplier_hutang,
                'supplier_overpaid' => (float) $r->supplier_overpaid,
            ]);
    }

    /**
     * Banding rekod accountant TERKINI vs proksi JEMiSys - rujuk NOTA JUJUR atas kelas ni.
     *
     * @return array<string, array{label: string, jemisys: float|null, accountant: float, diff: float|null, diff_pct: float|null, status: string}>
     */
    public static function reconciliation(): array
    {
        $latest = static::latestEntry();
        if (! $latest) {
            return [];
        }

        return Cache::remember('daily_asset_position_reconciliation', 3600, function () use ($latest) {
            return retry(6, fn () => static::compute($latest), 800);
        });
    }

    protected static function compute(DailyAssetPosition $latest): array
    {
        $date = $latest->entry_date->toDateString();

        $jemisysSales = (float) InventoryPiece::query()->realVendor()
            ->whereDate('SalesDate', $date)->sum('GoldWeight');

        $jemisysNewStock = (float) InventoryPiece::query()->realVendor()
            ->whereDate('PurchDate', $date)->sum('GoldWeight');

        $jemisysClosingStock = (float) InventoryPiece::query()->onHand()->realVendor()->sum('GoldWeight');

        $jemisysBranchTotal = (float) InventoryPiece::query()->onHand()->realVendor()->physicalStore()->sum('GoldWeight');

        return [
            'sales' => static::compare('Sales Weight', $jemisysSales, (float) $latest->sales),
            'new_stock' => static::compare('New Stock', $jemisysNewStock, (float) $latest->new_stock),
            'closing_stock' => static::compare('Closing Stock', $jemisysClosingStock, (float) $latest->closing_stock),
            'branch_total' => static::compare('Branch Stock Total', $jemisysBranchTotal, (float) $latest->closing_stock),
        ];
    }

    protected static function compare(string $label, float $jemisys, float $accountant): array
    {
        $diff = round($accountant - $jemisys, 3);
        $denominator = abs($jemisys) > 0.0 ? abs($jemisys) : max(abs($accountant), 1.0);
        $diffPct = round((abs($diff) / $denominator) * 100, 2);

        $yellowThreshold = (float) config('dashboard.daily_asset_position.reconciliation_yellow_pct', 2.0);
        $redThreshold = (float) config('dashboard.daily_asset_position.reconciliation_red_pct', 5.0);

        $status = match (true) {
            $diffPct >= $redThreshold => self::STATUS_RED,
            $diffPct >= $yellowThreshold => self::STATUS_YELLOW,
            default => self::STATUS_GREEN,
        };

        return [
            'label' => $label,
            'jemisys' => $jemisys,
            'accountant' => $accountant,
            'diff' => $diff,
            'diff_pct' => $diffPct,
            'status' => $status,
        ];
    }
}
