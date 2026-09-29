<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiada index leading Status di jadual ni langsung (semua composite sedia ada letak Status
 * lajur lain dulu) - GROUP BY Status (InventoryStatusOverview widget) & WHERE Status = ?
 * (SelectFilter Status pd InventoryStatusesTable) kena full table scan, ambil ~6.5s drpd 500K+
 * baris. Index tunggal ni terus percepatkan kedua-dua corak query tsb.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->index('Status', 'idx_mirror_status');
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_status');
        });
    }
};
