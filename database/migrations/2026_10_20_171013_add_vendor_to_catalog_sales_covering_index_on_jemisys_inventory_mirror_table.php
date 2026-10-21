<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier catalog (projek supplier-katelog, DB sama) kini kira jualan setiap vendor bagi sesuatu
 * design dalam tempoh yang dipilih (kad ikut vendor yang dicari / vendor jualan terakhir).
 * Index covering jualan sedia ada (SalesDate, InternalCode, GoldWeight) tiada VendorCode, jadi
 * mengumpul ikut vendor akan lompat ke jadual baris demi baris. Index baharu ini menambah
 * VendorCode supaya query dijawab drpd index sahaja. Index lama dikekalkan kerana app masih
 * boleh guna sbg fallback - boleh dibuang kemudian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->index(
                ['SalesDate', 'InternalCode', 'VendorCode', 'GoldWeight'],
                'idx_mirror_catalog_sales_vendor_covering',
            );
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_catalog_sales_vendor_covering');
        });
    }
};
