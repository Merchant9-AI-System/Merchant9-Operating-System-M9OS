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
        Schema::table('expense_claims', function (Blueprint $table) {
            // `user_id` (sedia ada) ialah CLAIMANT (cth. CEO En Haniff) - org yg expense tu
            // untuk. `created_by_id` (baharu) ialah staf Finance (cth. Aqilah) yg SEBENARNYA
            // buat kemasukan data - claimant TIDAK semestinya org yg key-in claim sendiri
            // (rujuk restructure "Finance create bagi pihak CEO").
            $table->foreignId('created_by_id')->nullable()->after('user_id')->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
        });
    }
};
