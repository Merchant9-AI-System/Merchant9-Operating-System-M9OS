<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda sumber nickname/gambar pada jadual cermin: NULL = padanan laman web merchant9.com sendiri (SyncMerchantNicknamesAndImages),
     * 'family' = diwarisi drpd kod adik-beradik dlm keluarga reka bentuk yg sama (rujuk App\Support\DesignFamily) - supaya
     * boleh dikira semula/dibersihkan & dipaparkan sbg "dikongsi". SyncJemisysMirrors::prepareMerchantCarryForward() WAJIB
     * membawa kedua-dua lajur ini ke hadapan (cermin di-truncate setiap hari).
     *
     * SATU pernyataan ALTER (bukan dua): jadual cermin ~1.4 GB dgn indeks FULLTEXT - menambah lajur memaksa MySQL membina semula
     * (menyalin) seluruh jadual, ~12 minit SETIAP ALTER di mesin dev & menyekat tulis. Schema::table() dgn dua lajur menjana DUA
     * ALTER berasingan (dua salinan penuh), jadi guna SQL mentah pd MySQL/MariaDB. Jalankan di luar waktu sibuk (bukan semasa sync 08:30).
     */
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE jemisys_inventory_mirror ADD COLUMN nickname_source VARCHAR(10) NULL, ADD COLUMN image_source VARCHAR(10) NULL');

            return;
        }

        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->string('nickname_source', 10)->nullable();
            $table->string('image_source', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropColumn(['nickname_source', 'image_source']);
        });
    }
};
