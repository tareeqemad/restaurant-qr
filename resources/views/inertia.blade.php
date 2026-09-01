@php
    $theme = \App\Support\ThemePalette::current();
    $market = \App\Support\MarketProfile::class;
@endphp
<!DOCTYPE html>
{{-- Root document for every Inertia page (MIGRATION-PILOT.md — Claude lane).
     Mirrors the admin layout's head essentials — DB-driven theme vars, Arabic
     font, LOCAL icon font — but none of its Blade chrome: the Vue
     pages own their whole viewport. Everything self-hosted; this project
     already paid the CDN tax once. --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $theme['primary'] }}">
    <title inertia>{{ \App\Helpers\Brand::name() }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Non-blocking font: offline, the page paints instantly in the system font. --}}
    <link href="{{ $market::fontUrl() }}" rel="stylesheet">
        <style>
        @include('partials.theme-vars', ['theme' => $theme])
        @include('partials.market-vars')
        html, body, #app { min-height: 100%; background: #f8fafc; }
        body { margin: 0; font-family: var(--market-font-family); }

        /* Customer routes use Inertia view transitions: one mounted app,
           no white document flash while moving between menu, orders and bill. */
        ::view-transition-old(root),
        ::view-transition-new(root) {
            animation-duration: 180ms;
            animation-timing-function: cubic-bezier(.22, .8, .35, 1);
        }
        ::view-transition-old(root) { animation-name: qr-app-out; }
        ::view-transition-new(root) { animation-name: qr-app-in; }
        @keyframes qr-app-out { to { opacity: 0; transform: translateY(-5px) scale(.995); } }
        @keyframes qr-app-in { from { opacity: 0; transform: translateY(7px) scale(.995); } }
        @media (prefers-reduced-motion: reduce) {
            ::view-transition-old(root),
            ::view-transition-new(root) { animation-duration: 1ms; }
        }
    </style>
    @routes
    @vite('resources/js/app-inertia.js')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
