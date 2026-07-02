<?php

namespace App\Support;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
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
        return collect(Cache::remember('branch_focus', 3600, function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        }));
    }

    protected static function compute(): Collection
    {
        $salesWindowDays = SalesVelocityHelper::salesWindowDays();

        $grp = InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->selectRaw('StoreCode, CategoryCode, '.
                'COUNT(*) as pieces_received, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(QtyOnHand) as current_stock')
            ->groupBy('StoreCode', 'CategoryCode')
            ->get();

        $categoryNames = Category::pluck('Description', 'CategoryCode');

        $out = $grp->map(function ($r) use ($salesWindowDays, $categoryNames) {
            $piecesReceived = (int) $r->pieces_received;
            $piecesSold = (int) $r->pieces_sold;
            $currentStock = (int) $r->current_stock;
            $velocity = SalesVelocityHelper::velocity($piecesSold, $salesWindowDays);
            $sellThrough = SalesVelocityHelper::sellThroughRate($piecesSold, $piecesReceived);
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
}
