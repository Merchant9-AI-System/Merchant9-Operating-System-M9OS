<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Insight yg ditulis agent AI (Procurement/Finance/Marketing/Media/CEO) - rujuk AI_AGENTS_PLAN.md.
     * `figures` = angka rujukan drpd tool, `sources` = senarai tool+parameter yg menghasilkan angka
     * tu (utk pengesahan manusia). Dashboard BACA jadual ni, agent TIDAK ubah UI/kod terus.
     */
    public function up(): void
    {
        Schema::create('insights', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 20)->index();
            $table->string('severity', 10)->index();
            $table->string('title');
            $table->text('summary');
            $table->json('figures');
            $table->text('recommendation')->nullable();
            $table->json('sources');
            $table->timestamp('data_as_of');
            $table->string('dedupe_key', 120)->nullable()->index();
            $table->timestamps();

            $table->index(['agent', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights');
    }
};
