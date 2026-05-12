<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>المراقبة المباشرة — {{ config('app.name') }}</title>

    @include('partials.runtime-theme')
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/icons.css') }}">

    {{-- Same font stack as the diner portal (Tajawal) so the live-monitor
         feels native to the brand — Tajawal renders Arabic with rounder,
         friendlier strokes than the admin shell's default. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

    {{-- We deliberately don't load relax-live-monitor.css here — the new
         design ships its full stylesheet inline so we can iterate on the
         layout without fighting the legacy rules. --}}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        /* ──────────────────────────────────────────────────────────
           Light theme — mirrors the diner-portal look (`/portal/order/history`).
           Cream/sage page background, white cards with soft borders, and
           a deep-green hero gradient for the marquee strips. Gold accents
           highlight the primary KPI. Owner-facing TV view but readable in
           a sunlit office, not just a dim back room.
           ────────────────────────────────────────────────────────── */
        :root {
            --lm-green-primary: #0f4731;
            --lm-green-dark:    #1c5e44;
            --lm-green-soft:    #ecf5ee;
            --lm-accent-gold:   #b8872a;
            --lm-accent-gold-soft: rgba(184, 135, 42, .12);
            --lm-text:        #14271f;
            --lm-text-muted:  #6b7d72;
            --lm-bd:          rgba(15, 71, 49, .08);
            --lm-bd-strong:   rgba(15, 71, 49, .15);
            --lm-success: #10b981;
            --lm-warning: #f59e0b;
            --lm-danger:  #ef4444;
            --lm-info:    #3b82f6;
        }

        * { box-sizing: border-box; }

        html { background: #f5f8f3; }
        body.lm-body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(ellipse 60% 80% at 90% 0%, rgba(184, 135, 42, .06) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 10% 100%, rgba(15, 71, 49, .04) 0%, transparent 60%),
                linear-gradient(180deg, #f7faf5 0%, #f0f5ee 100%);
            color: var(--lm-text);
            font-family: 'Tajawal', system-ui, "Segoe UI", Tahoma, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .lm-wrap { min-height: 100vh; display: flex; flex-direction: column; }

        /* ── Header ─────────────────────────────────── */
        .lm-header {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            padding: 1rem clamp(1rem, 2vw, 2rem);
            background: rgba(255, 255, 255, .92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--lm-bd);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .lm-header__title {
            display: inline-flex;
            align-items: center;
            gap: .65rem;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--lm-text);
        }
        .lm-header__title .lm-logo {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--lm-green-primary), var(--lm-green-dark));
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            box-shadow: 0 4px 14px rgba(15, 71, 49, .25);
        }
        .lm-header__title small {
            display: block;
            font-size: .72rem;
            font-weight: 600;
            color: var(--lm-text-muted);
            letter-spacing: 0;
        }
        .lm-header__meta {
            margin-inline-start: auto;
            display: flex;
            align-items: center;
            gap: .85rem;
            font-size: .82rem;
            color: var(--lm-text-muted);
        }
        .lm-header__meta strong {
            color: var(--lm-text);
            font-variant-numeric: tabular-nums;
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: 0;
        }
        .lm-pulse {
            display: inline-flex; align-items: center; gap: .35rem;
            background: rgba(16, 185, 129, .12);
            color: #047857;
            padding: .3rem .65rem;
            border-radius: 99px;
            font-size: .72rem;
            font-weight: 700;
            border: 1px solid rgba(16, 185, 129, .25);
        }
        .lm-pulse::before {
            content: '';
            width: 6px; height: 6px;
            background: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, .6);
            animation: lm-pulse-dot 1.6s ease-in-out infinite;
        }
        @keyframes lm-pulse-dot {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, .5); }
            50%      { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        }
        .lm-exit {
            width: 38px; height: 38px;
            background: #fff;
            border: 1px solid var(--lm-bd);
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--lm-text-muted); text-decoration: none;
            transition: all .15s;
        }
        .lm-exit:hover {
            background: rgba(239, 68, 68, .08);
            border-color: rgba(239, 68, 68, .3);
            color: #b91c1c;
        }

        /* ── Hero KPIs ─────────────────────────────── */
        .lm-overview {
            width: min(100% - 2rem, 1800px);
            margin: 1.25rem auto 1rem;
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        @media (max-width: 1024px) { .lm-overview { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 600px)  { .lm-overview { grid-template-columns: 1fr; } }

        .lm-overview-card {
            position: relative;
            background: #fff;
            border: 1px solid var(--lm-bd);
            border-radius: 18px;
            padding: 1.25rem 1.4rem;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(15, 71, 49, .04);
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .lm-overview-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 71, 49, .08);
        }
        .lm-overview-card::before {
            content: '';
            position: absolute;
            inset-inline-end: -12px; top: -12px;
            width: 90px; height: 90px;
            border-radius: 50%;
            opacity: .12;
            background: var(--accent-color, var(--lm-green-primary));
        }
        .lm-overview-card__icon {
            width: 38px; height: 38px;
            border-radius: 10px;
            background: var(--accent-tint, rgba(15, 71, 49, .08));
            color: var(--accent-color, var(--lm-green-primary));
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1rem;
            margin-bottom: .65rem;
            position: relative;
        }
        .lm-overview-card__label {
            display: block;
            font-size: .82rem;
            color: var(--lm-text-muted);
            font-weight: 600;
            margin-bottom: .25rem;
        }
        .lm-overview-card strong {
            display: block;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.1;
            color: var(--accent-color, var(--lm-green-primary));
            font-variant-numeric: tabular-nums;
            letter-spacing: -.01em;
        }
        .lm-overview-card strong .lm-unit {
            font-size: .82rem;
            font-weight: 600;
            color: var(--lm-text-muted);
            margin-inline-start: .35rem;
            letter-spacing: 0;
        }
        .lm-overview-card strong em {
            font-style: normal;
            font-size: 1rem;
            color: var(--lm-text-muted);
            font-weight: 600;
            margin-inline-start: .25rem;
        }
        .lm-overview-card small {
            display: block;
            font-size: .75rem;
            color: var(--lm-text-muted);
            margin-top: .35rem;
        }
        .lm-overview-card--primary { --accent-color: var(--lm-accent-gold); --accent-tint: var(--lm-accent-gold-soft); }
        .lm-overview-card--orders  { --accent-color: var(--lm-info);        --accent-tint: rgba(59, 130, 246, .1); }
        .lm-overview-card--tables  { --accent-color: var(--lm-success);     --accent-tint: rgba(16, 185, 129, .1); }
        .lm-overview-card--avg     { --accent-color: var(--lm-warning);     --accent-tint: rgba(245, 158, 11, .12); }

        /* ── Branches grid ──────────────────────────── */
        .lm-grid {
            width: min(100% - 2rem, 1800px);
            margin: 0 auto 2rem;
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(var(--columns, 2), minmax(320px, 1fr));
        }
        @media (max-width: 1100px) { .lm-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 760px)  { .lm-grid { grid-template-columns: 1fr; } }

        .lm-col {
            position: relative;
            background: #fff;
            border: 1px solid var(--lm-bd);
            border-radius: 20px;
            padding: 1.25rem 1.35rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(15, 71, 49, .04);
        }
        .lm-col::before {
            content: '';
            position: absolute;
            top: 0; inset-inline-start: 0;
            width: 100%; height: 4px;
            background: linear-gradient(90deg, transparent, hsl(var(--hue, 160), 55%, 40%), transparent);
            opacity: .9;
        }

        .lm-col__head {
            display: flex; align-items: center; gap: .85rem;
        }
        .lm-col__avatar {
            width: 48px; height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, hsl(var(--hue, 160), 50%, 38%), hsl(var(--hue, 160), 45%, 28%));
            color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(15, 71, 49, .15);
        }
        .lm-col__title { flex-grow: 1; min-width: 0; }
        .lm-col__title h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--lm-text);
            line-height: 1.2;
        }
        .lm-col__title span {
            font-size: .78rem;
            color: var(--lm-text-muted);
        }
        .lm-col__live {
            font-size: .68rem;
            background: rgba(16, 185, 129, .12);
            color: #047857;
            padding: 2px 8px;
            border-radius: 99px;
            font-weight: 700;
            border: 1px solid rgba(16, 185, 129, .2);
            display: inline-flex; align-items: center; gap: .25rem;
        }
        .lm-col__live::before {
            content: '';
            width: 5px; height: 5px;
            background: #10b981;
            border-radius: 50%;
        }

        /* Hero sales — green gradient marquee like the portal hero */
        .lm-hero-sales {
            position: relative;
            padding: 1.05rem 1.2rem;
            background: linear-gradient(135deg, var(--lm-green-primary) 0%, var(--lm-green-dark) 100%);
            border-radius: 14px;
            color: #fff;
            overflow: hidden;
        }
        .lm-hero-sales::before {
            content: '';
            position: absolute;
            inset-inline-end: -25px; top: -25px;
            width: 110px; height: 110px;
            background: rgba(255, 255, 255, .08);
            border-radius: 50%;
            pointer-events: none;
        }
        .lm-hero-sales__label {
            font-size: .76rem;
            color: rgba(255, 255, 255, .85);
            font-weight: 700;
            margin-bottom: .15rem;
            position: relative;
            z-index: 1;
        }
        .lm-hero-sales__amount {
            font-size: 1.85rem;
            font-weight: 800;
            color: #fff;
            font-variant-numeric: tabular-nums;
            line-height: 1.05;
            letter-spacing: -.01em;
            position: relative;
            z-index: 1;
        }
        .lm-hero-sales__amount small {
            font-size: .78rem;
            color: rgba(255, 255, 255, .82);
            font-weight: 600;
            margin-inline-start: .3rem;
        }

        /* KPI grid (3 cols) */
        .lm-kpis {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .55rem;
        }
        .lm-kpi {
            background: var(--lm-green-soft);
            border: 1px solid var(--lm-bd);
            border-radius: 12px;
            padding: .7rem .55rem;
            text-align: center;
        }
        .lm-kpi__value {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--lm-text);
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }
        .lm-kpi__value .lm-kpi__sub {
            font-size: .78rem;
            color: var(--lm-text-muted);
            font-weight: 600;
        }
        .lm-kpi__label {
            font-size: .68rem;
            color: var(--lm-text-muted);
            margin-top: .25rem;
            font-weight: 600;
            letter-spacing: 0;
        }
        .lm-kpi--orders { background: rgba(59, 130, 246, .08); border-color: rgba(59, 130, 246, .15); }
        .lm-kpi--orders .lm-kpi__value { color: #1d4ed8; }
        .lm-kpi--tables { background: rgba(16, 185, 129, .08); border-color: rgba(16, 185, 129, .15); }
        .lm-kpi--tables .lm-kpi__value { color: #047857; }
        .lm-kpi--avg    { background: rgba(184, 135, 42, .1); border-color: rgba(184, 135, 42, .2); }
        .lm-kpi--avg .lm-kpi__value { color: #8a6920; }

        /* Capacity bar */
        .lm-capacity {
            background: #fff;
            border: 1px solid var(--lm-bd);
            border-radius: 12px;
            padding: .8rem .9rem;
        }
        .lm-capacity__head {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: .5rem;
            font-size: .82rem;
        }
        .lm-capacity__head span { color: var(--lm-text-muted); font-weight: 600; }
        .lm-capacity__head strong {
            color: var(--lm-text);
            font-weight: 800;
            font-size: 1.05rem;
            font-variant-numeric: tabular-nums;
        }
        .lm-capacity__bar {
            height: 8px;
            background: rgba(15, 71, 49, .06);
            border-radius: 99px;
            overflow: hidden;
        }
        .lm-capacity__bar span {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, #10b981, #34d399);
            border-radius: 99px;
            transition: width .4s ease;
        }
        .lm-capacity[data-load="high"]   .lm-capacity__bar span { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .lm-capacity[data-load="full"]   .lm-capacity__bar span { background: linear-gradient(90deg, #ef4444, #f87171); }

        /* Tables mini-grid */
        .lm-tables-section {
            background: #fff;
            border: 1px solid var(--lm-bd);
            border-radius: 12px;
            padding: .85rem .9rem;
        }
        .lm-tables-section__head {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: .55rem;
            font-size: .82rem; color: var(--lm-text-muted);
            font-weight: 600;
        }
        .lm-tables-section__head strong { color: var(--lm-text); font-size: .9rem; }
        .lm-tables {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(38px, 1fr));
            gap: 5px;
        }
        .lm-table {
            aspect-ratio: 1;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px;
            font-size: .82rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            position: relative;
        }
        .lm-table--available     { background: rgba(16, 185, 129, .12); color: #047857; border: 1px solid rgba(16, 185, 129, .25); }
        .lm-table--occupied      { background: rgba(245, 158, 11, .15); color: #b45309; border: 1px solid rgba(245, 158, 11, .35); }
        .lm-table--reserved      { background: rgba(59, 130, 246, .1); color: #1d4ed8; border: 1px solid rgba(59, 130, 246, .25); }
        .lm-table--out_of_service { background: rgba(156, 163, 175, .1); color: #6b7280; border: 1px solid rgba(156, 163, 175, .2); text-decoration: line-through; }

        .lm-tables-legend {
            display: flex; flex-wrap: wrap; gap: .35rem .75rem;
            margin-top: .55rem;
            font-size: .68rem; color: var(--lm-text-muted);
        }
        .lm-tables-legend span { display: inline-flex; align-items: center; gap: .25rem; }
        .lm-tables-legend i {
            width: 8px; height: 8px;
            border-radius: 2px;
            display: inline-block;
        }

        /* Recent orders */
        .lm-recent {
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }
        .lm-recent__title {
            display: flex; justify-content: space-between; align-items: center;
            font-size: .85rem;
            font-weight: 700;
            color: var(--lm-text);
            margin-bottom: .15rem;
        }
        .lm-recent__title small { color: var(--lm-text-muted); font-size: .72rem; font-weight: 600; }
        .lm-recent__title i { color: var(--lm-green-primary); margin-inline-end: .25rem; }

        .lm-order {
            display: flex; align-items: center; gap: .65rem;
            padding: .65rem .8rem;
            background: #fff;
            border: 1px solid var(--lm-bd);
            border-radius: 10px;
            border-inline-start: 3px solid var(--st, #6b7280);
            transition: all .18s ease;
            animation: lm-order-in .35s ease backwards;
        }
        @keyframes lm-order-in {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .lm-order:hover {
            background: rgba(15, 71, 49, .025);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(15, 71, 49, .06);
        }
        .lm-order__main {
            display: flex; align-items: center; gap: .55rem;
            flex-grow: 1; min-width: 0;
        }
        .lm-order__ref {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: .8rem;
            font-weight: 700;
            color: var(--lm-text);
        }
        .lm-order__table {
            font-size: .7rem;
            color: var(--lm-text-muted);
            background: var(--lm-green-soft);
            padding: 2px 7px;
            border-radius: 6px;
        }
        .lm-order__meta {
            display: flex; flex-direction: column; align-items: flex-end;
            gap: 2px;
            flex-shrink: 0;
        }
        .lm-order__status {
            font-size: .65rem;
            font-weight: 800;
            background: var(--st, rgba(107, 114, 128, .2));
            color: #fff;
            padding: 2px 8px;
            border-radius: 99px;
        }
        .lm-order__total {
            font-size: .82rem;
            font-weight: 800;
            color: var(--lm-text);
            font-variant-numeric: tabular-nums;
        }
        .lm-order__total small { color: var(--lm-text-muted); font-weight: 600; font-size: .65rem; margin-inline-start: 2px; }
        .lm-order__time {
            font-size: .68rem;
            color: var(--lm-text-muted);
        }

        .lm-empty {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--lm-text-muted);
            background: #fafbfa;
            border: 1px dashed var(--lm-bd-strong);
            border-radius: 12px;
            font-size: .82rem;
        }
        .lm-empty i { font-size: 1.6rem; opacity: .35; display: block; margin-bottom: .35rem; }

        /* Responsive tweaks */
        @media (max-width: 600px) {
            .lm-header { padding: .75rem 1rem; gap: .65rem; }
            .lm-header__title { font-size: 1rem; }
            .lm-header__title .lm-logo { width: 32px; height: 32px; font-size: 1rem; }
            .lm-header__meta { gap: .55rem; font-size: .72rem; }
            .lm-header__meta strong { font-size: .9rem; }
            .lm-overview-card { padding: 1rem 1.1rem; }
            .lm-overview-card strong { font-size: 1.6rem; }
            .lm-col { padding: 1rem 1.1rem; }
            .lm-hero-sales__amount { font-size: 1.5rem; }
            .lm-kpis { grid-template-columns: 1fr 1fr 1fr; gap: .4rem; }
            .lm-kpi__value { font-size: 1.05rem; }
            .lm-kpi__label { font-size: .65rem; }
        }
    </style>
</head>
<body class="lm-body">

<livewire:admin.live-monitor />

@livewireScripts
</body>
</html>
