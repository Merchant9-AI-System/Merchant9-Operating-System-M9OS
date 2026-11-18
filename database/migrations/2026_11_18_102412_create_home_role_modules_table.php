<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Susun atur halaman /home bagi setiap PERANAN: modul mana yang dihidupkan, urutannya, dan (utk modul universal)
     * skop cawangan. Satu baris bagi setiap (peranan, modul). Peranan tanpa baris menggunakan susun atur lalai
     * (rujuk App\Support\HomeSummary\HomeLayout). `scope_mode`: own = cawangan pengguna sendiri, all = semua cawangan,
     * selected = senarai `scope_stores` sahaja.
     */
    public function up(): void
    {
        Schema::create('home_role_modules', function (Blueprint $table) {
            $table->id();
            $table->string('role_name', 100);
            $table->string('module_key', 50);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('scope_mode', 10)->default('own');
            $table->json('scope_stores')->nullable();
            $table->timestamps();

            $table->unique(['role_name', 'module_key']);
            $table->index('role_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_role_modules');
    }
};
