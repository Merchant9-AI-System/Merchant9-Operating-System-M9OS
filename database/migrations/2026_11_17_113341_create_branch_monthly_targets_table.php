<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sasaran jualan bulanan setiap cawangan (M07 Monthly Target vs Actual), dalam RM DAN gram. SATU rekod bagi
     * setiap (cawangan, bulan); `month` ialah hari pertama bulan itu. Boleh ditetapkan HQ (Filament, kebenaran Shield)
     * atau ketua cawangan (/home); `source` merekod yang mana, `remark` ialah catatan perubahan terakhir.
     * Setiap perubahan direkod dalam branch_monthly_target_revisions.
     */
    public function up(): void
    {
        Schema::create('branch_monthly_targets', function (Blueprint $table) {
            $table->id();
            $table->string('store_code', 20);
            $table->date('month');
            $table->decimal('target_rm', 14, 2)->default(0);
            $table->decimal('target_grams', 12, 2)->default(0);
            $table->text('remark')->nullable();
            $table->string('source', 10);
            $table->foreignId('set_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('set_by_name');
            $table->timestamps();

            $table->unique(['store_code', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_monthly_targets');
    }
};
