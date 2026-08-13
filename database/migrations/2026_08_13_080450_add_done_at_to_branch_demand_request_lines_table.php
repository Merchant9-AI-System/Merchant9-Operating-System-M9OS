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
            // Ditanda BO drpd AllBranchDemandLinesTable ("Selesai") - berasingan drpd
            // fulfillment_status (landasan progress). Bila diisi, line ni disorok drpd
            // widget ringkasan HQ & "Item Sedia Ada" cawangan (Create.vue) - rekod kekal DB,
            // cuma disorok drpd paparan aktif (rujuk AllBranchDemandLinesTable::scopedQuery()
            // & BranchDemandEntryController::currentItems()).
            $table->timestamp('done_at')->nullable()->after('fulfillment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            $table->dropColumn('done_at');
        });
    }
};
