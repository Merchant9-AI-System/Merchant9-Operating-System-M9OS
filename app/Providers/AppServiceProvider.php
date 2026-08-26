<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mcp::web() routes (rujuk routes/ai.php) - 60/minit per user token (fallback IP kalau
        // tiada, tapi laluan MCP web sentiasa di belakang auth:sanctum jadi user tak pernah null
        // dlm amalan).
        RateLimiter::for('mcp', fn ($request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
