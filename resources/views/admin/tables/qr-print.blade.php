@php
    $restaurantName = \App\Helpers\Brand::name();
    $logo = asset('default_logo.png');
    $branchName = $table->branch?->name ?: 'الفرع الرئيسي';
    $zoneLabel = $table->zone?->label ?: 'الصالة';
    $tableLabel = 'طاولة ' . $table->number;
    $tableName = filled($table->name) && $table->name !== $tableLabel ? $table->name : null;
    $market = \App\Support\MarketProfile::class;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>بطاقة {{ $tableLabel }} — {{ $restaurantName }}</title>
    <link href="{{ $market::fontUrl() }}" rel="stylesheet">
    <style id="dynamic-paper-style">@page { size: 148mm 210mm; margin: 0; }</style>

    <style>
        :root {
            --brand: #1f6b50;
            --brand-deep: #124f3b;
            --brand-soft: #edf7f2;
            --gold: #b7832f;
            --gold-soft: #fbf5e9;
            --ink: #15251e;
            --text: #40534a;
            --muted: #728179;
            --line: #d8e4dd;
            --canvas: #f2f6f4;
            --paper: #fff;
            --sheet-width: 148mm;
            --sheet-height: 210mm;
            --sheet-margin: 7mm;
            --print-card-width: 134mm;
            --print-card-height: 194mm;
            --print-qr-size: 70mm;
            --print-title-size: 21pt;
            --print-copy-size: 9.5pt;
        }
        @include('partials.market-vars')

        body.paper-card {
            --sheet-width: 100mm;
            --sheet-height: 150mm;
            --sheet-margin: 3mm;
            --print-card-width: 94mm;
            --print-card-height: 142mm;
            --print-qr-size: 45mm;
            --print-title-size: 14pt;
            --print-copy-size: 7pt;
        }

        body.paper-a4 {
            --sheet-width: 210mm;
            --sheet-height: 297mm;
            --sheet-margin: 12mm;
            --print-card-width: 186mm;
            --print-card-height: 267mm;
            --print-qr-size: 100mm;
            --print-title-size: 30pt;
            --print-copy-size: 12pt;
        }

        * { box-sizing: border-box; }
        html { background: var(--canvas); }

        body {
            margin: 0;
            min-height: 100vh;
            padding: clamp(.75rem, 2.5vw, 2rem);
            color: var(--ink);
            font-family: var(--market-font-family);
            -webkit-font-smoothing: antialiased;
            background:
                radial-gradient(circle at 84% 4%, rgba(183, 131, 47, .1), transparent 25rem),
                radial-gradient(circle at 8% 92%, rgba(31, 107, 80, .08), transparent 28rem),
                var(--canvas);
        }

        button, a { font: inherit; }

        .workspace {
            width: min(100%, 760px);
            margin-inline: auto;
        }

        .screen-panel {
            margin-bottom: .9rem;
            padding: .85rem;
            background: rgba(255, 255, 255, .94);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 12px 34px rgba(21, 63, 44, .08);
        }

        .screen-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
        }

        .toolbar-context {
            display: flex;
            align-items: center;
            gap: .65rem;
            min-width: 0;
        }

        .toolbar-icon {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            color: var(--brand);
            background: var(--brand-soft);
            border-radius: 12px;
        }

        .toolbar-icon svg,
        .button svg { width: 19px; height: 19px; }

        .toolbar-copy { min-width: 0; }
        .toolbar-copy strong,
        .toolbar-copy span { display: block; }
        .toolbar-copy strong { font-size: .95rem; line-height: 1.35; }
        .toolbar-copy span {
            margin-top: .1rem;
            color: var(--muted);
            font-size: .75rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .toolbar-actions { display: flex; align-items: center; gap: .5rem; }

        .button {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            padding: 0 1rem;
            color: var(--brand);
            font-size: .84rem;
            font-weight: 850;
            text-decoration: none;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            cursor: pointer;
        }

        .button:hover { background: var(--brand-soft); }

        .button--primary {
            color: #fff;
            background: var(--brand);
            border-color: var(--brand);
            box-shadow: 0 8px 18px rgba(31, 107, 80, .18);
        }

        .button--primary:hover { color: #fff; background: var(--brand-deep); }

        .print-options {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: .75rem;
            margin-top: .8rem;
            padding-top: .8rem;
            border-top: 1px solid var(--line);
        }

        .option-label {
            display: block;
            margin-bottom: .45rem;
            color: var(--text);
            font-size: .76rem;
            font-weight: 800;
        }

        .paper-choices {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .45rem;
        }

        .paper-choice {
            position: relative;
            min-height: 50px;
            padding: .45rem .75rem;
            color: var(--text);
            text-align: start;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            cursor: pointer;
        }

        .paper-choice strong,
        .paper-choice span { display: block; }
        .paper-choice strong { color: var(--ink); font-size: .82rem; }
        .paper-choice span { margin-top: .12rem; color: var(--muted); font-size: .65rem; }
        .paper-choice[aria-pressed="true"] {
            background: var(--brand-soft);
            border-color: var(--brand);
            box-shadow: inset 0 0 0 1px var(--brand);
        }

        .recommended {
            position: absolute;
            inset-inline-end: .4rem;
            top: .35rem;
            color: var(--brand);
            font-size: .55rem;
            font-weight: 900;
        }

        .print-tip {
            max-width: 230px;
            margin: 0;
            color: var(--muted);
            font-size: .69rem;
            line-height: 1.6;
        }

        .preview-stage {
            padding: clamp(.65rem, 2vw, 1.35rem);
            background:
                linear-gradient(45deg, rgba(31, 107, 80, .025) 25%, transparent 25%) 0 0 / 20px 20px,
                #e8eeea;
            border: 1px solid var(--line);
            border-radius: 22px;
        }

        .qr-card {
            position: relative;
            width: min(100%, 530px);
            min-height: 720px;
            margin-inline: auto;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: var(--paper);
            border: 1px solid #cbdad1;
            border-radius: 24px;
            box-shadow: 0 24px 55px rgba(21, 63, 44, .15);
        }

        body.paper-card .qr-card { width: min(100%, 465px); min-height: 695px; }
        body.paper-a4 .qr-card { width: min(100%, 570px); min-height: 790px; }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid var(--line);
        }

        .brand-lockup {
            display: flex;
            align-items: center;
            gap: .65rem;
            min-width: 0;
        }

        .brand-logo {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            background: var(--gold-soft);
            border: 1px solid rgba(183, 131, 47, .22);
            border-radius: 14px;
        }

        .brand-logo img { width: 36px; height: 36px; object-fit: contain; }
        .brand-copy { min-width: 0; }
        .brand-copy strong,
        .brand-copy span { display: block; }
        .brand-copy strong { font-size: 1.25rem; font-weight: 900; line-height: 1.15; }
        .brand-copy span { margin-top: .15rem; color: var(--muted); font-size: .72rem; font-weight: 700; }

        .table-badge {
            min-width: 94px;
            padding: .48rem .8rem .58rem;
            color: var(--brand-deep);
            text-align: center;
            background: var(--brand-soft);
            border: 1px solid rgba(31, 107, 80, .2);
            border-radius: 15px;
        }
        .table-badge span,
        .table-badge strong { display: block; }
        .table-badge span { font-size: .66rem; font-weight: 850; }
        .table-badge strong { margin-top: .05rem; font-size: 2rem; line-height: 1; font-weight: 950; }

        .card-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.25rem 1.2rem 1rem;
            text-align: center;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            color: var(--brand);
            font-size: .72rem;
            font-weight: 900;
        }
        .eyebrow::before {
            content: '';
            width: 7px;
            height: 7px;
            background: var(--gold);
            border-radius: 50%;
            box-shadow: 0 0 0 5px rgba(183, 131, 47, .12);
        }

        .card-main h1 {
            margin: .55rem 0 0;
            font-size: clamp(1.75rem, 6vw, 2.35rem);
            line-height: 1.15;
            font-weight: 950;
            letter-spacing: -.02em;
        }

        .card-main__intro {
            max-width: 400px;
            margin: .45rem auto 0;
            color: var(--text);
            font-size: .88rem;
            line-height: 1.65;
        }

        .qr-shell {
            position: relative;
            width: fit-content;
            max-width: 100%;
            margin-top: 1rem;
            padding: 15px;
            background: #fff;
            border: 2px solid var(--brand-deep);
            border-radius: 20px;
        }

        .qr-shell::before,
        .qr-shell::after {
            content: '';
            position: absolute;
            width: 25px;
            height: 25px;
            border-color: var(--gold);
        }
        .qr-shell::before {
            inset-inline-start: -5px;
            top: -5px;
            border-inline-start: 4px solid var(--gold);
            border-top: 4px solid var(--gold);
            border-start-start-radius: 9px;
        }
        .qr-shell::after {
            inset-inline-end: -5px;
            bottom: -5px;
            border-inline-end: 4px solid var(--gold);
            border-bottom: 4px solid var(--gold);
            border-end-end-radius: 9px;
        }

        .qr-shell svg {
            display: block;
            width: min(63vw, 320px);
            height: auto;
        }

        .scan-hint {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            margin-top: .7rem;
            color: var(--brand-deep);
            font-size: .8rem;
            font-weight: 900;
        }
        .scan-hint svg { width: 18px; height: 18px; }

        .quick-steps {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .55rem;
            margin: 1rem 0 0;
            padding: 0;
            list-style: none;
        }

        .quick-step {
            min-width: 0;
            padding: .65rem .45rem;
            background: var(--brand-soft);
            border: 1px solid rgba(31, 107, 80, .09);
            border-radius: 12px;
        }
        .quick-step__number {
            width: 24px;
            height: 24px;
            display: grid;
            place-items: center;
            margin: 0 auto .28rem;
            color: #fff;
            font-size: .7rem;
            font-weight: 900;
            background: var(--brand);
            border-radius: 50%;
        }
        .quick-step strong { display: block; font-size: .78rem; font-weight: 900; }
        .quick-step span { display: block; margin-top: .12rem; color: var(--muted); font-size: .62rem; }

        .table-identity {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: .35rem .7rem;
            margin-top: auto;
            padding-top: .8rem;
            color: var(--text);
            font-size: .72rem;
            font-weight: 800;
            border-top: 1px dashed var(--line);
        }
        .table-identity span { display: inline-flex; align-items: center; gap: .3rem; }
        .table-identity span + span::before {
            content: '';
            width: 4px;
            height: 4px;
            margin-inline-end: .25rem;
            background: var(--gold);
            border-radius: 50%;
        }

        .card-footer {
            padding: .72rem 1rem;
            color: var(--brand-deep);
            text-align: center;
            font-size: .72rem;
            font-weight: 850;
            background: var(--gold-soft);
            border-top: 1px solid rgba(183, 131, 47, .15);
        }

        .target-check {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            margin-top: .75rem;
            padding: .65rem .8rem;
            color: var(--muted);
            background: rgba(255, 255, 255, .82);
            border: 1px dashed var(--line);
            border-radius: 12px;
        }
        .target-check__copy { min-width: 0; }
        .target-check strong,
        .target-check span { display: block; }
        .target-check strong { color: var(--text); font-size: .72rem; }
        .target-check span {
            margin-top: .12rem;
            overflow: hidden;
            unicode-bidi: plaintext;
            font: 500 .65rem/1.45 ui-monospace, SFMono-Regular, Consolas, monospace;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .target-check a { flex: 0 0 auto; color: var(--brand); font-size: .72rem; font-weight: 900; text-decoration: none; }

        @media (max-width: 650px) {
            body { padding: .6rem; }
            .screen-toolbar { align-items: stretch; flex-direction: column; }
            .toolbar-actions { display: grid; grid-template-columns: 1fr 1fr; }
            .button { width: 100%; }
            .print-options { grid-template-columns: 1fr; }
            .print-tip { max-width: none; }
            .paper-choice { padding-inline: .55rem; }
            .recommended { display: none; }
            .preview-stage { padding: .45rem; }
            .qr-card { min-height: 650px; border-radius: 18px; }
            .card-header { padding: .8rem; }
            .brand-logo { width: 42px; height: 42px; }
            .brand-logo img { width: 32px; height: 32px; }
            .table-badge { min-width: 78px; }
            .card-main { padding-inline: .75rem; }
            .quick-step span { display: none; }
            .target-check { align-items: flex-start; }
        }

        @media print {
            html, body {
                width: 100%;
                height: 100%;
                margin: 0;
                padding: 0;
                overflow: hidden;
                background: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .no-print { display: none !important; }
            .workspace {
                width: 100%;
                height: 100%;
                display: grid;
                place-items: center;
                overflow: hidden;
            }
            .preview-stage {
                width: 100%;
                height: 100%;
                display: grid;
                place-items: center;
                padding: 0;
                overflow: hidden;
                background: #fff;
                border: 0;
                border-radius: 0;
            }

            .qr-card {
                width: var(--print-card-width) !important;
                min-height: var(--print-card-height) !important;
                height: var(--print-card-height) !important;
                margin: 0;
                display: grid;
                grid-template-rows: auto minmax(0, 1fr) auto;
                border: .6mm solid var(--brand-deep);
                border-radius: 5mm;
                box-shadow: none;
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .card-header { padding: 3.2mm 4.5mm; }
            .brand-logo { width: 12mm; height: 12mm; border-radius: 3mm; }
            .brand-logo img { width: 9mm; height: 9mm; }
            .brand-copy strong { font-size: 14pt; }
            .brand-copy span { font-size: 7pt; }
            .table-badge { min-width: 23mm; padding: 1.8mm 3mm 2.3mm; border-radius: 3mm; }
            .table-badge span { font-size: 7pt; }
            .table-badge strong { font-size: 24pt; }

            .card-main { min-height: 0; padding: 3.5mm 5mm 2.5mm; }
            .eyebrow { font-size: 8pt; }
            .card-main h1 { margin-top: 2mm; font-size: var(--print-title-size); line-height: 1.1; }
            .card-main__intro { max-width: 108mm; margin-top: 1.5mm; font-size: var(--print-copy-size); line-height: 1.5; }
            .qr-shell { margin-top: 3mm; padding: 3mm; border-width: .7mm; border-radius: 4.5mm; }
            .qr-shell svg { width: var(--print-qr-size); max-width: var(--print-qr-size); }
            .qr-shell::before,
            .qr-shell::after { width: 7mm; height: 7mm; }
            .scan-hint { margin-top: 2mm; font-size: 8pt; }
            .scan-hint svg { width: 4.5mm; height: 4.5mm; }
            .quick-steps { gap: 1.8mm; margin-top: 3mm; }
            .quick-step { padding: 2mm 1.3mm; border-radius: 2.5mm; }
            .quick-step__number { width: 5.7mm; height: 5.7mm; margin-bottom: .7mm; font-size: 7pt; }
            .quick-step strong { font-size: 8pt; }
            .quick-step span { font-size: 6pt; }
            .table-identity { margin-top: auto; padding-top: 2mm; font-size: 7.2pt; }
            .card-footer { padding: 2mm 3mm; font-size: 7.5pt; }

            body.paper-card .card-header { padding: 2mm 2.7mm; }
            body.paper-card .brand-logo { width: 9mm; height: 9mm; border-radius: 2.3mm; }
            body.paper-card .brand-logo img { width: 6.5mm; height: 6.5mm; }
            body.paper-card .brand-copy strong { font-size: 10pt; }
            body.paper-card .brand-copy span { font-size: 5pt; }
            body.paper-card .table-badge { min-width: 18mm; padding: 1mm 2mm 1.5mm; }
            body.paper-card .table-badge span { font-size: 5pt; }
            body.paper-card .table-badge strong { font-size: 18pt; }
            body.paper-card .card-main { padding: 2.2mm 3mm 1.5mm; }
            body.paper-card .eyebrow { font-size: 6pt; }
            body.paper-card .card-main__intro { margin-top: .8mm; line-height: 1.35; }
            body.paper-card .qr-shell { margin-top: 1.8mm; padding: 2mm; border-radius: 3mm; }
            body.paper-card .scan-hint { margin-top: 1.2mm; font-size: 6.5pt; }
            body.paper-card .quick-steps { gap: 1.2mm; margin-top: 1.8mm; }
            body.paper-card .quick-step { padding: 1.2mm .8mm; }
            body.paper-card .quick-step__number { width: 4.5mm; height: 4.5mm; margin-bottom: .4mm; font-size: 5.5pt; }
            body.paper-card .quick-step strong { font-size: 6.5pt; }
            body.paper-card .quick-step span { display: none; }
            body.paper-card .table-identity { margin-top: auto; padding-top: 1.2mm; font-size: 5.5pt; }
            body.paper-card .card-footer { padding: 1.3mm 2mm; font-size: 6pt; }

            body.paper-a4 .card-header { padding: 6mm 8mm; }
            body.paper-a4 .brand-logo { width: 17mm; height: 17mm; border-radius: 4.5mm; }
            body.paper-a4 .brand-logo img { width: 12.5mm; height: 12.5mm; }
            body.paper-a4 .brand-copy strong { font-size: 20pt; }
            body.paper-a4 .brand-copy span { font-size: 9pt; }
            body.paper-a4 .table-badge { min-width: 32mm; padding: 2.5mm 4.5mm 3.5mm; }
            body.paper-a4 .table-badge span { font-size: 9pt; }
            body.paper-a4 .table-badge strong { font-size: 34pt; }
            body.paper-a4 .card-main { padding: 7mm 9mm 5mm; }
            body.paper-a4 .eyebrow { font-size: 10pt; }
            body.paper-a4 .qr-shell { margin-top: 5mm; padding: 5mm; border-radius: 6mm; }
            body.paper-a4 .scan-hint { margin-top: 4mm; font-size: 11pt; }
            body.paper-a4 .quick-steps { gap: 3.5mm; margin-top: 5mm; }
            body.paper-a4 .quick-step { padding: 3.5mm 2mm; }
            body.paper-a4 .quick-step__number { width: 8mm; height: 8mm; font-size: 9pt; }
            body.paper-a4 .quick-step strong { font-size: 11pt; }
            body.paper-a4 .quick-step span { font-size: 8pt; }
            body.paper-a4 .table-identity { margin-top: auto; padding-top: 4mm; font-size: 9pt; }
            body.paper-a4 .card-footer { padding: 3.5mm; font-size: 9pt; }
        }
    </style>
</head>
<body class="paper-a5">
    <main class="workspace">
        <section class="screen-panel no-print" aria-label="إعداد طباعة بطاقة الطاولة">
            <div class="screen-toolbar">
                <div class="toolbar-context">
                    <span class="toolbar-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM18 14h3M14 21h2"/></svg>
                    </span>
                    <span class="toolbar-copy">
                        <strong>بطاقة {{ $tableLabel }}</strong>
                        <span>{{ $branchName }} · {{ $zoneLabel }} · جاهزة للطباعة والتغليف</span>
                    </span>
                </div>

                <div class="toolbar-actions">
                    <a class="button" href="{{ route('admin.tables.index') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        رجوع
                    </a>
                    <button type="button" class="button button--primary" data-print-document>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/><path d="M18 12h.01"/></svg>
                        <span data-print-label>طباعة بطاقة A5</span>
                    </button>
                </div>
            </div>

            <div class="print-options">
                <div>
                    <span class="option-label">اختر الورق الموجود في الطابعة</span>
                    <div class="paper-choices" role="group" aria-label="حجم ورق الطباعة">
                        <button type="button" class="paper-choice" data-paper-choice="card" aria-pressed="false">
                            <strong>10 × 15</strong>
                            <span>حامل صغير</span>
                        </button>
                        <button type="button" class="paper-choice" data-paper-choice="a5" aria-pressed="true">
                            <span class="recommended">مُوصى به</span>
                            <strong>A5</strong>
                            <span>بطاقة الطاولة</span>
                        </button>
                        <button type="button" class="paper-choice" data-paper-choice="a4" aria-pressed="false">
                            <strong>A4</strong>
                            <span>ملصق كبير</span>
                        </button>
                    </div>
                </div>
                <p class="print-tip">لأفضل نتيجة: اجعل المقياس 100% وأوقف «الرؤوس والتذييلات» من نافذة الطباعة.</p>
            </div>
        </section>

        <section class="preview-stage" aria-label="معاينة بطاقة الطاولة">
            <article class="qr-card" aria-label="بطاقة QR لـ{{ $tableLabel }}">
                <header class="card-header">
                    <div class="brand-lockup">
                        <span class="brand-logo">
                            <img src="{{ $logo }}" alt="شعار {{ $restaurantName }}">
                        </span>
                        <span class="brand-copy">
                            <strong>{{ $restaurantName }}</strong>
                            <span>المنيو والطلب من الطاولة</span>
                        </span>
                    </div>

                    <div class="table-badge" aria-label="رقم الطاولة {{ $table->number }}">
                        <span>طاولة رقم</span>
                        <strong>{{ $table->number }}</strong>
                    </div>
                </header>

                <section class="card-main">
                    <span class="eyebrow">منيو المطعم على جوالك</span>
                    <h1>امسح الرمز واطلب بسهولة</h1>
                    <p class="card-main__intro">افتح كاميرا جوالك، وجّهها نحو الرمز، ثم تصفّح المنيو وأرسل طلبك من مكانك.</p>

                    <div class="qr-shell" aria-label="رمز فتح منيو {{ $tableLabel }}">
                        {!! $svg !!}
                    </div>

                    <div class="scan-hint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM18 14h3M14 21h2"/></svg>
                        وجّه الكاميرا هنا
                    </div>

                    <ol class="quick-steps" aria-label="طريقة الطلب">
                        <li class="quick-step">
                            <span class="quick-step__number">1</span>
                            <strong>امسح</strong>
                            <span>بكاميرا الهاتف</span>
                        </li>
                        <li class="quick-step">
                            <span class="quick-step__number">2</span>
                            <strong>اختر</strong>
                            <span>من المنيو</span>
                        </li>
                        <li class="quick-step">
                            <span class="quick-step__number">3</span>
                            <strong>أرسل</strong>
                            <span>من نفس الصفحة</span>
                        </li>
                    </ol>

                    <footer class="table-identity">
                        <span>{{ $branchName }}</span>
                        <span>{{ $zoneLabel }}</span>
                        @if ($tableName)
                            <span>{{ $tableName }}</span>
                        @endif
                        <span>{{ $tableLabel }}</span>
                    </footer>
                </section>

                <div class="card-footer">بحاجة لمساعدة؟ نادِ الجرسون من المنيو</div>
            </article>
        </section>

        <aside class="target-check no-print" aria-label="فحص رابط رمز الطاولة">
            <span class="target-check__copy">
                <strong>افحصه من هاتف حقيقي قبل وضعه على الطاولة</strong>
                <span>{{ $qrUrl }}</span>
            </span>
            <a href="{{ $qrUrl }}" target="_blank" rel="noopener">فتح الرابط ↗</a>
        </aside>
    </main>

    <script>
        (() => {
            const formats = {
                card: { className: 'paper-card', width: '100mm', height: '150mm', label: 'طباعة بطاقة 10×15' },
                a5: { className: 'paper-a5', width: '148mm', height: '210mm', label: 'طباعة بطاقة A5' },
                a4: { className: 'paper-a4', width: '210mm', height: '297mm', label: 'طباعة ملصق A4' },
            };
            const pageStyle = document.querySelector('#dynamic-paper-style');
            const label = document.querySelector('[data-print-label]');
            const choices = [...document.querySelectorAll('[data-paper-choice]')];
            let selected = 'a5';

            const applyFormat = (format) => {
                if (!formats[format]) return;
                selected = format;
                document.body.classList.remove(...Object.values(formats).map((item) => item.className));
                document.body.classList.add(formats[format].className);
                pageStyle.textContent = `@page { size: ${formats[format].width} ${formats[format].height}; margin: 0; }`;
                label.textContent = formats[format].label;
                choices.forEach((choice) => choice.setAttribute('aria-pressed', choice.dataset.paperChoice === format ? 'true' : 'false'));
                try { window.localStorage.setItem('restaurant.qr-paper', format); } catch (error) { /* storage is optional */ }
            };

            choices.forEach((choice) => choice.addEventListener('click', () => applyFormat(choice.dataset.paperChoice)));
            document.querySelector('[data-print-document]')?.addEventListener('click', () => {
                applyFormat(selected);
                window.print();
            });
            window.addEventListener('beforeprint', () => applyFormat(selected));

            try {
                const saved = window.localStorage.getItem('restaurant.qr-paper');
                if (formats[saved]) applyFormat(saved);
            } catch (error) { /* keep the safe A5 default */ }
        })();
    </script>
</body>
</html>
