<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1B5E20">
    <title>@yield('title', 'بوابة الزبون') · {{ config('restaurant.name') }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --green-primary: #2E7D32;
            --green-dark:    #1B5E20;
            --green-soft:    #43A047;
            --gold:          #F9A825;
            --ink:           #1F2937;
            --ink-2:         #374151;
            --muted:         #6B7280;
            --muted-2:       #9CA3AF;
            --line:          #E5E7EB;
            --cream:         #FAF7F2;
            --paper:         #FFFFFF;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Tajawal', system-ui, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 90% -10%, rgba(67,160,71,.10), transparent 55%),
                radial-gradient(circle at 0% 100%, rgba(200,230,201,.30), transparent 55%),
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
        .pf-link.is-active { color: var(--green-dark); background: rgba(46,125,50,.08); }
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
        .pf-card + .pf-card { margin-top: 14px; }

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
            border-color: rgba(46,125,50,.7);
            box-shadow: 0 0 0 4px rgba(46,125,50,.12);
        }
        .pf-input.has-error { border-color: #dc2626; box-shadow: 0 0 0 4px rgba(220,38,38,.12); }
        .pf-error { color: #b91c1c; font-size: .78rem; font-weight: 600; margin-top: 4px; }

        .pf-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--green-primary), var(--green-dark));
            color: #fff; border: 0;
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: 800; font-size: .95rem;
            font-family: inherit;
            cursor: pointer;
            box-shadow:
                0 4px 14px -4px rgba(46,125,50,.45),
                inset 0 1px 0 rgba(255,255,255,.18),
                inset 0 -2px 0 rgba(249,168,37,.30);
            transition: transform .15s, filter .15s;
            text-decoration: none;
        }
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
            background: rgba(46,125,50,.08);
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
        }
    </style>
    @include('partials.runtime-theme')
    @stack('styles')
</head>
<body>
    @auth('customer')
        <header class="pf-header">
            <div class="pf-header-inner">
                <a href="{{ route('portal.dashboard') }}" class="pf-brand">
                    <img src="{{ \App\Helpers\Brand::logoUrl() }}" alt="{{ config('restaurant.name') }}">
                    <span>{{ config('restaurant.name') }}</span>
                </a>
                <div class="pf-spacer"></div>
                <a href="{{ route('portal.dashboard') }}"
                   class="pf-link {{ request()->routeIs('portal.dashboard') ? 'is-active' : '' }}">الرئيسية</a>
                <a href="{{ route('portal.order.branches') }}"
                   class="pf-link pf-link--cta {{ request()->routeIs('portal.order.*') ? 'is-active' : '' }}">
                    <i class="bi bi-bag-plus-fill"></i> اطلب الآن
                </a>
                <a href="{{ route('portal.reservations.index') }}"
                   class="pf-link {{ request()->routeIs('portal.reservations.*') ? 'is-active' : '' }}">حجوزاتي</a>
                <a href="{{ route('portal.reviews.index') }}"
                   class="pf-link {{ request()->routeIs('portal.reviews.*') ? 'is-active' : '' }}">تقييماتي</a>
                <form method="POST" action="{{ route('portal.logout') }}" class="d-inline" style="margin:0;">
                    @csrf
                    <button class="pf-logout"><i class="bi bi-box-arrow-right"></i> خروج</button>
                </form>
            </div>
        </header>
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

    @stack('scripts')
</body>
</html>
