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
        // Jadual ni asalnya PK InternalCode tunggal (definisi LALAI "overall" sahaja). Julat
        // tarikh (7/30/90/180/365 hari, overall) masing-masing ada set design layak BERBEZA
        // (design boleh layak utk "1 tahun" tapi tak layak utk "1 minggu"), jadi PK jadi
        // komposit (InternalCode, range_bucket) - satu baris per (design, julat) yg layak,
        // bukan satu baris global. Rujuk App\Support\StockoutReorderMaterializer::
        // materializeQualifyingDesigns() utk cara diisi (per julat, drpd stockout_reorder_
        // candidates yg sudah dimaterialize - BUKAN scan 481K baris jemisys_inventory_mirror
        // semula setiap julat).
        Schema::table('stockout_reorder_qualifying_designs', function (Blueprint $table) {
            $table->dropPrimary();
            $table->string('range_bucket', 10)->default('overall');
            $table->primary(['InternalCode', 'range_bucket']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stockout_reorder_qualifying_designs', function (Blueprint $table) {
            $table->dropPrimary(['InternalCode', 'range_bucket']);
            $table->dropColumn('range_bucket');
            $table->primary('InternalCode');
        });
    }
};
