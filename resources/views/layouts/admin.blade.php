@php
    $siteName = \App\Models\Setting::get('site_name', config('restaurant.name', 'Relax'));
    // Theme colors — admin can override via /admin/settings
    $primaryColor = \App\Models\Setting::get('theme_primary', config('restaurant.theme.primary', '#1f4733'));
    $darkColor    = \App\Models\Setting::get('theme_dark',    config('restaurant.theme.dark',    '#122d1e'));
    $accentColor  = \App\Models\Setting::get('theme_accent',  config('restaurant.theme.accent',  '#b8872a'));
    $menuColor    = \App\Models\Setting::get('theme_menu',    config('restaurant.theme.menu',    '#faf5eb'));

    $hexToRgb = function (string $color, string $fallback): string {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) === 6 && ctype_xdigit($hex)) {
            return hexdec(substr($hex,0,2)).", ".hexdec(substr($hex,2,2)).", ".hexdec(substr($hex,4,2));
        }
        return $fallback;
    };
    $primaryRgb = $hexToRgb($primaryColor, '31, 71, 51');
    $darkRgb    = $hexToRgb($darkColor,    '18, 45, 30');
    $accentRgb  = $hexToRgb($accentColor,  '184, 135, 42');
@endphp
<!DOCTYPE html>
{{-- <html> uses Dashtic's attribute schema exactly. The switcher docs in
     Documentation/switcher-styles.html list these as the canonical set. --}}
<html lang="ar" dir="rtl"
      data-nav-layout="horizontal"
      data-theme-mode="light"
      data-header-styles="light"
      data-menu-styles="light"
      data-toggled="close"
      data-nav-style="menu-click">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-id" content="{{ auth()->id() }}">
    <title>@yield('title', 'لوحة التحكم') - {{ $siteName }}</title>

    <link rel="icon" href="{{ asset('assets/dashtic/images/brand-logos/favicon.ico') }}" type="image/x-icon">

    {{-- === Dashtic core assets (order matches Dashtic's index.html exactly) === --}}

    <!-- Choices JS (early, as per template) -->
    <script src="{{ asset('assets/dashtic/libs/choices.js/public/assets/scripts/choices.min.js') }}"></script>

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
    <link rel="stylesheet" href="{{ asset('assets/dashtic/libs/select2/select2.min.css') }}">

    {{-- === Relax brand CSS variables (override Dashtic's primary-rgb etc.) === --}}
    <style>
        :root {
            --primary:     {{ $primaryColor }};
            --primary-rgb: {{ $primaryRgb }};
            --dark:        {{ $darkColor }};
            --dark-rgb:    {{ $darkRgb }};
            --accent:      {{ $accentColor }};
            --accent-rgb:  {{ $accentRgb }};
            --menu:        {{ $menuColor }};
        }
    </style>

    {{-- Minimal brand + our custom components. Loaded AFTER Dashtic base
         so our rules win when they touch the same classes. --}}
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/relax-brand.css') }}?v={{ filemtime(public_path('assets/dashtic/css/relax-brand.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/relax-components.css') }}?v={{ filemtime(public_path('assets/dashtic/css/relax-components.css')) }}">

    {{-- Arabic typography --}}
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Cinzel:wght@600;700&display=swap" rel="stylesheet">

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
                @include('admin.partials.toast')
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
    <script src="{{ asset('assets/dashtic/js/relax-init.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/general-helpers.js') }}"></script>
    <script src="{{ asset('assets/dashtic/js/admin-crud.js') }}"></script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    {{-- Global notification sound helper --}}
    <audio id="notify-sound" src="data:audio/wav;base64,UklGRl9vAAAXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQ=="></audio>
    <script>
        window.playNotify = function() {
            try { const a = document.getElementById('notify-sound'); if (a) { a.currentTime = 0; a.play().catch(()=>{}); } } catch(e) {}
        };
        window.showNotification = function(title, body, variant = 'info') {
            if (window.Swal) {
                Swal.fire({
                    toast: true, position: 'top-end', icon: variant, title, text: body,
                    showConfirmButton: false, timer: 5000, timerProgressBar: true
                });
            }
            window.playNotify();
        };

        @php $u = auth()->user(); @endphp
        @if($u && $u->hasAnyRole(['super_admin','admin','manager','waiter']))
            document.addEventListener('DOMContentLoaded', function() {
                const wait = setInterval(() => {
                    if (window.Echo) {
                        clearInterval(wait);
                        window.Echo.channel('waiters')
                            .listen('.order.created', (e) => {
                                showNotification('طلب جديد!', `طاولة ${e.table_number} — ${e.number}`, 'info');
                                if (typeof onNewOrder === 'function') onNewOrder(e);
                            })
                            .listen('.order.status_changed', (e) => {
                                if (typeof onOrderStatus === 'function') onOrderStatus(e);
                            });
                        @if($u->hasAnyRole(['super_admin','admin','manager','cashier','waiter']))
                        window.Echo.channel('cashiers')
                            .listen('.invoice.paid', (e) => {
                                showNotification('فاتورة مدفوعة', `${e.number} — طاولة ${e.table_number}`, 'success');
                            });
                        @endif
                    }
                }, 100);
            });
        @endif
    </script>

    @stack('scripts')
</body>
</html>
