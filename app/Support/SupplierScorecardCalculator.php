<?php

namespace App\Support;

use App\Models\Jemisys\Vendor;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Prestasi supplier dikira drpd data PO+GRN SEBENAR (bukan snapshot JEMiSys) - tiada jadual baru.
 * Kosong/0 sehingga ada PO sebenar dicipta & diterima (expected, bukan bug).
 */
class SupplierScorecardCalculator
{
    public static function scorecard(): Collection
    {
        return collect(Cache::remember('supplier_scorecard', 3600, function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        }));
    }

    protected static function compute(): Collection
    {
        $pos = PurchaseOrder::with('lines')
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->get()
            ->groupBy('vendor_code');

        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        return $pos->map(function ($vendorPos, $vendorCode) use ($vendorNames) {
            $totalOrdered = $vendorPos->flatMap->lines->sum('qty_ordered');
            $totalReceived = $vendorPos->flatMap->lines->sum('qty_received');
            $totalSpend = $vendorPos->flatMap->lines->sum(fn ($l) => $l->qty_ordered * $l->unit_cost);

            // Lead time: purata (received_at PALING AWAL GRN - approved_at) utk PO yg dah Received sepenuhnya.
            $leadTimes = $vendorPos
                ->filter(fn ($po) => $po->status === PurchaseOrder::STATUS_RECEIVED && $po->approved_at)
                ->map(function ($po) {
                    $firstReceipt = $po->goodsReceipts()->orderBy('received_at')->first();

                    return $firstReceipt ? $po->approved_at->diffInDays($firstReceipt->received_at) : null;
                })->filter(fn ($d) => $d !== null);

            return [
                'vendor_code' => $vendorCode,
                'vendor_name' => $vendorNames[$vendorCode] ?? $vendorCode,
                'total_po' => $vendorPos->count(),
                'total_spend' => $totalSpend,
                'total_ordered' => $totalOrdered,
                'total_received' => $totalReceived,
                'fill_rate' => $totalOrdered > 0 ? round(($totalReceived / $totalOrdered) * 100, 1) : null,
                'avg_lead_time_days' => $leadTimes->isNotEmpty() ? round($leadTimes->avg(), 1) : null,
                'po_received_count' => $leadTimes->count(),
            ];
        })->sortByDesc('total_spend')->values();
    }
}
