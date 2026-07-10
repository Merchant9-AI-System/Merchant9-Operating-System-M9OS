<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Port terus daripada procurement_report.py compute_metrics() + build_order_recommendation()
 * (Flask/Python) - kekalkan formula sama supaya keputusan konsisten merentas kedua-dua sistem.
 *
 * Formula (per kombinasi VendorCode+InternalCode):
 *   sell_through_rate  = pieces_sold / pieces_received
 *   velocity_per_month = pieces_sold / (sales_window_days / 30)
 *   target_stock       = round(velocity_per_month * TARGET_COVER_MONTHS)
 *   recommend_qty      = max(0, target_stock - current_stock)
 *   -- hanya utk design "sihat": pieces_received >= MIN_SAMPLE DAN sell_through_rate >= ORDER_MIN_SELLTHRU
 */
class OrderRecommendationCalculator
{
    public const TARGET_COVER_MONTHS = 1.5;

    public const MIN_SAMPLE = 3;

    public const ORDER_MIN_SELLTHRU = 0.4;

    public static function recommendations(): Collection
    {
        $plain = Cache::rememberForever('order_recommendations', function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        });

        return collect($plain);
    }

    protected static function compute(): Collection
    {
        $salesWindowDays = SalesVelocityHelper::salesWindowDays();

        $grp = InventoryPiece::query()
            ->realVendor()
            ->selectRaw('VendorCode, InternalCode, '.
                'COUNT(*) as pieces_received, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(QtyOnHand) as current_stock, '.
                'MAX(Description) as item_desc, MAX(CategoryCode) as category_code')
            ->groupBy('VendorCode', 'InternalCode')
            ->get();

        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        // PENTING: Python compute_metrics() BUNDAR sell_through_rate (3dp) & velocity_per_month
        // (2dp) SEBELUM build_order_recommendation() guna nilai tsb utk eligibility+target_stock -
        // jadi kita WAJIB bundar pada peringkat sama (bukan simpan full precision) utk padan tepat.
        $out = $grp->map(function ($r) use ($salesWindowDays, $vendorNames) {
            $sellThrough = SalesVelocityHelper::sellThroughRate((int) $r->pieces_sold, (int) $r->pieces_received);
            $velocity = SalesVelocityHelper::velocity((int) $r->pieces_sold, $salesWindowDays);

            return [
                'vendor_code' => $r->VendorCode,
                'vendor_name' => $vendorNames[$r->VendorCode] ?? $r->VendorCode,
                'internal_code' => $r->InternalCode,
                'item_desc' => $r->item_desc,
                'category_code' => $r->category_code,
                'pieces_received' => (int) $r->pieces_received,
                'pieces_sold' => (int) $r->pieces_sold,
                'current_stock' => (int) $r->current_stock,
                'sell_through_rate' => $sellThrough,
                'velocity_per_month' => $velocity,
            ];
        })->filter(fn ($r) => $r['pieces_received'] >= self::MIN_SAMPLE
            && $r['sell_through_rate'] >= self::ORDER_MIN_SELLTHRU)
            ->map(function ($r) {
                $targetStock = SalesVelocityHelper::targetStock($r['velocity_per_month'], self::TARGET_COVER_MONTHS);
                $r['target_stock'] = $targetStock;
                $r['recommend_qty'] = max(0, $targetStock - $r['current_stock']);
                // "Cover" (bulan baki) - sepadan lajur COVER dlm dashboard: current_stock / velocity.
                $r['cover_months'] = $r['velocity_per_month'] > 0
                    ? round($r['current_stock'] / $r['velocity_per_month'], 1)
                    : null;

                return $r;
            })
            ->filter(fn ($r) => $r['recommend_qty'] > 0)
            ->sortBy([['vendor_code', 'asc'], ['recommend_qty', 'desc']])
            ->values();

        return collect($out);
    }
}
