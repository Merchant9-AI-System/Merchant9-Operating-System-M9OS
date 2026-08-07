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
            // nickname (baru diisi App\Jobs\SyncMerchantNicknamesAndImages) tiada index langsung -
            // LIKE '%search%' (wildcard kedua-dua hujung) MESTI full table scan (490k+ baris),
            // sama isu yg dah diselesaikan utk Description (rujuk
            // add_search_indexes_to_jemisys_inventory_mirror_table). FULLTEXT bagi carian
            // perkataan/awalan jadi pantas - innodb_ft_min_token_size lalai = 3, carian < 3
            // aksara TAK PADAN via FULLTEXT (search() kendalikan ni eksplisit, sama spt
            // Description).
            $table->fullText('nickname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropFullText(['nickname']);
        });
    }
};
