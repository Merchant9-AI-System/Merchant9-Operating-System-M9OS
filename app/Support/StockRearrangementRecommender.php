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
 * Rule (per arahan): donor perlu stok >=3 di Cawangan A (baki >=2 sentiasa tak boleh diusik),
 * cuma 1 unit boleh dipindah ke SATU cawangan hotspot terpilih (sold out, stok=0) di setiap
 * design - jadi maksimum SATU baris cadangan per design (donor terbaik = stok tertinggi,
 * receiver terbaik = pernah jual paling banyak), bukan satu baris setiap cawangan sold out,
 * sbb donor cuma ada 1 unit utk diberi, bukan 1 unit per cawangan yg berkehendak. Utamakan
 * (Priority) design yg pernah jual >=3 di penerima. TIADA tulis ke database - papar sahaja.
 */
class StockRearrangementRecommender
{
    public const HIGH = 'High';

    public const MEDIUM = 'Medium';

    public const LOW = 'Low';

    public static function recommendations(): Collection
    {
        $plain = Cache::rememberForever('stock_rearrangement_recommendations', function () {
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
            // Donor perlu stok >=3 (kekal >=2 sbg baki tak boleh diusik) - HANYA 1 unit boleh
            // dipindah setiap design, jadi cuma SATU pasangan donor->receiver dijana per design
            // (bukan satu baris setiap cawangan sold out) - elak cadang lebih drpd 1 unit yg
            // sebenarnya tersedia drpd donor yg sama.
            $donors = $sub->filter(fn ($r) => (int) $r->stock >= 3)->sortByDesc('stock')->values();
            $receivers = $sub->filter(fn ($r) => (int) $r->stock === 0)->sortByDesc('sold')->values();

            if ($donors->isEmpty() || $receivers->isEmpty()) {
                continue;
            }

            $bestDonor = $donors->first();
            $bestReceiver = $receivers->first(fn ($r) => $r->StoreCode !== $bestDonor->StoreCode);

            if ($bestReceiver === null) {
                continue;
            }

            $m = $meta->get($code);

            $soldAtReceiver = (int) $bestReceiver->sold;
            $priority = match (true) {
                $soldAtReceiver >= 3 => self::HIGH,
                $soldAtReceiver >= 1 => self::MEDIUM,
                default => self::LOW,
            };

            $recs->push([
                'from_branch' => $bestDonor->StoreCode,
                'to_branch' => $bestReceiver->StoreCode,
                'internal_code' => $code,
                'item_desc' => $m->Description ?? '',
                'category_name' => $categoryNames[$m->CategoryCode ?? ''] ?? ($m->CategoryCode ?? ''),
                'vendor_name' => $vendorNames[$m->VendorCode ?? ''] ?? ($m->VendorCode ?? ''),
                'current_stock' => (int) $bestDonor->stock,
                'reason' => $soldAtReceiver > 0
                    ? "Ada stok di {$bestDonor->StoreCode} ({$bestDonor->stock} unit), sold out di {$bestReceiver->StoreCode} (pernah jual {$soldAtReceiver}x)"
                    : "Ada stok di {$bestDonor->StoreCode} ({$bestDonor->stock} unit), sold out di {$bestReceiver->StoreCode} (tiada rekod jualan lagi)",
                'priority' => $priority,
                // Sama gaya label spt RearrangeCalculator (receivers/suggestion) - konsisten
                // dgn page Rearrange, walaupun di sini SATU pasangan from->to sahaja per baris.
                'receiver_label' => "{$bestReceiver->StoreCode} (pernah jual {$soldAtReceiver}x)",
                'suggestion' => "1 unit: {$bestDonor->StoreCode} -> {$bestReceiver->StoreCode}",
            ]);
        }

        $order = [self::HIGH => 0, self::MEDIUM => 1, self::LOW => 2];

        return $recs->sortBy(fn ($r) => $order[$r['priority']])->values();
    }
}
