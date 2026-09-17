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
        Schema::table('expense_claim_lines', function (Blueprint $table) {
            // MySQL benarkan byk baris NULL dlm unique index (setiap NULL dianggap berbeza),
            // jadi line biasa (bukan invois vendor, invoice_number sentiasa null) tak terjejas.
            $table->unique('invoice_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_claim_lines', function (Blueprint $table) {
            $table->dropUnique(['invoice_number']);
        });
    }
};
