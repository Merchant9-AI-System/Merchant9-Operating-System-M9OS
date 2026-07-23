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
        Schema::create('physical_gold_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date')->unique();
            $table->dateTime('cutoff_at')->nullable();
            $table->string('status')->default('Draft'); // Draft -> Submitted -> Approved

            $table->foreignId('prepared_by_id')->constrained('users');
            $table->string('prepared_by');

            $table->foreignId('submitted_by_id')->nullable()->constrained('users');
            $table->string('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('physical_gold_reports');
    }
};
