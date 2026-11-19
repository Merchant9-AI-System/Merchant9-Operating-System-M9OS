<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sejarah PERUBAHAN Cadangan sahaja (bukan snapshot penuh setiap hari) - cukup utk "apa yang baharu Sold Out sejak semalam".
 * Ditulis oleh App\Support\RestockSnapshotMaterializer apabila Cadangan sebuah design x cawangan berbeza drpd snapshot sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restock_verdict_changes', function (Blueprint $table) {
            $table->id();
            $table->string('internal_code', 60);
            $table->string('store_code', 20);
            $table->string('category_code', 20)->nullable();
            // null = design baharu muncul dlm snapshot.
            $table->string('from_verdict', 40)->nullable();
            $table->string('to_verdict', 40);
            $table->dateTime('changed_at');

            $table->index(['changed_at', 'to_verdict']);
            $table->index(['store_code', 'changed_at']);
            $table->index(['internal_code', 'store_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_verdict_changes');
    }
};
