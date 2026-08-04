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
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            // Nota per-item drpd staf semasa hantar (BEZA drpd review_notes - tu utk HQ semasa
            // semakan). Cth. "urgent utk pelanggan VIP" atau "warna kegemaran pelanggan".
            $table->string('remark')->nullable()->after('qty_requested');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};
