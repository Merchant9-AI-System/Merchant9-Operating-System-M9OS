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
        Schema::create('stock_rearrangement_stops', function (Blueprint $table) {
            $table->id();
            $table->string('internal_code');
            $table->string('item_desc')->nullable();
            $table->text('reason');
            $table->string('status')->default('Pending'); // Pending/Approved (aktif-exclude) -> Rejected (muncul semula)
            $table->string('requested_by');
            $table->timestamp('requested_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('internal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_rearrangement_stops');
    }
};
