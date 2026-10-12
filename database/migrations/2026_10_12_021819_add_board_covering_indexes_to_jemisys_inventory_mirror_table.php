<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papan Back Office Actions (BackOfficeActionsAdvisor::board) query ~166 kod design (~47K baris cermin).
 * Index sedia ada cuma cari baris - lajur lain (StoreCode, VendorCode, QtyOnHand, nickname) masih
 * dibaca dgn lompat ke jadual satu-satu (~7s setiap query, jumlah papan ~17s). Tiga index "covering"
 * ini menjawab query drpd index sahaja:
 *  - stok:     InternalCode, StoreCode, VendorCode, QtyOnHand  (BackOfficeActionsAdvisor::stockByCode)
 *  - jualan:   InternalCode, StoreCode, SalesDate              (salesByCode)
 *  - nickname: InternalCode, nickname                          (BranchDemandLineSummary::nicknameLookup)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->index(['InternalCode', 'StoreCode', 'VendorCode', 'QtyOnHand'], 'idx_mirror_board_stock_covering');
            $table->index(['InternalCode', 'StoreCode', 'SalesDate'], 'idx_mirror_board_sales_covering');
            $table->index(['InternalCode', 'nickname'], 'idx_mirror_board_nickname_covering');
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_board_stock_covering');
            $table->dropIndex('idx_mirror_board_sales_covering');
            $table->dropIndex('idx_mirror_board_nickname_covering');
        });
    }
};
