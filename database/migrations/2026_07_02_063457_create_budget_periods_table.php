<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period_label'); // cth "2026-08"
            $table->string('category_code')->nullable(); // null = keseluruhan (semua kategori)
            $table->decimal('budget_amount', 14, 2);
            $table->string('created_by');
            $table->timestamps();

            $table->unique(['period_label', 'category_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_periods');
    }
};
