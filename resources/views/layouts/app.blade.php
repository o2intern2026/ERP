<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    {{-- Frozen zone: Pico.css (classless, CDN) + one app.css ≤ 100 lines, no build step (ERP_PLAN §8.1). --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    @include('layouts.nav')
    <main class="container">
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
