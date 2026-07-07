<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_asset_positions', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->unique();

            // Pergerakan stok (berat, gram - sepadan unit GoldWeight JEMiSys utk perbandingan terus).
            $table->decimal('opening_stock_weight', 14, 3);
            $table->decimal('new_stock', 14, 3)->default(0);
            $table->decimal('used_gold', 14, 3)->default(0);
            $table->decimal('gold_bar', 14, 3)->default(0);
            $table->decimal('unreceived_bar', 14, 3)->default(0);
            $table->decimal('loan_received', 14, 3)->default(0);
            $table->decimal('total_stock_in', 14, 3)->default(0); // dikira semula automatik, bukan input terus

            $table->decimal('sales', 14, 3)->default(0);
            $table->decimal('payment_to_supplier', 14, 3)->default(0);
            $table->decimal('stock_out_return', 14, 3)->default(0);
            $table->decimal('loss_from_melting', 14, 3)->default(0);
            $table->decimal('loan_out', 14, 3)->default(0);
            $table->decimal('total_stock_out', 14, 3)->default(0); // dikira semula automatik

            $table->decimal('closing_stock', 14, 3); // dikeyin oleh accountant, dibanding vs pengiraan

            $table->decimal('supplier_hutang', 14, 3)->default(0);
            $table->decimal('supplier_overpaid', 14, 3)->default(0);
            $table->decimal('net_weight', 14, 3)->default(0); // dikira semula automatik

            // Tunai/bank (RM).
            $table->decimal('ambank_balance', 14, 2)->default(0);
            $table->decimal('affin_balance', 14, 2)->default(0);
            $table->decimal('cash', 14, 2)->default(0);
            $table->decimal('affin_rm', 14, 2)->default(0);
            $table->decimal('od_affin', 14, 2)->default(0);
            $table->decimal('locked_gold_bar', 14, 3)->default(0); // berat
            $table->decimal('available_cash', 14, 2)->default(0); // dikira semula automatik

            $table->text('notes')->nullable();
            $table->string('created_by');
            $table->string('updated_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_asset_positions');
    }
};
