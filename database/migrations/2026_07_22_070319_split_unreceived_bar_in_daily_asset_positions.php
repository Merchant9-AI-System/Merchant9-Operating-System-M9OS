<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('daily_asset_positions', function (Blueprint $table) {
            $table->decimal('unpaid_unreceived_bar', 14, 2)->default(0)->after('unreceived_bar');
            $table->decimal('paid_unreceived_bar', 14, 2)->default(0)->after('unpaid_unreceived_bar');
        });

        // Pindah nilai sejarah unreceived_bar SEPENUHNYA ke unpaid_unreceived_bar (arahan
        // eksplisit) - paid_unreceived_bar kekal 0 bagi rekod sedia ada, "unreceived" sejarah
        // secara semula jadi bermaksud belum settle/bayar.
        DB::table('daily_asset_positions')->update([
            'unpaid_unreceived_bar' => DB::raw('unreceived_bar'),
        ]);

        Schema::table('daily_asset_positions', function (Blueprint $table) {
            $table->dropColumn('unreceived_bar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_asset_positions', function (Blueprint $table) {
            $table->decimal('unreceived_bar', 14, 2)->default(0)->after('gold_bar');
        });

        // Gabung semula kedua lajur jadi satu - TIDAK boleh bezakan drpd nilai asal (data yg
        // dikeyin selepas split, kalau ada, akan digabung bersama, bukan hilang).
        DB::table('daily_asset_positions')->update([
            'unreceived_bar' => DB::raw('unpaid_unreceived_bar + paid_unreceived_bar'),
        ]);

        Schema::table('daily_asset_positions', function (Blueprint $table) {
            $table->dropColumn(['unpaid_unreceived_bar', 'paid_unreceived_bar']);
        });
    }
};
