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
        // Ubah grain drpd SATU baris SETIAP DESIGN (InternalCode) kpd SATU baris SETIAP
        // (InternalCode, VendorCode) - membolehkan StockoutReorder kira semula sold_count/
        // eligibility SECARA LIVE ikut vendor dipilih/dikecualikan (rujuk
        // StockoutReorderCandidate::candidateQuery()), bukan hanya tapis baris drpd senarai
        // vendor_codes statik spt sebelum ni (bug: exclude 1 vendor minor sembunyikan seluruh
        // design walaupun vendor lain [cth. GRJ] masih bekalkan majoriti piece). Jadual ni
        // snapshot terbitan penuh (dikira semula oleh StockoutReorderMaterializer setiap sync),
        // jadi selamat drop+recreate - gabungkan 3 migration terdahulu pd jadual ni (create asal
        // + vendor_codes + repair_qty_on_hand) kpd satu bentuk baru.
        Schema::dropIfExists('stockout_reorder_candidates');

        Schema::create('stockout_reorder_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('InternalCode', 20);
            $table->string('VendorCode', 20);
            $table->string('Description', 100)->nullable();
            $table->string('CategoryCode', 20)->nullable();
            $table->unsignedInteger('repair_qty_on_hand')->default(0);
            $table->unsignedInteger('sold_count');
            $table->unsignedInteger('qty_on_hand');
            $table->dateTime('last_sale_date')->nullable();
            $table->timestamp('synced_at');
            $table->unique(['InternalCode', 'VendorCode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembali ke bentuk asal (satu baris setiap design) - data snapshot terbitan, selamat
        // hilang, akan diisi semula oleh StockoutReorderMaterializer pd sync seterusnya.
        Schema::dropIfExists('stockout_reorder_candidates');

        Schema::create('stockout_reorder_candidates', function (Blueprint $table) {
            $table->string('InternalCode', 20)->primary();
            $table->string('Description', 100)->nullable();
            $table->string('CategoryCode', 20)->nullable();
            $table->string('VendorCode', 20)->nullable();
            $table->text('vendor_codes')->nullable();
            $table->unsignedInteger('repair_qty_on_hand')->default(0);
            $table->unsignedInteger('sold_count');
            $table->dateTime('last_sale_date')->nullable();
            $table->timestamp('synced_at');
        });
    }
};
