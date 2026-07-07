<?php

namespace App\Support;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CEO Dashboard Phase 1 (D) - "Best Seller Lost Opportunity". Guna definisi stockout SAMA spt
 * App\Filament\Pages\StockoutReorder (design pernah laku >=3, kini stok=0 di semua saluran).
 *
 * Anggaran hasil hilang ("estimated_lost_revenue") HANYA guna SalesAmount SEJARAH SEBENAR bagi
 * design terlibat (purata harga jualan realized, bukan anggaran/rekaan) - andaian konservatif
 * "1 unit peluang terlepas setiap design" didedahkan dgn jelas. SalesAmount cuma ~61% terisi
 * dlm data JEMiSys sedia ada (sama nota spt SupplierPerformanceCalculator) - design tanpa
 * SalesAmount TIDAK dimasukkan dlm anggaran RM (dikira dlm bilangan sahaja, bukan direka).
 */
class BestSellerLostOpportunityCalculator
{
    public static function summary(): array
    {
        return Cache::remember('best_seller_lost_opportunity_summary', 3600, function () {
            return retry(6, fn () => static::compute(), 800);
        });
    }

    protected static function compute(): array
    {
        $designs = static::stockoutDesigns()->get();

        if ($designs->isEmpty()) {
            return [
                'total_count' => 0,
                'estimated_lost_revenue' => null,
                'priced_design_count' => 0,
                'unpriced_design_count' => 0,
                'top_branches' => collect(),
                'top10' => collect(),
            ];
        }

        $codes = $designs->pluck('InternalCode');

        // Purata harga jualan realized (SalesAmount>0) sejarah bagi design terlibat sahaja.
        $avgPrices = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->whereNotNull('SalesDate')
            ->whereNotNull('SalesAmount')
            ->where('SalesAmount', '>', 0)
            ->selectRaw('InternalCode, AVG(SalesAmount) as avg_price')
            ->groupBy('InternalCode')
            ->pluck('avg_price', 'InternalCode');

        $pricedCount = $avgPrices->count();
        $unpricedCount = $codes->count() - $pricedCount;

        // Andaian konservatif: 1 unit peluang terlepas setiap design berharga - JANGAN anggar
        // permintaan sebenar (perlukan data velocity/duration stockout yg tak boleh dipercayai lagi).
        $estimatedLostRevenue = $pricedCount > 0
            ? round($avgPrices->sum(), 2)
            : null;

        $categoryNames = Category::pluck('Description', 'CategoryCode');
        $vendorNames = Vendor::pluck('Description', 'VendorCode');

        $top10 = $designs->sortByDesc('sold_count')->take(10)->values()->map(fn ($r) => [
            'internal_code' => $r->InternalCode,
            'description' => $r->Description,
            'category_name' => $categoryNames[$r->CategoryCode] ?? $r->CategoryCode,
            'vendor_name' => $vendorNames[$r->VendorCode] ?? $r->VendorCode,
            'sold_count' => (int) $r->sold_count,
            'last_sale_date' => $r->last_sale_date,
        ]);

        // Cawangan mana paling terjejas - kira drpd sejarah jualan (SalesDate) design yg kini
        // sold out, ikut StoreCode. Ni penunjuk permintaan sejarah, BUKAN anggaran masa depan.
        $topBranches = InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->whereIn('InternalCode', $codes)
            ->whereNotNull('SalesDate')
            ->selectRaw('StoreCode, COUNT(*) as past_sales')
            ->groupBy('StoreCode')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['store_code' => $r->StoreCode, 'past_sales' => (int) $r->past_sales]);

        return [
            'total_count' => $codes->count(),
            'estimated_lost_revenue' => $estimatedLostRevenue,
            'priced_design_count' => $pricedCount,
            'unpriced_design_count' => $unpricedCount,
            'top_branches' => $topBranches,
            'top10' => $top10,
        ];
    }

    protected static function stockoutDesigns(): Builder
    {
        return InventoryPiece::query()
            ->realVendor()
            ->select([
                DB::raw('InternalCode as InventoryCode'),
                'InternalCode',
                DB::raw('MAX(Description) as Description'),
                DB::raw('MAX(CategoryCode) as CategoryCode'),
                DB::raw('MAX(VendorCode) as VendorCode'),
                DB::raw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold_count'),
                DB::raw('MAX(SalesDate) as last_sale_date'),
            ])
            ->groupBy('InternalCode')
            // havingRaw kena ulang expression penuh - SQL Server tak benarkan alias dlm HAVING.
            ->havingRaw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) >= 3 AND SUM(QtyOnHand) = 0');
    }
}
