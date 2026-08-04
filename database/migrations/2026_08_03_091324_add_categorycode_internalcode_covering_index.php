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
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            // Index CategoryCode sedia ada TIDAK cover InternalCode - carian "design dlm kategori
            // ini" (BranchDemandEntryController::search()) terpaksa row lookup berasingan bagi
            // SETIAP baris padan (2000 baris = 2000 lookup berselerak) walaupun index scan sendiri
            // pantas. Diukur sebenar: ~500ms drpd index CategoryCode sahaja, turun mendadak dgn
            // index meliputi (covering) ni sbb InternalCode terus terbaca drpd index, tiada row
            // lookup ke jadual utama langsung.
            $table->index(['CategoryCode', 'InternalCode'], 'idx_mirror_category_internalcode_covering');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_category_internalcode_covering');
        });
    }
};
