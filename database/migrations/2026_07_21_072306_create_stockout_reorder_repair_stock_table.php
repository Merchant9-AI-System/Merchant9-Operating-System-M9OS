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
        // Stok item repair (VendorCode='.') per (InternalCode, StoreCode) - berasingan drpd
        // stockout_reorder_candidates (yg hanya realVendor()) supaya StockoutReorderCandidate::
        // candidateQuery() boleh kecualikan repair stock ikut cawangan yg sama dipilih/
        // dikecualikan utk vendor (rujuk lajur 'Stok Repair' StockoutReorder::table()).
        Schema::create('stockout_reorder_repair_stock', function (Blueprint $table) {
            $table->id();
            $table->string('InternalCode', 20);
            $table->string('StoreCode', 20);
            $table->unsignedInteger('repair_qty');
            $table->timestamp('synced_at');
            $table->unique(['InternalCode', 'StoreCode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stockout_reorder_repair_stock');
    }
};
