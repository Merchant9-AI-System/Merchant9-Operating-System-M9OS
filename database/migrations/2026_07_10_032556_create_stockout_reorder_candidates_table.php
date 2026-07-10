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
        // Hasil pra-agregat StockoutReorder::baseQuery() (design >=3 pcs terjual, kini stok=0
        // merentas semua saluran) - dikira SEKALI oleh SyncJemisysMirrors (rujuk
        // App\Support\StockoutReorderMaterializer), bukan setiap page load/filter/sort/paginate.
        // InternalCode ialah PK semula jadi (unik ikut design, sepadan GROUP BY InternalCode asal).
        Schema::create('stockout_reorder_candidates', function (Blueprint $table) {
            $table->string('InternalCode', 20)->primary();
            $table->string('Description', 100)->nullable();
            $table->string('CategoryCode', 20)->nullable();
            $table->string('VendorCode', 20)->nullable();
            $table->unsignedInteger('sold_count');
            $table->dateTime('last_sale_date')->nullable();
            $table->timestamp('synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stockout_reorder_candidates');
    }
};
