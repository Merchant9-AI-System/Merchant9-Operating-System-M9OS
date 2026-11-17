<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arahan HQ kepada cawangan (HQ Direction): dicipta HQ/CEO melalui widget Filament, dipaparkan pd /home ketua
     * cawangan sepanjang tempoh berkuat kuasa. `body` = HTML rich editor (WAJIB disanitasi sebelum dipaparkan).
     * `all_branches` = semua cawangan jualan; jika tidak, cawangan dipilih di hq_direction_branches.
     */
    public function up(): void
    {
        Schema::create('hq_directions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->longText('body');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('all_branches')->default(false);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hq_directions');
    }
};
