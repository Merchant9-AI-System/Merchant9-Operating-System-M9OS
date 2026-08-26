<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keselamatan HTTP (HSTS/CSP/Referrer-Policy/Permissions-Policy/dll) - digunakan GLOBAL
 * (rujuk bootstrap/app.php ->append(), BUKAN ->web() sahaja) sbb panel Filament (/admin/*) ada
 * middleware stack SENDIRI (rujuk AdminPanelProvider::panel() ->middleware()), tak semestinya
 * warisi group 'web'.
 *
 * img-src WAJIB benarkan https://merchant9.com - imej produk di-HOTLINK terus drpd situ (rujuk
 * ProductImageFetcher/MerchantWebsiteSearch ORIGIN), bukan dimuat turun tempatan. TURUT benarkan
 * https://ui-avatars.com - AdminPanelProvider TIADA ->defaultAvatarProvider() kustom, jadi
 * Filament\AvatarProviders\UiAvatarsProvider (default panel) yg aktif, avatar pengguna (menu
 * atas kanan) dijana terus drpd URL situ. script-src/style-src kekal 'unsafe-inline' (bukan
 * nonce ketat) - Livewire/Alpine/Filament suntik skrip/gaya inline utk hydration komponen;
 * nonce penuh perlukan kerja lebih menyeluruh merentasi Livewire/Inertia & risiko pecahkan
 * panel admin production semasa waktu bekerja staf.
 *
 * script-src WAJIB ada 'unsafe-eval' jugak (bukan 'unsafe-inline' sahaja) - disahkan via
 * browser (console CSP violation, halaman login /admin/login) Alpine.js (dipakai SELURUH
 * Filament v5) nilai ekspresi x-data/x-bind/x-on dgn eval() runtime, TANPA ni SELURUH panel
 * admin pecah (toggle password, validasi borang, modal action - semua guna Alpine).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), camera=(), microphone=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()'
        );

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https://merchant9.com https://ui-avatars.com",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        // Skrin kelulusan OAuth Passport (rujuk resources/views/mcp/authorize.blade.php) - lepas
        // approve, Passport redirect (302) balik ke redirect_uri client OAuth yg didaftar via DCR
        // (cth. https://claude.ai/api/mcp/auth_callback) - CROSS-ORIGIN, itu memang tujuan OAuth.
        // Chrome kuatkuasa form-action 'self' terhadap redirect POST tsb (bukan setakat sasaran
        // POST awal), jadi form-action SEKAT redirect yg SAH tu (disahkan sebenar - ralat CSP
        // "form-action 'self'" semasa ujian production). Passport SENDIRI dah sahkan redirect_uri
        // padan client berdaftar (rujuk oauth_clients) - itu sempadan keselamatan open-redirect yg
        // betul di sini, form-action app-wide jadi lebih. Buang directive ni utk 3 laluan
        // passport.authorizations.* sahaja (bukan CSP seluruh app).
        if ($request->route()?->named('passport.authorizations.*')) {
            $directives = array_values(array_filter($directives, fn (string $directive) => ! str_starts_with($directive, 'form-action ')));
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $directives));

        return $response;
    }
}
