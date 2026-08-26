<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Inline script to detect system dark mode preference and apply it immediately --}}
    <script>
        (function() {
            const appearance = '{{ $appearance ?? "system" }}';

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script>

    <style>
        html {
            background-color: oklch(1 0 0);
        }

        html.dark {
            background-color: oklch(0.145 0 0);
        }
    </style>

    <title>Authorize Application - {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Authorize MCP" />
    <link rel="manifest" href="/site.webmanifest" />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    {{-- inertia.css (bukan app.css) - satu2nya entry yg mendefinisikan token warna shadcn-vue
    (--card, --primary, --muted-foreground dll., rujuk resources/css/inertia.css) yg dipakai
    komponen Card/Button Vue di bawah. --}}
    @vite(['resources/css/inertia.css', 'resources/js/oauth-authorize.ts'])
</head>
<body class="font-sans antialiased bg-background text-foreground">
    {{--
        Skrin kelulusan OAuth ni dirender Passport punya AuthorizationController terus
        (SimpleViewResponse), BUKAN laluan Inertia - x boleh guna createInertiaApp() sedia ada
        (rujuk resources/js/inertia.ts). Mount App Vue MANDIRI (resources/js/oauth-authorize.ts)
        supaya boleh guna komponen shadcn-vue sebenar (@/components/ui/card, @/components/ui/button)
        drpd Tailwind class tulis-tangan.

        JSON_HEX_* WAJIB - $client->name/scopes datang dari client OAuth yg daftar sendiri via DCR
        (Mcp::oauthRoutes(), POST oauth/register) - input BUKAN dipercayai, tanpa flag ni nama client
        boleh bawa "</script>" atau aksara HTML utk keluar dari tag <script> ni (XSS).
    --}}
    <script type="application/json" id="oauth-authorize-props">{!! json_encode([
        'clientName' => $client->name,
        'clientId' => (string) $client->id,
        'userEmail' => $user->email,
        'scopes' => collect($scopes)->map(fn ($scope) => ['description' => $scope->description])->values()->all(),
        'authToken' => $authToken,
        'csrfToken' => csrf_token(),
        'approveUrl' => route('passport.authorizations.approve'),
        'denyUrl' => route('passport.authorizations.deny'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>

    <div id="oauth-authorize-app"></div>
</body>
</html>
