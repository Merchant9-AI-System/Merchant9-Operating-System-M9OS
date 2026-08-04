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
            // Landasan BO SENDIRI (Listed -> Dah Order -> Dah Restock -> Dah Delivery, dll) -
            // BERASINGAN drpd line_status (keputusan Lulus/Tolak HQ, tak berubah). Sesetengah
            // permintaan makan 2-3 hari bekerja utk selesai - medan ni benarkan BO kemaskini
            // progress dari semasa ke semasa, & staf cawangan nampak progress tsb (rujuk
            // RequestList.vue) tanpa tunggu keputusan akhir Lulus/Tolak.
            $table->string('fulfillment_status', 30)->default('requested')->after('line_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            $table->dropColumn('fulfillment_status');
        });
    }
};
