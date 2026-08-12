<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * JobSheetNo (varchar(10)) ada spacing tak konsisten drpd JEMiSys (cth. "JS000001 ") - carian
 * (rujuk JobsheetLookupController) WAJIB TRIM() dua-dua belah utk padanan tepat, tapi TRIM(lajur)
 * dlm WHERE tolak indeks B-tree biasa. MySQL 8.0.30 (disahkan versi semasa) sokong indeks
 * fungsian terus - lebih tepat drpd indeks lajur mentah (yg x terpakai bila WHERE guna TRIM()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX jemisys_inventory_mirror_jobsheetno_trim_index ON jemisys_inventory_mirror ((TRIM(JobSheetNo)))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX jemisys_inventory_mirror_jobsheetno_trim_index ON jemisys_inventory_mirror');
    }
};
