<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sejarah setiap perubahan sasaran bulanan: nilai lama -> baharu, catatan, siapa dan bila (null = pertama kali ditetapkan). */
    public function up(): void
    {
        Schema::create('branch_monthly_target_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_monthly_target_id')->constrained('branch_monthly_targets')->cascadeOnDelete();
            $table->decimal('old_rm', 14, 2)->nullable();
            $table->decimal('old_grams', 12, 2)->nullable();
            $table->decimal('new_rm', 14, 2);
            $table->decimal('new_grams', 12, 2);
            $table->text('remark')->nullable();
            $table->string('source', 10);
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_name');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_monthly_target_revisions');
    }
};
