<?php

namespace App\Support;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use App\Models\StockoutReorderCandidate;
use App\Models\StockoutReorderQualifyingDesign;
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
 *
 * $range (rujuk StockoutReorderCandidate::RANGE_*) menukar SET DESIGN yg layak (sold_count
 * dikira semula dlm tempoh terpilih, qty_on_hand=0 kekal semasa/tidak dibaldi) - bukan sekadar
 * tapis paparan drpd senarai "overall" yg sama (rujuk StockoutReorder::handleTableFilterUpdates()
 * utk cara table/widget kekal selari bila julat ditukar).
 */
class BestSellerLostOpportunityCalculator
{
    public static function summary(string $range = StockoutReorderCandidate::RANGE_OVERALL): array
    {
        // Cache guna array biasa (top_branches/top10 kekal array, BUKAN di-collect()) - elak
        // isu unserialize __PHP_Incomplete_Class bila cache ditulis dari konteks CLI (cth.
        // artisan app:warm-dashboard-cache) & dibaca semula dari konteks web (php artisan
        // serve) atau sebaliknya (sama nota spt RearrangeCalculator). SEBAB TAMBAHAN: nilai ni
        // turut lalu sempadan hydrate/dehydrate Livewire (StockoutReorder::getWidgetData() ->
        // widget public array $summary) - Collection bersarang dlm array tak "hidup" merentasi
        // pusingan tu (Livewire sifatkan array biasa, bukan reconstruct Collection), jadi Stat
        // yg cuba panggil ->isNotEmpty()/->first() atasnya akan crash. Kekalkan array biasa
        // sepanjang - guna helper array biasa (empty()/[0]) di titik penggunaan, bukan Collection.
        // Satu cache key per julat - flush oleh Cache::flush() global SyncJemisysMirrors sama
        // spt sedia ada (rujuk nota kelas ni), tiada wiring tambahan diperlukan.
        return Cache::rememberForever("best_seller_lost_opportunity_summary:{$range}", function () use ($range) {
            return retry(6, fn () => static::compute($range), 800);
        });
    }

    protected static function compute(string $range): array
    {
        $designs = StockoutReorderCandidate::candidateQuery(range: $range)->get();

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

        // Cutoff sepadan $range (null utk overall - tiada had tarikh) - avgPrices/topBranches
        // di bawah dihadkan tarikh yg SAMA dgn julat qualifying designs, supaya "purata harga"/
        // "cawangan terjejas" mencerminkan jualan DALAM tempoh dipilih, bukan sejarah keseluruhan
        // bagi design yg baru layak sbb julat singkat.
        $days = StockoutReorderCandidate::RANGE_DAYS[$range] ?? null;
        $cutoff = $days !== null ? now()->subDays($days) : null;

        // Purata harga jualan realized (SalesAmount>0) sejarah bagi design terlibat sahaja.
        // whereIn() guna subquery drpd stockout_reorder_qualifying_designs (jadual kecil unik-
        // key (InternalCode,range_bucket) SEMATA-MATA utk tujuan semi-join ni, rujuk migration
        // create_..._table) - BUKAN senarai literal ribuan kod (>900 saat, disahkan) MAHUPUN
        // StockoutReorderCandidate::candidateInternalCodesQuery() (GROUP BY/HAVING live atas
        // jadual 131.8K baris sbg subquery JOIN ke 481K baris InventoryPiece - turut lembab,
        // 55+ saat, disahkan). Jadual unik-key kecil dibenarkan MySQL materialize/index sekali
        // sbg semi-join, jauh lebih pantas drpd kedua-dua alternatif tsb.
        $avgPrices = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', StockoutReorderQualifyingDesign::query()->where('range_bucket', $range)->select('InternalCode'))
            ->whereNotNull('SalesDate')
            ->whereNotNull('SalesAmount')
            ->where('SalesAmount', '>', 0)
            ->when($cutoff, fn ($q) => $q->where('SalesDate', '>=', $cutoff))
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
            ->whereIn('InternalCode', StockoutReorderQualifyingDesign::query()->where('range_bucket', $range)->select('InternalCode'))
            ->whereNotNull('SalesDate')
            ->when($cutoff, fn ($q) => $q->where('SalesDate', '>=', $cutoff))
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
