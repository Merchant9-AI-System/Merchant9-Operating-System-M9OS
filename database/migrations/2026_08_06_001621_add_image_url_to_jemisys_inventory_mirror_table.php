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
            // BUKAN lajur JEMiSys asal (TIADA dlm TblInventory sumber) - diisi SENDIRI semasa
            // sync (rujuk App\Jobs\SyncJemisysMirrors::syncInventory()) drpd
            // App\Support\ProductImageFetcher::firstImageUrlFor() (imej PERTAMA sahaja, scrape
            // merchant9.com). snake_case (spt synced_at) SENGAJA berbeza drpd PascalCase 140+
            // lajur mirror sebenar, supaya senang kenal pasti lajur mana "kita" tambah sendiri
            // vs lajur asal TblInventory.
            $table->string('image_url', 500)->nullable()->after('ImagePath');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
