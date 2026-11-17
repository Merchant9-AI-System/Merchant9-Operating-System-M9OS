<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akuan "Faham" ketua cawangan - SATU akuan bagi setiap (arahan, cawangan). Arahan yang telah diakui kekal
     * kelihatan di /home pada hari akuan sahaja (tarikh acknowledged_at, waktu Malaysia).
     */
    public function up(): void
    {
        Schema::create('hq_direction_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hq_direction_id')->constrained('hq_directions')->cascadeOnDelete();
            $table->string('store_code', 20);
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acknowledged_by_name');
            $table->timestamp('acknowledged_at');

            $table->unique(['hq_direction_id', 'store_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hq_direction_acknowledgements');
    }
};
