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
        Schema::create('physical_gold_report_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_gold_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('physical_gold_category_id')->constrained();

            // Jemisys\Store / Jemisys\Vendor referenced by plain code, no FK - matches
            // PurchaseOrder.vendor_code / StockTransfer.from_store/to_store house convention.
            $table->string('store_code')->nullable();
            $table->string('vendor_code')->nullable();

            $table->foreignId('physical_gold_purity_id')->nullable()->constrained();
            $table->string('remarks')->nullable(); // free-text tag e.g. "YS", "KIV"

            $table->date('date_range_from')->nullable();
            $table->date('date_range_to')->nullable();

            $table->decimal('gross_weight', 20, 4)->nullable();
            $table->decimal('pure_weight', 20, 4)->nullable();

            $table->decimal('payable_pure_weight', 20, 4)->nullable();
            $table->decimal('receivable_pure_weight', 20, 4)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('physical_gold_report_lines');
    }
};
