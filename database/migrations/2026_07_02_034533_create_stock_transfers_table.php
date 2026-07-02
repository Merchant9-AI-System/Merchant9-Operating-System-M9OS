<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->unique();
            $table->string('internal_code');
            $table->string('item_desc')->nullable();
            $table->string('category_code')->nullable();
            $table->string('from_store');
            $table->string('to_store');
            $table->unsignedInteger('qty');
            $table->string('status')->default('Requested'); // Requested -> In Transit -> Received (atau Cancelled)
            $table->string('requested_by');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->string('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('internal_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
