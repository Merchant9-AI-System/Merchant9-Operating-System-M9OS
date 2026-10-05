<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Senarai Restock leader BO (rujuk halaman Back Office Actions) - satu baris = satu design
     * yg akan diorder dgn supplier. Disimpan supaya boleh dicetak/dieksport kemudian utk CEO.
     * Saiz/berat/cawangan peminta TIDAK disalin di sini - dibaca semula drpd line permintaan
     * (status "Order") semasa paparan/eksport, supaya sentiasa terkini.
     */
    public function up(): void
    {
        Schema::create('restock_list_items', function (Blueprint $table) {
            $table->id();
            $table->string('internal_code', 50)->index();
            $table->string('item_desc')->nullable();
            $table->string('category_name')->nullable();
            $table->unsignedInteger('qty_to_order')->default(0);
            $table->unsignedInteger('suggested_qty')->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_list_items');
    }
};
