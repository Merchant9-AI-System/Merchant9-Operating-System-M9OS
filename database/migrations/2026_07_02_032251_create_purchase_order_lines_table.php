<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('internal_code');
            $table->string('item_desc')->nullable();
            $table->string('category_code')->nullable();
            $table->unsignedInteger('qty_ordered');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->unsignedInteger('qty_received')->default(0);
            // Jejak asal cadangan (procurement_orders lama, jemisys.db) - nullable sebab
            // line item boleh ditambah manual tanpa asal daripada Order Recommendation.
            $table->unsignedBigInteger('source_recommendation_id')->nullable();
            $table->timestamps();

            $table->index('internal_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
