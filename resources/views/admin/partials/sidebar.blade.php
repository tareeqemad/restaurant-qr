@php
    $siteName = \App\Helpers\Brand::name();
    $u = auth()->user();
    $isActive = fn($routes) => request()->routeIs($routes) ? 'active' : '';
    $isOpen   = fn($routes) => request()->routeIs($routes) ? 'open'   : '';
    // Single 30s-cached round-trip for the four "needs attention" badges
    // (open attendance, pending reservations, pending orders, pending
    // expenses). Beats running 4 separate COUNTs on every page render.
    $sidebarBadges = \App\Support\SidebarBadges::counts();
@endphp
{{-- Sidebar structure follows Dashtic's horizontal admin navigation.
     .app-sidebar + .main-sidebar-header (logo) + .main-sidebar >
     nav.main-menu-container > .slide-left/right + ul.main-menu.
     Only menu <li>s differ — they're our Laravel routes. --}}
<aside class="app-sidebar sticky" id="sidebar">

    <!-- Start::main-sidebar-header (logo retained for Dashtic layout states) -->
    <div class="main-sidebar-header">
        <a href="{{ route('admin.dashboard') }}" class="header-logo">
            @php $brandLogo = \App\Helpers\Brand::logoUrl(); @endphp
            <img src="{{ $brandLogo }}" alt="logo" class="desktop-logo">
            <img src="{{ $brandLogo }}" alt="logo" class="toggle-logo">
            <img src="{{ $brandLogo }}" alt="logo" class="desktop-dark">
            <img src="{{ $brandLogo }}" alt="logo" class="toggle-dark">
            <img src="{{ $brandLogo }}" alt="logo" class="desktop-white">
            <img src="{{ $brandLogo }}" alt="logo" class="toggle-white">
        </a>
    </div>
    <!-- End::main-sidebar-header -->

    <!-- Start::main-sidebar -->
    <div class="main-sidebar" id="sidebar-scroll">

        <!-- Start::nav -->
        <nav class="main-menu-container nav nav-pills flex-column sub-open">

            {{-- Dashtic's horizontal scroll arrows (visible only when the menu overflows). --}}
            <div class="slide-left" id="slide-left">
                <svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24" height="24" viewBox="0 0 24 24"><path d="M13.293 6.293 7.586 12l5.707 5.707 1.414-1.414L10.414 12l4.293-4.293z"></path></svg>
            </div>

            <ul class="main-menu">

                {{-- Dashboard --}}
                <li class="slide {{ $isActive('admin.dashboard') }}">
                    <a href="{{ route('admin.dashboard') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                        <span class="side-menu__label">{{ __('admin.nav.dashboard') }}</span>
                    </a>
                </li>

                {{-- Branch overview + live monitor for management roles. --}}
                @if(auth()->user()?->isManagementLevel())
                @php
                    $monitorOpen = request()->routeIs('admin.partner.overview')
                        || request()->routeIs('admin.partner.live-monitor');
                @endphp
                <li class="slide has-sub {{ $monitorOpen ? 'open' : '' }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $monitorOpen ? 'active' : '' }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                        <span class="side-menu__label">{{ __('admin.nav.monitoring') }}</span>
                        <span class="badge bg-success-transparent ms-1" style="font-size: .58rem;">LIVE</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child1">
                <li class="slide {{ $isActive('admin.partner.overview') }}">
                    <a href="{{ route('admin.partner.overview') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path></svg>
                        <span class="side-menu__label">{{ __('admin.nav.branch_overview') }}</span>
                    </a>
                </li>
                <li class="slide {{ $isActive('admin.partner.live-monitor') }}">
                    <a href="{{ route('admin.partner.live-monitor') }}" class="side-menu__item" target="_blank" rel="noopener">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                        <span class="side-menu__label">{{ __('admin.nav.live_monitor') }}</span>
                        <span class="badge bg-success-transparent ms-1" style="font-size: .58rem;">LIVE</span>
                    </a>
                </li>
                    </ul>
                </li>
                @endif

                {{-- Daily operations --}}
                @php
                    $operationsOpen = request()->routeIs('admin.tables.*')
                        || request()->routeIs('admin.section-assignments.*')
                        || request()->routeIs('admin.attendance.*')
                        || request()->routeIs('admin.customers.*')
                        || request()->routeIs('admin.reservations.*')
                        || request()->routeIs('admin.reviews.*')
                        || request()->routeIs('admin.orders.*')
                        || request()->routeIs('admin.station.show')
                        || request()->routeIs('admin.cashier.*')
                        || request()->routeIs('admin.refunds.*')
                        || request()->routeIs('admin.expenses.*');
                @endphp
                <li class="slide has-sub {{ $operationsOpen ? 'open' : '' }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $operationsOpen ? 'active' : '' }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><path d="M14 4h7"></path><path d="M14 9h7"></path><path d="M3 14h18"></path><path d="M3 19h18"></path></svg>
                        <span class="side-menu__label">{{ __('admin.nav.operations') }}</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child1">

                {{-- Tables come before orders because hosts and waiters start from seating. --}}
                @can('viewAny', \App\Models\Table::class)
                <li class="slide {{ $isActive('admin.tables.*') }}">
                    <a href="{{ route('admin.tables.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        <span class="side-menu__label">{{ __('admin.nav.tables') }}</span>
                    </a>
                </li>
                @endcan

                {{-- Section roster sits next to Tables: it's the plan the floor
                     board reads to decide whose tables are whose. --}}
                @if($u && $u->hasAnyRole(['super_admin', 'admin', 'manager']))
                <li class="slide {{ $isActive('admin.section-assignments.*') }}">
                    <a href="{{ route('admin.section-assignments.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        <span class="side-menu__label">توزيع الأقسام</span>
                    </a>
                </li>
                @endif

                @can('viewAny', \App\Models\Attendance::class)
                <li class="slide {{ $isActive('admin.attendance.*') }}">
                    <a href="{{ route('admin.attendance.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span class="side-menu__label">{{ __('admin.nav.attendance') }}</span>
                        @if($sidebarBadges['open_attendance'] > 0)
                            <span class="badge bg-success ms-auto">{{ $sidebarBadges['open_attendance'] }}</span>
                        @endif
                    </a>
                </li>
                @endcan

                {{-- Customer area: customers, reservations, and reviews. --}}
                @php
                    $u           = auth()->user();
                    $canCust     = $u && $u->can('viewAny', \App\Models\Customer::class);
                    $canRes      = $u && $u->can('viewAny', \App\Models\Reservation::class);
                    $canRev      = $u && $u->can('viewAny', \App\Models\Review::class);
                    $showCustGrp = $canCust || $canRes || $canRev;
                    $custGrpOpen = request()->routeIs('admin.customers.*')
                                || request()->routeIs('admin.reservations.*')
                                || request()->routeIs('admin.reviews.*');

                    // Cached count from SidebarBadges (computed once per request).
                    $pendingRes = $canRes ? $sidebarBadges['pending_reservations'] : 0;
                @endphp
                @if($showCustGrp)
                <li class="slide has-sub {{ $custGrpOpen ? 'open' : '' }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $custGrpOpen ? 'active' : '' }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <span class="side-menu__label">{{ __('admin.nav.customers') }}</span>
                        @if($pendingRes > 0)
                            <span class="badge bg-warning ms-auto">{{ $pendingRes }}</span>
                        @endif
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child2">
                        @if($canCust)
                            <li class="slide">
                                <a href="{{ route('admin.customers.index') }}"
                                   class="side-menu__item {{ $isActive('admin.customers.*') }}">
                                    <i class="bi bi-people-fill submenu-icon"></i>{{ __('admin.nav.customers') }}
                                </a>
                            </li>
                            <li class="slide">
                                <a href="{{ route('admin.customers.debts.index') }}"
                                   class="side-menu__item {{ $isActive('admin.customers.debts.*') }}">
                                    <i class="bi bi-wallet2 submenu-icon"></i>{{ __('admin.nav.debt_ledger') }}
                                </a>
                            </li>
                        @endif
                        @if($canRes)
                            <li class="slide">
                                <a href="{{ route('admin.reservations.index') }}"
                                   class="side-menu__item {{ $isActive('admin.reservations.*') }}">
                                    <i class="bi bi-calendar-event-fill submenu-icon"></i>{{ __('admin.nav.reservations') }}
                                    @if($pendingRes > 0)
                                        <span class="badge bg-warning ms-auto">{{ $pendingRes }}</span>
                                    @endif
                                </a>
                            </li>
                        @endif
                        @if($canRev)
                            <li class="slide">
                                <a href="{{ route('admin.reviews.index') }}"
                                   class="side-menu__item {{ $isActive('admin.reviews.*') }}">
                                    <i class="bi bi-star-fill submenu-icon"></i>{{ __('admin.nav.reviews') }}
                                </a>
                            </li>
                        @endif
                    </ul>
                </li>
                @endif

                @can('viewAny', \App\Models\Order::class)
                <li class="slide {{ $isActive('admin.orders.*') }}">
                    <a href="{{ route('admin.orders.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        <span class="side-menu__label">{{ __('admin.nav.dine_in_orders') }}</span>
                        @if($sidebarBadges['pending_orders'] > 0)
                            <span class="badge bg-danger ms-auto">{{ $sidebarBadges['pending_orders'] }}</span>
                        @endif
                    </a>
                </li>
                @endcan

                {{-- Waiter quick entry — for walk-ins without QR access.
                     Same OrderPolicy::create gate as the orders board,
                     so anyone who can see the board can take a phone-
                     less customer's order from the floor. --}}
                @can('create', \App\Models\Order::class)
                <li class="slide {{ $isActive('admin.waiter-orders.*') }}">
                    <a href="{{ route('admin.waiter-orders.index') }}" class="side-menu__item">
                        <i class="bi bi-clipboard-plus side-menu__icon"></i>
                        <span class="side-menu__label">{{ __('admin.nav.manual_order') }}</span>
                    </a>
                </li>
                @endcan

                {{-- Staff meal allowance: manager-only per-employee monthly ledger. --}}
                @if(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']))
                <li class="slide {{ $isActive('admin.staff-meals.*') }}">
                    <a href="{{ route('admin.staff-meals.index') }}" class="side-menu__item">
                        <i class="bi bi-cup-hot-fill side-menu__icon"></i>
                        <span class="side-menu__label">{{ __('admin.nav.staff_meals') }}</span>
                    </a>
                </li>
                @endif

                @can('archive', \App\Models\Order::class)
                <li class="slide {{ $isActive('admin.orders.archive') }}">
                    <a href="{{ route('admin.orders.archive') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
                        <span class="side-menu__label">{{ __('admin.nav.orders_archive') }}</span>
                    </a>
                </li>
                @endcan

                {{-- Production station screens
                     Single collapsible entry. Lists every active station
                     the current user has access to (`station.{code}.view`
                     permission OR a matching user.station_id). Hides
                     entirely if the user has zero accessible stations, so
                     a cashier / accountant never sees an empty menu.
                     Adding a station from the admin panel appears here
                     instantly once the role is granted its new permission. --}}
                @php
                    $accessibleStations = collect();
                    if ($u) {
                        try {
                            $accessibleStations = \App\Models\Station::where('active', true)
                                ->orderBy('display_order')
                                ->get()
                                ->filter(fn($s) => $u->canAccessStation($s->code))
                                ->values();
                        } catch (\Throwable $e) {}
                    }
                    $productionOpen = request()->routeIs('admin.station.show');
                @endphp
                @if($accessibleStations->isNotEmpty())
                <li class="slide has-sub {{ $productionOpen ? 'open' : '' }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $productionOpen ? 'active' : '' }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path>
                        </svg>
                        <span class="side-menu__label">{{ __('admin.nav.production') }}</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child2">
                        @foreach($accessibleStations as $station)
                            @php
                                $isThisStation = request()->routeIs('admin.station.show')
                                    && request()->route('code') === $station->code;
                            @endphp
                            <li class="slide">
                                <a href="{{ route('admin.station.show', $station->code) }}"
                                   class="side-menu__item {{ $isThisStation ? 'active' : '' }}">
                                    <i class="{{ $station->icon ?: 'ri-fire-fill' }} submenu-icon"></i>{{ $station->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
                @endif


                {{-- Accounting: cashier, shifts, refunds, and expenses. --}}
                @php
                    $canCashier  = $u && $u->can('viewAny', \App\Models\Payment::class);
                    $canShifts   = $u && $u->can('viewAny', \App\Models\Shift::class);
                    $canRefunds  = $u && $u->can('viewAny', \App\Models\Refund::class);
                    $canExpenses = $u && $u->can('viewAny', \App\Models\Expense::class);
                    $canAccounting = $u && $u->hasAnyRole(['super_admin','admin','manager']);
                    $showAccounts = $canCashier || $canShifts || $canRefunds || $canExpenses || $canAccounting;
                    $accountsOpen = request()->routeIs('admin.cashier.*')
                                 || request()->routeIs('admin.shifts.*')
                                 || request()->routeIs('admin.refunds.*')
                                 || request()->routeIs('admin.expenses.*')
                                 || request()->routeIs('admin.accounting.*');

                    // Cached count from SidebarBadges (computed once per request).
                    $pendingExpenses = $canExpenses ? $sidebarBadges['pending_expenses'] : 0;
                @endphp
                @if($showAccounts)
                <li class="slide has-sub {{ $accountsOpen ? 'open' : '' }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $accountsOpen ? 'active' : '' }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                        <span class="side-menu__label">{{ __('admin.nav.accounting') }}</span>
                        @if($pendingExpenses > 0)
                            <span class="badge bg-warning ms-auto">{{ $pendingExpenses }}</span>
                        @endif
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child2">
                        @if($canCashier)
                            @php
                                $pendingTransfers = $sidebarBadges['pending_transfers'] ?? 0;
                            @endphp
                            <li class="slide"><a href="{{ route('admin.cashier.index') }}" class="side-menu__item {{ $isActive('admin.cashier.index') }}"><i class="bi bi-cash-stack submenu-icon"></i>{{ __('admin.nav.cashier') }}</a></li>
                            <li class="slide">
                                <a href="{{ route('admin.cashier.transfers.queue') }}" class="side-menu__item {{ $isActive('admin.cashier.transfers.*') }}">
                                    <i class="bi bi-bank submenu-icon"></i>
                                    {{ __('admin.nav.pending_transfers') }}
                                    @if($pendingTransfers > 0)
                                        <span class="badge bg-warning text-dark ms-auto">{{ $pendingTransfers }}</span>
                                    @endif
                                </a>
                            </li>
                        @endif
                        @if($canAccounting)
                            <li class="slide"><a href="{{ route('admin.accounting.trial-balance') }}" class="side-menu__item {{ $isActive('admin.accounting.trial-balance') }}"><i class="bi bi-columns-gap submenu-icon"></i>{{ __('admin.nav.trial_balance') }}</a></li>
                            <li class="slide"><a href="{{ route('admin.accounting.journal') }}" class="side-menu__item {{ $isActive('admin.accounting.journal') }}"><i class="bi bi-journal-text submenu-icon"></i>{{ __('admin.nav.journal') }}</a></li>
                            <li class="slide"><a href="{{ route('admin.accounting.balance-sheet') }}" class="side-menu__item {{ $isActive('admin.accounting.balance-sheet') }}"><i class="bi bi-bank submenu-icon"></i>الميزانية العمومية</a></li>
                            <li class="slide"><a href="{{ route('admin.accounting.fixed-assets.index') }}" class="side-menu__item {{ $isActive('admin.accounting.fixed-assets.*') }}"><i class="bi bi-building-gear submenu-icon"></i>الأصول الثابتة</a></li>
                            <li class="slide"><a href="{{ route('admin.accounting.aging') }}" class="side-menu__item {{ $isActive('admin.accounting.aging') }}"><i class="bi bi-hourglass-split submenu-icon"></i>أعمار الذمم</a></li>
                            <li class="slide"><a href="{{ route('admin.accounting.tax-report') }}" class="side-menu__item {{ $isActive('admin.accounting.tax-report') }}"><i class="bi bi-percent submenu-icon"></i>تقرير الضريبة</a></li>
                            @if(auth()->user()?->hasPermission('chart_of_accounts.update'))
                                <li class="slide"><a href="{{ route('admin.accounting.mappings') }}" class="side-menu__item {{ $isActive('admin.accounting.mappings') }}"><i class="bi bi-diagram-3 submenu-icon"></i>قواعد الترحيل</a></li>
                                <li class="slide"><a href="{{ route('admin.accounting.periods') }}" class="side-menu__item {{ $isActive('admin.accounting.periods') }}"><i class="bi bi-calendar-lock submenu-icon"></i>الفترات المحاسبية</a></li>
                                <li class="slide"><a href="{{ route('admin.accounting.fiscal-years') }}" class="side-menu__item {{ $isActive('admin.accounting.fiscal-years') }}"><i class="bi bi-calendar2-check submenu-icon"></i>السنوات المالية</a></li>
                                <li class="slide"><a href="{{ route('admin.accounting.tax-jurisdictions') }}" class="side-menu__item {{ $isActive('admin.accounting.tax-jurisdictions') }}"><i class="bi bi-percent submenu-icon"></i>قواعد ضريبة المبيعات</a></li>
                                <li class="slide"><a href="{{ route('admin.accounting.reconciliations') }}" class="side-menu__item {{ $isActive('admin.accounting.reconciliations') }}"><i class="bi bi-check2-square submenu-icon"></i>مطابقة الصندوق والبنك</a></li>
                            @endif
                            @if(auth()->user()?->hasPermission('chart_of_accounts.update'))
                                <li class="slide"><a href="{{ route('admin.accounting.settlements') }}" class="side-menu__item {{ $isActive('admin.accounting.settlements') }}"><i class="bi bi-arrow-left-right submenu-icon"></i>التسويات المحاسبية</a></li>
                            @endif
                            @if(auth()->user()?->hasPermission('chart_of_accounts.create'))
                                <li class="slide"><a href="{{ route('admin.accounting.opening-balances') }}" class="side-menu__item {{ $isActive('admin.accounting.opening-balances') }}"><i class="bi bi-door-open submenu-icon"></i>الأرصدة الافتتاحية</a></li>
                            @endif
                            @if(auth()->user()?->hasPermission('chart_of_accounts.viewAny'))
                                <li class="slide"><a href="{{ route('admin.accounts.index') }}" class="side-menu__item {{ $isActive('admin.accounts.*') }}"><i class="bi bi-diagram-3-fill submenu-icon"></i>{{ __('admin.nav.chart_of_accounts') }}</a></li>
                            @endif
                        @endif
                        @if($canShifts)
                            <li class="slide">
                                <a href="{{ route('admin.shifts.index') }}" class="side-menu__item {{ $isActive('admin.shifts.*') }}">
                                    <i class="bi bi-clock-history submenu-icon"></i>{{ __('admin.nav.shifts') }}
                                </a>
                            </li>
                        @endif
                        @if($canExpenses)
                            <li class="slide">
                                <a href="{{ route('admin.expenses.index') }}" class="side-menu__item {{ $isActive('admin.expenses.*') }}">
                                    <i class="bi bi-credit-card-fill submenu-icon"></i>{{ __('admin.nav.operating_expenses') }}
                                    @if($pendingExpenses > 0)
                                        <span class="badge bg-warning ms-auto">{{ $pendingExpenses }}</span>
                                    @endif
                                </a>
                            </li>
                        @endif
                        @if($canRefunds)
                            <li class="slide"><a href="{{ route('admin.refunds.index') }}" class="side-menu__item {{ $isActive('admin.refunds.*') }}"><i class="bi bi-arrow-counterclockwise submenu-icon"></i>{{ __('admin.nav.refunds') }}</a></li>
                        @endif
                        @can('viewAny', App\Models\Announcement::class)
                            <li class="slide">
                                <a href="{{ route('admin.announcements.index') }}" class="side-menu__item {{ $isActive('admin.announcements.*') }}">
                                    <i class="bi bi-megaphone-fill submenu-icon"></i>{{ __('admin.nav.offers_announcements') }}
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endif
                    </ul>
                </li>
                {{-- These two tags close the big operations mega-menu
                     <li> that opens around line 98 (with its nested
                     <ul class="slide-menu child1">). Without them, every
                     subsequent group (inventory / reports / administration
                     / chart of accounts) collapses into operations as
                     sub-items instead of standing as top-level
                     dropdowns — which made the horizontal nav look
                     almost empty on wider screens. Confirmed via tag
                     balance: removing them produced exactly 1 missing
                     </li> + 1 missing </ul>. --}}

                {{-- Inventory
                     The menu submenu (categories, items, modifiers,
                     allergens, stations) was moved out of this section to
                     Administration per owner's reorganisation. What remains
                     here is inventory + purchasing — back-of-house ops. --}}
                @if($u && $u->hasAnyRole(['super_admin','admin','manager']))
                @php
                    // Branch transfers are only meaningful with 2+ active branches —
                    // auto-hide for a single-branch shop, auto-show once a second
                    // branch is added. Cheap COUNT on a tiny table.
                    $multiBranch = \App\Models\Branch::where('is_active', true)->count() > 1;
                @endphp
                <li class="slide has-sub {{ $isOpen('admin.ingredients.*','admin.inventory.*','admin.units.*','admin.suppliers.*','admin.purchase-orders.*','admin.supplier-invoices.*','admin.stock-counts.*','admin.batches.*','admin.storage-locations.*','admin.waste.*','admin.vendor-prices.*') }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $isActive('admin.ingredients.*','admin.inventory.*','admin.units.*','admin.suppliers.*','admin.purchase-orders.*','admin.supplier-invoices.*','admin.stock-counts.*','admin.batches.*','admin.storage-locations.*','admin.waste.*','admin.vendor-prices.*') }}">
                        {{-- Feather "box" icon — same outer hex as before but with the
                             interior 3D edges so it reads as a closed crate, not a
                             flat hexagon at small sizes. --}}
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                            <line x1="12" y1="22.08" x2="12" y2="12"></line>
                        </svg>
                        <span class="side-menu__label">{{ __('admin.nav.inventory_purchasing') }}</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    {{-- Reordered to follow the real-world inventory workflow:
                         (1) reference data you set up first, (2) the
                         procurement chain in sequence (suppliers → price
                         compare → PO → invoice), (3) daily ops in the
                         order they happen (expiry → count → waste →
                         transfer), and finally (4) the monitoring views
                         on top so the manager opens the dropdown and the
                         dashboard is the first thing they see. --}}
                    <ul class="slide-menu child1 inventory-menu">
                        <li class="slide-section">{{ __('admin.nav.monitoring_section') }}</li>
                        <li class="slide"><a href="{{ route('admin.inventory.dashboard') }}" class="side-menu__item {{ $isActive('admin.inventory.dashboard') }}"><i class="bi bi-speedometer2 submenu-icon"></i>{{ __('admin.nav.inventory_dashboard') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.inventory.index') }}" class="side-menu__item {{ $isActive('admin.inventory.*') }}"><i class="bi bi-arrow-left-right submenu-icon"></i>{{ __('admin.nav.stock_movements') }}</a></li>

                        <li class="slide-section">{{ __('admin.nav.references') }}</li>
                        <li class="slide"><a href="{{ route('admin.ingredients.index') }}" class="side-menu__item {{ $isActive('admin.ingredients.*') }}"><i class="bi bi-basket-fill submenu-icon"></i>{{ __('admin.nav.ingredients') }}</a></li>
                        @if(config('restaurant.nav.units_management'))
                        <li class="slide"><a href="{{ route('admin.units.index') }}" class="side-menu__item {{ $isActive('admin.units.*') }}"><i class="bi bi-rulers submenu-icon"></i>{{ __('admin.nav.units') }}</a></li>
                        @endif
                        <li class="slide"><a href="{{ route('admin.storage-locations.index') }}" class="side-menu__item {{ $isActive('admin.storage-locations.*') }}"><i class="bi bi-house-door-fill submenu-icon"></i>{{ __('admin.nav.storage_locations') }}</a></li>

                        <li class="slide-section">{{ __('admin.nav.purchasing') }}</li>
                        <li class="slide"><a href="{{ route('admin.suppliers.index') }}" class="side-menu__item {{ $isActive('admin.suppliers.*') }}"><i class="bi bi-truck submenu-icon"></i>{{ __('admin.nav.suppliers') }}</a></li>
                        @if(config('restaurant.nav.vendor_price_compare'))
                        <li class="slide"><a href="{{ route('admin.vendor-prices.compare') }}" class="side-menu__item {{ $isActive('admin.vendor-prices.*') }}"><i class="bi bi-arrows-collapse submenu-icon"></i>{{ __('admin.nav.vendor_price_compare') }}</a></li>
                        @endif
                        <li class="slide"><a href="{{ route('admin.purchase-orders.index') }}" class="side-menu__item {{ $isActive('admin.purchase-orders.*') }}"><i class="bi bi-file-earmark-text-fill submenu-icon"></i>{{ __('admin.nav.purchase_orders') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.supplier-invoices.index') }}" class="side-menu__item {{ $isActive('admin.supplier-invoices.*') }}"><i class="bi bi-receipt submenu-icon"></i>{{ __('admin.nav.supplier_invoices') }}</a></li>

                        <li class="slide-section">{{ __('admin.nav.daily_operations') }}</li>
                        @if(config('restaurant.nav.batch_expiry'))
                        <li class="slide"><a href="{{ route('admin.batches.index') }}" class="side-menu__item {{ $isActive('admin.batches.*') }}"><i class="bi bi-calendar-x submenu-icon"></i>{{ __('admin.nav.batches_expiry') }}</a></li>
                        @endif
                        <li class="slide"><a href="{{ route('admin.stock-counts.index') }}" class="side-menu__item {{ $isActive('admin.stock-counts.*') }}"><i class="bi bi-clipboard-check-fill submenu-icon"></i>{{ __('admin.nav.stock_counts') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.waste.index') }}" class="side-menu__item {{ $isActive('admin.waste.*') }}"><i class="bi bi-trash3-fill submenu-icon"></i>{{ __('admin.nav.waste') }}</a></li>
                        @if($multiBranch)
                        <li class="slide"><a href="{{ route('admin.branch-transfers.index') }}" class="side-menu__item {{ $isActive('admin.branch-transfers.*') }}"><i class="bi bi-arrow-left-right submenu-icon"></i>{{ __('admin.nav.branch_transfers') }}</a></li>
                        @endif
                    </ul>
                </li>
                @endif

                {{-- Reports --}}
                @if($u && $u->hasAnyRole(['super_admin','admin','manager']))
                <li class="slide has-sub {{ $isOpen('admin.reports.*') }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $isActive('admin.reports.*') }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                        <span class="side-menu__label">{{ __('admin.nav.reports') }}</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child1">
                        <li class="slide"><a href="{{ route('admin.reports.index') }}" class="side-menu__item {{ $isActive('admin.reports.index') }}"><i class="bi bi-speedometer2 submenu-icon"></i>{{ __('admin.nav.reports_dashboard') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.end-of-day') }}" class="side-menu__item {{ $isActive('admin.reports.end-of-day') }}"><i class="bi bi-calendar-check-fill submenu-icon"></i>{{ __('admin.nav.end_of_day') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.profit-loss') }}" class="side-menu__item {{ $isActive('admin.reports.profit-loss') }}"><i class="bi bi-graph-up-arrow submenu-icon"></i>{{ __('admin.nav.profit_loss') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.accounting.trial-balance') }}" class="side-menu__item {{ $isActive('admin.accounting.trial-balance') }}"><i class="bi bi-columns-gap submenu-icon"></i>{{ __('admin.nav.trial_balance') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.menu-engineering') }}" class="side-menu__item {{ $isActive('admin.reports.menu-engineering') }}"><i class="bi bi-diagram-3-fill submenu-icon"></i>{{ __('admin.nav.menu_engineering') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.reorder-suggestions') }}" class="side-menu__item {{ $isActive('admin.reports.reorder-suggestions') }}"><i class="bi bi-cart-plus-fill submenu-icon"></i>{{ __('admin.nav.reorder_suggestions') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.stock-valuation') }}" class="side-menu__item {{ $isActive('admin.reports.stock-valuation') }}"><i class="bi bi-cash-stack submenu-icon"></i>{{ __('admin.nav.stock_valuation') }}</a></li>
                        @if($u && $u->isOwnerLevel())
                            <li class="slide"><a href="{{ route('admin.reports.branch-comparison') }}" class="side-menu__item {{ $isActive('admin.reports.branch-comparison') }}"><i class="bi bi-arrows-collapse submenu-icon"></i>{{ __('admin.nav.branch_comparison') }}</a></li>
                        @endif
                        <li class="slide"><a href="{{ route('admin.reports.sales') }}" class="side-menu__item {{ $isActive('admin.reports.sales') }}"><i class="bi bi-bar-chart-line-fill submenu-icon"></i>{{ __('admin.nav.daily_sales') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.items') }}" class="side-menu__item {{ $isActive('admin.reports.items') }}"><i class="bi bi-trophy-fill submenu-icon"></i>{{ __('admin.nav.top_selling_items') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.inventory') }}" class="side-menu__item {{ $isActive('admin.reports.inventory') }}"><i class="bi bi-box-seam submenu-icon"></i>{{ __('admin.nav.inventory_consumption') }}</a></li>
                        <li class="slide"><a href="{{ route('admin.reports.shifts') }}" class="side-menu__item {{ $isActive('admin.reports.shifts') }}"><i class="bi bi-clock-history submenu-icon"></i>{{ __('admin.nav.shifts') }}</a></li>
                    </ul>
                </li>
                @endif

                {{-- System administration.
                     Menu and stations moved here from inventory / legacy admin,
                     and users were grouped with roles.
                     Section header is shown whenever the user can see at
                     least ONE item inside. --}}
                @php
                    $canMenu       = $u && $u->hasAnyRole(['super_admin','admin','manager']);
                    $canStations   = $u && $u->hasAnyRole(['super_admin','admin']);
                    $canUsers      = $u && $u->can('viewAny', \App\Models\User::class);
                    $canRoles      = $u && $u->can('viewAny', \App\Models\Role::class);
                    $canBranches   = $u && $u->can('viewAny', \App\Models\Branch::class);
                    $canSettings   = $u && $u->hasAnyRole(['super_admin','admin']);
                    $canActivity   = $u && $u->can('viewAny', \App\Models\ActivityLog::class);
                    $canLookups    = $u && $u->can('viewAny', \App\Models\Lookup::class);
                    $canLicenseStatus = $u && config('license.enabled') && $u->hasAnyRole(['super_admin','admin']);
                    $canLicenses = $u && config('license.role') === 'cloud' && $u->isSuperAdmin();

                    $showSystemSection = $canMenu || $canStations || $canUsers || $canRoles || $canBranches || $canSettings || $canActivity || $canLookups || $canLicenseStatus || $canLicenses;

                    // Menu submenu opens when any catalogue-management route is active
                    $menuOpen = request()->routeIs('admin.categories.*')
                             || request()->routeIs('admin.menu-items.*')
                             || request()->routeIs('admin.modifiers.*')
                             || request()->routeIs('admin.allergens.*')
                             || request()->routeIs('admin.stations.*');

                    // Users-and-roles submenu
                    $usersOpen = request()->routeIs('admin.users.*')
                              || request()->routeIs('admin.roles.*');
                @endphp

                @if($showSystemSection)
                @php
                    $adminOpen = $menuOpen
                        || $usersOpen
                        || request()->routeIs('admin.branches.*')
                        || request()->routeIs('admin.lookups.*')
                        || request()->routeIs('admin.settings.*')
                        || request()->routeIs('admin.currencies.*')
                        || request()->routeIs('admin.license-status.*')
                        || request()->routeIs('admin.licenses.*')
                        || request()->routeIs('admin.activity-logs.*')
                        || request()->routeIs('admin.system.*');
                @endphp
                <li class="slide has-sub {{ $adminOpen ? 'open' : '' }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $adminOpen ? 'active' : '' }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"></path><path d="M2 17l10 5 10-5"></path><path d="M2 12l10 5 10-5"></path></svg>
                        <span class="side-menu__label">{{ __('admin.nav.system_admin') }}</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child1">

                {{-- Menu catalogue and stations --}}
                @if($canMenu || $canStations)
                <li class="slide has-sub {{ $menuOpen ? 'open' : '' }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $menuOpen ? 'active' : '' }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        <span class="side-menu__label">{{ __('admin.nav.menu') }}</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child2">
                        @if($canMenu)
                            <li class="slide"><a href="{{ route('admin.categories.index') }}" class="side-menu__item {{ $isActive('admin.categories.*') }}"><i class="bi bi-grid-fill submenu-icon"></i>{{ __('admin.nav.categories') }}</a></li>
                            <li class="slide"><a href="{{ route('admin.menu-items.index') }}" class="side-menu__item {{ $isActive('admin.menu-items.*') }}"><i class="bi bi-egg-fried submenu-icon"></i>{{ __('admin.nav.menu_items') }}</a></li>
                            <li class="slide"><a href="{{ route('admin.modifiers.index') }}" class="side-menu__item {{ $isActive('admin.modifiers.*') }}"><i class="bi bi-plus-circle-fill submenu-icon"></i>{{ __('admin.nav.modifiers') }}</a></li>
                            <li class="slide"><a href="{{ route('admin.allergens.index') }}" class="side-menu__item {{ $isActive('admin.allergens.*') }}"><i class="bi bi-exclamation-triangle-fill submenu-icon"></i>{{ __('admin.nav.allergens') }}</a></li>
                            @if(auth()->user()?->hasPermission('promotions.viewAny'))
                                <li class="slide"><a href="{{ route('admin.promotions.index') }}" class="side-menu__item {{ $isActive('admin.promotions.*') }}"><i class="bi bi-tag-fill submenu-icon"></i>{{ __('admin.nav.promotions') }}</a></li>
                            @endif
                        @endif
                        @if($canStations)
                            <li class="slide"><a href="{{ route('admin.stations.index') }}" class="side-menu__item {{ $isActive('admin.stations.*') }}"><i class="bi bi-fire submenu-icon"></i>{{ __('admin.nav.stations') }}</a></li>
                        @endif
                    </ul>
                </li>
                @endif

                {{-- Branches --}}
                @if($canBranches)
                <li class="slide {{ $isActive('admin.branches.*') }}">
                    <a href="{{ route('admin.branches.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 21h18"></path>
                            <path d="M5 21V7l8-4v18"></path>
                            <path d="M19 21V11l-6-4"></path>
                            <path d="M9 9v.01"></path>
                            <path d="M9 12v.01"></path>
                            <path d="M9 15v.01"></path>
                            <path d="M9 18v.01"></path>
                        </svg>
                        <span class="side-menu__label">{{ __('admin.nav.branches') }}</span>
                    </a>
                </li>
                @endif

                {{-- Users and permissions --}}
                @if($canUsers || $canRoles)
                <li class="slide has-sub {{ $usersOpen ? 'open' : '' }}">
                    <a href="javascript:void(0);" class="side-menu__item {{ $usersOpen ? 'active' : '' }}">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        <span class="side-menu__label">{{ __('admin.nav.users_permissions') }}</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child2">
                        @if($canUsers)
                            <li class="slide"><a href="{{ route('admin.users.index') }}" class="side-menu__item {{ $isActive('admin.users.*') }}"><i class="bi bi-person-fill submenu-icon"></i>{{ __('admin.nav.employees') }}</a></li>
                        @endif
                        @if($canRoles)
                            <li class="slide"><a href="{{ route('admin.roles.index') }}" class="side-menu__item {{ $isActive('admin.roles.*') }}"><i class="bi bi-shield-lock-fill submenu-icon"></i>{{ __('admin.nav.roles_permissions') }}</a></li>
                        @endif
                        {{-- Dedicated page for tweaking PER-USER permission deviations.
                             Gated INDEPENDENTLY of $canRoles (RolePolicy::viewAny uses
                             a hardcoded role check, NOT the permission system) — so a
                             manager who got `roles.update` granted as a per-user
                             override can still find the link. This is literally the
                             feature this page exists to serve. --}}
                        @if(auth()->user()?->hasPermission('roles.update'))
                            <li class="slide"><a href="{{ route('admin.permissions.index') }}" class="side-menu__item {{ $isActive('admin.permissions.*') }}"><i class="bi bi-person-fill-gear submenu-icon"></i>{{ __('admin.nav.permission_management') }}</a></li>
                        @endif
                    </ul>
                </li>
                @endif

                {{-- Lookups --}}
                @if($canLookups)
                <li class="slide {{ $isActive('admin.lookups.*') }}">
                    <a href="{{ route('admin.lookups.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="8" y1="6" x2="21" y2="6"></line>
                            <line x1="8" y1="12" x2="21" y2="12"></line>
                            <line x1="8" y1="18" x2="21" y2="18"></line>
                            <line x1="3" y1="6" x2="3.01" y2="6"></line>
                            <line x1="3" y1="12" x2="3.01" y2="12"></line>
                            <line x1="3" y1="18" x2="3.01" y2="18"></line>
                        </svg>
                        <span class="side-menu__label">{{ __('admin.nav.lookups') }}</span>
                    </a>
                </li>
                @endif

                {{-- Settings --}}
                @if($canSettings)
                <li class="slide {{ $isActive('admin.settings.*','admin.currencies.*') }}">
                    <a href="{{ route('admin.settings.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        <span class="side-menu__label">{{ __('admin.nav.settings') }}</span>
                    </a>
                </li>
                @endif

                {{-- Chart of accounts.
                     The chart is also linked from the accounting group
                     (operational accounting menu), but super-admin /
                     manager naturally look for system-level config
                     items under administration. Duplicate link here
                     so it's discoverable from both spots. --}}
                @if($canLicenseStatus)
                <li class="slide {{ $isActive('admin.license-status.*') }}">
                    <a href="{{ route('admin.license-status.index') }}" class="side-menu__item">
                        <i class="bi bi-key-fill side-menu__icon"></i>
                        <span class="side-menu__label">{{ __('admin.nav.license') }}</span>
                    </a>
                </li>
                @endif

                @if($canLicenses)
                <li class="slide {{ $isActive('admin.licenses.*') }}">
                    <a href="{{ route('admin.licenses.index') }}" class="side-menu__item">
                        <i class="bi bi-patch-check-fill side-menu__icon"></i>
                        <span class="side-menu__label">{{ __('admin.nav.customer_licenses') }}</span>
                    </a>
                </li>
                @endif

                @if(auth()->user()?->hasPermission('chart_of_accounts.viewAny'))
                <li class="slide {{ $isActive('admin.accounts.*') }}">
                    <a href="{{ route('admin.accounts.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"></circle><line x1="12" y1="22" x2="12" y2="8"></line><path d="M5 12H2a10 10 0 0 0 20 0h-3"></path></svg>
                        <span class="side-menu__label">{{ __('admin.nav.chart_of_accounts') }}</span>
                    </a>
                </li>
                @endif

                {{-- Activity log --}}
                @if($canActivity)
                <li class="slide {{ $isActive('admin.activity-logs.*') }}">
                    <a href="{{ route('admin.activity-logs.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span class="side-menu__label">{{ __('admin.nav.activity_log') }}</span>
                    </a>
                </li>
                @endif

                {{-- System management --}}
                @if(auth()->user()?->isSuperAdmin())
                <li class="slide {{ $isActive('admin.system.*') }}">
                    <a href="{{ route('admin.system.index') }}" class="side-menu__item">
                        <svg class="side-menu__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"></path><path d="M2 17l10 5 10-5"></path><path d="M2 12l10 5 10-5"></path></svg>
                        <span class="side-menu__label">{{ __('admin.nav.system_management') }}</span>
                    </a>
                </li>
                @endif
                    </ul>
                </li>

                @endif {{-- /$showSystemSection --}}

            </ul>

            <div class="slide-right" id="slide-right">
                <svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24" height="24" viewBox="0 0 24 24"><path d="M10.707 17.707 16.414 12l-5.707-5.707-1.414 1.414L13.586 12l-4.293 4.293z"></path></svg>
            </div>

        </nav>
        <!-- End::nav -->

    </div>
    <!-- End::main-sidebar -->

</aside>
