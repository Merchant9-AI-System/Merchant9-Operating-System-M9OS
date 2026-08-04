<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            // InventoryCode cuma wujud sbg lajur KEDUA index unik (StoreCode, InventoryCode) -
            // carian "InventoryCode LIKE '{search}%'" TANPA StoreCode (rujuk pemilihan cawangan
            // BranchDemandEntryController::search()) tak dpt guna index tsb, terpaksa index scan
            // penuh. Index berasingan di sini bagi carian kod fizikal seketul jadi range scan cepat.
            $table->index('InventoryCode');

            // Description tiada index langsung - LIKE '%search%' (wildcard kedua-dua hujung) MESTI
            // full table scan (488k+ baris). FULLTEXT bagi carian perkataan/awalan jadi pantas -
            // nota: innodb_ft_min_token_size lalai = 3, jadi carian < 3 aksara TAK PADAN via FULLTEXT
            // (search() kendalikan ni secara eksplisit, skip carian Description utk carian 2 aksara).
            $table->fullText('Description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex(['InventoryCode']);
            $table->dropFullText(['Description']);
        });
    }
};
