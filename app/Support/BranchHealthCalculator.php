<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * CEO Dashboard Phase 1 (B) - Branch Health Table. Semua metrik dikira terus drpd TblInventory
 * (GoldWeight, TotalCost, PurchDate, SalesDate, QtyOnHand semua wujud) - tiada placeholder
 * diperlukan. Threshold status (Healthy/Watch/Critical) ialah andaian konservatif permulaan
 * ("rule-based"), sesuaikan bila ada lebih data pemerhatian.
 */
class BranchHealthCalculator
{
    public const CRITICAL = 'Critical';

    public const WATCH = 'Watch';

    public const HEALTHY = 'Healthy';

    public static function rows(): Collection
    {
        $plain = Cache::rememberForever('branch_health_rows', function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        });

        return collect($plain);
    }

    protected static function compute(): Collection
    {
        $today = now();

        $base = InventoryPiece::query()->onHand()->realVendor()->physicalStore()
            ->selectRaw('StoreCode, '.
                'SUM(GoldWeight) as gold_weight, '.
                'SUM(TotalCost) as inventory_value, '.
                'SUM(CASE WHEN PurchDate <= ? THEN TotalCost ELSE 0 END) as dead_stock_value', [
                    $today->copy()->subDays(365),
                ])
            ->groupBy('StoreCode')
            ->get()
            ->keyBy('StoreCode');

        $stockoutPerStore = InventoryPiece::query()->realVendor()->physicalStore()
            ->selectRaw('StoreCode, InternalCode, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, '.
                'SUM(QtyOnHand) as stock')
            ->groupBy('StoreCode', 'InternalCode')
            // havingRaw kena ulang expression penuh - SQL Server tak benarkan alias dlm HAVING.
            ->havingRaw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) >= 3 AND SUM(QtyOnHand) = 0')
            ->get()
            ->groupBy('StoreCode')
            ->map(fn ($rows) => $rows->count());

        return $base->map(function ($r, $storeCode) use ($stockoutPerStore) {
            $inventoryValue = (float) $r->inventory_value;
            $deadValue = (float) $r->dead_stock_value;
            $deadPct = $inventoryValue > 0 ? round(($deadValue / $inventoryValue) * 100, 1) : 0.0;
            $stockoutCount = (int) ($stockoutPerStore[$storeCode] ?? 0);

            [$status, $action] = static::status($deadPct, $stockoutCount);

            return [
                'store_code' => $storeCode,
                'gold_weight_kg' => round((float) $r->gold_weight / 1000, 2),
                'inventory_value' => round($inventoryValue, 2),
                'dead_stock_pct' => $deadPct,
                'stockout_bestseller_count' => $stockoutCount,
                'status' => $status,
                'suggested_action' => $action,
            ];
        })->sortByDesc('inventory_value')->values();
    }

    /** @return array{0: string, 1: string} */
    protected static function status(float $deadPct, int $stockoutCount): array
    {
        if ($deadPct >= 30 || $stockoutCount >= 5) {
            return [self::CRITICAL, 'Semak segera: dead stock tinggi dan/atau banyak best-seller sold out - reorder & lelong/promosi dead stock.'];
        }

        if ($deadPct >= 15 || $stockoutCount >= 2) {
            return [self::WATCH, 'Pantau: mula rancang reorder/rearrange sebelum jadi kritikal.'];
        }

        return [self::HEALTHY, 'Tiada tindakan segera diperlukan.'];
    }
}
