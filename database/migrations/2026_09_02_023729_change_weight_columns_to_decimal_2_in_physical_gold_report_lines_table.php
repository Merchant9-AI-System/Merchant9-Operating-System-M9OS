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
        Schema::table('physical_gold_report_lines', function (Blueprint $table) {
            // Berat (g)/Berat Tulen (g) sepatutnya 2dp (bukan 4dp) atas permintaan eksplisit -
            // ->change() ke decimal(20,2) MEMBULATKAN nilai sedia ada tersimpan terus (bukan
            // sekadar format paparan), sepadan konvensyen migration "weight" sesi ni.
            $table->decimal('gross_weight', 20, 2)->nullable()->change();
            $table->decimal('pure_weight', 20, 2)->nullable()->change();
            $table->decimal('payable_gross_weight', 20, 2)->nullable()->change();
            $table->decimal('receivable_gross_weight', 20, 2)->nullable()->change();
            $table->decimal('payable_pure_weight', 20, 2)->nullable()->change();
            $table->decimal('receivable_pure_weight', 20, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('physical_gold_report_lines', function (Blueprint $table) {
            $table->decimal('gross_weight', 20, 4)->nullable()->change();
            $table->decimal('pure_weight', 20, 4)->nullable()->change();
            $table->decimal('payable_gross_weight', 20, 4)->nullable()->change();
            $table->decimal('receivable_gross_weight', 20, 4)->nullable()->change();
            $table->decimal('payable_pure_weight', 20, 4)->nullable()->change();
            $table->decimal('receivable_pure_weight', 20, 4)->nullable()->change();
        });
    }
};
