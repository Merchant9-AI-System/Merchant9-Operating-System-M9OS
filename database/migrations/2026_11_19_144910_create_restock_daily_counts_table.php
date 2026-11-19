<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiraan harian Sold Out & Perlu Restock setiap cawangan - sumber carta trend pada halaman Restock Analysis ("makin baik atau makin teruk?").
 * Ditulis (upsert) oleh App\Support\RestockSnapshotMaterializer pada setiap larian; satu baris setiap hari x cawangan, jadi sangat kecil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restock_daily_counts', function (Blueprint $table) {
            $table->id();
            $table->date('counted_on');
            $table->string('store_code', 20);
            $table->unsignedInteger('sold_out')->default(0);
            $table->unsignedInteger('restock')->default(0);

            $table->unique(['counted_on', 'store_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_daily_counts');
    }
};
