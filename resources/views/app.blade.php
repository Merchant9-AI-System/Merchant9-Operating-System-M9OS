<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'm9os') }}</title>

    @vite(['resources/css/inertia.css', 'resources/js/inertia.ts'])
    @inertiaHead
</head>
<body class="bg-zinc-50">
    @inertia
</body>
</html>
