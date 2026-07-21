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
        // Senarai InternalCode yg LAYAK jadi calon reorder ikut definisi LALAI (semua
        // vendor/cawangan dikira, tiada exclude) - satu-satunya tujuan jadual ni ialah jadi
        // sumber semi-join MURAH utk BestSellerLostOpportunityCalculator (kekal cached forever,
        // TIDAK perlukan exclude interaktif spt StockoutReorder). stockout_reorder_candidates
        // (grain per-vendor-per-cawangan) tak lagi sesuai utk tujuan ni selepas re-grain -
        // GROUP BY/HAVING live sbg subquery JOIN ke jemisys_inventory_mirror (481K baris) ambil
        // 55+ saat (disahkan timing), berbanding jadual kecil unik-key macam ni (rujuk sejarah
        // asal stockout_reorder_candidates sblm re-grain - PK InternalCode tunggal, cepat sbg
        // semi-join). InternalCode PK supaya "senarai literal ribuan kod" pun tak diperlukan.
        Schema::create('stockout_reorder_qualifying_designs', function (Blueprint $table) {
            $table->string('InternalCode', 20)->primary();
            $table->timestamp('synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stockout_reorder_qualifying_designs');
    }
};
