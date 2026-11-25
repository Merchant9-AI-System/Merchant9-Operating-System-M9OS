<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

// Thread-safe PHP (Apache mod_php on Windows/Laragon): putenv() is PROCESS-wide, so one app's .env values (DB_DATABASE, ...)
// leak into another app's request that runs in the same process - e.g. SSO, where one app calls another during its own
// request. Dotenv then treats those variables as already set and keeps the OTHER app's database. Disable putenv() on ZTS builds
// (superglobals are per request); NTS/php-fpm in production is unaffected.
if (PHP_ZTS) {
    Env::disablePutenv();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Global (bukan ->web() sahaja) - panel Filament (/admin/*) ada middleware stack
        // sendiri (rujuk AdminPanelProvider), header keselamatan WAJIB kena semua respons.
        $middleware->append(SecurityHeaders::class);

        // Filament dikendalikan pd guard 'web' yg SAMA (bukan guard berasingan) - login sedia
        // ada di /admin/login (filament.admin.auth.login), bukan laluan 'login' lalai Laravel
        // yg dijangka oleh middleware auth default.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        // Sanctum TIDAK auto-daftar alias ni (disahkan baca source SanctumServiceProvider) -
        // wajib daftar sendiri utk guna 'ability:...'/'abilities:...' pd laluan (rujuk
        // routes/api.php, POST /api/internal/members/lookup).
        $middleware->alias([
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 'mcp/*' turut disertakan - TANPA ni, kegagalan auth:sanctum di laluan Mcp::web()
        // (rujuk routes/ai.php) tersasar ke redirectGuestsTo() (login Filament, HTML) drpd
        // JSON 401 bersih - klien MCP/JSON-RPC (Claude.ai, dll) jangka JSON, bukan redirect.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp/*'),
        );
    })->create();
