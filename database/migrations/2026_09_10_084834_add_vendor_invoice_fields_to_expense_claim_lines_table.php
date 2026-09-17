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
            // Toggle "Ada Invois Vendor?" borang - item macam Google/Facebook/TikTok Ads ada
            // invois formal dgn breakdown cukai (rujuk contoh invois Google Ads), CEO perlu
            // nampak butiran ni sebelum approve, bukan cuma 1 jumlah lump sum.
            $table->boolean('is_vendor_invoice')->default(false)->after('category');
            $table->string('vendor')->nullable()->after('is_vendor_invoice');
            $table->string('invoice_number')->nullable()->after('vendor');
            // amount (lajur sedia ada) kekal sbg JUMLAH KESELURUHAN - bila is_vendor_invoice,
            // dikira auto server-side (base_amount + tax_amount), bukan ditaip terus.
            $table->decimal('base_amount', 10, 2)->nullable()->after('amount');
            $table->decimal('tax_amount', 10, 2)->nullable()->after('base_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_claim_lines', function (Blueprint $table) {
            $table->dropColumn(['is_vendor_invoice', 'vendor', 'invoice_number', 'base_amount', 'tax_amount']);
        });
    }
};
