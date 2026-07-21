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
        // Ubah grain drpd (InternalCode, VendorCode) kpd (InternalCode, VendorCode, StoreCode) -
        // membolehkan StockoutReorder kira semula sold_count/qty_on_hand secara LIVE ikut cawangan
        // dipilih/dikecualikan (rujuk StockoutReorderCandidate::candidateQuery()), sama mekanisme
        // spt vendor exclude sedia ada. repair_qty_on_hand dipindah keluar drpd jadual ni ke
        // stockout_reorder_repair_stock (jadual berasingan, grain (InternalCode, StoreCode) -
        // rujuk migration create_stockout_reorder_repair_stock_table) supaya turut boleh
        // dikecualikan ikut cawangan. Jadual ni snapshot terbitan penuh (dikira semula oleh
        // StockoutReorderMaterializer setiap sync), jadi selamat drop+recreate.
        Schema::dropIfExists('stockout_reorder_candidates');

        Schema::create('stockout_reorder_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('InternalCode', 20);
            $table->string('VendorCode', 20);
            $table->string('StoreCode', 20);
            $table->string('Description', 100)->nullable();
            $table->string('CategoryCode', 20)->nullable();
            $table->unsignedInteger('sold_count');
            $table->unsignedInteger('qty_on_hand');
            $table->dateTime('last_sale_date')->nullable();
            $table->timestamp('synced_at');
            // Nama eksplisit - nama auto Laravel (internalcode_vendorcode_storecode_unique)
            // lebihi had 64 aksara pengecam MySQL.
            $table->unique(['InternalCode', 'VendorCode', 'StoreCode'], 'sroc_internal_vendor_store_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembali ke bentuk (InternalCode, VendorCode) + repair_qty_on_hand - data snapshot
        // terbitan, selamat hilang, akan diisi semula oleh StockoutReorderMaterializer.
        Schema::dropIfExists('stockout_reorder_candidates');

        Schema::create('stockout_reorder_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('InternalCode', 20);
            $table->string('VendorCode', 20);
            $table->string('Description', 100)->nullable();
            $table->string('CategoryCode', 20)->nullable();
            $table->unsignedInteger('repair_qty_on_hand')->default(0);
            $table->unsignedInteger('sold_count');
            $table->unsignedInteger('qty_on_hand');
            $table->dateTime('last_sale_date')->nullable();
            $table->timestamp('synced_at');
            $table->unique(['InternalCode', 'VendorCode']);
        });
    }
};
