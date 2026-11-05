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
     * Berat tulen (pure) Used Gold at HQ TANPA baris YS/KIV - utk graf Used Gold tersedia vs Hutang Supplier.
     * Baris YS/KIV = (a) ketulenan varian bernama "... YS" / "... KIV" (cth. "916 - YS", "916 - KIV") dan (b) baris tambahan bagi
     * ketulenan asas yg sama selepas slot asas pertama (cth. baris kedua "9999" - set tetap borang cuma satu
     * slot per ketulenan asas, selebihnya baris istimewa, rujuk PhysicalGoldReportLineMapper::mergeGradedRows()).
     * TIDAK menjejaskan netPureWeight() (jumlah rasmi laporan masih termasuk YS).
     */
    public static function usedGoldPureWeightExcludingYs(PhysicalGoldReport $report): float
    {
        $seenBaseSlots = [];
        $total = 0.0;

        foreach ($report->lines->sortBy('id') as $line) {
            if ($line->category?->code !== 'USED_GOLD_HQ') {
                continue;
            }

            $purity = $line->purity;

            if ($purity && preg_match('/\b(YS|KIV)\b/i', (string) $purity->code)) {
                continue;
            }

            if ($purity?->is_base_purity) {
                if (isset($seenBaseSlots[$purity->id])) {
                    continue;
                }

                $seenBaseSlots[$purity->id] = true;
            }

            $total += (float) ($line->pure_weight ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Sumbangan bersih (pure) setiap kategori kpd netPureWeight() - formula SAMA (include_in_physical_total,
     * payable/receivable, is_deduction), jadi jumlah semua nilai === netPureWeight(). Kunci = nama kategori.
     *
     * @return array<string, float>
     */
    public static function categoryContributions(PhysicalGoldReport $report): array
    {
        $contributions = [];

        foreach ($report->lines as $line) {
            $category = $line->category;

            if (! $category || ! $category->include_in_physical_total) {
                continue;
            }

            if ($category->value_mode === PhysicalGoldCategory::VALUE_MODE_PAYABLE_RECEIVABLE) {
                $value = (float) ($line->receivable_pure_weight ?? 0) - (float) ($line->payable_pure_weight ?? 0);
            } else {
                $pure = (float) ($line->pure_weight ?? 0);
                $value = $category->is_deduction ? -$pure : $pure;
            }

            $contributions[$category->name] = ($contributions[$category->name] ?? 0.0) + $value;
        }

        return array_map(fn (float $v) => round($v, 2), $contributions);
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
