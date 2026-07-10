<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index komposit tambahan berdasarkan corak query SEBENAR merentasi semua Calculator/Widget
 * (bukan sekadar index tunggal per lajur) - sekarang boleh dibuat sebab jadual ni cermin
 * TEMPATAN kita kawal sepenuhnya (tak macam live SQL Server, index custom di sana hilang
 * setiap kali job jadual jemisys gantikan seluruh DB).
 *
 * Setiap index dipetakan terus kpd corak groupBy/where sebenar (rujuk audit query pattern):
 * - InternalCode+VendorCode+QtyOnHand+SalesDate: corak "stockout proven seller" (>=8 tempat
 *   guna corak ni - RearrangeCalculator, StockRearrangementRecommender,
 *   BestSellerLostOpportunityCalculator, CeoActionCentreCalculator, InventoryKpiStats,
 *   ActionAlerts, StockoutReorder).
 * - VendorCode+InternalCode+SalesDate+QtyOnHand: OrderRecommendationCalculator,
 *   SupplierPerformanceCalculator (x2 query), SalesVelocityHelper::salesWindowDays().
 * - StoreCode+InternalCode+QtyOnHand+SalesDate: RearrangeCalculator::rows,
 *   StockRearrangementRecommender::rows, BranchHealthCalculator::stockoutPerStore.
 * - CategoryCode+StoreCode+QtyOnHand+SalesDate: BranchFocusCalculator, RestockAnalysisCalculator,
 *   StockVsOptimumChart (lajur utama CategoryCode - padan lebih byk pengguna drpd StoreCode dulu).
 * - QtyOnHand+PurchDate+VendorCode: corak "dead stock/capital aging" (CapitalAgingCalculator,
 *   CapitalAgingChart, CeoActionCentreCalculator::deadStockAlert, InventoryKpiStats::dead_value,
 *   BranchHealthCalculator dead-stock CASE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->index(['InternalCode', 'VendorCode', 'QtyOnHand', 'SalesDate'], 'idx_mirror_stockout_pattern');
            $table->index(['VendorCode', 'InternalCode', 'SalesDate', 'QtyOnHand'], 'idx_mirror_vendor_pattern');
            $table->index(['StoreCode', 'InternalCode', 'QtyOnHand', 'SalesDate'], 'idx_mirror_store_internal_pattern');
            $table->index(['CategoryCode', 'StoreCode', 'QtyOnHand', 'SalesDate'], 'idx_mirror_category_store_pattern');
            $table->index(['QtyOnHand', 'PurchDate', 'VendorCode'], 'idx_mirror_aging_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_stockout_pattern');
            $table->dropIndex('idx_mirror_vendor_pattern');
            $table->dropIndex('idx_mirror_store_internal_pattern');
            $table->dropIndex('idx_mirror_category_store_pattern');
            $table->dropIndex('idx_mirror_aging_pattern');
        });
    }
};
