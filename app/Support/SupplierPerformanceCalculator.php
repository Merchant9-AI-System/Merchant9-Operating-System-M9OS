<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Prestasi supplier 100% drpd data JEMiSys sebenar (TblInventory) - TIADA pergantungan PO/GRN.
 * Jawapan: supplier mana mahal (avg kos seunit), margin tinggi/rendah (drpd SalesAmount barang
 * terjual), dan fast-moving rating (velocity/sell-through).
 *
 * NOTA JUJUR: SalesAmount cuma ~61% terisi dlm data JEMiSys sedia ada - margin_pct dikira
 * HANYA drpd baris yg ada SalesAmount (bukan anggaran keseluruhan). avg_margin_sample_size
 * didedahkan supaya user tahu ketepatan bergantung liputan data.
 */
class SupplierPerformanceCalculator
{
    /** Tempoh "3 bulan terkini" utk % Terjual & Fast-Moving Rating - atas permintaan (bukan sejarah penuh). */
    public const TREND_MONTHS = 3;

    public static function performance(): Collection
    {
        return collect(Cache::rememberForever('supplier_performance_jemisys', function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        }));
    }

    protected static function compute(): Collection
    {
        $trendStart = now()->subMonths(self::TREND_MONTHS);
        $trendWindowDays = max((int) $trendStart->diffInDays(now()), 1);

        $grp = InventoryPiece::query()
            ->realVendor()
            ->selectRaw('VendorCode, '.
                'SUM(CASE WHEN PurchDate >= ? THEN 1 ELSE 0 END) as pieces_received, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(QtyOnHand) as current_stock, '.
                'AVG(TotalCost) as avg_unit_cost, '.
                'SUM(CASE WHEN QtyOnHand=1 THEN TotalCost ELSE 0 END) as stock_value', [$trendStart, $trendStart])
            ->groupBy('VendorCode')
            ->get();

        // Margin: dikira berasingan, HANYA drpd baris SUDAH TERJUAL dgn SalesAmount>0 (~61% liputan).
        $marginRows = InventoryPiece::query()
            ->realVendor()
            ->whereNotNull('SalesDate')
            ->whereNotNull('SalesAmount')
            ->where('SalesAmount', '>', 0)
            ->selectRaw('VendorCode, COUNT(*) as sample_size, '.
                'SUM(SalesAmount) as total_sales, SUM(TotalCost) as total_cost_of_sold')
            ->groupBy('VendorCode')
            ->get()
            ->keyBy('VendorCode');

        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        return $grp->map(function ($r) use ($trendWindowDays, $marginRows, $vendorNames) {
            $velocity = SalesVelocityHelper::velocity((int) $r->pieces_sold, $trendWindowDays);
            $sellThrough = SalesVelocityHelper::sellThroughRate((int) $r->pieces_sold, (int) $r->pieces_received);

            $margin = $marginRows->get($r->VendorCode);
            $marginPct = ($margin && $margin->total_sales > 0)
                ? round((($margin->total_sales - $margin->total_cost_of_sold) / $margin->total_sales) * 100, 1)
                : null;

            return [
                'vendor_code' => $r->VendorCode,
                'vendor_name' => $vendorNames[$r->VendorCode] ?? $r->VendorCode,
                'pieces_received' => (int) $r->pieces_received,
                'pieces_sold' => (int) $r->pieces_sold,
                'current_stock' => (int) $r->current_stock,
                'avg_unit_cost' => round((float) $r->avg_unit_cost, 2),
                'stock_value' => round((float) $r->stock_value, 2),
                'sell_through_rate' => $sellThrough,
                'velocity_per_month' => $velocity,
                'margin_pct' => $marginPct,
                'margin_sample_size' => $margin->sample_size ?? 0,
            ];
        })
            ->filter(fn ($r) => $r['pieces_received'] >= 3) // elak vendor sample terlalu kecil
            ->sortByDesc('avg_unit_cost')
            ->values();
    }
}
