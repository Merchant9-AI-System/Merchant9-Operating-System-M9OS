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
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            // Tiada index langsung sebelum ni - MAX(synced_at)/MAX(merchant_synced_at)
            // (App\Filament\Pages\JemisysConnectionStatus) kena FULL TABLE SCAN 490K baris,
            // disahkan ~9.6-9.9s SETIAP panggilan - punca sebenar 504 Gateway Timeout bersama
            // isu caching yg dah diselesaikan berasingan (rujuk dokblok kaedah tsb).
            $table->index('synced_at');
            $table->index('merchant_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex(['synced_at']);
            $table->dropIndex(['merchant_synced_at']);
        });
    }
};
