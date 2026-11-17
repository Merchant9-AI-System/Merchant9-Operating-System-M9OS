<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Cawangan sasaran sesuatu arahan HQ (hanya bila all_branches = false). Kod cawangan disimpan trim + huruf besar. */
    public function up(): void
    {
        Schema::create('hq_direction_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hq_direction_id')->constrained('hq_directions')->cascadeOnDelete();
            $table->string('store_code', 20);

            $table->unique(['hq_direction_id', 'store_code']);
            $table->index('store_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hq_direction_branches');
    }
};
