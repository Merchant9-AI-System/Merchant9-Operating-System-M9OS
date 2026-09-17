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
        Schema::table('expense_claim_lines', function (Blueprint $table) {
            // % WHT yg dipilih (ToggleGroup borang - '8'/'10') - disimpan BERASINGAN drpd
            // `wht_amount` (jumlah terkira) supaya bila line invois vendor dibuka semula utk
            // edit, toggle WHT boleh pre-select balik nilai asal (rujuk Edit.vue lineForm.wht).
            $table->string('wht', 2)->nullable()->after('wht_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_claim_lines', function (Blueprint $table) {
            $table->dropColumn('wht');
        });
    }
};
