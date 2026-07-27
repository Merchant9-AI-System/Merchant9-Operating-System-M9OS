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
            // Utk kategori New Stock Not Yet Key-in - sepadan lajur Weekly Stock Report sebenar
            // (Workmanship, Gold price, Gold Amount, Total Price). gold_amount & total_price
            // sentiasa dikira semula (bukan input terus), rujuk PhysicalGoldReportLine::booted().
            $table->decimal('workmanship_amount', 20, 4)->nullable()->after('pure_weight');
            $table->decimal('gold_price_per_gram', 20, 4)->nullable()->after('workmanship_amount');
            $table->decimal('gold_amount', 20, 4)->nullable()->after('gold_price_per_gram');
            $table->decimal('total_price', 20, 4)->nullable()->after('gold_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('physical_gold_report_lines', function (Blueprint $table) {
            $table->dropColumn(['workmanship_amount', 'gold_price_per_gram', 'gold_amount', 'total_price']);
        });
    }
};
