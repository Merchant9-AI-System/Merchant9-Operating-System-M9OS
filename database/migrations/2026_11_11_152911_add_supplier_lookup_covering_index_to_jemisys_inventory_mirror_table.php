<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Halaman Supplier Lookup (App\Support\SupplierLookup) mengira Amik/Sold setiap design x cawangan bagi piece yg PurchDate-nya
 * dlm tempoh: PurchDate (julat) + InternalCode, StoreCode, Status (kiraan terjual) & VendorCode. Tiada index sedia ada yg
 * memuatkan kesemuanya (index catalog stock-in tiada StoreCode/Status), jadi MySQL melompat ke jadual ~1.5 GB baris demi
 * baris (diukur ~212 saat utk 180 hari). Index covering ini menjawab query drpd index sahaja. Menambah index sekunder
 * InnoDB ialah operasi dalam-tempat (tiada salinan jadual, tulis tak disekat) - jauh lebih ringan drpd menambah lajur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->index(
                ['PurchDate', 'InternalCode', 'StoreCode', 'Status', 'VendorCode'],
                'idx_mirror_supplier_lookup_covering',
            );
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_supplier_lookup_covering');
        });
    }
};
