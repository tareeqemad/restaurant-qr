@php
    $restaurantName = \App\Helpers\Brand::name();
    $logo = asset('default_logo.png');
    $branchName = $table->branch?->name ?: 'الفرع الرئيسي';
    $zoneLabel = $table->zone?->label;
    $market = \App\Support\MarketProfile::class;
@endphp
<!DOCTYPE html>
<html lang="{{ $market::lang() }}" dir="{{ $market::direction() }}" data-market="{{ $market::current() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR طاولة {{ $table->number }}</title>

    {{-- Same Tajawal font + colour palette as the diner portal so the QR
         poster reads as part of the same brand system, not a one-off. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="{{ $market::fontUrl() }}" rel="stylesheet">

    <style>
        :root {
            --green-primary: #0f4731;
            --green-dark:    #1c5e44;
            --green-soft:    #ecf5ee;
            --gold:          #b8872a;
            --gold-soft:     rgba(184, 135, 42, .12);
            --ink:           #14271f;
            --ink-2:         #4b5d54;
            --muted:         #6b7d72;
            --bd:            rgba(15, 71, 49, .08);
            --bd-strong:     rgba(15, 71, 49, .15);
            --paper:         #f7faf5;
        }
        @include('partials.market-vars')

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: clamp(1rem, 3vw, 2rem);
            color: var(--ink);
            font-family: var(--market-font-family);
            -webkit-font-smoothing: antialiased;
            background:
                radial-gradient(ellipse 60% 80% at 90% 0%, rgba(184, 135, 42, .08) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 10% 100%, rgba(15, 71, 49, .05) 0%, transparent 60%),
                linear-gradient(180deg, #f7faf5 0%, #f0f5ee 100%);
        }

        .page { width: min(100%, 580px); }

        /* ── Card ───────────────────────────────────── */
        .qr-card {
            position: relative;
            overflow: hidden;
            background: #fff;
            border: 1px solid var(--bd);
            border-radius: 22px;
            box-shadow: 0 8px 32px rgba(15, 71, 49, .08);
        }

        /* ── Header (green gradient hero) ──────────────── */
        .qr-head {
            position: relative;
            padding: 1.5rem 1.6rem 1.4rem;
            background: linear-gradient(135deg, var(--green-primary) 0%, var(--green-dark) 100%);
            color: #fff;
            overflow: hidden;
        }
        .qr-head::before {
            content: '';
            position: absolute;
            inset-inline-end: -40px; top: -40px;
            width: 160px; height: 160px;
            background: rgba(255, 255, 255, .07);
            border-radius: 50%;
            pointer-events: none;
        }
        .qr-head::after {
            content: '';
            position: absolute;
            inset-inline-start: -30px; bottom: -30px;
            width: 100px; height: 100px;
            background: rgba(184, 135, 42, .12);
            border-radius: 50%;
            pointer-events: none;
        }
        .qr-head__row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem;
            position: relative; z-index: 1;
        }
        .qr-brand {
            display: flex; align-items: center; gap: .85rem;
            min-width: 0;
        }
        .qr-brand__logo {
            width: 56px; height: 56px;
            background: rgba(255, 255, 255, .15);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 14px;
            display: grid; place-items: center;
            flex-shrink: 0;
        }
        .qr-brand__logo img {
            width: 40px; height: 40px;
            object-fit: contain;
        }
        .qr-brand__text { min-width: 0; }
        .qr-brand__eyebrow {
            display: block;
            font-size: .72rem;
            font-weight: 600;
            opacity: .85;
            margin-bottom: .1rem;
        }
        .qr-brand__name {
            display: block;
            font-size: clamp(1.3rem, 4vw, 1.65rem);
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -.01em;
        }
        .qr-table-badge {
            background: rgba(184, 135, 42, .25);
            border: 1px solid rgba(184, 135, 42, .4);
            backdrop-filter: blur(6px);
            border-radius: 14px;
            padding: .6rem 1rem;
            text-align: center;
            min-width: 90px;
            flex-shrink: 0;
        }
        .qr-table-badge span {
            display: block;
            font-size: .68rem;
            font-weight: 700;
            opacity: .9;
            margin-bottom: 2px;
        }
        .qr-table-badge strong {
            display: block;
            font-size: 2rem;
            font-weight: 900;
            line-height: 1;
            color: #fff;
        }

        .qr-branch-pill {
            display: inline-flex; align-items: center; gap: .35rem;
            margin-top: .85rem;
            padding: .3rem .7rem;
            background: rgba(255, 255, 255, .15);
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 99px;
            font-size: .75rem;
            font-weight: 700;
            position: relative; z-index: 1;
        }
        .qr-branch-pill::before {
            content: '';
            width: 6px; height: 6px;
            background: var(--gold);
            border-radius: 50%;
        }

        /* ── Body ──────────────────────────────────── */
        .qr-body { padding: 1.6rem 1.6rem 1.4rem; }

        .qr-intro { text-align: center; margin-bottom: 1.2rem; }
        .qr-intro__title {
            display: inline-flex; align-items: center; gap: .85rem;
            margin: 0 0 .5rem;
            font-size: clamp(1.4rem, 5vw, 1.75rem);
            font-weight: 900;
            color: var(--ink);
            letter-spacing: 0;
        }
        .qr-intro__title::before,
        .qr-intro__title::after {
            content: '';
            width: 32px; height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold));
            border-radius: 99px;
        }
        .qr-intro__title::after {
            background: linear-gradient(90deg, var(--gold), transparent);
        }
        .qr-intro__sub {
            margin: 0 auto;
            max-width: 360px;
            color: var(--ink-2);
            font-size: .92rem;
            line-height: 1.6;
        }

        /* ── QR box ────────────────────────────────── */
        .qr-box {
            position: relative;
            display: grid; place-items: center;
            width: fit-content;
            max-width: 100%;
            margin: 0 auto;
            padding: 1.4rem;
            background: #fff;
            border: 1px solid var(--bd);
            border-radius: 18px;
            box-shadow:
                inset 0 0 0 1px rgba(184, 135, 42, .15),
                0 4px 16px rgba(15, 71, 49, .04);
        }
        /* Gold corner brackets — subtle premium tell */
        .qr-box::before,
        .qr-box::after {
            content: '';
            position: absolute;
            width: 16px; height: 16px;
            border: 2px solid var(--gold);
        }
        .qr-box::before {
            top: 8px; inset-inline-start: 8px;
            border-inline-end: 0; border-block-end: 0;
            border-start-start-radius: 8px;
        }
        .qr-box::after {
            bottom: 8px; inset-inline-end: 8px;
            border-inline-start: 0; border-block-start: 0;
            border-end-end-radius: 8px;
        }
        .qr-box svg {
            display: block;
            width: min(70vw, 320px);
            height: auto;
        }

        /* ── Info grid ─────────────────────────────── */
        .qr-info {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .55rem;
            margin: 1.3rem 0 0;
        }
        .qr-info__item {
            padding: .75rem .85rem;
            background: var(--green-soft);
            border: 1px solid var(--bd);
            border-radius: 12px;
            text-align: center;
        }
        .qr-info__item span {
            display: block;
            color: var(--muted);
            font-size: .7rem;
            font-weight: 700;
            margin-bottom: .15rem;
            letter-spacing: 0;
        }
        .qr-info__item strong {
            display: block;
            color: var(--ink);
            font-size: .92rem;
            font-weight: 800;
        }

        /* ── Scan URL (screen only) ────────────────── */
        .qr-url {
            margin-top: 1rem;
            padding: .75rem 1rem;
            direction: ltr;
            background: rgba(15, 71, 49, .04);
            border: 1px dashed var(--bd-strong);
            border-radius: 12px;
            color: var(--ink-2);
            font-size: .78rem;
            font-family: ui-monospace, SFMono-Regular, monospace;
            text-align: center;
            word-break: break-all;
        }

        /* ── Actions ───────────────────────────────── */
        .qr-actions {
            display: flex; gap: .65rem;
            justify-content: center;
            margin-top: 1.25rem;
        }
        .qr-btn {
            display: inline-flex; align-items: center; gap: .45rem;
            min-height: 44px;
            padding: 0 1.4rem;
            border: 0;
            border-radius: 12px;
            font: inherit;
            font-weight: 800;
            font-size: .95rem;
            color: #fff;
            cursor: pointer;
            text-decoration: none;
            background: linear-gradient(135deg, var(--green-primary), var(--green-dark));
            box-shadow: 0 6px 18px -3px rgba(15, 71, 49, .35);
            transition: transform .15s, box-shadow .15s;
        }
        .qr-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px -3px rgba(15, 71, 49, .45);
            color: #fff;
        }
        .qr-btn--secondary {
            background: #fff;
            color: var(--green-primary);
            border: 1px solid var(--bd-strong);
            box-shadow: none;
        }
        .qr-btn--secondary:hover {
            background: var(--green-soft);
            color: var(--green-primary);
            box-shadow: none;
        }

        /* ── Mobile ────────────────────────────────── */
        @media (max-width: 520px) {
            .qr-head { padding: 1.2rem 1.2rem 1.1rem; }
            .qr-head__row { flex-direction: column; align-items: stretch; }
            .qr-table-badge { align-self: stretch; }
            .qr-body { padding: 1.2rem; }
            .qr-info { grid-template-columns: 1fr 1fr; }
            .qr-actions { flex-direction: column; }
            .qr-btn { width: 100%; }
        }

        /* ── Print (A4 portrait, single page guarantee) ───── */
        @page {
            size: A4 portrait;
            margin: 0;
        }
        @media print {
            html, body {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 0;
                background: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            body { display: block; min-height: auto; padding: 0; }
            main.page {
                width: 210mm;
                height: 297mm;
                margin: 0 auto;
                padding: 8mm;
                page-break-after: avoid;
                page-break-inside: avoid;
            }
            .qr-card {
                width: 100%;
                height: 100%;
                border: 1.5pt solid var(--green-primary);
                border-radius: 14pt;
                box-shadow: none;
                break-inside: avoid;
                page-break-inside: avoid;
                page-break-after: avoid;
                display: flex; flex-direction: column;
                overflow: hidden;
            }

            .qr-head {
                padding: 7mm 10mm;
                background: linear-gradient(135deg, var(--green-primary), var(--green-dark)) !important;
            }
            .qr-head::before, .qr-head::after { display: none; }
            .qr-brand__logo { width: 18mm; height: 18mm; }
            .qr-brand__logo img { width: 14mm; height: 14mm; }
            .qr-brand__name { font-size: 22pt; }
            .qr-brand__eyebrow { font-size: 9pt; }
            .qr-table-badge { padding: 3mm 5mm; min-width: 26mm; }
            .qr-table-badge span { font-size: 9pt; }
            .qr-table-badge strong { font-size: 30pt; }
            .qr-branch-pill { font-size: 9pt; padding: 1.5pt 7pt; margin-top: 3mm; }

            .qr-body { padding: 5mm 10mm 5mm; flex-grow: 1; display: flex; flex-direction: column; }
            .qr-intro { margin-bottom: 4mm; }
            .qr-intro__title { font-size: 22pt; gap: 8mm; }
            .qr-intro__title::before, .qr-intro__title::after { width: 20mm; height: .6mm; }
            .qr-intro__sub { font-size: 11pt; max-width: 110mm; line-height: 1.5; }

            .qr-box {
                margin: 2mm auto;
                padding: 6mm;
                box-shadow: inset 0 0 0 .8pt rgba(184, 135, 42, .35) !important;
            }
            .qr-box::before, .qr-box::after {
                width: 5mm; height: 5mm;
                border-width: 1pt;
            }
            .qr-box svg { width: 90mm; max-width: 90mm; }

            .qr-info { grid-template-columns: repeat(3, 1fr); gap: 2.5mm; margin-top: 4mm; }
            .qr-info__item { padding: 2.5mm 3mm; border-radius: 6pt; }
            .qr-info__item span { font-size: 8.5pt; }
            .qr-info__item strong { font-size: 11pt; }

            /* URL is in the QR — keep poster clean. */
            .qr-url { display: none !important; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="qr-card" aria-label="بطاقة QR للطاولة {{ $table->number }}">

            <header class="qr-head">
                <div class="qr-head__row">
                    <div class="qr-brand">
                        <div class="qr-brand__logo">
                            <img src="{{ $logo }}" alt="{{ $restaurantName }}">
                        </div>
                        <div class="qr-brand__text">
                            <span class="qr-brand__eyebrow">قائمة وطلبات مباشرة</span>
                            <strong class="qr-brand__name">{{ $restaurantName }}</strong>
                        </div>
                    </div>
                    <div class="qr-table-badge">
                        <span>طاولة</span>
                        <strong>{{ $table->number }}</strong>
                    </div>
                </div>
                <span class="qr-branch-pill">{{ $branchName }}</span>
            </header>

            <div class="qr-body">
                <div class="qr-intro">
                    <h1 class="qr-intro__title">امسح واطلب</h1>
                    <p class="qr-intro__sub">افتح القائمة من جوالك، اختر طلبك، وسيصل مباشرة لفريق المطعم.</p>
                </div>

                <div class="qr-box">
                    {!! $svg !!}
                </div>

                <div class="qr-info">
                    <div class="qr-info__item">
                        <span>الفرع</span>
                        <strong>{{ $branchName }}</strong>
                    </div>
                    <div class="qr-info__item">
                        <span>اسم الطاولة</span>
                        <strong>{{ $table->name ?: 'طاولة ' . $table->number }}</strong>
                    </div>
                    <div class="qr-info__item">
                        <span>المنطقة</span>
                        <strong>{{ $zoneLabel ?: 'الصالة' }}</strong>
                    </div>
                </div>

                <div class="qr-url">{{ $table->qrUrl() }}</div>
            </div>
        </section>

        <div class="qr-actions no-print">
            <button type="button" class="qr-btn" onclick="window.print()">
                <i></i> طباعة البطاقة
            </button>
            <a class="qr-btn qr-btn--secondary" href="{{ route('admin.tables.index') }}">
                رجوع للطاولات
            </a>
        </div>
    </main>
</body>
</html>
