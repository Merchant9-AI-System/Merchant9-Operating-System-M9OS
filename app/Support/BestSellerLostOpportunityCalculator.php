<?php

namespace App\Support;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use App\Models\StockoutReorderCandidate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CEO Dashboard Phase 1 (D) - "Best Seller Lost Opportunity". Guna definisi stockout SAMA spt
 * App\Filament\Pages\StockoutReorder - baca terus drpd stockout_reorder_candidates (snapshot
 * pra-agregat App\Support\StockoutReorderMaterializer), BUKAN kira semula aggregat yg sama.
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
        // Cache guna array biasa (top_branches/top10 di-toArray()) - elak isu unserialize
        // __PHP_Incomplete_Class bila cache ditulis dari konteks CLI (cth. artisan
        // app:warm-dashboard-cache) & dibaca semula dari konteks web (php artisan serve) atau
        // sebaliknya (sama nota spt RearrangeCalculator). collect() semula lepas keluar cache.
        $s = Cache::rememberForever('best_seller_lost_opportunity_summary', function () {
            return retry(6, fn () => static::compute(), 800);
        });

        $s['top_branches'] = collect($s['top_branches']);
        $s['top10'] = collect($s['top10']);

        return $s;
    }

    protected static function compute(): array
    {
        $designs = StockoutReorderCandidate::candidateQuery()->get();

        if ($designs->isEmpty()) {
            return [
                'total_count' => 0,
                'estimated_lost_revenue' => null,
                'priced_design_count' => 0,
                'unpriced_design_count' => 0,
                'top_branches' => [],
                'top10' => [],
            ];
        }

        $codes = $designs->pluck('InternalCode');

        // Purata harga jualan realized (SalesAmount>0) sejarah bagi design terlibat sahaja.
        // whereIn() guna SUBQUERY (bukan senarai literal ribuan kod) - senarai literal ~14K
        // placeholder buat MySQL ambil >900 saat (disahkan EXPLAIN/timing) sebab query planner
        // tak dapat optimumkan IN literal sebesar ni; subquery dibenarkan MySQL materialize/
        // index sekali sbg semi-join, jauh lebih pantas.
        $avgPrices = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', fn ($q) => $q->select('InternalCode')->from('stockout_reorder_candidates'))
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
        // trim() VendorCode - jemisys_vendor_mirror simpan kod berpad ruang, tapi
        // StockoutReorderCandidate::vendorCodes() pulangkan kod yg sudah trim (rujuk model tsb).
        $vendorNames = Vendor::get()->mapWithKeys(fn ($v) => [trim($v->VendorCode) => $v->Description]);

        $top10 = $designs->sortByDesc('sold_count')->take(10)->values()->map(fn ($r) => [
            'internal_code' => $r->InternalCode,
            'description' => $r->Description,
            'category_name' => $categoryNames[$r->CategoryCode] ?? $r->CategoryCode,
            // Design boleh ada >1 vendor (rujuk StockoutReorderCandidate::vendorCodes()) -
            // gabung semua nama vendor drpd senarai vendor_codes, bukan satu VendorCode tunggal.
            'vendor_name' => collect($r->vendorCodes())
                ->map(fn (string $code) => $vendorNames[$code] ?? $code)
                ->implode(', '),
            'sold_count' => (int) $r->sold_count,
            // ->toDateTimeString() (bukan Carbon object terus) - $r->last_sale_date datang drpd
            // cast 'datetime' StockoutReorderCandidate, jadi objek Carbon PENUH. toArray() di
            // bawah cuma tukar Collection LUAR jadi array, TAK recurse ke dlm nested object -
            // Carbon tsb kekal tersimpan whole dlm cache & kena __PHP_Incomplete_Class yg sama
            // bila unserialize merentas proses CLI/web (rujuk nota summary() di atas).
            'last_sale_date' => $r->last_sale_date?->toDateTimeString(),
        ]);

        // Cawangan mana paling terjejas - kira drpd sejarah jualan (SalesDate) design yg kini
        // sold out, ikut StoreCode. Ni penunjuk permintaan sejarah, BUKAN anggaran masa depan.
        $topBranches = InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->whereIn('InternalCode', fn ($q) => $q->select('InternalCode')->from('stockout_reorder_candidates'))
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
            'top_branches' => $topBranches->toArray(),
            'top10' => $top10->toArray(),
        ];
    }
}
