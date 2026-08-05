<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2b736e">

    <title>{{ isset($titulo) ? "{$titulo} · " : '' }}{{ config('app.name') }}</title>

    <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#2b736e"/><path d="M16 7v18M7 16h18" stroke="#fff" stroke-width="4.5" stroke-linecap="round"/></svg>') }}">

    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 font-sans text-slate-900 antialiased">
    <div class="flex min-h-full flex-col justify-center px-4 py-10 sm:px-6">
        {{ $slot }}
    </div>
</body>
</html>
