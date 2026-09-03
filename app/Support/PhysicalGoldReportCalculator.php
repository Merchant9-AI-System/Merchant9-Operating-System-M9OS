<?php

namespace App\Support;

use App\Models\PhysicalGoldCategory;
use App\Models\PhysicalGoldReport;
use Illuminate\Support\Collection;

/**
 * Pengiraan drpd baris satu PhysicalGoldReport - TIDAK pernah mengubah/menyimpan apa-apa,
 * baca-sahaja drpd relationship lines() yg sedia dimuat.
 */
class PhysicalGoldReportCalculator
{
    public static function netPureWeight(PhysicalGoldReport $report): float
    {
        $net = 0.0;

        foreach ($report->lines as $line) {
            $category = $line->category;

            if (! $category || ! $category->include_in_physical_total) {
                continue;
            }

            if ($category->value_mode === PhysicalGoldCategory::VALUE_MODE_PAYABLE_RECEIVABLE) {
                // Disahkan oleh pengguna: Payable menolak, Receivable menambah.
                $net += (float) ($line->receivable_pure_weight ?? 0) - (float) ($line->payable_pure_weight ?? 0);

                continue;
            }

            $pure = (float) ($line->pure_weight ?? 0);
            $net += $category->is_deduction ? -$pure : $pure;
        }

        return round($net, 2);
    }

    /**
     * Kategori mod "payable_receivable" (Outstanding Gold Due to Suppliers) TIDAK PERNAH isi
     * gross_weight/pure_weight generik (baris tsb simpan payable_gross_weight/
     * receivable_gross_weight sebaliknya, rujuk PhysicalGoldReportLineMapper::
     * syncLinesFromFormState()) - sum(gross_weight) x kategori tsb akan SENYAP pulangkan 0,
     * walaupun baris sebenar ADA data (disahkan pengguna - Ringkasan Kategori papar 0.00
     * sedangkan seksyen Outstanding penuh data). Netkan receivable-payable SAMA formula spt
     * netPureWeight() (BUKAN check is_deduction - payable_receivable branch tsb turut x check).
     */
    public static function grossWeightTotal(PhysicalGoldReport $report): float
    {
        $total = 0.0;

        foreach ($report->lines as $line) {
            $category = $line->category;

            if ($category?->value_mode === PhysicalGoldCategory::VALUE_MODE_PAYABLE_RECEIVABLE) {
                $total += (float) ($line->receivable_gross_weight ?? 0) - (float) ($line->payable_gross_weight ?? 0);

                continue;
            }

            $total += (float) ($line->gross_weight ?? 0);
        }

        return round($total, 2);
    }

    /** @return Collection<int, array{category: PhysicalGoldCategory, gross_weight: float, pure_weight: float}> */
    public static function categoryBreakdown(PhysicalGoldReport $report): Collection
    {
        return $report->lines
            ->groupBy('physical_gold_category_id')
            ->map(function ($lines) {
                $category = $lines->first()->category;

                if ($category?->value_mode === PhysicalGoldCategory::VALUE_MODE_PAYABLE_RECEIVABLE) {
                    return [
                        'category' => $category,
                        'gross_weight' => round((float) $lines->sum(fn ($l) => (float) ($l->receivable_gross_weight ?? 0) - (float) ($l->payable_gross_weight ?? 0)), 2),
                        'pure_weight' => round((float) $lines->sum(fn ($l) => (float) ($l->receivable_pure_weight ?? 0) - (float) ($l->payable_pure_weight ?? 0)), 2),
                    ];
                }

                return [
                    'category' => $category,
                    'gross_weight' => round((float) $lines->sum(fn ($l) => (float) ($l->gross_weight ?? 0)), 2),
                    'pure_weight' => round((float) $lines->sum(fn ($l) => (float) ($l->pure_weight ?? 0)), 2),
                ];
            })
            ->values();
    }

    public static function latestApproved(): ?PhysicalGoldReport
    {
        return PhysicalGoldReport::query()
            ->where('status', PhysicalGoldReport::STATUS_APPROVED)
            ->orderByDesc('report_date')
            ->first();
    }

    public static function previousApproved(PhysicalGoldReport $report): ?PhysicalGoldReport
    {
        return PhysicalGoldReport::query()
            ->where('status', PhysicalGoldReport::STATUS_APPROVED)
            ->where('report_date', '<', $report->report_date)
            ->orderByDesc('report_date')
            ->first();
    }

    /** Null (bukan 0) bila tiada laporan diluluskan sebelum ini - "tiada data", bukan "tiada pergerakan". */
    public static function dayOnDayMovement(PhysicalGoldReport $report): ?float
    {
        $previous = static::previousApproved($report);

        if (! $previous) {
            return null;
        }

        return round(static::netPureWeight($report) - static::netPureWeight($previous), 2);
    }
}
