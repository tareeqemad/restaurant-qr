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

    {{-- Global audio system — single AudioContext on `window.__audioCtx`
         shared across kitchen/waiter/cashier components. Browsers block
         AudioContext until the user gestures; we attach a permanent
         document-wide gesture listener that keeps trying to resume() and
         show/hide the unlock banner accordingly. --}}
    <div id="audio-unlock-banner" class="audio-unlock-banner d-none" role="button" tabindex="0">
        <i class="bi bi-volume-up-fill"></i>
        <span>اضغط لتفعيل التنبيهات الصوتية</span>
    </div>
    <style>
        .audio-unlock-banner {
            position: fixed;
            inset-block-start: 64px;
            inset-inline-end: 16px;
            z-index: 1090;
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .55rem 1rem;
            border-radius: 999px;
            background: linear-gradient(135deg, #b97818, #d18a23);
            color: #fff;
            font-weight: 800;
            font-size: .82rem;
            box-shadow: 0 10px 24px rgba(185, 120, 24, .35);
            cursor: pointer;
            animation: audio-unlock-pulse 2s ease-in-out infinite;
        }
        .audio-unlock-banner > i { font-size: 1rem; }
        .audio-unlock-banner.d-none { display: none !important; }
        @keyframes audio-unlock-pulse {
            0%, 100% { transform: scale(1); }
            50%      { transform: scale(1.04); }
        }
    </style>
    <script>
        (function () {
            const ensureCtx = () => {
                if (!window.__audioCtx) {
                    const C = window.AudioContext || window.webkitAudioContext;
                    if (!C) return null;
                    window.__audioCtx = new C();
                }
                return window.__audioCtx;
            };

            const banner = () => document.getElementById('audio-unlock-banner');

            const updateBanner = () => {
                const b = banner();
                if (!b) return;
                const c = window.__audioCtx;
                // Show only if SOME component asks for sound but the context
                // is suspended/missing. The "wants sound" check looks at any
                // of the per-component flags — they default to true, so a
                // chef who never touched a toggle still gets the prompt.
                const wantsSound = window.__kbSoundEnabled !== false
                                || window.__wbSoundEnabled !== false
                                || window.__cxSoundEnabled !== false;
                const locked = !c || c.state !== 'running';
                b.classList.toggle('d-none', !(wantsSound && locked));
            };

            // Aliases kept for backwards compat with components that still
            // reference window.__kbAudioCtx — they'll all share the same ctx.
            const linkAliases = () => {
                window.__kbAudioCtx = window.__audioCtx;
                window.__wbAudioCtx = window.__audioCtx;
                window.__cxAudioCtx = window.__audioCtx;
            };

            window.unlockAudioCtx = function () {
                const c = ensureCtx();
                if (!c) return null;
                if (c.state === 'suspended') {
                    c.resume().then(updateBanner).catch(() => updateBanner());
                }
                // Tiny silent buffer — fully unlocks iOS Safari which needs
                // a buffer start within the gesture handler, not just resume.
                try {
                    const buf = c.createBuffer(1, 1, 22050);
                    const src = c.createBufferSource();
                    src.buffer = buf; src.connect(c.destination); src.start(0);
                } catch (e) {}
                linkAliases();
                updateBanner();
                return c;
            };

            // ANY gesture anywhere unlocks. We keep listening (not once) in
            // case a context gets garbage-collected or the browser re-suspends
            // it after a long idle period.
            ['pointerdown', 'keydown', 'touchstart', 'click'].forEach(ev =>
                window.addEventListener(ev, window.unlockAudioCtx, { passive: true, capture: true })
            );

            // The banner is itself a gesture target — clicking it satisfies
            // the autoplay policy AND hides the prompt.
            document.addEventListener('DOMContentLoaded', () => {
                banner()?.addEventListener('click', window.unlockAudioCtx);
                updateBanner();
            });

            // Re-check banner state when components flip their own flags.
            window.__refreshAudioBanner = updateBanner;
            setInterval(updateBanner, 3000);

            // Two-tone chime, shared by toast notifications.
            window.playNotify = function () {
                const c = window.__audioCtx;
                if (!c || c.state !== 'running') return;
                const now = c.currentTime;
                const gain = c.createGain();
                gain.gain.setValueAtTime(0.0001, now);
                gain.gain.exponentialRampToValueAtTime(0.18, now + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.5);
                gain.connect(c.destination);

                [880, 1320].forEach((freq, i) => {
                    const osc = c.createOscillator();
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    osc.connect(gain);
                    osc.start(now + i * 0.12);
                    osc.stop(now + i * 0.12 + 0.18);
                });
            };
        })();
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
