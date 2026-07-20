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
        // Senarai VendorCode unik (dipisah koma) bagi design ini - VendorCode lama (MAX single
        // value) menyembunyikan bila satu design dibekalkan oleh >1 vendor (cth. CFWWDL00K02G:
        // GRJ, GJ, '.'). Kekalkan lajur VendorCode sedia ada utk backward compat.
        Schema::table('stockout_reorder_candidates', function (Blueprint $table) {
            $table->text('vendor_codes')->nullable()->after('VendorCode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stockout_reorder_candidates', function (Blueprint $table) {
            $table->dropColumn('vendor_codes');
        });
    }
};
