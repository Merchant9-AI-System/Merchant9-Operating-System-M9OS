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
        Schema::create('branch_demand_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_demand_request_id')->constrained()->cascadeOnDelete();
            $table->string('internal_code');
            $table->string('item_desc')->nullable();
            $table->unsignedInteger('qty_requested');
            $table->unsignedInteger('qty_approved')->nullable();
            $table->string('line_status')->default('Pending');
            $table->string('review_notes')->nullable();
            $table->timestamps();

            $table->index('internal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_demand_request_lines');
    }
};
