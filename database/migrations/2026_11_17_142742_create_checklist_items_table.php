<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Templat Daily Checklist (M02): senarai tugasan harian staf jualan, diurus admin (Filament, kebenaran Shield).
     * `due_time` = waktu Malaysia sebelum item dianggap tertunggak (kosong = tiada had). `store_codes` kosong
     * = semua cawangan. Item BUKAN rawak setiap hari - sama setiap hari selagi aktif.
     */
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->time('due_time')->nullable();
            $table->json('store_codes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};
