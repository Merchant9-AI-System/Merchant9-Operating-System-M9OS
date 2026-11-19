<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kedudukan kemendesakan Cadangan (0 = Sold Out ... 4 = Data Tak Cukup) sebagai lajur berindeks - susunan lalai halaman Restock Analysis
 * (paling mendesak dahulu) kini boleh guna indeks, bukan CASE yg menyusun ratusan ribu baris setiap permintaan.
 * Baris sedia ada diisi semula di sini; selepas ini RestockSnapshotMaterializer yg mengisinya.
 */
return new class extends Migration
{
    /** @var array<string, int> */
    private const RANKS = [
        'Perlu Restock (Sold Out)' => 0,
        'Perlu Restock' => 1,
        'Overstock' => 2,
        'Stok Cukup' => 3,
    ];

    public function up(): void
    {
        Schema::table('restock_snapshots', function (Blueprint $table) {
            $table->unsignedTinyInteger('urgency')->default(4)->after('verdict');
            $table->index(['urgency', 'gap']);
            $table->index(['store_code', 'urgency', 'gap']);
        });

        Schema::table('restock_bucket_snapshots', function (Blueprint $table) {
            $table->unsignedTinyInteger('urgency')->default(4)->after('verdict');
            $table->index(['kind', 'urgency', 'gap'], 'restock_bucket_urgency');
        });

        $cases = collect(self::RANKS)->map(fn (int $rank, string $verdict) => "WHEN '{$verdict}' THEN {$rank}")->implode(' ');

        foreach (['restock_snapshots', 'restock_bucket_snapshots'] as $table) {
            DB::statement("UPDATE {$table} SET urgency = CASE verdict {$cases} ELSE 4 END");
        }
    }

    public function down(): void
    {
        Schema::table('restock_snapshots', function (Blueprint $table) {
            $table->dropIndex(['urgency', 'gap']);
            $table->dropIndex(['store_code', 'urgency', 'gap']);
            $table->dropColumn('urgency');
        });

        Schema::table('restock_bucket_snapshots', function (Blueprint $table) {
            $table->dropIndex('restock_bucket_urgency');
            $table->dropColumn('urgency');
        });
    }
};
