<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suis on/off halaman /home (ringkasan semalam) untuk setiap peranan - diurus super_admin melalui halaman
     * Filament "Halaman Home" (rujuk App\Support\HomeSummary\HomeAccess). Peranan tanpa baris guna nilai lalai.
     */
    public function up(): void
    {
        Schema::create('home_role_settings', function (Blueprint $table) {
            $table->id();
            $table->string('role_name')->unique();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_role_settings');
    }
};
