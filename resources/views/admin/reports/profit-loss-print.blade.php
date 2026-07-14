<!DOCTYPE html>
@php($market = \App\Support\MarketProfile::class)
<html lang="{{ $market::lang() }}" dir="{{ $market::direction() }}" data-market="{{ $market::current() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تفاصيل الصندوق — {{ $brand['name'] }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="{{ $market::fontUrl() }}" rel="stylesheet">

@php
    use App\Helpers\Money;

    $period = $r['period'];
    $sales  = $r['sales'];
    $costs  = $r['costs'];
    $profit = $r['profit'];
    $cur = $brand['currency'];
    $source = $period['source'] ?? 'ledger';
    $sourceLabel = $source === 'ledger' ? 'دفتر القيود' : 'تشغيلي';
    $totalCosts = $costs['cogs'] + $costs['waste'] + $costs['expenses'] + $costs['platform_commission'] + $costs['refunds'];
    $fmt = fn ($v) => number_format((float) $v, 2, '.', ',') . ' ' . $cur;
    $pct = fn ($v) => number_format((float) $v, 2) . '%';
@endphp

<style>
    :root {
        --green-deep: #14532d;
        --green-mid: #166534;
        --green-soft: #2d6e4a;
        --green-tint: #f0fdf4;
        --gold: #d97706;
        --gold-soft: #fef9e7;
        --gold-mid: #d8a13a;
        --red: #b91c1c;
        --red-soft: #fef2f2;
        --slate: #475569;
        --slate-light: #94a3b8;
        --line: #e2e8f0;
        --bg: #f8fafc;
    }
    @include('partials.market-vars')
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
        font-family: var(--market-font-family);
        background: var(--bg);
        color: #1f2937;
        line-height: 1.7;
        font-size: 14px;
    }
    body { padding: 0 0 60px 0; }

    /* ─── ACTION BAR (white card sitting above the report — print-hidden) ─ */
    .action-bar {
        max-width: 920px;
        margin: 16px auto 0;
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 12px 18px;
        display: flex; align-items: center; gap: 14px;
        box-shadow: 0 1px 3px rgba(15,45,34,.04), 0 4px 10px rgba(15,45,34,.04);
    }
    .action-bar__title {
        font-weight: 700; font-size: 14px; color: var(--green-deep); flex: 1;
    }
    .action-bar__title small {
        color: var(--slate); display: block; font-weight: 500;
        font-size: 12px; margin-top: 3px; line-height: 1.6;
    }
    .btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 9px 16px; border-radius: 10px; border: none;
        font-family: inherit; font-weight: 700; font-size: 13.5px; cursor: pointer;
        transition: transform .12s, box-shadow .12s, background .12s;
        text-decoration: none;
    }
    .btn:hover { transform: translateY(-1px); }
    .btn--primary {
        background: var(--gold); color: #fff;
        box-shadow: 0 2px 6px rgba(185,120,24,.3);
    }
    .btn--primary:hover { background: #a96a14; }
    .btn--ghost {
        background: transparent; color: var(--slate);
        border: 1px solid var(--line);
    }
    .btn--ghost:hover { background: #f3f4f6; color: var(--green-deep); border-color: #cbd5e1; }
    @media print {
        .action-bar, .no-print { display: none !important; }
    }

    /* ─── PAGE LAYOUT ─────────────────────────────────────────── */
    .page {
        max-width: 920px; margin: 24px auto; background: #fff;
        border-radius: 14px; overflow: hidden;
        box-shadow: 0 4px 24px rgba(0,0,0,.08);
    }
    @media print {
        body { background: #fff; padding: 0; }
        .page { max-width: 100%; margin: 0; box-shadow: none; border-radius: 0; }
        @page { size: A4; margin: 14mm 12mm; }
    }

    /* ─── BRAND HEADER (clean — no decorative blob, no dashed strip) ─── */
    .brand {
        position: relative;
        background: linear-gradient(135deg, #0f4731 0%, #1c5e44 100%);
        color: #fff;
        margin: 16px;
        border-radius: 18px;
        padding: 1.6rem 1.7rem 1.4rem;
        overflow: hidden;
    }
    .brand__row { display: flex; align-items: center; gap: 18px; position: relative; z-index: 1; }
    .brand__logo {
        width: 52px; height: 52px;
        background: rgba(255,255,255,.18);
        border-radius: 14px;
        display: inline-flex; align-items: center; justify-content: center;
        font-family: 'Amiri', serif;
        font-size: 24px;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
    }
    .brand__head { flex-grow: 1; min-width: 0; }
    .brand__eyebrow {
        display: block;
        font-size: 12px;
        font-weight: 600;
        opacity: .82;
        margin-bottom: .15rem;
    }
    .brand h1 {
        font-size: 24px;
        font-weight: 800;
        margin: 0;
        line-height: 1.2;
        letter-spacing: -.2px;
    }
    .brand__sub { color: rgba(212,241,221,.9); font-size: 13px; margin-top: 6px; }
    .brand__meta {
        margin-inline-start: auto;
        text-align: end;
        color: rgba(212,241,221,.9);
        font-size: 12.5px;
        line-height: 1.85;
        flex-shrink: 0;
    }
    .brand__meta strong { color: #fff; }

    .brand__divider {
        height: 1px;
        background: rgba(255,255,255,.18);
        margin: 1rem 0 .9rem 0;
        position: relative;
        z-index: 1;
    }
    .brand__period {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem .85rem;
        font-size: .82rem;
        opacity: .95;
        position: relative;
        z-index: 1;
    }
    .brand__period > div { color: #fff; display: inline-flex; align-items: center; gap: .4rem; }
    .brand__period .lbl { color: rgba(212,241,221,.75); font-weight: 600; }
    .brand__period .val { color: #fff; font-weight: 700; }
    .brand__period .pm-dot {
        width: 4px; height: 4px;
        background: rgba(255,255,255,.4);
        border-radius: 50%;
        align-self: center;
    }

    /* ─── BODY PADDING ──────────────────────────────────────────── */
    .body-pad { padding: 24px 36px; }

    /* ─── SECTION TITLE ─────────────────────────────────────────── */
    .section-title {
        font-size: 16px; font-weight: 800; color: var(--green-deep);
        padding: 22px 0 10px 0; margin-top: 4px;
        border-bottom: 3px solid var(--gold);
        display: flex; align-items: center; gap: 8px;
    }
    .section-title::before {
        content: ''; display: inline-block; width: 6px; height: 22px;
        background: var(--gold); border-radius: 2px;
    }

    /* ─── KPI ROW ──────────────────────────────────────────────── */
    .kpis {
        display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
        margin-top: 20px;
    }
    .kpi {
        background: #fff; border: 1px solid var(--line); border-radius: 12px;
        padding: 16px 18px; position: relative; overflow: hidden;
    }
    .kpi::after {
        content: ''; position: absolute; top: 0; right: 0; width: 4px; height: 100%;
        background: var(--gold);
    }
    .kpi--rev::after  { background: var(--green-mid); }
    .kpi--gross::after{ background: var(--green-soft); }
    .kpi--cost::after { background: #b45309; }
    .kpi--net::after  { background: var(--gold); }
    .kpi--loss::after { background: var(--red); }
    .kpi__lbl { font-size: 12px; color: var(--slate); font-weight: 600; margin-bottom: 6px; }
    .kpi__val { font-size: 20px; font-weight: 800; color: var(--green-deep); line-height: 1.2; }
    .kpi--loss .kpi__val { color: var(--red); }
    .kpi__sub { font-size: 11.5px; color: var(--slate-light); margin-top: 4px; }

    /* ─── INCOME STATEMENT (the heart of the report) ──────────── */
    .stmt {
        margin-top: 14px; background: #fff; border: 1px solid var(--line);
        border-radius: 12px; overflow: hidden;
    }
    .stmt__row {
        display: flex; align-items: center; gap: 12px;
        padding: 14px 22px; border-bottom: 1px dashed var(--line);
    }
    .stmt__row:last-child { border-bottom: none; }
    .stmt__row .lbl { flex: 1; color: #334155; font-size: 14px; }
    .stmt__row .amt { font-weight: 700; color: var(--green-deep); min-width: 140px; text-align: end; font-size: 14.5px; }
    .stmt__row .pct {
        background: #f1f5f9; padding: 3px 12px; border-radius: 999px;
        font-size: 12px; font-weight: 700; color: var(--slate); min-width: 76px; text-align: center;
    }
    .stmt__row.indent { padding-right: 50px; background: #fafdfa; }
    .stmt__row.indent .lbl { color: var(--slate); font-size: 13px; }
    .stmt__row.neg .amt { color: var(--red); }
    .stmt__row.subtotal {
        background: #f8fafc; border-top: 2px solid var(--line); border-bottom: 2px solid var(--line);
        font-weight: 700;
    }
    .stmt__row.subtotal .lbl { color: var(--green-deep); font-weight: 700; }
    .stmt__row.gross { background: var(--green-tint); border-color: #bbf7d0; }
    .stmt__row.gross .amt { color: #14532d; }
    .stmt__row.final {
        background: linear-gradient(135deg, var(--green-tint) 0%, var(--gold-soft) 100%);
        border: 2px solid var(--gold) !important; border-radius: 10px;
        margin: 14px 16px 16px 16px; padding: 18px 22px;
        font-size: 15px;
    }
    .stmt__row.final .lbl { color: var(--green-deep); font-weight: 800; font-size: 15.5px; }
    .stmt__row.final .amt { color: var(--gold); font-size: 22px; font-weight: 800; }
    .stmt__row.final.is-loss { background: linear-gradient(135deg, var(--red-soft) 0%, var(--gold-soft) 100%); border-color: var(--red) !important; }
    .stmt__row.final.is-loss .amt { color: var(--red); }

    /* ─── NOTES BOX (side-collected funds) ───────────────────── */
    .notes {
        margin-top: 14px; background: var(--gold-soft); border: 1px solid #fde68a;
        border-radius: 12px; overflow: hidden;
    }
    .notes__head {
        background: #fde68a; padding: 10px 18px; font-weight: 700; color: var(--gold);
        font-size: 13px;
    }
    .notes__row {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 18px; border-bottom: 1px dashed #fde68a;
        font-size: 13px; color: var(--slate);
    }
    .notes__row:last-child { border-bottom: none; }
    .notes__row .lbl { flex: 1; }
    .notes__row .amt { font-weight: 700; color: var(--green-deep); }

    /* ─── BREAKDOWN GRID ──────────────────────────────────────── */
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; }
    .card {
        background: #fff; border: 1px solid var(--line); border-radius: 12px; overflow: hidden;
    }
    .card__head {
        background: linear-gradient(180deg, #fafdfa, #f5f9f5);
        padding: 12px 18px; border-bottom: 1px solid #e6efe9;
    }
    .card__head h3 { font-size: 14px; font-weight: 700; color: var(--green-deep); margin: 0; }
    .card__head small { color: var(--slate); font-size: 12px; }

    .table { width: 100%; border-collapse: collapse; }
    .table th, .table td {
        padding: 10px 14px; font-size: 12.5px; border-bottom: 1px dashed var(--line);
        text-align: start;
    }
    .table th {
        background: #f8fafc; color: var(--slate); font-weight: 700;
        font-size: 11.5px; letter-spacing: .3px;
    }
    .table tr:last-child td { border-bottom: none; }
    .table td.num { text-align: end; font-weight: 700; color: var(--green-deep); }
    .table td.muted { color: var(--slate-light); font-size: 11.5px; }
    .dot {
        display: inline-block; width: 8px; height: 8px; border-radius: 50%;
        margin-left: 8px; vertical-align: middle;
    }
    .empty {
        padding: 20px; text-align: center; color: var(--slate-light); font-size: 13px;
    }

    /* ─── TOP ITEMS ────────────────────────────────────────────── */
    .items-table th {
        background: var(--green-mid); color: #fff;
    }
    .rank {
        display: inline-block; width: 22px; height: 22px; line-height: 22px; text-align: center;
        background: var(--gold-soft); color: var(--gold); font-weight: 800; border-radius: 50%;
        font-size: 11px; margin-left: 6px;
    }
    .pill {
        display: inline-block; padding: 3px 10px; border-radius: 999px;
        font-size: 11px; font-weight: 700;
    }
    .pill--good { background: var(--green-tint); color: #14532d; }
    .pill--warn { background: var(--gold-soft); color: var(--gold); }
    .pill--bad  { background: var(--red-soft); color: var(--red); }

    /* ─── GLOSSARY ─────────────────────────────────────────────── */
    .glossary {
        margin-top: 16px; background: #fff; border: 1px solid var(--line);
        border-radius: 12px; overflow: hidden;
    }
    .glossary__head {
        background: linear-gradient(180deg, #fafdfa, #f5f9f5);
        padding: 12px 18px; border-bottom: 1px solid #e6efe9; font-weight: 700; color: var(--green-deep);
    }
    .glossary dl { padding: 18px; margin: 0; }
    .glossary dt {
        color: var(--gold); font-weight: 700; font-size: 13.5px; margin-top: 14px;
    }
    .glossary dt:first-child { margin-top: 0; }
    .glossary dd {
        color: var(--slate); font-size: 12.5px; margin: 4px 0 0 0; line-height: 1.9;
    }
    .glossary code {
        background: #f1f5f9; padding: 2px 8px; border-radius: 4px;
        font-family: var(--market-font-family); color: var(--green-deep); font-size: 12px;
    }

    /* ─── FOOTER (signature line) ──────────────────────────────── */
    .signature {
        display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px;
        margin-top: 30px; padding: 24px; border-top: 2px dashed var(--line);
    }
    .signature .box {
        text-align: center; padding-top: 36px; border-top: 1px solid var(--green-deep);
        font-size: 12px; color: var(--slate);
    }
    .signature .box strong { display: block; color: var(--green-deep); font-size: 13px; }

    .footer-note {
        text-align: center; font-size: 11.5px; color: var(--slate-light);
        padding: 18px; border-top: 1px solid var(--line); background: #fafafa;
    }

    /* ─── PRINT TWEAKS ─────────────────────────────────────────── */
    /* Force the browser to honour our backgrounds and gradients on print
     * AND on save-as-PDF. Without these the green hero and the card fills
     * disappear because Chrome skips backgrounds by default to save ink.
     * `print-color-adjust: exact` is the standard property; the WebKit
     * prefix covers older Chrome/Safari versions.
     */
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }

    @media print {
        /* Strip shadows everywhere — they don't print well anyway. */
        .stmt, .card, .glossary, .kpi, .brand, .notes { box-shadow: none !important; }

        /* Avoid breaks ONLY on small atomic units. The income statement
           (`.stmt`) is tall — forbidding breaks inside it pushes the whole
           block to the next page and leaves a giant gap under the KPIs.
           Allow the rows to flow naturally; just keep individual rows
           together so a single subtotal line never splits across pages. */
        .brand, .kpi, .notes,
        .stmt__row, .stmt__row.subtotal, .stmt__row.final, .stmt__row.gross {
            page-break-inside: avoid;
        }
        .section-title { page-break-after: avoid; }

        /* Brand has no margin on print (margin would create a gap on the
           paper edge — the @page already gives us proper margins). */
        .brand { margin: 0 0 14px 0; border-radius: 0; }
        .body-pad { padding: 0 16px 16px; }

        body { font-size: 12px; }
        .kpis { gap: 8px; }
        .kpi { padding: 12px; }
        .kpi__val { font-size: 17px; }

        /* Hero gradient + colored card stripes need exact rendering */
        .brand, .kpi, .stmt__row.subtotal, .stmt__row.gross,
        .stmt__row.final, .notes, .notes__head, .card__head,
        .items-table th, .pill, .rank, .stmt__row .pct {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }
</style>
</head>
<body>

{{-- ═══════════════ ACTION BAR (hidden on print) ═══════════════ --}}
<div class="action-bar">
    <div class="action-bar__title">
        تفاصيل الصندوق · {{ $brand['name'] }}
        <small>اضغط زر "اطبع" أدناه أو استخدم Ctrl/⌘ + P · لحفظه PDF اختر "حفظ بصيغة PDF" من نافذة الطباعة</small>
    </div>
    <button class="btn btn--primary" onclick="window.print()">
        <span>🖨</span>
        <span>اطبع التقرير</span>
    </button>
    <a class="btn btn--ghost" href="javascript:window.close()">
        <span>×</span>
        <span>إغلاق</span>
    </a>
</div>

<div class="page">

{{-- ═══════════════ BRAND HEADER (mirrors /portal/order hero) ═════ --}}
<div class="brand">
    <div class="brand__row">
        <div class="brand__logo">{{ mb_substr($brand['name'], 0, 1) }}</div>
        <div class="brand__head">
            <span class="brand__eyebrow">تفاصيل الصندوق</span>
            <h1>{{ $brand['name'] }}</h1>
            <div class="brand__sub">
                @if($brand['legal']){{ $brand['legal'] }}@endif
                @if($brand['legal'] && $brand['tax']) · @endif
                @if($brand['tax']) رقم ضريبي {{ $brand['tax'] }} @endif
            </div>
        </div>
        <div class="brand__meta">
            <strong>{{ $branchName }}</strong><br>
            {{ \Carbon\Carbon::parse($period['from'])->locale(\App\Support\MarketProfile::lang())->isoFormat('D MMMM YYYY') }}
            —
            {{ \Carbon\Carbon::parse($period['to'])->locale(\App\Support\MarketProfile::lang())->isoFormat('D MMMM YYYY') }}<br>
            <span style="font-size:11.5px; opacity:.8;">صدر {{ $generatedAt->locale(\App\Support\MarketProfile::lang())->isoFormat('D MMMM YYYY · HH:mm') }}</span>
        </div>
    </div>

    <div class="brand__divider"></div>

    <div class="brand__period">
        <div><span class="lbl">الفترة:</span><span class="val">{{ $period['days'] }} يوم</span></div>
        <span class="pm-dot"></span>
        <div><span class="lbl">عدد الفواتير:</span><span class="val">{{ number_format($sales['invoice_count']) }}</span></div>
        <span class="pm-dot"></span>
        <div><span class="lbl">متوسط الفاتورة:</span><span class="val">{{ $fmt($sales['average_ticket']) }}</span></div>
        <span class="pm-dot"></span>
        <div><span class="lbl">معدل اليوم:</span><span class="val">{{ $fmt($sales['daily_average']) }}</span></div>
        <span class="pm-dot"></span>
        <div><span class="lbl">مصدر التقرير:</span><span class="val">{{ $sourceLabel }}</span></div>
        <span class="pm-dot"></span>
        <div><span class="lbl">احتساب التكلفة:</span><span class="val">{{ $source === 'ledger' ? 'من دفتر القيود' : ($period['per_branch_cost'] ? 'بأسعار شراء الفرع' : 'موحَّدة') }}</span></div>
    </div>
</div>

<div class="body-pad">

{{-- ═══════════════ KPI ROW ═══════════════ --}}
<div class="kpis">
    <div class="kpi kpi--rev">
        <div class="kpi__lbl">صافي المبيعات</div>
        <div class="kpi__val">{{ $fmt($sales['net_sales']) }}</div>
        <div class="kpi__sub">قبل التكاليف · إجمالي {{ $fmt($sales['gross_sales']) }}</div>
    </div>
    <div class="kpi kpi--gross">
        <div class="kpi__lbl">الربح الإجمالي</div>
        <div class="kpi__val">{{ $fmt($profit['gross_profit']) }}</div>
        <div class="kpi__sub">هامش {{ $pct($profit['gross_margin_pct']) }}</div>
    </div>
    <div class="kpi kpi--cost">
        <div class="kpi__lbl">إجمالي التكاليف</div>
        <div class="kpi__val">{{ $fmt($totalCosts) }}</div>
        <div class="kpi__sub">{{ $sales['net_sales'] > 0 ? $pct(($totalCosts / $sales['net_sales']) * 100) : '—' }} من المبيعات</div>
    </div>
    <div class="kpi kpi--net {{ $profit['net_profit'] < 0 ? 'kpi--loss' : '' }}">
        <div class="kpi__lbl">صافي الربح</div>
        <div class="kpi__val">{{ $fmt($profit['net_profit']) }}</div>
        <div class="kpi__sub">هامش صافي {{ $pct($profit['net_margin_pct']) }}</div>
    </div>
</div>

{{-- ═══════════════ INCOME STATEMENT ═══════════════ --}}
<h2 class="section-title">قائمة الدخل التفصيلية</h2>

<div class="stmt">
    <div class="stmt__row">
        <div class="lbl">الإيرادات الإجمالية</div>
        <div class="amt">{{ $fmt($sales['gross_sales']) }}</div>
        <div class="pct">100%</div>
    </div>
    <div class="stmt__row indent neg">
        <div class="lbl">يُطرح: الخصومات الممنوحة للزبائن</div>
        <div class="amt">−{{ $fmt($sales['discounts_total']) }}</div>
        <div class="pct">{{ $sales['gross_sales'] > 0 ? $pct(($sales['discounts_total'] / $sales['gross_sales']) * 100) : '—' }}</div>
    </div>
    <div class="stmt__row subtotal">
        <div class="lbl">صافي المبيعات</div>
        <div class="amt">{{ $fmt($sales['net_sales']) }}</div>
        <div class="pct">100%</div>
    </div>
    <div class="stmt__row indent neg">
        <div class="lbl">يُطرح: تكلفة البضاعة المباعة (تكلفة المكوّنات)</div>
        <div class="amt">−{{ $fmt($costs['cogs']) }}</div>
        <div class="pct">{{ $sales['net_sales'] > 0 ? $pct(($costs['cogs'] / $sales['net_sales']) * 100) : '—' }}</div>
    </div>
    <div class="stmt__row indent neg">
        <div class="lbl">يُطرح: قيمة الهدر والتالف</div>
        <div class="amt">−{{ $fmt($costs['waste']) }}</div>
        <div class="pct">{{ $sales['net_sales'] > 0 ? $pct(($costs['waste'] / $sales['net_sales']) * 100) : '—' }}</div>
    </div>
    <div class="stmt__row subtotal gross">
        <div class="lbl">الربح الإجمالي</div>
        <div class="amt">{{ $fmt($profit['gross_profit']) }}</div>
        <div class="pct">{{ $pct($profit['gross_margin_pct']) }}</div>
    </div>
    <div class="stmt__row indent neg">
        <div class="lbl">يُطرح: المصروفات التشغيلية</div>
        <div class="amt">−{{ $fmt($costs['expenses']) }}</div>
        <div class="pct">{{ $sales['net_sales'] > 0 ? $pct(($costs['expenses'] / $sales['net_sales']) * 100) : '—' }}</div>
    </div>
    <div class="stmt__row indent neg">
        <div class="lbl">يُطرح: عمولات منصات التوصيل</div>
        <div class="amt">−{{ $fmt($costs['platform_commission']) }}</div>
        <div class="pct">{{ $sales['net_sales'] > 0 ? $pct(($costs['platform_commission'] / $sales['net_sales']) * 100) : '—' }}</div>
    </div>
    <div class="stmt__row subtotal">
        <div class="lbl">الربح من العمليات</div>
        <div class="amt">{{ $fmt($profit['operating_profit']) }}</div>
        <div class="pct">{{ $pct($profit['operating_margin_pct']) }}</div>
    </div>
    {{-- Always render the refunds line, even at zero — a printed P&L
         should show every line item explicitly so the reader knows
         refunds were considered (and not silently dropped). --}}
    <div class="stmt__row indent {{ $costs['refunds'] > 0 ? 'neg' : '' }}">
        <div class="lbl">يُطرح: مبالغ مُستردَّة للزبائن</div>
        <div class="amt">{{ $costs['refunds'] > 0 ? '−' : '' }}{{ $fmt($costs['refunds']) }}</div>
        <div class="pct">{{ $sales['net_sales'] > 0 && $costs['refunds'] > 0 ? $pct(($costs['refunds'] / $sales['net_sales']) * 100) : '—' }}</div>
    </div>
    <div class="stmt__row final {{ $profit['net_profit'] < 0 ? 'is-loss' : '' }}">
        <div class="lbl">صافي الربح</div>
        <div class="amt">{{ $fmt($profit['net_profit']) }}</div>
        <div class="pct">{{ $pct($profit['net_margin_pct']) }}</div>
    </div>
</div>

{{-- Side-collected funds --}}
@if($sales['tax_collected'] + $sales['service_collected'] + $sales['tip_collected'] + $sales['delivery_collected'] + $sales['unpaid_balance'] > 0)
    <div class="notes">
        <div class="notes__head">مبالغ محصَّلة بشكل منفصل (ليست إيراداً للمطعم)</div>
        @if($sales['tax_collected'] > 0)
            <div class="notes__row"><div class="lbl">الضريبة المحصَّلة (تُورَّد للجهة الضريبية)</div><div class="amt">{{ $fmt($sales['tax_collected']) }}</div></div>
        @endif
        @if($sales['service_collected'] > 0)
            <div class="notes__row"><div class="lbl">رسوم الخدمة</div><div class="amt">{{ $fmt($sales['service_collected']) }}</div></div>
        @endif
        @if($sales['tip_collected'] > 0)
            <div class="notes__row"><div class="lbl">الإكراميات (تُمرَّر للموظفين)</div><div class="amt">{{ $fmt($sales['tip_collected']) }}</div></div>
        @endif
        @if($sales['delivery_collected'] > 0)
            <div class="notes__row"><div class="lbl">رسوم التوصيل المُحصَّلة</div><div class="amt">{{ $fmt($sales['delivery_collected']) }}</div></div>
        @endif
        @if($sales['unpaid_balance'] > 0)
            <div class="notes__row"><div class="lbl">متبقٍ على الزبائن (دين)</div><div class="amt" style="color: var(--gold);">{{ $fmt($sales['unpaid_balance']) }}</div></div>
        @endif
    </div>
@endif

{{-- ═══════════════ DISCOUNTS + EXPENSES BREAKDOWN ═══════════════ --}}
<h2 class="section-title">تفصيل الخصومات والمصروفات</h2>

<div class="grid-2">
    {{-- Discounts --}}
    <div class="card">
        <div class="card__head">
            <h3>الخصومات حسب التصنيف</h3>
            <small>{{ $r['discounts']['count'] }} خصم بإجمالي {{ $fmt($r['discounts']['total']) }}</small>
        </div>
        @if(empty($r['discounts']['by_category']))
            <div class="empty">لا توجد خصومات في هذه الفترة</div>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>التصنيف</th>
                        <th style="text-align:center;">عدد</th>
                        <th style="text-align:end;">المجموع</th>
                        <th style="text-align:end;">% من المبيعات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r['discounts']['by_category'] as $row)
                        <tr>
                            <td><span class="dot" style="background:{{ $row['color'] }}"></span>{{ $row['label'] }}</td>
                            <td style="text-align:center;">{{ $row['count'] }}</td>
                            <td class="num">{{ $fmt($row['total']) }}</td>
                            <td class="num muted">{{ $sales['net_sales'] > 0 ? $pct(($row['total']/$sales['net_sales'])*100) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Expenses by category --}}
    <div class="card">
        <div class="card__head">
            <h3>المصروفات حسب التصنيف</h3>
            <small>إجمالي {{ $fmt($costs['expenses']) }}</small>
        </div>
        @if(empty($r['expenses_breakdown']['by_category']))
            <div class="empty">لا توجد مصروفات في هذه الفترة</div>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>التصنيف</th>
                        <th style="text-align:center;">عدد</th>
                        <th style="text-align:end;">المجموع</th>
                        <th style="text-align:end;">% من المبيعات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r['expenses_breakdown']['by_category'] as $row)
                        <tr>
                            <td><span class="dot" style="background:{{ $row['color'] }}"></span>{{ $row['label'] }}</td>
                            <td style="text-align:center;">{{ $row['count'] }}</td>
                            <td class="num">{{ $fmt($row['total']) }}</td>
                            <td class="num muted">{{ $sales['net_sales'] > 0 ? $pct(($row['total']/$sales['net_sales'])*100) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

@if(count($r['discounts']['by_cashier']) || count($r['expenses_breakdown']['by_method']))
    <div class="grid-2" style="margin-top: 14px;">
        @if(count($r['discounts']['by_cashier']))
            <div class="card">
                <div class="card__head">
                    <h3>أعلى الموظفين منحاً للخصومات</h3>
                </div>
                <table class="table">
                    <thead><tr><th>الموظف</th><th style="text-align:center;">عدد الخصومات</th><th style="text-align:end;">إجمالي ما مُنح</th></tr></thead>
                    <tbody>
                        @foreach($r['discounts']['by_cashier'] as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td style="text-align:center;">{{ $row['count'] }}</td>
                                <td class="num">{{ $fmt($row['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(count($r['expenses_breakdown']['by_method']))
            <div class="card">
                <div class="card__head">
                    <h3>المصروفات حسب طريقة الدفع</h3>
                </div>
                <table class="table">
                    <thead><tr><th>الطريقة</th><th style="text-align:center;">عدد</th><th style="text-align:end;">المجموع</th></tr></thead>
                    <tbody>
                        @foreach($r['expenses_breakdown']['by_method'] as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td style="text-align:center;">{{ $row['count'] }}</td>
                                <td class="num">{{ $fmt($row['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

{{-- ═══════════════ TOP ITEMS ═══════════════ --}}
@if($r['top_items']->count())
    <h2 class="section-title">الأصناف الأكثر مساهمة في الربح</h2>
    <div class="card">
        <div class="card__head">
            <h3>أعلى ١٠ أصناف</h3>
            <small>مرتَّبة حسب الربح المُحقَّق (الإيراد − تكلفة المكوّنات)</small>
        </div>
        <table class="table items-table">
            <thead>
                <tr>
                    <th style="width: 35%;">الصنف</th>
                    <th style="text-align:center;">الكمية</th>
                    <th style="text-align:end;">الإيراد</th>
                    <th style="text-align:end;">التكلفة</th>
                    <th style="text-align:end;">الربح</th>
                    <th style="text-align:center;">هامش الربح</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['top_items'] as $i => $it)
                    @php
                        $rev = (float) $it->revenue;
                        $margin = $rev > 0 ? ((float) $it->profit / $rev) * 100 : 0;
                        $pillClass = $margin >= 50 ? 'pill--good' : ($margin >= 30 ? 'pill--warn' : 'pill--bad');
                    @endphp
                    <tr>
                        <td><span class="rank">{{ $i + 1 }}</span><strong>{{ $it->name }}</strong></td>
                        <td style="text-align:center;">{{ rtrim(rtrim(number_format((float) $it->qty, 2), '0'), '.') }}</td>
                        <td class="num">{{ $fmt($rev) }}</td>
                        <td class="num" style="color: var(--red);">{{ $fmt($it->cogs) }}</td>
                        <td class="num" style="color: #14532d;">{{ $fmt($it->profit) }}</td>
                        <td style="text-align:center;"><span class="pill {{ $pillClass }}">{{ number_format($margin, 1) }}%</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- ═══════════════ GLOSSARY ═══════════════ --}}
<h2 class="section-title">دليل المصطلحات والمعادلات</h2>
<div class="glossary">
    <dl>
        <dt>الإيرادات الإجمالية</dt>
        <dd>مجموع قيم الفواتير المُصدرة في الفترة قبل خصم الخصومات. أساس قياس النشاط التجاري.</dd>

        <dt>الخصومات</dt>
        <dd>مجموع الخصومات الممنوحة للزبائن (يدوية من الكاشير، تخفيضات للعملاء الدائمين، كوبونات).</dd>

        <dt>صافي المبيعات</dt>
        <dd>= الإيرادات الإجمالية − الخصومات. هو أساس حساب كل النسب لاحقاً.</dd>

        <dt>تكلفة البضاعة المباعة</dt>
        <dd>= مجموع (كمية الصنف المباع × تكلفة المكوّنات للصنف). محسوبة من الوصفات المسجَّلة، ولا تشمل المصروفات التشغيلية.</dd>

        <dt>الهدر والتالف</dt>
        <dd>قيمة المكوّنات المُسجَّلة كهدر/تالف في الفترة. خسارة مباشرة لا يقابلها بيع.</dd>

        <dt>الربح الإجمالي</dt>
        <dd>= صافي المبيعات − تكلفة البضاعة المباعة − الهدر. مؤشر صحة قائمة الطعام والتسعير.</dd>

        <dt>المصروفات التشغيلية</dt>
        <dd>المصروفات المعتمدة في الفترة من شاشة المصروفات (إيجار، رواتب، فواتير الكهرباء والماء، نقدية صغيرة، …).</dd>

        <dt>عمولات منصات التوصيل</dt>
        <dd>= مجموع (إجمالي الطلب × نسبة عمولة المنصة). محسوبة من الطلبات المُسجَّلة بمصدر خارجي مثل طلبات وكريم وأوبر.</dd>

        <dt>الربح من العمليات</dt>
        <dd>= الربح الإجمالي − المصروفات − عمولات المنصات. مؤشر فعالية الإدارة اليومية.</dd>

        <dt>الاستردادات</dt>
        <dd>المبالغ المُعادة للزبائن في الفترة (شكاوى، أخطاء، تعويضات). تظهر منفصلة لتمييز الخسائر الاستثنائية.</dd>

        <dt>صافي الربح</dt>
        <dd>= الربح من العمليات − الاستردادات. <strong>الرقم النهائي.</strong></dd>

        <dt>هامش الربح</dt>
        <dd>= (الربح ÷ صافي المبيعات) × ١٠٠. <strong>أكثر من ٥٠٪ ممتاز · ٣٠–٥٠٪ مقبول · أقل من ٣٠٪ يحتاج مراجعة.</strong></dd>
    </dl>
</div>

{{-- ═══════════════ SIGNATURES ═══════════════ --}}
<div class="signature">
    <div class="box">
        <strong>أعدّ التقرير</strong>
        النظام آلياً
    </div>
    <div class="box">
        <strong>راجعه</strong>
        مدير الفرع
    </div>
    <div class="box">
        <strong>اعتمده</strong>
        الإدارة العامة
    </div>
</div>

</div> {{-- /.body-pad --}}

<div class="footer-note">
    {{ $brand['footer'] ?? 'هذا التقرير مُولَّد آلياً من نظام إدارة المطعم — لا يحتاج توقيعاً للصلاحية' }}
    · {{ $brand['name'] }}
</div>

</div> {{-- /.page --}}

</body>
</html>
