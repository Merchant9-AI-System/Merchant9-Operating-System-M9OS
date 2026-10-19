<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier catalog (projek supplier-katelog, DB sama) agregat setiap design ikut tempoh stok masuk
 * (PurchDate) dan jualan (SalesDate). Index sedia ada cuma cari baris - lajur lain dibaca dgn
 * lompat ke jadual satu-satu (~7s utk ~47K baris), jadi tempoh 90 hari ambil 10-65s & 2 tahun
 * tak muat dlm satu request. Dua index "covering" ini menjawab query drpd index sahaja:
 *  - stok masuk: PurchDate, InternalCode, VendorCode, CategoryCode, GoldWeight
 *  - jualan:     SalesDate, InternalCode, GoldWeight
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->index(
                ['PurchDate', 'InternalCode', 'VendorCode', 'CategoryCode', 'GoldWeight'],
                'idx_mirror_catalog_stockin_covering',
            );
            $table->index(
                ['SalesDate', 'InternalCode', 'GoldWeight'],
                'idx_mirror_catalog_sales_covering',
            );
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_catalog_stockin_covering');
            $table->dropIndex('idx_mirror_catalog_sales_covering');
        });
    }
};
