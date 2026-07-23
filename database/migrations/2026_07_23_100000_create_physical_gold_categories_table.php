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
        Schema::create('physical_gold_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('value_mode')->default('gross_purity'); // 'gross_purity' | 'payable_receivable'
            $table->boolean('requires_branch')->default(false);
            $table->boolean('requires_supplier')->default(false);
            $table->boolean('requires_purity')->default(false);
            $table->boolean('requires_date_range')->default(false);
            $table->boolean('include_in_physical_total')->default(true);
            $table->boolean('is_deduction')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('physical_gold_categories');
    }
};
