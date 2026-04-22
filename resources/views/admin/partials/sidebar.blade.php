@php
    $siteName = \App\Models\Setting::get('site_name', config('restaurant.name', 'Relax'));
    $u = auth()->user();
    $isActive = fn($routes) => request()->routeIs($routes) ? 'active' : '';
    $isOpen   = fn($routes) => request()->routeIs($routes) ? 'open'   : '';
@endphp
{{-- Sidebar structure copied 1:1 from Dashtic's dist/html/index.html.
     .app-sidebar + .main-sidebar-header (logo) + .main-sidebar >
     nav.main-menu-container > .slide-left/right + ul.main-menu.
     Only menu <li>s differ — they're our Laravel routes. --}}
<aside class="app-sidebar sticky" id="sidebar">

    <!-- Start::main-sidebar-header (logo shown when sidebar collapses or in vertical) -->
    <div class="main-sidebar-header">
        <a href="{{ route('admin.dashboard') }}" class="header-logo">
            <img src="" alt="logo" class="desktop-logo">
            <img src="" alt="logo" class="toggle-logo">
            <img src="" alt="logo" class="desktop-dark">
            <img src="" alt="logo" class="toggle-dark">
            <img src="" alt="logo" class="desktop-white">
            <img src="" alt="logo" class="toggle-white">
        </a>
    </div>
    <!-- End::main-sidebar-header -->

    <!-- Start::main-sidebar -->
    <div class="main-sidebar" id="sidebar-scroll">

        <!-- Start::nav -->
        <nav class="main-menu-container nav nav-pills flex-column sub-open">

            {{-- Dashtic's horizontal scroll-arrows (visible only in horizontal layout with overflow) --}}
            <div class="slide-left" id="slide-left">
                <svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24" height="24" viewBox="0 0 24 24"><path d="M13.293 6.293 7.586 12l5.707 5.707 1.414-1.414L10.414 12l4.293-4.293z"></path></svg>
            </div>

            <ul class="main-menu">

                {{-- لوحة التحكم --}}
                <li class="slide {{ $isActive('admin.dashboard') }}">
                    <a href="{{ route('admin.dashboard') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                        <span class="side-menu__label">لوحة التحكم</span>
                    </a>
                </li>

                {{-- ─── العمليات اليومية ─── --}}
                <li class="slide__category">
                    <span class="category-name">العمليات</span>
                </li>

                @can('viewAny', \App\Models\Order::class)
                <li class="slide {{ $isActive('admin.orders.*') }}">
                    <a href="{{ route('admin.orders.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        <span class="side-menu__label">الطلبات</span>
                        @php
                            $pending = 0;
                            try { $pending = \App\Models\Order::where('status','pending')->count(); } catch(\Throwable $e) {}
                        @endphp
                        @if($pending > 0)
                            <span class="badge bg-danger ms-auto">{{ $pending }}</span>
                        @endif
                    </a>
                </li>
                @endcan

                @if($u && $u->canAccessStation('kitchen'))
                <li class="slide {{ $isActive('admin.kitchen.*') }}">
                    <a href="{{ route('admin.kitchen.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path></svg>
                        <span class="side-menu__label">شاشة المطبخ</span>
                    </a>
                </li>
                @endif

                @if($u && $u->canAccessStation('bar'))
                <li class="slide {{ $isActive('admin.bar.*') }}">
                    <a href="{{ route('admin.bar.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line></svg>
                        <span class="side-menu__label">شاشة البار</span>
                    </a>
                </li>
                @endif

                @can('viewAny', \App\Models\Table::class)
                <li class="slide {{ $isActive('admin.tables.*') }}">
                    <a href="{{ route('admin.tables.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        <span class="side-menu__label">الطاولات</span>
                    </a>
                </li>
                @endcan

                @can('viewAny', \App\Models\Payment::class)
                <li class="slide {{ $isActive('admin.cashier.*') }}">
                    <a href="{{ route('admin.cashier.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                        <span class="side-menu__label">الكاشير</span>
                    </a>
                </li>
                @endcan

                @can('viewAny', \App\Models\Refund::class)
                <li class="slide {{ $isActive('admin.refunds.*') }}">
                    <a href="{{ route('admin.refunds.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                        <span class="side-menu__label">الاستردادات</span>
                    </a>
                </li>
                @endcan

                {{-- ─── القائمة والمخزون ─── --}}
                @if($u && $u->hasAnyRole(['super_admin','admin','manager']))
                <li class="slide__category">
                    <span class="category-name">القائمة والمخزون</span>
                </li>

                <li class="slide has-sub {{ $isOpen('admin.categories.*','admin.menu-items.*','admin.modifiers.*','admin.allergens.*') }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $isActive('admin.categories.*','admin.menu-items.*','admin.modifiers.*','admin.allergens.*') }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        <span class="side-menu__label">القائمة</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child1">
                        <li class="slide"><a href="{{ route('admin.categories.index') }}" class="side-menu__item {{ $isActive('admin.categories.*') }}">الأقسام</a></li>
                        <li class="slide"><a href="{{ route('admin.menu-items.index') }}" class="side-menu__item {{ $isActive('admin.menu-items.*') }}">الأصناف</a></li>
                        <li class="slide"><a href="{{ route('admin.modifiers.index') }}" class="side-menu__item {{ $isActive('admin.modifiers.*') }}">الإضافات</a></li>
                        <li class="slide"><a href="{{ route('admin.allergens.index') }}" class="side-menu__item {{ $isActive('admin.allergens.*') }}">مسببات الحساسية</a></li>
                    </ul>
                </li>

                <li class="slide has-sub {{ $isOpen('admin.ingredients.*','admin.inventory.*','admin.units.*','admin.suppliers.*','admin.purchase-orders.*','admin.supplier-invoices.*','admin.stock-counts.*','admin.batches.*','admin.storage-locations.*') }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $isActive('admin.ingredients.*','admin.inventory.*','admin.units.*','admin.suppliers.*','admin.purchase-orders.*','admin.supplier-invoices.*','admin.stock-counts.*','admin.batches.*','admin.storage-locations.*') }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.89 1.45l8 4A2 2 0 0 1 22 7.24v9.53a2 2 0 0 1-1.11 1.79l-8 4a2 2 0 0 1-1.79 0l-8-4a2 2 0 0 1-1.1-1.8V7.24a2 2 0 0 1 1.11-1.79l8-4a2 2 0 0 1 1.78 0z"></path></svg>
                        <span class="side-menu__label">المخزون والمشتريات</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child1">
                        <li class="slide"><a href="{{ route('admin.ingredients.index') }}" class="side-menu__item {{ $isActive('admin.ingredients.*') }}">المكونات</a></li>
                        <li class="slide"><a href="{{ route('admin.inventory.index') }}" class="side-menu__item {{ $isActive('admin.inventory.*') }}">حركات المخزن</a></li>
                        <li class="slide"><a href="{{ route('admin.stock-counts.index') }}" class="side-menu__item {{ $isActive('admin.stock-counts.*') }}">الجرد الدوري</a></li>
                        <li class="slide"><a href="{{ route('admin.batches.index') }}" class="side-menu__item {{ $isActive('admin.batches.*') }}">الدفعات والصلاحية</a></li>
                        <li class="slide"><a href="{{ route('admin.storage-locations.index') }}" class="side-menu__item {{ $isActive('admin.storage-locations.*') }}">مواقع التخزين</a></li>
                        <li class="slide"><a href="{{ route('admin.purchase-orders.index') }}" class="side-menu__item {{ $isActive('admin.purchase-orders.*') }}">أوامر الشراء</a></li>
                        <li class="slide"><a href="{{ route('admin.supplier-invoices.index') }}" class="side-menu__item {{ $isActive('admin.supplier-invoices.*') }}">فواتير الموردين</a></li>
                        <li class="slide"><a href="{{ route('admin.suppliers.index') }}" class="side-menu__item {{ $isActive('admin.suppliers.*') }}">الموردون</a></li>
                        <li class="slide"><a href="{{ route('admin.units.index') }}" class="side-menu__item {{ $isActive('admin.units.*') }}">وحدات القياس</a></li>
                    </ul>
                </li>
                @endif

                {{-- ─── التقارير ─── --}}
                @if($u && $u->hasAnyRole(['super_admin','admin','manager']))
                <li class="slide__category">
                    <span class="category-name">التقارير</span>
                </li>

                <li class="slide has-sub {{ $isOpen('admin.reports.*') }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $isActive('admin.reports.*') }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                        <span class="side-menu__label">التقارير</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child1">
                        <li class="slide"><a href="{{ route('admin.reports.end-of-day') }}" class="side-menu__item {{ $isActive('admin.reports.end-of-day') }}">نهاية اليوم</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.profit-loss') }}" class="side-menu__item {{ $isActive('admin.reports.profit-loss') }}">الأرباح والخسائر</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.menu-engineering') }}" class="side-menu__item {{ $isActive('admin.reports.menu-engineering') }}">هندسة المنيو</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.reorder-suggestions') }}" class="side-menu__item {{ $isActive('admin.reports.reorder-suggestions') }}">اقتراحات الشراء</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.sales-by-platform') }}" class="side-menu__item {{ $isActive('admin.reports.sales-by-platform') }}">مبيعات حسب المنصة</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.sales') }}" class="side-menu__item {{ $isActive('admin.reports.sales') }}">المبيعات اليومية</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.items') }}" class="side-menu__item {{ $isActive('admin.reports.items') }}">أكثر الأصناف مبيعاً</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.inventory') }}" class="side-menu__item {{ $isActive('admin.reports.inventory') }}">استهلاك المخزن</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.shifts') }}" class="side-menu__item {{ $isActive('admin.reports.shifts') }}">الورديات</a></li>
                    </ul>
                </li>
                @endif

                {{-- ─── إدارة النظام ─── --}}
                @if($u && $u->hasAnyRole(['super_admin','admin']))
                <li class="slide__category">
                    <span class="category-name">إدارة النظام</span>
                </li>

                @can('viewAny', \App\Models\User::class)
                <li class="slide {{ $isActive('admin.users.*') }}">
                    <a href="{{ route('admin.users.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        <span class="side-menu__label">الموظفون</span>
                    </a>
                </li>
                @endcan

                @can('viewAny', \App\Models\Role::class)
                <li class="slide {{ $isActive('admin.roles.*') }}">
                    <a href="{{ route('admin.roles.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        <span class="side-menu__label">الأدوار والصلاحيات</span>
                    </a>
                </li>
                @endcan

                <li class="slide {{ $isActive('admin.stations.*') }}">
                    <a href="{{ route('admin.stations.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="6" height="6" rx="1"></rect><rect x="15" y="3" width="6" height="6" rx="1"></rect><rect x="9" y="15" width="6" height="6" rx="1"></rect><path d="M6 9v3a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9"></path><path d="M12 13v2"></path></svg>
                        <span class="side-menu__label">المحطات</span>
                    </a>
                </li>

                {{-- العملات انتقلت لتبويب داخل /admin/settings — لا حاجة لرابط منفصل هنا --}}

                <li class="slide {{ $isActive('admin.settings.*','admin.currencies.*') }}">
                    <a href="{{ route('admin.settings.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        <span class="side-menu__label">الإعدادات</span>
                    </a>
                </li>

                @can('viewAny', \App\Models\ActivityLog::class)
                <li class="slide {{ $isActive('admin.activity-logs.*') }}">
                    <a href="{{ route('admin.activity-logs.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span class="side-menu__label">سجل النشاطات</span>
                    </a>
                </li>
                @endcan
                @endif

            </ul>

            <div class="slide-right" id="slide-right">
                <svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24" height="24" viewBox="0 0 24 24"><path d="M10.707 17.707 16.414 12l-5.707-5.707-1.414 1.414L13.586 12l-4.293 4.293z"></path></svg>
            </div>

        </nav>
        <!-- End::nav -->

    </div>
    <!-- End::main-sidebar -->

</aside>
