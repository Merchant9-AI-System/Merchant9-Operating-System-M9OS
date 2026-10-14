<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dana rizab (RM) yg ditolak drpd Available Cash (rujuk DailyAssetPosition::calculateAvailableCash()).
     * Lalai 0 - rekod lama tak berubah nilai Available Cash / Cash For GB tersimpan.
     */
    public function up(): void
    {
        Schema::table('daily_asset_positions', function (Blueprint $table) {
            $table->decimal('reserved_fund', 14, 2)->default(0)->after('locked_gold_bar');
        });
    }

    public function down(): void
    {
        Schema::table('daily_asset_positions', function (Blueprint $table) {
            $table->dropColumn('reserved_fund');
        });
    }
};
