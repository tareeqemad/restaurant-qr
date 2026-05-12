@php
    $settings = \App\Models\Setting::class;
    $themeDefaults = config('restaurant.theme', []);
    $siteName = $settings::get('site_name', config('restaurant.name', 'Relax'));
    // Theme colors — admin can override via /admin/settings
    $normalizeHex = function ($color, string $fallback): string {
        $hex = ltrim((string) $color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return strlen($hex) === 6 && ctype_xdigit($hex) ? '#'.strtolower($hex) : $fallback;
    };

    $hexToRgb = function (string $color, string $fallback): string {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) === 6 && ctype_xdigit($hex)) {
            return hexdec(substr($hex,0,2)).", ".hexdec(substr($hex,2,2)).", ".hexdec(substr($hex,4,2));
        }
        return $fallback;
    };
    $darkenHex = function (string $color, float $factor = 0.68): string {
        $hex = ltrim($color, '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '#805113';
        }

        $channel = function (string $value) use ($factor): int {
            return max(0, min(255, (int) round(hexdec($value) * $factor)));
        };

        return sprintf(
            '#%02x%02x%02x',
            $channel(substr($hex, 0, 2)),
            $channel(substr($hex, 2, 2)),
            $channel(substr($hex, 4, 2))
        );
    };

    $primaryDefault = $themeDefaults['primary'] ?? '#164c37';
    $darkDefault = $themeDefaults['dark'] ?? '#0f2d22';
    $headerDefault = $themeDefaults['header'] ?? $primaryDefault;
    $accentDefault = $themeDefaults['accent'] ?? '#b97818';
    $menuDefault = $themeDefaults['menu'] ?? '#f7f8f5';

    $primaryColor = $normalizeHex($settings::get('theme_primary', $primaryDefault), $primaryDefault);
    $darkColor = $normalizeHex($settings::get('theme_dark', $darkDefault), $darkDefault);
    $headerColor = $normalizeHex($settings::get('theme_header', $headerDefault), $headerDefault);
    $accentColor = $normalizeHex($settings::get('theme_accent', $accentDefault), $accentDefault);
    $menuColor = $normalizeHex($settings::get('theme_menu', $menuDefault), $menuDefault);

    $primaryRgb = $hexToRgb($primaryColor, '22, 76, 55');
    $darkRgb = $hexToRgb($darkColor, '15, 45, 34');
    $headerRgb = $hexToRgb($headerColor, '22, 76, 55');
    $accentRgb = $hexToRgb($accentColor, '185, 120, 24');
    $accentDark = $darkenHex($accentColor);

    $headerStyle = $settings::get('theme_header_style', $themeDefaults['header_style'] ?? 'color');
    $headerStyle = in_array($headerStyle, ['light', 'dark', 'color'], true) ? $headerStyle : 'color';

    $menuStyle = $settings::get('theme_menu_style', $themeDefaults['menu_style'] ?? 'brand');
    $menuStyle = in_array($menuStyle, ['brand', 'light', 'dark'], true) ? $menuStyle : 'brand';
    $dashticMenuStyle = $menuStyle === 'brand' ? 'light' : $menuStyle;
@endphp
<!DOCTYPE html>
{{-- Admin navigation is intentionally horizontal, matching the desired
     Dashtic switcher mode while keeping the switcher UI out of the app. --}}
