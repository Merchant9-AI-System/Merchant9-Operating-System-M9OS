<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staf cawangan menaip beberapa saiz dlm satu medan (cth. "8-10, 11-15, 16-18, 20, 23" = 26 aksara) - lajur 20 aksara
     * menolak hantaran Leader WM (19 Okt 2026) dgn ralat pengesahan yg tak dipaparkan di borang. Had dinaikkan kpd 100.
     */
    public function up(): void
    {
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            $table->string('size', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('branch_demand_request_lines', function (Blueprint $table) {
            $table->string('size', 20)->nullable()->change();
        });
    }
};
