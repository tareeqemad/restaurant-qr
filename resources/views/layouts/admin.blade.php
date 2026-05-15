@php
    $settings = \App\Models\Setting::class;
    $theme = \App\Support\ThemePalette::current();
    $siteName = $settings::get('site_name', config('restaurant.name', 'Relax'));
    $headerStyle = $theme['header_style'];
    $menuStyle = $theme['menu_style'];
    $dashticMenuStyle = $theme['dashtic_menu_style'];
    $optimizedAsset = fn (string $path): string => route('optimized.asset', [
        'path' => $path,
        'v' => filemtime(public_path($path)),
    ]);
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <style>
        #loader {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgb(var(--body-bg-rgb, 250, 245, 235));
        }

        #loader.d-none {
            display: none !important;
        }

        #loader img {
            width: 3rem;
            height: 3rem;
        }
    </style>

    {{-- === Dashtic core assets: keep render-blocking CSS before template JS. === --}}

    {{-- Dashtic stores switcher choices in localStorage. Since this app has no
         switcher UI, stale demo values must not override our horizontal menu. --}}
    <script>
        try {
            [
                'dashticlayout',
                'dashticrtl',
                'dashticltr',
                'dashticnavstyles',
                'dashticverticalstyles',
                'dashticmenufixed',
                'dashticmenuscrollable',
                'dashticheaderfixed',
                'dashticheaderscrollable',
                'dashticboxed',
                'dashticfullwidth',
                'dashticclassic',
                'dashticregular',
                'dashticdarktheme',
                'dashticlighttheme',
                'dashticMenu',
                'dashticHeader',
                'primaryRGB',
                'bodyBgRGB',
                'bodylightRGB',
                'dashticbgColor',
                'dashticheaderbg',
                'dashticmenubg',
                'dashticbgwhite',
                'bgtheme',
                'bgimg',
                'loaderEnable'
            ].forEach((key) => localStorage.removeItem(key));
        } catch (e) {}
    </script>

    <!-- Bootstrap RTL -->
    <link id="style" href="{{ $optimizedAsset('assets/dashtic/libs/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">

    <!-- Style Css -->
    <link href="{{ $optimizedAsset('assets/dashtic/css/styles.min.css') }}" rel="stylesheet">

    <!-- Icons Css -->
    <link href="{{ asset('assets/dashtic/icon-fonts/RemixIcons/fonts/remixicon.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/dashtic/icon-fonts/feather/feather.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">

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
    <link rel="stylesheet" href="{{ $optimizedAsset('assets/dashtic/css/relax-components.css') }}">

    {{-- Runtime theme override. It is intentionally loaded after Relax CSS so
         dashboard settings win over the compiled default palette. --}}
    <style>
        @include('partials.theme-vars', ['theme' => $theme])
    </style>

    {{-- Arabic typography: load after first paint; do not block dashboard CSS. --}}
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Cinzel:wght@600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Cinzel:wght@600;700&display=swap" rel="stylesheet"></noscript>

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

    {{-- === Dashtic core scripts === --}}
    <script src="{{ asset('assets/dashtic/libs/jquery/jquery.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/@popperjs/core/umd/popper.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/main.js') }}?v={{ filemtime(public_path('assets/dashtic/js/main.js')) }}"></script>
    <script src="{{ asset('assets/dashtic/js/defaultmenu.min.js') }}?v={{ filemtime(public_path('assets/dashtic/js/defaultmenu.min.js')) }}"></script>
    <script src="{{ asset('assets/dashtic/libs/node-waves/waves.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/sticky.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/simplebar/simplebar.min.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/simplebar.js') }}"></script>
    <script src="{{ asset('assets/dashtic/libs/choices.js/public/assets/scripts/choices.min.js') }}"></script>
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

    <script src="{{ asset('assets/dashtic/js/relax-submit-lock.js') }}?v={{ filemtime(public_path('assets/dashtic/js/relax-submit-lock.js')) }}"></script>

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
