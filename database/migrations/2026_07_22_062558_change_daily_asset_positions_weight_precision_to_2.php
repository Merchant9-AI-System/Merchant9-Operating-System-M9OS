<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lajur berat (gram) DENGAN default(0) - dikira semula automatik / medan bukan-wajib. */
    private const WEIGHT_COLUMNS_WITH_DEFAULT = [
        'new_stock', 'used_gold', 'gold_bar', 'unreceived_bar', 'loan_received', 'total_stock_in',
        'sales', 'payment_to_supplier', 'stock_out_return', 'loss_from_melting', 'loan_out',
        'total_stock_out', 'supplier_hutang', 'supplier_overpaid', 'net_weight', 'locked_gold_bar',
    ];

    /** Lajur berat TANPA default - dikeyin accountant terus (wajib), rujuk migration asal. */
    private const WEIGHT_COLUMNS_REQUIRED = ['opening_stock_weight', 'closing_stock'];

    public function up(): void
    {
        // ALTER decimal(14,3) -> decimal(14,2) BULATKAN nilai sedia ada kpd 2 titik perpuluhan
        // (arahan eksplisit) - digit ke-3 (mg) hilang kekal bagi rekod sejarah. nullable/default
        // setiap lajur DIKEKALKAN SAMA spt migration asal (change() gantikan definisi PENUH lajur).
        Schema::table('daily_asset_positions', function (Blueprint $table) {
            foreach (self::WEIGHT_COLUMNS_WITH_DEFAULT as $column) {
                $table->decimal($column, 14, 2)->default(0)->change();
            }

            foreach (self::WEIGHT_COLUMNS_REQUIRED as $column) {
                $table->decimal($column, 14, 2)->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembali ke decimal(14,3) - TIDAK boleh pulihkan digit ke-3 yg dah hilang semasa up(),
        // hanya kembalikan skema (bukan ketepatan data).
        Schema::table('daily_asset_positions', function (Blueprint $table) {
            foreach (self::WEIGHT_COLUMNS_WITH_DEFAULT as $column) {
                $table->decimal($column, 14, 3)->default(0)->change();
            }

            foreach (self::WEIGHT_COLUMNS_REQUIRED as $column) {
                $table->decimal($column, 14, 3)->change();
            }
        });
    }
};