<html lang="ar" dir="rtl"
      data-nav-layout="horizontal"
      data-theme-mode="light"
      data-header-styles="{{ $headerStyle }}"
      data-menu-styles="{{ $dashticMenuStyle }}"
      data-relax-menu-style="{{ $menuStyle }}"
      data-toggled="close"
      data-nav-style="menu-hover">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-id" content="{{ auth()->id() }}">
    <title>@yield('title', 'لوحة التحكم') - {{ $siteName }}</title>

    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}" type="image/x-icon">

    {{-- === Dashtic core assets (order matches Dashtic's index.html exactly) === --}}

    <!-- Choices JS (early, as per template) -->
    <script src="{{ asset('assets/dashtic/libs/choices.js/public/assets/scripts/choices.min.js') }}"></script>

    {{-- Dashtic stores switcher choices in localStorage. Since this app has no
         switcher UI, stale demo values must not override our horizontal menu. --}}
    <script>
        try {
            [
                'dashticlayout',
                'dashticnavstyles',
                'dashticverticalstyles',
                'dashticmenufixed',
                'dashticmenuscrollable',
                'dashticheaderfixed',
                'dashticheaderscrollable',
                'dashticboxed',
                'dashticclassic'
            ].forEach((key) => localStorage.removeItem(key));
        } catch (e) {}
    </script>

    <!-- Main Theme Js -->
    <script src="{{ asset('assets/dashtic/js/main.js') }}"></script>

    <!-- Bootstrap RTL -->
    <link id="style" href="{{ asset('assets/dashtic/libs/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">

    <!-- Style Css -->
    <link href="{{ asset('assets/dashtic/css/styles.min.css') }}" rel="stylesheet">

    <!-- Icons Css -->
    <link href="{{ asset('assets/dashtic/css/icons.css') }}" rel="stylesheet">

    <!-- Node Waves Css -->
    <link href="{{ asset('assets/dashtic/libs/node-waves/waves.min.css') }}" rel="stylesheet">

    <!-- Simplebar Css -->
    <link href="{{ asset('assets/dashtic/libs/simplebar/simplebar.min.css') }}" rel="stylesheet">

    <!-- Flatpickr + Choices CSS -->
    <link rel="stylesheet" href="{{ asset('assets/dashtic/libs/flatpickr/flatpickr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/dashtic/libs/choices.js/public/assets/styles/choices.min.css') }}">

    {{-- Minimal brand + our custom components. Loaded AFTER Dashtic base
         so our rules win when they touch the same classes. --}}
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/relax-brand.css') }}?v={{ filemtime(public_path('assets/dashtic/css/relax-brand.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/relax-components.css') }}?v={{ filemtime(public_path('assets/dashtic/css/relax-components.css')) }}">

    {{-- Runtime theme override. It is intentionally loaded after Relax CSS so
         dashboard settings win over the compiled default palette. --}}
    <style>
        :root {
            --primary: {{ $primaryColor }};
            --primary-rgb: {{ $primaryRgb }};
            --primary-color: rgb(var(--primary-rgb));
            --primary-border: rgb(var(--primary-rgb));
            --primary005: rgba(var(--primary-rgb), 0.05);
            --primary01: rgba(var(--primary-rgb), 0.1);
            --primary02: rgba(var(--primary-rgb), 0.2);
            --primary03: rgba(var(--primary-rgb), 0.3);
            --primary04: rgba(var(--primary-rgb), 0.4);
            --primary05: rgba(var(--primary-rgb), 0.5);
            --primary06: rgba(var(--primary-rgb), 0.6);
            --primary07: rgba(var(--primary-rgb), 0.7);
            --primary08: rgba(var(--primary-rgb), 0.8);
            --primary09: rgba(var(--primary-rgb), 0.9);
            --dark: {{ $darkColor }};
            --dark-rgb: {{ $darkRgb }};
            --accent: {{ $accentColor }};
            --accent-rgb: {{ $accentRgb }};
            --accent-dark: {{ $accentDark }};
            --menu: {{ $menuColor }};
        }

        [data-relax-menu-style="brand"] {
            --menu-bg: {{ $menuColor }};
            --menu-prime-color: rgb(var(--primary-rgb));
            --menu-border-color: rgba(var(--primary-rgb), 0.12);
        }

        [data-header-styles="color"] {
            --header-bg: {{ $headerColor }};
            --header-bg2: rgba({{ $headerRgb }}, 0.5);
            --header-prime-color: rgba(255, 255, 255, 0.88);
            --header-border-color: rgba(255, 255, 255, 0.14);
        }
    </style>

    {{-- Arabic typography --}}
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Cinzel:wght@600;700&display=swap" rel="stylesheet">

    @stack('head-scripts')
    @vite(['resources/js/app.js'])
    @livewireStyles
    @stack('styles')
</head>

<body>

    <!-- Loader -->
    <div id="loader">
        <img src="{{ asset('assets/dashtic/images/svgs/loader.svg') }}" alt="">
    </div>
    <!-- /Loader -->

    <div class="page">
        @include('admin.partials.header')
        @include('admin.partials.sidebar')

        <!-- Start::app-content -->
        <div class="main-content app-content">
            <div class="container-fluid">
                @yield('content')
            </div>
        </div>
        <!-- End::app-content -->

        @include('admin.partials.footer')
    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    {{-- Pill removed with the Reverb/WebSocket pivot. Polling mode has no
         "connection state" to show — Livewire just re-renders on interval.
         If you later switch to Reverb, restore this block + the JS in the
         inline script section below. --}}

    {{-- === Dashtic core scripts (order matches their index.html) === --}}
    <script src="{{ asset('assets/dashtic/libs/jquery/jquery.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/@popperjs/core/umd/popper.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/defaultmenu.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/node-waves/waves.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/sticky.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/simplebar/simplebar.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/simplebar.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/flatpickr/flatpickr.min.js') }}"></script>
    @if(file_exists(public_path('assets/dashtic/libs/flatpickr/l10n/ar.js')))
        <script src="{{ asset('assets/dashtic/libs/flatpickr/l10n/ar.js') }}"></script>
    @endif
    <script src="{{ asset('assets/dashtic/js/admin-datepicker.js') }}"></script>
    {{-- relax-init.js replaces Dashtic's custom.js (which requires the switcher
         offcanvas DOM we deliberately skip). Provides only what we actually
         use: loader hide, tooltip init, fullscreen helper, scrollToTop. --}}
    <script src="{{ asset('assets/dashtic/js/relax-init.js') }}?v={{ filemtime(public_path('assets/dashtic/js/relax-init.js')) }}"></script>
    <script src="{{ asset('assets/dashtic/js/admin-crud.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/relax-choices.js') }}?v={{ filemtime(public_path('assets/dashtic/js/relax-choices.js')) }}"></script>

    {{-- Horizontal-nav dropdown bridge: when the user opens one top-level
         dropdown, close every other one. Dashtic's defaultmenu.min.js
         leaves `relax-pinned-open` on each parent independently, so without
         this two dropdowns can sit open at once after a couple of hovers.
         Scoped to the main-menu top level only — the second-level submenus
         intentionally stay open while the parent is open. --}}
    <script>
    (function () {
        const close = (li) => li.classList.remove('open', 'relax-pinned-open', 'relax-hover-open');
        const top   = () => document.querySelectorAll('.app-sidebar .main-menu > .slide.has-sub');
        document.addEventListener('mouseover', (ev) => {
            const enteredItem = ev.target.closest('.app-sidebar .main-menu > .slide.has-sub');
            if (! enteredItem) return;
            top().forEach(li => { if (li !== enteredItem) close(li); });
        });
    })();
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    {{-- SweetAlert2-driven toast + form-confirm interceptor. Must come AFTER
         the Swal CDN — the partial gates its IIFE on window.Swal. --}}
    @include('admin.partials.toast')

    {{-- Global notification sound helper --}}
    <audio id="notify-sound" src="data:audio/wav;base64,UklGRl9vAAAXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQ=="></audio>
    <script>
        window.playNotify = function() {
            try { const a = document.getElementById('notify-sound'); if (a) { a.currentTime = 0; a.play().catch(()=>{}); } } catch(e) {}
        };
        // window.showNotification is registered by admin.partials.toast (uses SweetAlert2).

        @php $u = auth()->user(); @endphp
        /* Echo listeners intentionally absent in polling mode. window.Echo
           exists as a no-op stub (see resources/js/bootstrap.js) so any
           `.channel(...).listen(...)` calls elsewhere won't throw. If you
           later switch to Reverb/Pusher, just flip VITE_REVERB_ENABLED=true
           and BROADCAST_CONNECTION=reverb — the toast/listener wiring can
           be re-added here without touching any other screen. */
    </script>

    @stack('scripts')
</body>
</html>
