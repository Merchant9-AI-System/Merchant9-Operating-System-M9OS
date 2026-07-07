<?php

namespace App\Support;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * CEO Dashboard Phase 1 (E) - "Stock Rearrangement Recommendation", cadangan RINGKAS &
 * berasingan drpd App\Support\RearrangeCalculator sedia ada (yg guna algoritma greedy
 * multi-donor/multi-receiver serta ada tindakan TULIS "Cipta Transfer" di page Rearrange).
 * Kelas ni SENGAJA berasingan supaya page/algoritma Rearrange sedia ada TIDAK disentuh.
 *
 * Rule (per arahan): kalau design ada stok di Cawangan A tapi sold out (stok=0) di Cawangan B,
 * cadangkan pindah drpd A ke B. Utamakan (Priority) design yg pernah jual >=3 di B. Untuk
 * setiap (design, B), guna SATU donor terbaik (stok tertinggi) sbg "From" - elak ledakan
 * kombinasi bila byk cawangan terlibat. TIADA tulis ke database - papar sahaja.
 */
class StockRearrangementRecommender
{
    public const HIGH = 'High';

    public const MEDIUM = 'Medium';

    public const LOW = 'Low';

    public static function recommendations(): Collection
    {
        $plain = Cache::remember('stock_rearrangement_recommendations', 3600, function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        });

        return collect($plain);
    }

    protected static function compute(): Collection
    {
        $rows = InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->selectRaw('InternalCode, StoreCode, SUM(QtyOnHand) as stock, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold')
            ->groupBy('InternalCode', 'StoreCode')
            ->get();

        $meta = InventoryPiece::query()
            ->realVendor()
            ->selectRaw('InternalCode, MAX(Description) as Description, MAX(CategoryCode) as CategoryCode, MAX(VendorCode) as VendorCode')
            ->groupBy('InternalCode')
            ->get()
            ->keyBy('InternalCode');

        $categoryNames = Category::pluck('Description', 'CategoryCode');
        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        $recs = collect();

        foreach ($rows->groupBy('InternalCode') as $code => $sub) {
            $donors = $sub->filter(fn ($r) => (int) $r->stock >= 1)->sortByDesc('stock')->values();
            $receivers = $sub->filter(fn ($r) => (int) $r->stock === 0)->values();

            if ($donors->isEmpty() || $receivers->isEmpty()) {
                continue;
            }

            $bestDonor = $donors->first();
            $m = $meta->get($code);

            foreach ($receivers as $receiver) {
                if ($receiver->StoreCode === $bestDonor->StoreCode) {
                    continue;
                }

                $soldAtReceiver = (int) $receiver->sold;
                $priority = match (true) {
                    $soldAtReceiver >= 3 => self::HIGH,
                    $soldAtReceiver >= 1 => self::MEDIUM,
                    default => self::LOW,
                };

                $recs->push([
                    'from_branch' => $bestDonor->StoreCode,
                    'to_branch' => $receiver->StoreCode,
                    'internal_code' => $code,
                    'item_desc' => $m->Description ?? '',
                    'category_name' => $categoryNames[$m->CategoryCode ?? ''] ?? ($m->CategoryCode ?? ''),
                    'vendor_name' => $vendorNames[$m->VendorCode ?? ''] ?? ($m->VendorCode ?? ''),
                    'current_stock' => (int) $bestDonor->stock,
                    'reason' => $soldAtReceiver > 0
                        ? "Ada stok di {$bestDonor->StoreCode} ({$bestDonor->stock} unit), sold out di {$receiver->StoreCode} (pernah jual {$soldAtReceiver}x)"
                        : "Ada stok di {$bestDonor->StoreCode} ({$bestDonor->stock} unit), sold out di {$receiver->StoreCode} (tiada rekod jualan lagi)",
                    'priority' => $priority,
                ]);
            }
        }

        $order = [self::HIGH => 0, self::MEDIUM => 1, self::LOW => 2];

        return $recs->sortBy(fn ($r) => $order[$r['priority']])->values();
    }
}
