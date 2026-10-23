<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ProductImageFetcher::referenceImages() (dipanggil drpd papan Back Office Actions utk ~300 kod
 * design) whereIn(InternalCode) + where(image_source='nearest') + whereNotNull(image_url) - tiada
 * index sedia ada merangkumi image_source/image_url, jadi MySQL kena lompat baca baris sebenar
 * utk tiap kod (~9.3s diukur). Index "covering" ni menjawab query drpd index sahaja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->index(['InternalCode', 'image_source', 'image_url'], 'idx_mirror_board_image_ref_covering');
        });
    }

    public function down(): void
    {
        Schema::table('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->dropIndex('idx_mirror_board_image_ref_covering');
        });
    }
};
