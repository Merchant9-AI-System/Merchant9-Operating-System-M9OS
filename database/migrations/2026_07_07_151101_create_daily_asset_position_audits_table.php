<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit ringkas utk Daily Asset Position - tiada pakej composer baru (spatie/laravel-permission
 * sahaja dipasang dlm app ni), rekod diri sendiri (append-only, tiada updated_at sebab tak pernah diubah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_asset_position_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_asset_position_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // created | updated
            $table->string('actor');
            $table->json('changes')->nullable(); // ['field' => ['old' => ..., 'new' => ...], ...]
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_asset_position_audits');
    }
};
