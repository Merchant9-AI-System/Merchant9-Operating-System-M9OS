<?php

namespace App\Support;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cawangan mana perlu focus pada kategori mana - 100% drpd data JEMiSys sebenar. Kira gap
 * (target_stock - current_stock) per (Cawangan, Kategori); gap besar +ve = understock (perlu
 * fokus beli), gap besar -ve = overstock (perlu fokus jual/promosi/rearrange keluar).
 */
class BranchFocusCalculator
{
    public const TARGET_COVER_MONTHS = OrderRecommendationCalculator::TARGET_COVER_MONTHS;

    public const MIN_SAMPLE = 3;

    public static function focus(): Collection
    {
        return collect(Cache::rememberForever('branch_focus', function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        }));
    }

    protected static function compute(): Collection
    {
        $salesWindowDays = SalesVelocityHelper::salesWindowDays();
        $monthStart = now()->startOfMonth();

        $grp = InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->selectRaw('StoreCode, CategoryCode, '.
                'COUNT(*) as pieces_received, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as pieces_sold_this_month, '.
                'SUM(QtyOnHand) as current_stock', [$monthStart])
            ->groupBy('StoreCode', 'CategoryCode')
            ->get();

        $categoryNames = Category::pluck('Description', 'CategoryCode');

        $out = $grp->map(function ($r) use ($salesWindowDays, $categoryNames) {
            $piecesReceived = (int) $r->pieces_received;
            $piecesSold = (int) $r->pieces_sold;
            $piecesSoldThisMonth = (int) $r->pieces_sold_this_month;
            $currentStock = (int) $r->current_stock;
            $velocity = SalesVelocityHelper::velocity($piecesSold, $salesWindowDays);
            $sellThrough = SalesVelocityHelper::sellThroughRate($piecesSold, $piecesReceived); // TODO salah
            $targetStock = SalesVelocityHelper::targetStock($velocity, self::TARGET_COVER_MONTHS);
            $gap = $targetStock - $currentStock;

            $hasSample = $piecesReceived >= self::MIN_SAMPLE;
            $focusArea = ! $hasSample
                ? 'Data Tak Cukup'
                : ($gap > 0 ? 'Understock - Fokus Beli' : ($currentStock > 0 && $targetStock >= 0 && $currentStock > max($targetStock, 1) * 2 ? 'Overstock - Fokus Jual/Promosi' : 'Seimbang'));

            return [
                'store_code' => $r->StoreCode,
                'category_code' => $r->CategoryCode,
                'category_name' => $categoryNames[$r->CategoryCode] ?? $r->CategoryCode,
                'pieces_received' => $piecesReceived,
                'pieces_sold' => $piecesSold,
                'pieces_sold_this_month' => $piecesSoldThisMonth,
                'current_stock' => $currentStock,
                'sell_through_rate' => $sellThrough,
                'velocity_per_month' => $velocity,
                'target_stock' => $targetStock,
                'gap' => $gap,
                'focus_area' => $focusArea,
            ];
        });

        return $out->sortByDesc(fn ($r) => abs($r['gap']))->values();
    }

    /**
     * Senarai design (InternalCode) individu bagi satu (Cawangan, Kategori) - utk jawab "yang
     * perlu fokus tu, design MANA sebenarnya" (rujuk baris focus() yang cuma tunjuk kategori/
     * cawangan, bukan design tertentu). TIDAK di-cache spt focus() sbb ini drill-down on-demand.
     */
    public static function designsForFocus(string $storeCode, string $categoryCode): Collection
    {
        $monthStart = now()->startOfMonth();
        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        return InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->where('StoreCode', $storeCode)
            ->where('CategoryCode', $categoryCode)
            ->get(['InternalCode', 'VendorCode', 'Description', 'QtyOnHand', 'SalesDate'])
            ->groupBy('InternalCode')
            ->map(function ($group) use ($monthStart, $vendorNames) {
                $first = $group->first();
                $piecesSold = $group->filter(fn ($r) => $r->SalesDate !== null)->count();
                $soldThisMonth = $group->filter(fn ($r) => $r->SalesDate !== null && $r->SalesDate->greaterThanOrEqualTo($monthStart))->count();

                return [
                    'internal_code' => $first->InternalCode,
                    'description' => $first->Description,
                    'vendor_name' => $vendorNames[$first->VendorCode] ?? $first->VendorCode,
                    'current_stock' => (int) $group->sum('QtyOnHand'),
                    'pieces_sold' => $piecesSold,
                    'sold_this_month' => $soldThisMonth,
                ];
            })
            ->sortByDesc('sold_this_month')
            ->values();
    }
}
