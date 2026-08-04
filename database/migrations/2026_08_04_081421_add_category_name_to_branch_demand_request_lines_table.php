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
            // Kategori TAK pernah disimpan sebelum ni (cuma ditafsir sekejap semasa search() drpd
            // JEMiSys Category - rujuk BranchDemandEntryController::search()) - perlu disimpan
            // terus di line supaya BO boleh susun/tapis ikut kategori di LinesRelationManager
            // tanpa join balik ke JEMiSys (yg x wujud utk line 'web'/'upload' pun).
            $table->string('category_name')->nullable()->after('weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            $table->dropColumn('category_name');
        });
    }
};
