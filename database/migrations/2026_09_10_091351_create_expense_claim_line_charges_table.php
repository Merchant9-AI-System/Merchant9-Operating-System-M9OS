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
        // Baris caj bebas (description bebas taip + amount) di bawah 1 line "Ada Invois Vendor?"
        // - gantikan pasangan medan tetap base_amount/tax_amount asal, supaya boleh tambah
        // BERAPA banyak caj pun ikut invois sebenar (cth. invois Google Ads: Amount + Service
        // Tax 8% + Turkey Regulatory Operating Cost + India Regulatory Operating Cost + Service
        // Tax atas caj tsb - 5 baris, bukan cuma 2).
        Schema::create('expense_claim_line_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_claim_line_id')->constrained('expense_claim_lines')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });

        Schema::table('expense_claim_lines', function (Blueprint $table) {
            $table->dropColumn(['base_amount', 'tax_amount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_claim_lines', function (Blueprint $table) {
            $table->decimal('base_amount', 10, 2)->nullable()->after('amount');
            $table->decimal('tax_amount', 10, 2)->nullable()->after('base_amount');
        });

        Schema::dropIfExists('expense_claim_line_charges');
    }
};
