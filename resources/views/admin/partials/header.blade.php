@php
    $siteName = \App\Models\Setting::get('site_name', config('restaurant.name', 'Relax'));
    $u = auth()->user();
    $pendingCount = 0;
    try {
        if (class_exists(\App\Models\Order::class)) {
            $pendingCount = \App\Models\Order::where('status', 'pending')->count();
        }
    } catch (\Throwable $e) {}
@endphp
{{-- Header structure copied 1:1 from Dashtic's dist/html/index.html — do NOT
     add custom positioning / layout rules; let Dashtic's styles.min.css do
     the work. Only the content (logo image, links, labels, dropdowns) is
     ours. --}}
<header class="app-header">

    <!-- Start::main-header-container -->
    <div class="main-header-container container-fluid">

        <!-- Start::header-content-left -->
        <div class="header-content-left">

            <!-- Start::header-element (brand logo for horizontal layout) -->
            <div class="header-element">
                <div class="horizontal-logo">
                    <a href="{{ route('admin.dashboard') }}" class="header-logo">
                        {{-- All 6 logo variants Dashtic expects. Empty src = user supplies later. --}}
                        <img src="" alt="logo" class="desktop-logo">
                        <img src="" alt="logo" class="toggle-logo">
                        <img src="" alt="logo" class="desktop-dark">
                        <img src="" alt="logo" class="toggle-dark">
                        <img src="" alt="logo" class="desktop-white">
                        <img src="" alt="logo" class="toggle-white">
                    </a>
                </div>
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element (sidebar toggle — hamburger) -->
            <div class="header-element">
                <div class="sidemenu-toggle hor-toggle horizontal-navtoggle" data-bs-toggle="sidebar">
                    <a aria-label="anchor" class="open-toggle" href="javascript:void(0);">
                        <svg class="header-link-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                    </a>
                    <a aria-label="anchor" class="close-toggle" href="javascript:void(0);">
                        <svg class="header-link-icon" xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 0 24 24" width="24"><path d="M0 0h24v24H0V0z" fill="none"></path><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"></path></svg>
                </a>
                </div>
            </div>
            <!-- End::header-element -->

        </div>
        <!-- End::header-content-left -->

        <!-- Start::header-content-right -->
        <div class="header-content-right">

            <!-- Start::header-element (pending orders bell) -->
            <div class="header-element notifications-dropdown">
                <a aria-label="الطلبات المنتظرة" href="{{ route('admin.orders.index', ['status' => 'pending']) }}" class="header-link" title="الطلبات المنتظرة">
                    <svg class="header-link-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><path opacity=".3" d="M12 6.5c-2.49 0-4 2.02-4 4.5v6h8v-6c0-2.48-1.51-4.5-4-4.5z"></path><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-11c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2v-5zm-2 6H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"></path></svg>
                    @if($pendingCount > 0)
                        <span class="badge bg-danger rounded-pill header-icon-badge pulse pulse-danger">{{ $pendingCount }}</span>
                    @endif
                </a>
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element (fullscreen) -->
            <div class="header-element header-fullscreen">
                <a aria-label="anchor" onclick="openFullscreen();" href="#" class="header-link">
                    <svg class="full-screen-open header-link-icon" x="1008" y="1248" viewBox="0 0 24 24" height="100%" width="100%" preserveAspectRatio="xMidYMid meet" focusable="false"><path d="M7,14 L5,14 L5,19 L10,19 L10,17 L7,17 L7,14 Z M5,10 L7,10 L7,7 L10,7 L10,5 L5,5 L5,10 Z M17,17 L14,17 L14,19 L19,19 L19,14 L17,14 L17,17 Z M14,5 L14,7 L17,7 L17,10 L19,10 L19,5 L14,5 Z"></path></svg>
                    <svg class="full-screen-close header-link-icon d-none" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 0 24 24" width="24px" fill="#000000"><path d="M0 0h24v24H0V0z" fill="none"/><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>
                </a>
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element (profile dropdown) -->
            <div class="header-element main-profile-user">
                <a href="#" class="header-link dropdown-toggle" id="mainHeaderProfile" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                    <div class="d-flex align-items-center">
                        <img src="{{ $u?->avatar_url ?? '' }}" alt="img" class="rounded-circle header-profile-img avatar me-sm-2 me-0">
                        <div class="d-xl-block d-none align-items-center my-auto text-start">
                            <h6 class="fw-medium mb-0 lh-1 fs-13">{{ $u?->name }}</h6>
                            <span class="op-5 fw-normal d-block fs-11 lh-1">{{ $u?->role_label ?? $u?->role }}</span>
                        </div>
                    </div>
                </a>
                <div class="main-header-dropdown dropdown-menu header-profile-dropdown dropdown-menu-end" aria-labelledby="mainHeaderProfile">
                    <ul class="list-unstyled mb-0">
                        <li class="drop-heading d-xl-none d-block">
                            <div class="text-center">
                                <h5 class="text-dark mb-0 fs-16 fw-bold">{{ $u?->name }}</h5>
                                <small class="text-muted fs-12">{{ $u?->role_label ?? $u?->role }}</small>
                            </div>
                        </li>
                        <li class="dropdown-item">
                            <a class="d-flex align-items-center w-100" href="{{ route('admin.profile.show') }}">
                                <svg class="profile-icon me-3" viewBox="0 0 24 24" height="100%" width="100%">
                                    <path d="M12 16c-2.69 0-5.77 1.28-6 2h12c-.2-.71-3.3-2-6-2z" opacity=".3"></path><circle cx="12" cy="8" opacity=".3" r="2"></circle><path d="M12 14c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4zm-6 4c.22-.72 3.31-2 6-2 2.7 0 5.8 1.29 6 2H6zm6-6c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0-6c1.1 0 2 .9 2 2s-.9 2-2 2-2-.9-2-2 .9-2 2-2z"></path>
                                </svg>
                                الملف الشخصي
                            </a>
                        </li>
                        <li class="dropdown-item">
                            <form action="{{ route('logout') }}" method="POST" class="m-0 p-0">
                                @csrf
                                <button type="submit" class="d-flex align-items-center w-100 border-0 bg-transparent p-0 text-danger">
                                    <svg class="profile-icon me-3" viewBox="0 0 24 24" height="100%" width="100%"><path d="M0 0h24v24H0V0z" fill="none"/><path d="M12 4c-4.41 0-8 3.59-8 8s3.59 8 8 8 8-3.59 8-8-3.59-8-8-8zm1 13h-2v-6h2v6zm0-8h-2V7h2v2z" opacity=".3"/><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
                                    تسجيل الخروج
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
            <!-- End::header-element -->

        </div>
        <!-- End::header-content-right -->

    </div>
    <!-- End::main-header-container -->

</header>

<script>
    window.openFullscreen = window.openFullscreen || function() {
        const openIcon  = document.querySelector('.full-screen-open');
        const closeIcon = document.querySelector('.full-screen-close');
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen?.();
            openIcon?.classList.add('d-none');
            closeIcon?.classList.remove('d-none');
        } else {
            document.exitFullscreen?.();
            openIcon?.classList.remove('d-none');
            closeIcon?.classList.add('d-none');
        }
    };
</script>
