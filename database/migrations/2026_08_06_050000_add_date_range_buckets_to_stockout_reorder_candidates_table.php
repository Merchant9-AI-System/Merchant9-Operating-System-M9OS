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
        // sold_count sedia ada = "overall" (all-time) - lajur ni tambah baldi tempoh (7/30/90/
        // 180/365 hari) supaya App\Models\StockoutReorderCandidate::candidateQuery() boleh tapis
        // ikut julat tarikh TANPA agregat semula 481K baris jemisys_inventory_mirror setiap page
        // load (rujuk App\Support\StockoutReorderMaterializer - dikira sekali di sini semasa
        // sync, sepadan cara sold_count/qty_on_hand sedia ada dikira).
        Schema::table('stockout_reorder_candidates', function (Blueprint $table) {
            $table->unsignedInteger('sold_count_7d')->default(0)->after('sold_count');
            $table->unsignedInteger('sold_count_30d')->default(0)->after('sold_count_7d');
            $table->unsignedInteger('sold_count_90d')->default(0)->after('sold_count_30d');
            $table->unsignedInteger('sold_count_180d')->default(0)->after('sold_count_90d');
            $table->unsignedInteger('sold_count_365d')->default(0)->after('sold_count_180d');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stockout_reorder_candidates', function (Blueprint $table) {
            $table->dropColumn(['sold_count_7d', 'sold_count_30d', 'sold_count_90d', 'sold_count_180d', 'sold_count_365d']);
        });
    }
};
