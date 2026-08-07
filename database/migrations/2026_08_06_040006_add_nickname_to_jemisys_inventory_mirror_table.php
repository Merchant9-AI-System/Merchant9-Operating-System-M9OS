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
            // BUKAN lajur JEMiSys asal - diisi SENDIRI oleh App\Jobs\SyncMerchantNicknamesAndImages
            // (scrape merchant9.com via App\Support\MerchantWebsiteSearch::search(), rujuk dokblok
            // kelas tsb). nickname = nama gaya/nickname TAK FORMAL yg merchant9.com guna utk satu
            // design (cth. "Cincin Fesyen Coco Pasir Dimensi L:0.7CM"), BERBEZA drpd
            // Description/InternalCode formal JEMiSys. snake_case (spt synced_at/image_url)
            // SENGAJA berbeza drpd PascalCase 140+ lajur mirror sebenar.
            $table->string('nickname', 200)->nullable()->after('image_url');

            // Bila baris ni kali TERAKHIR "ditanya" merchant9.com (BUKAN bila nickname/image_url
            // dikemas kini) - diisi WALAUPUN search() x jumpa padanan (nickname/image_url kekal
            // null dlm kes tu), supaya kod yg SAH x wujud di storefront TAK discrape berulang
            // setiap run. Ini jugak signal "kerja tinggal" App\Jobs\SyncMerchantNicknamesAndImages
            // guna (WHERE merchant_synced_at IS NULL) - lajur DB (bukan cache) SENGAJA, supaya x
            // hilang bila SyncJemisysMirrors::handle() panggil Cache::flush() setiap run (rujuk
            // dokblok job tsb).
            $table->timestamp('merchant_synced_at')->nullable()->after('nickname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropColumn(['nickname', 'merchant_synced_at']);
        });
    }
};
