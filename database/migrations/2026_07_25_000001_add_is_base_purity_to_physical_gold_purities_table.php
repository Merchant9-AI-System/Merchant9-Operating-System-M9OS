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
        Schema::table('physical_gold_purities', function (Blueprint $table) {
            // Bezakan set ketulenan "asas" (8 gred standard, sentiasa pra-isi di Used Gold at
            // HQ/GDN) drpd varian istimewa (930 blended, "916 - YS", "916 - KIV") yg cuma boleh
            // dipilih via "+ Tambah Baris Lain", bukan sebahagian set tetap lalai.
            $table->boolean('is_base_purity')->default(true)->after('factor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('physical_gold_purities', function (Blueprint $table) {
            $table->dropColumn('is_base_purity');
        });
    }
};
