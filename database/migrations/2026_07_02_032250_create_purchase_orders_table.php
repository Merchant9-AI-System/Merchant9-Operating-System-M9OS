<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->string('vendor_code');
            $table->string('vendor_name')->nullable();
            $table->string('status')->default('Draft');
            // Draft -> Pending Approval -> Approved -> Sent -> Partially Received -> Received
            // (atau Cancelled pada mana-mana peringkat sebelum Sent)
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by');
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('vendor_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
