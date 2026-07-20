<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Kuantiti item repair (VendorCode='.') dlm stok bagi design ini - dikira berasingan drpd
        // realVendor() (rujuk InventoryPiece::scopeRealVendor()) sebab item repair TIDAK dikira
        // langsung dlm agregat sold_count/stock=0 sedia ada. Sekadar penanda maklumat (badge) -
        // TIDAK menyingkir design drpd senarai reorder walaupun ada stok repair.
        Schema::table('stockout_reorder_candidates', function (Blueprint $table) {
            $table->unsignedInteger('repair_qty_on_hand')->default(0)->after('vendor_codes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stockout_reorder_candidates', function (Blueprint $table) {
            $table->dropColumn('repair_qty_on_hand');
        });
    }
};
