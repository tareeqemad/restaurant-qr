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
            padding: 24px;
            border: 1px solid rgba(22, 76, 55, .14);
            border-radius: 28px;
            background: #fff;
            box-shadow: 0 24px 70px rgba(16, 35, 27, .14);
        }

        .qr-card::before {
            content: "";
            position: absolute;
            inset-inline: 18px;
            top: 0;
            height: 8px;
            border-radius: 0 0 999px 999px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
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
            padding: 20px 4px 14px;
            text-align: center;
        }

        .qr-intro h1 {
            margin: 0;
            color: var(--dark);
            font-size: clamp(28px, 7vw, 44px);
            font-weight: 900;
            letter-spacing: 0;
        }

        .qr-intro p {
            margin: 8px auto 0;
            max-width: 360px;
            color: var(--muted);
            font-size: 16px;
            font-weight: 700;
            line-height: 1.7;
        }

        .qr-box {
            display: grid;
            place-items: center;
            width: fit-content;
            max-width: 100%;
            margin: 12px auto 18px;
            padding: 18px;
            border: 1px solid #efe7d7;
            border-radius: 24px;
            background: #fff;
            box-shadow: inset 0 0 0 8px #faf7ef;
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
            size: A5 portrait;
            margin: 10mm;
        }

        @media print {
            body {
                min-height: auto;
                padding: 0;
                background: #fff;
            }

            .page {
                width: 100%;
            }

            .qr-card {
                padding: 12mm;
                border: 1.5pt solid #1f3f32;
                border-radius: 18pt;
                box-shadow: none;
                break-inside: avoid;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .qr-box {
                padding: 10mm;
                box-shadow: inset 0 0 0 5pt #faf7ef;
            }

            .qr-box svg {
                width: 82mm;
            }

            .no-print {
                display: none !important;
            }
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
