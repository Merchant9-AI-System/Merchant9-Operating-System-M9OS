<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])> {{-- @class(['dark' => ($appearance ?? 'system') == 'dark']) --}}

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- <script>
        (function() {
            const appearance = '{{ $appearance ?? 'system' }}';

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script> --}}

    {{-- Inline style to set the HTML background color based on our theme in app.css --}}
    {{-- <style>
        html {
            background-color: oklch(1 0 0);
        }

        html.dark {
            background-color: oklch(0.145 0 0);
        }
    </style> --}}
    <title inertia>{{ config('app.name') }}</title>

    {{-- Logo Merchant9 OS. Naikkan ?v= bila logo ditukar (pelayar menyimpan favicon lama sangat lama). --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=2" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon.svg') }}?v=2" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32x32.png') }}?v=2" type="image/png" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon-16x16.png') }}?v=2" type="image/png" sizes="16x16">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=2">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}?v=2">
    <meta name="theme-color" content="#16181D">

    {{-- @vite(['resources/css/inertia.css', 'resources/js/inertia.ts', "resources/js/pages/{$page['component']}.vue"]) --}}
    @vite(['resources/css/inertia.css', 'resources/js/inertia.ts'])
    @inertiaHead
</head>

<body class="font-sans antialiased bg-muted"> {{-- bg-background --}}
    @inertia
</body>

</html>
