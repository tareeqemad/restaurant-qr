@php
    $theme = \App\Support\ThemePalette::current();
    $market = \App\Support\MarketProfile::class;
    // These framework styles already live under public/. Let LiteSpeed serve
    // them directly: the PHP file response fails on some shared hosts and
    // leaves the whole admin shell unstyled.
    $staticAsset = static function (string $path): string {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    };
@endphp
<!DOCTYPE html>
{{-- Root document for Inertia ADMIN pages (Wave 0 — AdminLayout.vue chrome).
     Wears the exact Dashtic skin the Blade admin wears — Bootstrap RTL,
     styles.min, relax-brand/components, DB-driven theme vars — but loads
     NONE of the classic admin JS: no jQuery, no SweetAlert, no Choices /
     flatpickr auto-init, no menu scripts. AdminLayout.vue owns all
     behavior. Everything self-hosted (offline doctrine). --}}
<html lang="ar" dir="rtl"
      data-nav-layout="horizontal"
      data-theme-mode="light"
      data-header-styles="{{ $theme['header_style'] }}"
      data-menu-styles="{{ $theme['dashtic_menu_style'] }}"
      data-relax-menu-style="{{ $theme['menu_style'] }}"
      data-toggled="close"
      data-nav-style="menu-hover">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-id" content="{{ auth()->id() }}">
    <meta name="theme-color" content="{{ $theme['primary'] }}">
    <title inertia>{{ \App\Helpers\Brand::name() }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">

    <link id="style" href="{{ $staticAsset('assets/dashtic/libs/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ $staticAsset('assets/dashtic/css/styles.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/dashtic/icon-fonts/RemixIcons/fonts/remixicon.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/relax-brand.css') }}?v={{ filemtime(public_path('assets/dashtic/css/relax-brand.css')) }}">
    <link rel="stylesheet" href="{{ $staticAsset('assets/dashtic/css/relax-components.css') }}">

    {{-- Runtime theme override — after the compiled CSS so dashboard settings win. --}}
    <style>
        @include('partials.market-vars')
        @include('partials.theme-vars', ['theme' => $theme])
    </style>

    {{-- Plain LOCAL font stylesheet: offline it paints instantly in system font. --}}
    <link href="{{ $market::fontUrl() }}" rel="stylesheet">

    @routes
    @vite('resources/js/app-inertia.js')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
