<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Lookup mengira Total Sold bagi piece yg SalesDate-nya dlm tempoh JUALAN (berasingan drpd tempoh stock in):
 * SalesDate (julat) + InternalCode, StoreCode, VendorCode & Status. Index jualan sedia ada (catalog sales) tiada StoreCode/Status, jadi
 * MySQL melompat ke jadual baris demi baris. Index covering ini menjawab query drpd index sahaja (operasi dalam-tempat InnoDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->index(
                ['SalesDate', 'InternalCode', 'StoreCode', 'VendorCode', 'Status'],
                'idx_mirror_supplier_sales_covering',
            );
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_supplier_sales_covering');
        });
    }
};
