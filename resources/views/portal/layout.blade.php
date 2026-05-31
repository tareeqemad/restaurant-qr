@php
    $theme = \App\Support\ThemePalette::current();
    $siteName = \App\Helpers\Brand::name();
    $market = \App\Support\MarketProfile::class;
@endphp
<!DOCTYPE html>
<html lang="{{ $market::lang() }}" dir="{{ $market::direction() }}" data-market="{{ $market::current() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $theme['primary'] }}">
    <title>@yield('title', __('portal.layout.title')) · {{ $siteName }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="{{ $market::fontUrl() }}" rel="stylesheet">
    <style>
        :root {
            --green-primary: {{ $theme['primary'] }};
            --green-dark:    {{ $theme['dark'] }};
            --green-soft:    {{ $theme['primary_light'] }};
            --gold:          #F9A825;
            --ink:           #1F2937;
            --ink-2:         #374151;
            --muted:         #6B7280;
            --muted-2:       #9CA3AF;
            --line:          #E5E7EB;
            --cream:         #FAF7F2;
            --paper:         #FFFFFF;
        }
        @include('partials.theme-vars', ['theme' => $theme])
        @include('partials.market-vars')
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: var(--market-font-family);
            color: var(--ink);
            background:
                radial-gradient(circle at 90% -10%, rgba(var(--primary-rgb), .10), transparent 55%),
                radial-gradient(circle at 0% 100%, rgba(var(--primary-rgb), .18), transparent 55%),
                linear-gradient(135deg, #FFFFFF 0%, #FAF7F2 100%);
            min-height: 100vh;
        }
        .pf-header {
            position: sticky; top: 0; z-index: 50;
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
        }
        .pf-header-inner {
            display: flex; align-items: center; gap: 14px;
            max-width: 720px; margin: 0 auto;
            padding: 14px 18px;
        }
        .pf-brand {
            display: flex; align-items: center; gap: 10px;
            text-decoration: none; color: var(--ink);
            font-weight: 800; font-size: 1rem;
        }
        .pf-brand img { width: 32px; height: 32px; object-fit: contain; }
        .pf-spacer { flex: 1; }
        .pf-link {
            color: var(--ink-2); text-decoration: none;
            font-size: .88rem; font-weight: 600;
            padding: 7px 12px; border-radius: 8px;
            transition: background .12s;
        }
        .pf-link:hover { background: #f3f4f6; color: var(--ink); }
        .pf-link.is-active { color: var(--green-dark); background: rgba(var(--primary-rgb), .08); }
        .pf-logout {
            background: transparent; border: 0;
            font: inherit; cursor: pointer;
            color: var(--muted); font-size: .85rem;
            padding: 7px 12px; border-radius: 8px;
        }
        .pf-logout:hover { background: #fef2f2; color: #b91c1c; }

        .pf-main { max-width: 720px; margin: 0 auto; padding: 24px 18px 60px; }

        .pf-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 1px 3px rgba(15,23,42,.04), 0 4px 12px rgba(15,23,42,.04);
        }
        .pf-section {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 1px 3px rgba(15,23,42,.04), 0 4px 12px rgba(15,23,42,.04);
            min-width: 0;
        }
        .pf-section--centered {
            text-align: center;
            padding: 34px 24px;
        }
        .pf-card:has(.pf-btn) { overflow: visible; }
        .pf-section:has(.pf-btn) { overflow: visible; }
        .pf-card + .pf-card { margin-top: 14px; }
        .pf-card + .pf-section,
        .pf-section + .pf-card,
        .pf-section + .pf-section {
            margin-top: 14px;
        }

        .pf-title {
            font-size: 1.15rem; font-weight: 800; margin: 0 0 4px;
            color: var(--ink);
        }
        .pf-subtitle { font-size: .85rem; color: var(--muted); margin: 0 0 16px; }

        .pf-input-group { margin-bottom: 14px; }
        .pf-label {
            display: block; font-size: .8rem; font-weight: 700;
            color: var(--ink-2); margin-bottom: 6px;
        }
        .pf-input {
            width: 100%;
            background: var(--cream);
            border: 1.5px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: .95rem; font-weight: 500;
            color: var(--ink);
            font-family: inherit;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .pf-input:focus {
            outline: none;
            background: #fff;
            border-color: rgba(var(--primary-rgb), .7);
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), .12);
        }
        select.pf-input:not([multiple]):not([size]) {
            appearance: none;
            -webkit-appearance: none;
            background-image:
                linear-gradient(45deg, transparent 50%, rgb(var(--primary-rgb)) 50%),
                linear-gradient(135deg, rgb(var(--primary-rgb)) 50%, transparent 50%);
            background-repeat: no-repeat;
            background-size: .42rem .42rem, .42rem .42rem;
            background-position: left 1rem center, left .72rem center;
            padding-inline-start: 2.35rem;
        }
        .pf-input.has-error { border-color: #dc2626; box-shadow: 0 0 0 4px rgba(220,38,38,.12); }
        .pf-error { color: #b91c1c; font-size: .78rem; font-weight: 600; margin-top: 4px; }

        .pf-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px;
            max-width: 100%;
            background: linear-gradient(135deg, var(--green-primary), var(--green-dark));
            color: #fff; border: 0;
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: 800; font-size: .95rem;
            line-height: 1.25;
            font-family: inherit;
            cursor: pointer;
            white-space: normal;
            box-shadow:
                0 4px 14px -4px rgba(var(--primary-rgb), .45),
                inset 0 1px 0 rgba(255,255,255,.18),
                inset 0 -2px 0 rgba(249,168,37,.30);
            transition: transform .15s, filter .15s;
            text-decoration: none;
            text-align: center;
        }
        .pf-btn i, .pf-btn svg { flex: 0 0 auto; }
        .pf-btn:hover { transform: translateY(-1px); filter: brightness(1.05); }
        .pf-btn--block { width: 100%; }
        .pf-btn--ghost {
            background: #fff; color: var(--ink-2);
            border: 1px solid var(--line); box-shadow: none;
        }
        .pf-btn--ghost:hover { background: #f9fafb; border-color: #d1d5db; }
        .pf-btn--danger {
            background: #fff; color: #b91c1c; border: 1px solid #fecaca;
            box-shadow: none;
        }
        .pf-btn--danger:hover { background: #fef2f2; }
        .pf-btn--primary {
            background: linear-gradient(135deg, var(--green-primary), var(--green-dark));
            color: #fff;
        }
        .pf-btn--light {
            background: #fff;
            color: var(--ink-2);
            border: 1px solid var(--line);
            box-shadow: none;
        }
        .pf-btn--light:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        .pf-btn--sm {
            min-height: 34px;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: .84rem;
        }

        .pf-link-bare { color: var(--green-dark); font-weight: 700; text-decoration: none; }
        .pf-link-bare:hover { text-decoration: underline; }

        .pf-alert {
            padding: 12px 14px; border-radius: 10px; font-size: .88rem;
            font-weight: 600; margin-bottom: 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .pf-alert--success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .pf-alert--error   { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

        .pf-stat-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
            margin-bottom: 14px;
        }
        .pf-stat {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 12px;
            text-align: center;
        }
        .pf-stat__value { font-size: 1.45rem; font-weight: 900; color: var(--ink); line-height: 1; }
        .pf-stat__label { font-size: .72rem; font-weight: 600; color: var(--muted); margin-top: 4px; }

        .pf-res {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
        }
        .pf-res + .pf-res { margin-top: 8px; }
        .pf-res__date {
            flex-shrink: 0;
            min-width: 56px;
            text-align: center;
            background: rgba(var(--primary-rgb), .08);
            border-radius: 10px;
            padding: 8px 10px;
        }
        .pf-res__day {
            font-size: 1.4rem; font-weight: 900; color: var(--green-dark); line-height: 1;
        }
        .pf-res__month {
            font-size: .68rem; font-weight: 700; color: var(--green-primary);
            text-transform: uppercase; margin-top: 2px;
        }
        .pf-res__body { flex: 1; min-width: 0; }
        .pf-res__head {
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        }
        .pf-res__branch { font-weight: 700; font-size: .92rem; color: var(--ink); }
        .pf-res__meta {
            font-size: .78rem; color: var(--muted);
            display: flex; gap: 10px; flex-wrap: wrap;
            margin-top: 4px;
        }
        .pf-res__meta i { font-size: .9em; opacity: .7; }
        .pf-res__ref { font-family: ui-monospace, monospace; font-size: .72rem; color: var(--muted-2); }

        .pf-pill {
            font-size: .68rem; font-weight: 800;
            padding: 2px 8px; border-radius: 99px;
            line-height: 1.4;
        }
        .pf-pill--warning { background: #fef3c7; color: #92400e; }
        .pf-pill--success { background: #d1fae5; color: #065f46; }
        .pf-pill--info    { background: #dbeafe; color: #1e40af; }
        .pf-pill--danger  { background: #fee2e2; color: #991b1b; }
        .pf-pill--secondary { background: #f3f4f6; color: #4b5563; }
        .pf-pill--dark    { background: #1f2937; color: #fff; }

        .pf-section-head {
            display: flex; align-items: baseline; justify-content: space-between;
            margin: 22px 4px 10px;
        }
        .pf-section-head h2 { font-size: 1rem; font-weight: 800; margin: 0; color: var(--ink); }

        .pf-empty {
            text-align: center;
            padding: 28px 18px;
            color: var(--muted);
            font-size: .88rem;
        }
        .pf-empty i { font-size: 2rem; color: var(--muted-2); display: block; margin-bottom: 6px; }

        /* ─── Page hero header (title + CTA) ────────────────────────── */
        .pf-hero {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
        }
        .pf-hero__lead {
            display: flex; flex-direction: column;
            min-width: 0;
        }
        .pf-hero__title {
            font-size: 1.4rem; font-weight: 900;
            margin: 0 0 4px;
            color: var(--ink);
            letter-spacing: -.01em;
        }
        .pf-hero__subtitle {
            font-size: .85rem; color: var(--muted);
            margin: 0;
        }
        .pf-hero__cta { flex-shrink: 0; }
        .pf-card .d-flex:has(.pf-btn),
        .pf-section .d-flex:has(.pf-btn),
        .pf-hero__cta,
        .pf-section-head:has(.pf-btn) {
            flex-wrap: wrap;
            gap: 10px;
            min-width: 0;
        }

        /* Improved section header — clearer gap from sibling content */
        .pf-section-head {
            display: flex; align-items: baseline; justify-content: space-between;
            margin: 28px 4px 12px;
        }
        .pf-section-head h2 {
            font-size: .92rem;
            font-weight: 800;
            margin: 0;
            color: var(--ink-2);
            letter-spacing: .02em;
        }
        .pf-section-head h2::before {
            content: '';
            display: inline-block;
            width: 4px; height: 14px;
            background: var(--green-primary);
            border-radius: 2px;
            margin-inline-end: 8px;
            vertical-align: -2px;
        }

        /* ─── Bootstrap-compatible utility classes ─────────────────────
           Portal doesn't load full Bootstrap (only its icons font) but
           the markup borrows familiar utility names for layout. These
           are the ones we actually use; keep this list lean. */
        .d-flex { display: flex; }
        .d-inline { display: inline; }
        .d-grid { display: grid; }
        .align-items-center  { align-items: center; }
        .align-items-start   { align-items: flex-start; }
        .justify-content-between { justify-content: space-between; }
        .justify-content-end     { justify-content: flex-end; }
        .flex-wrap { flex-wrap: wrap; }
        .gap-1 { gap: 4px; }
        .gap-2 { gap: 8px; }
        .gap-3 { gap: 12px; }
        .gap-4 { gap: 16px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 10px; }
        .mt-3 { margin-top: 16px; }
        .mt-4 { margin-top: 22px; }
        .mb-1 { margin-bottom: 4px; }
        .mb-2 { margin-bottom: 10px; }
        .mb-3 { margin-bottom: 16px; }
        .mb-4 { margin-bottom: 22px; }
        .fw-bold { font-weight: 700; }
        .text-center { text-align: center; }
        .text-muted { color: var(--muted); }

        @media (max-width: 480px) {
            .pf-stat-grid { grid-template-columns: 1fr 1fr; }
            .pf-stat:nth-child(3) { grid-column: span 2; }
            .pf-hero { flex-direction: column; align-items: stretch; }
            .pf-hero__cta { width: 100%; }
            .pf-hero__cta .pf-btn { width: 100%; }
            .pf-card .d-flex:has(.pf-btn) .pf-btn,
            .pf-card .d-flex:has(.pf-btn) form,
            .pf-section .d-flex:has(.pf-btn) .pf-btn,
            .pf-section .d-flex:has(.pf-btn) form {
                width: 100%;
                flex: 1 1 100%;
            }
        }

        /* ─── Hamburger menu (mobile only) ───────────────────────────
           Desktop keeps the inline horizontal nav. Below 760px the nav
           collapses into a hamburger button on the start side; tapping
           it slides a full-height drawer in from the start edge with
           every link, role, and a logout footer. The drawer locks body
           scroll while open and traps clicks outside to close itself. */
        .pf-burger {
            display: none;
            background: transparent; border: 0;
            width: 42px; height: 42px;
            border-radius: 10px;
            font-size: 1.4rem; color: var(--ink);
            cursor: pointer; padding: 0;
            transition: background .15s;
        }
        .pf-burger:hover { background: #f3f4f6; }

        .pf-drawer-overlay {
            position: fixed; inset: 0;
            background: rgba(15,45,34,.55);
            backdrop-filter: blur(4px);
            opacity: 0; pointer-events: none;
            transition: opacity .25s;
            z-index: 100;
        }
        .pf-drawer-overlay.is-open { opacity: 1; pointer-events: auto; }

        .pf-drawer {
            position: fixed; top: 0; bottom: 0; inset-inline-start: 0;
            width: 84%; max-width: 320px;
            background: #fff;
            box-shadow: 0 0 30px rgba(15,45,34,.18);
            transform: translateX(100%); /* RTL: starts off-screen on the right */
            transition: transform .28s cubic-bezier(.4, 0, .2, 1);
            z-index: 101;
            display: flex; flex-direction: column;
            overflow-y: auto;
        }
        [dir="rtl"] .pf-drawer { transform: translateX(100%); }
        .pf-drawer.is-open { transform: translateX(0) !important; }

        .pf-drawer__head {
            background: linear-gradient(135deg, var(--dark) 0%, var(--primary) 100%);
            color: #fff;
            padding: 22px 20px 20px;
            position: relative;
            border-bottom: 3px solid #F9A825;
        }
        .pf-drawer__brand {
            display: flex; align-items: center; gap: 10px;
            font-size: 1.05rem; font-weight: 800;
        }
        .pf-drawer__brand img {
            width: 38px; height: 38px;
            border-radius: 10px;
            background: rgba(255,255,255,.1);
            padding: 4px; object-fit: contain;
        }
        .pf-drawer__user {
            margin-top: 14px;
            font-size: .82rem;
            color: rgba(255,255,255,.78);
            display: flex; align-items: center; gap: 8px;
        }
        .pf-drawer__user i { color: #F9A825; }
        .pf-drawer__close {
            position: absolute;
            top: 14px; inset-inline-end: 14px;
            width: 32px; height: 32px;
            border: 0; border-radius: 8px;
            background: rgba(255,255,255,.12);
            color: #fff; font-size: 1rem;
            cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .pf-drawer__close:hover { background: rgba(255,255,255,.22); }

        .pf-drawer__nav {
            padding: 12px 14px;
            display: flex; flex-direction: column; gap: 4px;
        }
        .pf-drawer__link {
            display: flex; align-items: center; gap: 12px;
            padding: 13px 14px;
            border-radius: 12px;
            color: var(--ink-2); text-decoration: none;
            font-size: .95rem; font-weight: 600;
            transition: all .15s;
            border: 1px solid transparent;
        }
        .pf-drawer__link i {
            width: 28px; height: 28px;
            background: #f3f4f6; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--green-primary);
            font-size: .95rem; flex-shrink: 0;
            transition: all .15s;
        }
        .pf-drawer__link:hover {
            background: #f9fafb;
            color: var(--green-dark);
        }
        .pf-drawer__link.is-active {
            background: rgba(var(--primary-rgb), .08);
            color: var(--green-dark);
            border-color: rgba(var(--primary-rgb), .18);
        }
        .pf-drawer__link.is-active i {
            background: linear-gradient(135deg, var(--green-primary), var(--green-dark));
            color: #fff;
        }
        .pf-drawer__badge {
            margin-inline-start: auto;
            min-width: 22px; height: 22px;
            background: linear-gradient(135deg, #F9A825, #b97818);
            color: #fff;
            font-size: .68rem; font-weight: 800;
            line-height: 22px; text-align: center;
            border-radius: 99px;
            padding: 0 7px;
            box-shadow: 0 2px 4px rgba(185,120,24,.3);
        }

        .pf-drawer__foot {
            margin-top: auto;
            padding: 14px;
            border-top: 1px solid var(--line);
        }
        .pf-drawer__logout {
            display: flex; align-items: center; gap: 10px;
            width: 100%;
            padding: 12px 14px;
            background: #fff7f7; border: 1px solid #fecaca;
            color: #b91c1c; font-weight: 700; font-size: .92rem;
            border-radius: 12px;
            font-family: inherit;
            cursor: pointer;
            transition: background .15s;
        }
        .pf-drawer__logout:hover { background: #fee2e2; }

        body.pf-no-scroll { overflow: hidden; }

        /* Tablet & phone: swap horizontal nav for hamburger */
        @media (max-width: 760px) {
            .pf-header-inner { padding: 12px 14px; }
            .pf-brand span { font-size: .95rem; }
            .pf-link, .pf-logout { display: none !important; }
            .pf-burger { display: inline-flex; align-items: center; justify-content: center; }
            /* Reorder: burger on the start (right in RTL), brand centered,
               bell on the end (left in RTL) so it's reachable with one hand */
            .pf-header-inner > .pf-burger { order: 0; }
            .pf-header-inner > .pf-brand { order: 1; }
            .pf-header-inner > .pf-spacer { order: 2; }
            .pf-header-inner > .pf-bell  { order: 3; }
        }

        /* ─── Notification bell ─────────────────────────────────────
           Same shape as the header link buttons but with a tiny gold
           counter pill. Renders on every viewport, scaled down for
           mobile. The data-count attribute drives `display:none` so
           an unread total of 0 hides the badge entirely. */
        .pf-bell {
            position: relative;
            display: inline-flex; align-items: center; justify-content: center;
            width: 38px; height: 38px;
            border-radius: 10px;
            color: var(--ink-2); text-decoration: none;
            transition: all .15s;
            flex-shrink: 0;
        }
        .pf-bell:hover { background: rgba(var(--primary-rgb), .08); color: var(--green-dark); }
        .pf-bell.is-active { background: rgba(var(--primary-rgb), .10); color: var(--green-dark); }
        .pf-bell i { font-size: 1.05rem; }
        .pf-bell__count {
            position: absolute;
            top: 4px; inset-inline-start: 4px;
            min-width: 18px; height: 18px;
            background: linear-gradient(135deg, #F9A825, #b97818);
            color: #fff;
            font-size: .65rem; font-weight: 800;
            line-height: 18px; text-align: center;
            border-radius: 99px;
            padding: 0 5px;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(185,120,24,.35);
            animation: bellPulse 1.6s ease-in-out infinite;
        }
        @keyframes bellPulse {
            0%, 100% { transform: scale(1); }
            50%      { transform: scale(1.08); }
        }
        @media (prefers-reduced-motion: reduce) {
            .pf-bell__count { animation: none; }
        }
    </style>
    @include('partials.runtime-theme', ['theme' => $theme])
    @stack('styles')
</head>
<body>
    @auth('customer')
        @php
            $customer = auth('customer')->user();
            // Cheap COUNT scoped to the active customer — runs once per page
            // load. The bell icon polls /notifications/unread-count later if
            // the page is left open for a while.
            $unreadNotifs = $customer ? $customer->unreadNotifications()->count() : 0;
        @endphp
        <header class="pf-header">
            <div class="pf-header-inner">
                <button type="button" class="pf-burger" onclick="pfOpenDrawer()" aria-label="{{ __('portal.layout.menu') }}" aria-controls="pfDrawer">
                    <i class="bi bi-list"></i>
                </button>
                <a href="{{ route('portal.dashboard') }}" class="pf-brand">
                    <img src="{{ \App\Helpers\Brand::logoUrl() }}" alt="{{ $siteName }}">
                    <span>{{ $siteName }}</span>
                </a>
                <div class="pf-spacer"></div>
                <a href="{{ route('portal.dashboard') }}"
                   class="pf-link {{ request()->routeIs('portal.dashboard') ? 'is-active' : '' }}">{{ __('portal.layout.home') }}</a>
                <a href="{{ route('portal.order.history') }}"
                   class="pf-link {{ request()->routeIs('portal.order.*') ? 'is-active' : '' }}">
                    <i class="bi bi-bag-fill"></i> {{ __('portal.layout.my_orders') }}
                </a>
                <a href="{{ route('portal.reservations.index') }}"
                   class="pf-link {{ request()->routeIs('portal.reservations.*') ? 'is-active' : '' }}">{{ __('portal.layout.my_reservations') }}</a>
                <a href="{{ route('portal.reviews.index') }}"
                   class="pf-link {{ request()->routeIs('portal.reviews.*') ? 'is-active' : '' }}">{{ __('portal.layout.my_reviews') }}</a>

                {{-- Notification bell — visible on every viewport (next to
                     the burger on mobile, in the link row on desktop). The
                     dot/counter only renders when there are unread items. --}}
                <a href="{{ route('portal.notifications.index') }}"
                   class="pf-bell {{ request()->routeIs('portal.notifications.*') ? 'is-active' : '' }}"
                   id="pfBell"
                   title="{{ __('portal.layout.notifications_offers') }}"
                   aria-label="{{ __('portal.layout.notifications') }}">
                    <i class="bi bi-bell-fill"></i>
                    <span class="pf-bell__count" id="pfBellCount" data-count="{{ $unreadNotifs }}"
                          @if($unreadNotifs === 0) style="display:none;" @endif>
                        {{ $unreadNotifs > 99 ? '99+' : $unreadNotifs }}
                    </span>
                </a>

                <form method="POST" action="{{ route('portal.logout') }}" class="d-inline" style="margin:0;">
                    @csrf
                    <button class="pf-logout"><i class="bi bi-box-arrow-right"></i> {{ __('portal.layout.logout') }}</button>
                </form>
            </div>
        </header>

        {{-- Mobile slide-out drawer (hidden on desktop). Toggled by the
             hamburger button above; closes on overlay click, ESC, or
             a tap on any inner link. --}}
        <div class="pf-drawer-overlay" id="pfOverlay" onclick="pfCloseDrawer()"></div>
        <aside class="pf-drawer" id="pfDrawer" role="dialog" aria-modal="true" aria-label="{{ __('portal.layout.navigation') }}">
            <div class="pf-drawer__head">
                <button type="button" class="pf-drawer__close" onclick="pfCloseDrawer()" aria-label="{{ __('portal.layout.close') }}">
                    <i class="bi bi-x-lg"></i>
                </button>
                <div class="pf-drawer__brand">
                    <img src="{{ \App\Helpers\Brand::logoUrl() }}" alt="">
                    <span>{{ $siteName }}</span>
                </div>
                @if($customer)
                    <div class="pf-drawer__user">
                        <i class="bi bi-person-circle"></i>
                        <span>{{ $customer->name ?? $customer->phone ?? __('portal.layout.valued_customer') }}</span>
                    </div>
                @endif
            </div>

            <nav class="pf-drawer__nav">
                <a href="{{ route('portal.dashboard') }}"
                   class="pf-drawer__link {{ request()->routeIs('portal.dashboard') ? 'is-active' : '' }}">
                    <i class="bi bi-house-fill"></i>
                    <span>{{ __('portal.layout.home') }}</span>
                </a>
                <a href="{{ route('portal.order.history') }}"
                   class="pf-drawer__link {{ request()->routeIs('portal.order.*') ? 'is-active' : '' }}">
                    <i class="bi bi-bag-fill"></i>
                    <span>{{ __('portal.layout.my_orders') }}</span>
                </a>
                <a href="{{ route('portal.reservations.index') }}"
                   class="pf-drawer__link {{ request()->routeIs('portal.reservations.*') ? 'is-active' : '' }}">
                    <i class="bi bi-calendar-heart-fill"></i>
                    <span>{{ __('portal.layout.my_reservations') }}</span>
                </a>
                <a href="{{ route('portal.reviews.index') }}"
                   class="pf-drawer__link {{ request()->routeIs('portal.reviews.*') ? 'is-active' : '' }}">
                    <i class="bi bi-stars"></i>
                    <span>{{ __('portal.layout.my_reviews') }}</span>
                </a>
                <a href="{{ route('portal.notifications.index') }}"
                   class="pf-drawer__link {{ request()->routeIs('portal.notifications.*') ? 'is-active' : '' }}">
                    <i class="bi bi-bell-fill"></i>
                    <span>{{ __('portal.layout.notifications_offers') }}</span>
                    @if($unreadNotifs > 0)
                        <span class="pf-drawer__badge">{{ $unreadNotifs > 99 ? '99+' : $unreadNotifs }}</span>
                    @endif
                </a>
            </nav>

            <div class="pf-drawer__foot">
                <form method="POST" action="{{ route('portal.logout') }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="pf-drawer__logout">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>{{ __('portal.layout.logout') }}</span>
                    </button>
                </form>
            </div>
        </aside>

        <script>
            (function () {
                window.pfOpenDrawer = function () {
                    document.getElementById('pfDrawer')?.classList.add('is-open');
                    document.getElementById('pfOverlay')?.classList.add('is-open');
                    document.body.classList.add('pf-no-scroll');
                };
                window.pfCloseDrawer = function () {
                    document.getElementById('pfDrawer')?.classList.remove('is-open');
                    document.getElementById('pfOverlay')?.classList.remove('is-open');
                    document.body.classList.remove('pf-no-scroll');
                };
                // ESC key closes the drawer
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') window.pfCloseDrawer();
                });
                // Auto-close when navigating via a drawer link
                document.querySelectorAll('.pf-drawer__link').forEach(a => {
                    a.addEventListener('click', () => window.pfCloseDrawer());
                });
            })();
        </script>
    @endauth

    <main class="pf-main">
        @if(session('success'))
            <div class="pf-alert pf-alert--success"><i class="bi bi-check-circle-fill"></i>{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="pf-alert pf-alert--error"><i class="bi bi-exclamation-triangle-fill"></i>{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>

    <script src="{{ asset('assets/dashtic/js/relax-submit-lock.js') }}?v={{ filemtime(public_path('assets/dashtic/js/relax-submit-lock.js')) }}"></script>
    @stack('scripts')
</body>
</html>
