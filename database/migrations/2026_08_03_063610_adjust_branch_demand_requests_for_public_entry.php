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
        Schema::table('branch_demand_requests', function (Blueprint $table) {
            // Borang awam (Inertia, tiada login) tiada User sebenar - simpan nama taip tangan.
            $table->string('submitted_by_name')->nullable()->after('submitted_by_id');
            $table->unsignedBigInteger('submitted_by_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_demand_requests', function (Blueprint $table) {
            $table->dropColumn('submitted_by_name');
            $table->unsignedBigInteger('submitted_by_id')->nullable(false)->change();
        });
    }
};
