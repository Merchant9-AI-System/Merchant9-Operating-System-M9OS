<?php

namespace App\Providers;

use App\Http\Responses\HomeLoginResponse;
use App\Support\RemoteThumbnails;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Thumbnail gambar utk eksport Excel Supplier Lookup (cache di storage/app/supplier-lookup-thumbs); ujian menggantikannya.
        $this->app->bind(RemoteThumbnails::class, fn () => RemoteThumbnails::default());

        // Selepas log masuk Filament -> halaman ringkasan semalam /home (bukan terus ke dashboard admin).
        $this->app->bind(LoginResponse::class, HomeLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mcp::web() routes (rujuk routes/ai.php) - 60/minit per user token (fallback IP kalau
        // tiada, tapi laluan MCP web sentiasa di belakang auth:sanctum,api jadi user tak pernah
        // null dlm amalan).
        RateLimiter::for('mcp', fn ($request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // POST /api/internal/members/lookup (rujuk routes/api.php) - carian sempit satu ahli per
        // panggilan (QMS kiosk), bukan API pukal - had lebih ketat drpd 'mcp' di atas.
        RateLimiter::for('member-lookup', fn ($request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        // Skrin kelulusan OAuth (Passport /oauth/authorize) - TANPA ni, AuthorizationController
        // throw "Target [AuthorizationViewResponse] is not instantiable" (disahkan 500 sebenar
        // semasa ujian) - Passport TIADA default, WAJIB daftar eksplisit. View "mcp.authorize"
        // ialah view SEDIA ADA drpd package laravel/mcp sendiri (bukan Passport punya - dibina
        // khas utk konteks kelulusan MCP), rujuk resources/views/mcp/authorize.blade.php
        // (dipublish via `vendor:publish --tag=mcp-views`). Guna default (belum jenamakan) buat
        // masa ni - cukup berfungsi utk aliran DCR Claude.ai.
        Passport::authorizationView('mcp.authorize');
    }
}
