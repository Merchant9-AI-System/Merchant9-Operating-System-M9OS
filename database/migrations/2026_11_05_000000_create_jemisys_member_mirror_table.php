<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jemisys_member_mirror', function (Blueprint $table) {
            $table->id();

            $table->string('VIPNo', 20);
            $table->string('NricNo', 30)->nullable();
            $table->string('Name', 100)->nullable();
            $table->string('MemberType', 20)->nullable();
            $table->string('StoreCode', 20)->nullable();
            $table->date('MemberSince')->nullable();
            $table->decimal('DiscountPer', 5, 2)->nullable();
            $table->string('HandPhone', 30)->nullable();
            $table->string('HandPhone2', 30)->nullable();
            $table->string('WhatsApp', 30)->nullable();

            // Lajur sintetik (bukan drpd sumber, dikira semasa sync - rujuk
            // SyncJemisysMirrors::syncMembers()) - bentuk dinormalize (App\Support\MemberLookup::
            // normaliseIc()/normalisePhone()) SUPAYA carian MemberLookup::find() jadi perbandingan
            // index TEPAT (`= ?`), bukan whereRaw(REPLACE(RTRIM(LTRIM(...)))...) atas lajur mentah
            // - disahkan EXPLAIN 2026-11-05: whereRaw atas ungkapan fungsi tu buat MySQL full table
            // scan (type=ALL, 194K baris, key=null) walaupun ada index pd lajur mentah, carian
            // ambil ~4s/panggilan - defeat tujuan asal cermin ni (kiosk perlukan respons milisaat).
            $table->string('nric_normalized', 30)->nullable();
            $table->string('hand_phone_normalized', 20)->nullable();
            $table->string('hand_phone2_normalized', 20)->nullable();
            $table->string('whatsapp_normalized', 20)->nullable();

            $table->timestamp('synced_at');

            $table->unique('VIPNo');
            $table->index('nric_normalized');
            $table->index('hand_phone_normalized');
            $table->index('hand_phone2_normalized');
            $table->index('whatsapp_normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jemisys_member_mirror');
    }
};
