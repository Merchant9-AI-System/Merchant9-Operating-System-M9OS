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
            // Berat kasar payable/receivable - borang kini kumpul berat KASAR utk kategori
            // "Outstanding Gold Due to Suppliers" (bukan terus berat tulen), ditukar ke
            // payable_pure_weight/receivable_pure_weight via faktor ketulenan baris (rujuk
            // PhysicalGoldReportLine::booted()).
            $table->decimal('payable_gross_weight', 20, 4)->nullable()->after('gross_weight');
            $table->decimal('receivable_gross_weight', 20, 4)->nullable()->after('payable_gross_weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('physical_gold_report_lines', function (Blueprint $table) {
            $table->dropColumn(['payable_gross_weight', 'receivable_gross_weight']);
        });
    }
};
