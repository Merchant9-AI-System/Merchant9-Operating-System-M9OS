<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paras setiap design dlm Senarai Restock (keputusan BO: setiap cawangan patut ada berapa unit):
     * - target_stock: paras lalai utk SEMUA cawangan jualan design ini.
     * - branch_targets: pengecualian setiap cawangan, cth {"WM": 5, "DAMAI": 2}. Kosong = semua guna target_stock.
     * - allocation: agihan Qty order ke setiap cawangan semasa Simpan (cth {"WM": 3, "DAMAI": 2}) - disimpan
     *   supaya Print/Export tunjuk agihan tetap walaupun stok berubah kemudian.
     */
    public function up(): void
    {
        Schema::table('restock_list_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('target_stock')->default(3)->after('suggested_qty');
            $table->json('branch_targets')->nullable()->after('target_stock');
            $table->json('allocation')->nullable()->after('branch_targets');
        });
    }

    public function down(): void
    {
        Schema::table('restock_list_items', function (Blueprint $table) {
            $table->dropColumn(['target_stock', 'branch_targets', 'allocation']);
        });
    }
};
