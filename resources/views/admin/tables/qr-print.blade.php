@php
    $restaurantName = \App\Models\Setting::get('site_name', config('restaurant.name', 'Relax'));
    $primary = \App\Models\Setting::get('theme_primary', config('restaurant.theme.primary', '#164c37'));
    $dark = \App\Models\Setting::get('theme_dark', config('restaurant.theme.dark', '#0f2d22'));
    $accent = \App\Models\Setting::get('theme_accent', config('restaurant.theme.accent', '#b97818'));
    $logo = asset('default_logo.png');
    $branchName = $table->branch?->name ?: 'الفرع الرئيسي';
    $zoneLabel = $table->zone?->label;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR طاولة {{ $table->number }}</title>
    <style>
        :root {
            --primary: {{ $primary }};
            --dark: {{ $dark }};
            --accent: {{ $accent }};
            --paper: #fbfaf6;
            --ink: #10231b;
            --muted: #66736d;
            --line: #e4ded2;
            --gold-soft: #efe4c6;
            --gold-line: #d6b876;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            color: var(--ink);
            font-family: "Tajawal", "Segoe UI", Arial, sans-serif;
            background:
                linear-gradient(135deg, rgba(22, 76, 55, .08), rgba(185, 120, 24, .10)),
                var(--paper);
        }

        .page {
            width: min(100%, 560px);
        }

        .qr-card {
            position: relative;
            overflow: hidden;
            padding: 28px 24px 24px;
            border: 1px solid rgba(22, 76, 55, .14);
            border-radius: 28px;
            background:
                radial-gradient(120% 60% at 50% -10%, rgba(214, 184, 118, .14), transparent 55%),
                #ffffff;
            box-shadow: 0 24px 70px rgba(16, 35, 27, .14);
        }

        /* Luxury double-stripe at the top: brand-green underlay + thinner
           gold band laid on top with a tiny breathing gap between them. */
        .qr-card::before {
            content: "";
            position: absolute;
            inset-inline: 22px;
            top: 0;
            height: 6px;
            border-radius: 0 0 999px 999px;
            background: linear-gradient(90deg, var(--primary), var(--dark));
        }

        .qr-card::after {
            content: "";
            position: absolute;
            inset-inline: 60px;
            top: 9px;
            height: 2px;
            border-radius: 0 0 999px 999px;
            background: linear-gradient(90deg, transparent, var(--accent) 30%, var(--accent) 70%, transparent);
        }

        .qr-card-inner {
            position: relative;
            padding: 8px 4px 0;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-block: 8px 18px;
            border-bottom: 1px solid var(--line);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }

        .brand-mark {
            width: 74px;
            height: 74px;
            flex: 0 0 74px;
            display: grid;
            place-items: center;
            border-radius: 0;
            background: transparent;
            border: 0;
            box-shadow: none;
        }

        .brand-mark img {
            width: 74px;
            height: 74px;
            object-fit: contain;
        }

        .brand-text {
            min-width: 0;
        }

        .brand-text span {
            display: block;
            color: var(--muted);
            font-size: 14px;
            font-weight: 800;
        }

        .brand-text strong {
            display: block;
            overflow: hidden;
            color: var(--dark);
            font-size: clamp(32px, 7vw, 44px);
            font-weight: 900;
            line-height: 1.05;
            text-overflow: ellipsis;
            white-space: normal;
        }

        .branch-line {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            margin-top: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            color: var(--primary);
            font-size: 12px;
            font-weight: 900;
            background: #eef5ef;
        }

        .table-badge {
            min-width: 116px;
            padding: 10px 14px;
            border-radius: 18px;
            color: #fff;
            text-align: center;
            background: var(--primary);
            box-shadow: 0 10px 24px rgba(22, 76, 55, .24);
        }

        .table-badge span {
            display: block;
            font-size: 12px;
            font-weight: 700;
            opacity: .82;
        }

        .table-badge strong {
            display: block;
            font-size: 34px;
            line-height: 1;
            font-weight: 900;
        }

        .qr-intro {
            position: relative;
            padding: 22px 4px 14px;
            text-align: center;
        }

        /* Subtle gold flourish lines flanking the headline — quiet but
           gives the page a hand-finished feel rather than a templated one. */
        .qr-intro h1 {
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 14px;
            color: var(--dark);
            font-size: clamp(28px, 7vw, 42px);
            font-weight: 900;
            letter-spacing: 0;
        }

        .qr-intro h1::before,
        .qr-intro h1::after {
            content: "";
            width: 38px;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold-line));
            border-radius: 999px;
        }

        .qr-intro h1::after {
            background: linear-gradient(90deg, var(--gold-line), transparent);
        }

        .qr-intro p {
            margin: 10px auto 0;
            max-width: 380px;
            color: var(--muted);
            font-size: 15px;
            font-weight: 600;
            line-height: 1.65;
        }

        .qr-box {
            position: relative;
            display: grid;
            place-items: center;
            width: fit-content;
            max-width: 100%;
            margin: 14px auto 18px;
            padding: 22px;
            border: 1px solid var(--gold-line);
            border-radius: 22px;
            background: #fff;
            box-shadow:
                inset 0 0 0 1px rgba(214, 184, 118, .35),
                0 8px 24px rgba(16, 35, 27, .08);
        }

        /* Four gold corner brackets — the classic high-end menu / wedding
           invite tell. Drawn as ::before/::after on the qr-box plus two
           helper layers on its inner spans, but we don't want extra DOM,
           so we use radial-gradients on a pseudo. */
        .qr-box::before,
        .qr-box::after {
            content: "";
            position: absolute;
            width: 18px;
            height: 18px;
            border: 2px solid var(--gold-line);
        }

        .qr-box::before {
            top: 8px;
            inset-inline-start: 8px;
            border-inline-end: 0;
            border-block-end: 0;
            border-start-start-radius: 8px;
        }

        .qr-box::after {
            bottom: 8px;
            inset-inline-end: 8px;
            border-inline-start: 0;
            border-block-start: 0;
            border-end-end-radius: 8px;
        }

        .qr-box svg {
            display: block;
            width: min(72vw, 330px);
            height: auto;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-block: 8px 16px;
        }

        .info-item {
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fcfbf8;
        }

        .info-item span {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .info-item strong {
            display: block;
            color: var(--dark);
            font-size: 16px;
            font-weight: 900;
        }

        .scan-url {
            padding: 12px 14px;
            border-radius: 16px;
            direction: ltr;
            color: #315145;
            font-size: 12px;
            line-height: 1.5;
            text-align: center;
            word-break: break-all;
            background: #eef5ef;
        }

        .actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 18px;
            border: 1px solid transparent;
            border-radius: 999px;
            color: #fff;
            font: inherit;
            font-weight: 900;
            text-decoration: none;
            cursor: pointer;
            background: var(--primary);
            box-shadow: 0 12px 24px rgba(22, 76, 55, .18);
        }

        .btn.secondary {
            color: var(--dark);
            border-color: var(--line);
            background: #fff;
            box-shadow: none;
        }

        @media (max-width: 520px) {
            body { padding: 12px; }
            .qr-card { padding: 18px; border-radius: 22px; }
            .card-head { align-items: stretch; flex-direction: column; }
            .table-badge { width: 100%; }
            .info-grid { grid-template-columns: 1fr; }
            .actions { flex-direction: column; }
            .btn { width: 100%; }
        }

        @page {
            /* A4 portrait poster sheet — one table per sheet. */
            size: A4 portrait;
            margin: 0;
        }

        @media print {
            html, body {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 0;
                background: #fff;
                color: var(--ink);
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            body {
                display: block;
                min-height: auto;
            }

            main.page {
                width: 210mm;
                height: 297mm;
                margin: 0 auto;
                padding: 8mm;
                page-break-after: avoid;
                page-break-inside: avoid;
            }

            .qr-card {
                /* Hard-pin the card to the printable area so the browser
                   can't push any content to a phantom page 2. */
                width: 100%;
                height: 100%;
                padding: 12mm 12mm 10mm;
                border: 1.5pt solid var(--primary);
                border-radius: 14pt;
                background: #ffffff;
                box-shadow: none;
                break-inside: avoid;
                page-break-inside: avoid;
                page-break-after: avoid;
                display: flex;
                flex-direction: column;
                gap: 0;
                overflow: hidden;
            }

            .qr-card::before {
                inset-inline: 16mm;
                height: 3mm;
            }

            .qr-card::after {
                inset-inline: 50mm;
                top: 5mm;
                height: 1.2mm;
            }

            .card-head {
                padding-block: 1mm 5mm;
                margin-bottom: 3mm;
            }

            .brand { gap: 4mm; }

            .brand-mark, .brand-mark img {
                width: 22mm;
                height: 22mm;
                flex-basis: 22mm;
            }

            .brand-text strong { font-size: 24pt; }
            .brand-text span { font-size: 10pt; }
            .branch-line { font-size: 10pt; padding: 1pt 7pt; margin-top: 2mm; }

            .table-badge {
                min-width: 32mm;
                padding: 4mm 5mm;
                border-radius: 9pt;
            }
            .table-badge span { font-size: 10pt; }
            .table-badge strong { font-size: 40pt; }

            .qr-intro { padding: 4mm 0 2mm; }
            .qr-intro h1 {
                font-size: 24pt;
                gap: 9mm;
            }
            .qr-intro h1::before,
            .qr-intro h1::after { width: 20mm; height: .6mm; }
            .qr-intro p {
                font-size: 11pt;
                max-width: 120mm;
                line-height: 1.5;
                margin-top: 3mm;
            }

            .qr-box {
                /* Auto-fill remaining vertical space so the QR is the
                   centerpiece without overflowing. */
                margin: 4mm auto 4mm;
                padding: 8mm;
                border-radius: 12pt;
                box-shadow: inset 0 0 0 .8pt rgba(214, 184, 118, .4);
            }

            .qr-box::before,
            .qr-box::after {
                width: 6mm;
                height: 6mm;
                border-width: 1.2pt;
            }

            .qr-box svg {
                width: 95mm;
                max-width: 95mm;
                height: auto;
            }

            .info-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 3mm;
                margin-block: 3mm 0;
            }

            .info-item {
                padding: 3mm 4mm;
                border-radius: 8pt;
            }

            .info-item span { font-size: 8.5pt; margin-bottom: 1mm; }
            .info-item strong { font-size: 12pt; }

            /* The URL is encoded in the QR itself, and reading a long URL
               off paper is slower than scanning. Drop it on print so the
               poster stays clean and guaranteed-single-page. */
            .scan-url { display: none !important; }

            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="qr-card" aria-label="بطاقة QR للطاولة {{ $table->number }}">
            <header class="card-head">
                <div class="brand">
                    <div class="brand-mark">
                        <img src="{{ $logo }}" alt="{{ $restaurantName }}">
                    </div>
                    <div class="brand-text">
                        <span>قائمة وطلبات مباشرة</span>
                        <strong>{{ $restaurantName }}</strong>
                        <small class="branch-line">{{ $branchName }}</small>
                    </div>
                </div>

                <div class="table-badge">
                    <span>طاولة</span>
                    <strong>{{ $table->number }}</strong>
                </div>
            </header>

            <div class="qr-intro">
                <h1>امسح واطلب</h1>
                <p>افتح القائمة من جوالك، اختر طلبك، وسيصل مباشرة لفريق المطعم.</p>
            </div>

            <div class="qr-box">
                {!! $svg !!}
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <span>الفرع</span>
                    <strong>{{ $branchName }}</strong>
                </div>
                <div class="info-item">
                    <span>اسم الطاولة</span>
                    <strong>{{ $table->name ?: 'طاولة ' . $table->number }}</strong>
                </div>
                <div class="info-item">
                    <span>المنطقة</span>
                    <strong>{{ $zoneLabel ?: 'الصالة' }}</strong>
                </div>
            </div>

            <div class="scan-url">{{ $table->qrUrl() }}</div>
        </section>

        <div class="actions no-print">
            <button type="button" class="btn" onclick="window.print()">طباعة البطاقة</button>
            <a class="btn secondary" href="{{ route('admin.tables.index') }}">رجوع للطاولات</a>
        </div>
    </main>
</body>
</html>
