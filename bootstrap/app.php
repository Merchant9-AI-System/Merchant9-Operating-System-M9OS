<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 'mcp/*' turut disertakan - TANPA ni, kegagalan auth:sanctum di laluan Mcp::web()
        // (rujuk routes/ai.php) tersasar ke redirectGuestsTo() (login Filament, HTML) drpd
        // JSON 401 bersih - klien MCP/JSON-RPC (Claude.ai, dll) jangka JSON, bukan redirect.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp/*'),
        );
    })->create();
