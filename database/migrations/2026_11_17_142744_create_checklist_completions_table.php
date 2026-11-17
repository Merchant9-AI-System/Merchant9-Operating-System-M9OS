<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda "siap" Daily Checklist bagi SATU cawangan pada SATU hari (bukan setiap staf, tiada bukti gambar).
     * `date` = hari Malaysia. Unik (item, cawangan, hari) - menanda dua kali tidak menggandakan.
     */
    public function up(): void
    {
        Schema::create('checklist_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_item_id')->constrained('checklist_items')->cascadeOnDelete();
            $table->string('store_code', 20);
            $table->date('date');
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completed_by_name');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['checklist_item_id', 'store_code', 'date'], 'checklist_completion_unique');
            $table->index(['store_code', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_completions');
    }
};
