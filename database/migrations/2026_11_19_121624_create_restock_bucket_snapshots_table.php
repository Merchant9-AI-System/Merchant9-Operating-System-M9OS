<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan restock per KATEGORI x CAWANGAN x BUCKET (saiz atau berat) - sumber paparan Saiz/Berat, dikira bersama
 * restock_snapshots (rujuk App\Support\RestockSnapshotMaterializer). Lajur jualan/penerimaan sama spt restock_snapshots.
 */
return new class extends Migration
{
    private const PERIODS = ['1w', '1m', '3m', '6m', '1y', 'all'];

    public function up(): void
    {
        Schema::create('restock_bucket_snapshots', function (Blueprint $table) {
            $table->id();
            // 'size' atau 'weight'.
            $table->string('kind', 10);
            $table->string('category_code', 20);
            $table->string('store_code', 20);
            $table->string('bucket', 40);
            $table->integer('stock')->default(0);

            foreach (self::PERIODS as $period) {
                $table->unsignedInteger("sold_{$period}")->default(0);
            }

            foreach (self::PERIODS as $period) {
                $table->unsignedInteger("received_{$period}")->default(0);
            }

            $table->decimal('velocity_per_month', 10, 2)->default(0);
            $table->integer('target_stock')->default(0);
            $table->integer('gap')->default(0);
            $table->decimal('cover_months', 8, 1)->nullable();
            $table->string('verdict', 40);
            $table->dateTime('computed_at');

            $table->unique(['kind', 'category_code', 'store_code', 'bucket'], 'restock_bucket_unique');
            $table->index(['kind', 'store_code', 'verdict', 'gap'], 'restock_bucket_lookup');
            $table->index('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_bucket_snapshots');
    }
};
