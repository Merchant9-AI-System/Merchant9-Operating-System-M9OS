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
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            // 'catalog' (biasa, ada InternalCode sebenar) vs 'web' (cadangan carian laman web
            // merchant9.com - staf cari guna nickname tak formal, rujuk MerchantWebsiteSearch -
            // TIADA InternalCode boleh dipercayai, HQ padankan ke stok sebenar semasa semakan).
            $table->string('source_type', 10)->default('catalog')->after('internal_code');

            // Imej design biasa (source_type='catalog') sentiasa boleh diambil semula bila-bila
            // via ProductImageFetcher::firstImageUrlFor($internal_code) - TAK perlu simpan. Line
            // 'web' pula TIADA internal_code utk buat carian semula, jadi imej mesti disimpan
            // terus di sini semasa staf pilih, supaya HQ tetap nampak gambar rujukan semasa semakan.
            $table->string('image_url', 500)->nullable()->after('source_type');

            // Line 'web' TIADA kod design sebenar - internal_code jadi pilihan (nullable), kekal
            // wajib utk line 'catalog' biasa (dikuatkuasakan di validasi store(), bukan di DB).
            // Panjang (255) dikekalkan sama dgn migration asal (string('internal_code') lalai).
            $table->string('internal_code')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'image_url']);
            $table->string('internal_code')->nullable(false)->change();
        });
    }
};
