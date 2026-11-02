<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nota bebas BO utk design dlm Senarai Restock (cth. alasan qty/paras diubah, arahan khas
     * utk supplier) - papar & disunting terus di lajur "Remark" (sebelum "Qty order").
     */
    public function up(): void
    {
        Schema::table('restock_list_items', function (Blueprint $table) {
            $table->text('remark')->nullable()->after('allocation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restock_list_items', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};
