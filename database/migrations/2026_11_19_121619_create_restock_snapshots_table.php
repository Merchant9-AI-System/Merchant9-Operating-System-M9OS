<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan restock per DESIGN x CAWANGAN, dikira sekali selepas segerak mirror harian (rujuk
 * App\Support\RestockSnapshotMaterializer) supaya halaman Restock Analysis membaca jadual kecil ini - bukan mengagregat
 * ratusan ribu piece setiap permintaan. Jualan/penerimaan disimpan utk SEMUA tempoh sekali (1w,1m,3m,6m,1y,all) jadi
 * menukar tempoh tidak memerlukan pengiraan semula. Cadangan/Disyorkan/Gap sentiasa dikira drpd tetingkap 3 bulan tetap.
 */
return new class extends Migration
{
    private const PERIODS = ['1w', '1m', '3m', '6m', '1y', 'all'];

    public function up(): void
    {
        Schema::create('restock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('internal_code', 60);
            $table->string('store_code', 20);
            $table->string('category_code', 20)->nullable();
            $table->string('vendor_code', 20)->nullable();
            $table->string('description', 255)->nullable();
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
            // Stok semasa cukup utk berapa bulan pada kadar jualan 3 bulan terkini (null = tiada jualan).
            $table->decimal('cover_months', 8, 1)->nullable();
            $table->string('verdict', 40);
            $table->dateTime('last_sold_at')->nullable();
            $table->dateTime('computed_at');

            $table->unique(['internal_code', 'store_code']);
            $table->index(['store_code', 'verdict', 'gap']);
            $table->index(['category_code', 'store_code']);
            $table->index('vendor_code');
            $table->index('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_snapshots');
    }
};
