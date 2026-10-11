<!DOCTYPE html>
@php($market = \App\Support\MarketProfile::class)
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>الأرباح والخسائر — {{ $brand['name'] }}</title>
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
    $totalCosts = $costs['cogs'] + $costs['waste'] + $costs['expenses'] + $costs['refunds'];
    $fmt = fn ($v) => number_format((float) $v, 2, '.', ',') . ' ' . $cur;
    $pct = fn ($v) => number_format((float) $v, 2) . '%';
@endphp

<style>
:root{--ink:#25343A;--muted:#626B6D;--brand:#A3422B;--line:#DFD7CB;--canvas:#F7F3ED;--surface:#FFFDFA;--red:#B83232;--gold:#B78338;--slate:#626B6D}
@include('partials.market-vars')
*{box-sizing:border-box}html,body{margin:0;font-family:var(--market-font-family);font-size:14px;line-height:1.7;color:var(--ink);background:var(--canvas)}
.action-bar{display:flex;align-items:center;gap:14px;max-width:1040px;margin:20px auto;padding:16px 22px;border:1px solid var(--line);border-radius:12px;background:var(--surface)}.action-bar__title{flex:1;font-weight:800}.action-bar__title small{display:block;color:var(--muted);font-size:13px;font-weight:400}.btn{min-height:44px;padding:10px 18px;border:1px solid var(--line);border-radius:8px;background:transparent;color:var(--ink);font:700 14px var(--market-font-family);cursor:pointer}.btn--primary{background:var(--brand);border-color:var(--brand);color:#fff}
.page{max-width:1040px;margin:0 auto 40px;background:var(--surface);border:1px solid var(--line)}.brand{padding:32px 36px 0;background:var(--surface);color:var(--ink)}.brand__row{display:flex;align-items:center;gap:18px}.brand__logo{display:grid;flex:0 0 54px;width:54px;height:54px;place-items:center;border:1px solid var(--line);border-radius:12px;color:var(--brand);font-size:30px;font-weight:800;background:var(--canvas)}.brand__head{flex:1}.brand h1{margin:3px 0;font-size:30px;line-height:1.35}.brand__eyebrow{font-size:13px;font-weight:800;color:var(--brand)}.brand__sub,.brand__meta{font-size:12px;color:var(--muted)}.brand__meta{line-height:1.85;text-align:end}.brand__meta strong{font-size:14px;color:var(--ink)}.brand__divider{height:2px;margin:24px 0 0;background:var(--ink)}.brand__period{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 24px;padding:18px 0;border-bottom:1px solid var(--line)}.brand__period>div{display:flex;gap:8px;justify-content:space-between;font-size:12px}.brand__period .lbl{color:var(--muted)}.brand__period .val{font-weight:700}.pm-dot{display:none}.body-pad{padding:28px 36px 36px}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;border:1px solid var(--line);background:var(--line);margin-bottom:32px}.kpi{padding:20px 16px;background:var(--surface)}.kpi__lbl{color:var(--muted);font-size:13px}.kpi__val{font-size:21px;font-weight:800;line-height:1.8;white-space:nowrap;font-variant-numeric:tabular-nums}.kpi__sub{font-size:11px;color:var(--muted)}.kpi--net{background:var(--canvas)}.kpi--net .kpi__val{color:var(--brand)}.kpi--loss .kpi__val{color:var(--red)}
.section-title{margin:28px 0 14px;font-size:19px;font-weight:800}.stmt{border-top:2px solid var(--ink);border-bottom:1px solid var(--line)}.stmt__row{display:grid;grid-template-columns:minmax(0,1fr) 180px 75px;gap:18px;padding:13px 12px;border-bottom:1px solid var(--line)}.stmt__row .amt,.stmt__row .pct{text-align:end;font-variant-numeric:tabular-nums}.stmt__row .amt{font-weight:700}.stmt__row .pct{font-size:12px;color:var(--muted)}.stmt__row.indent .lbl{padding-inline-start:20px;color:var(--muted)}.stmt__row.neg .amt{color:var(--red)}.stmt__row.subtotal{background:var(--canvas);font-weight:800}.stmt__row.final{padding:19px 12px;border-top:2px solid var(--ink);border-bottom:0;background:var(--ink);color:#fff;font-size:18px;font-weight:800}.stmt__row.final .pct{color:#fff}.stmt__row.final.is-loss{background:#8E302D}
.notes{margin-top:22px;padding:18px;border-inline-start:3px solid var(--gold);background:var(--canvas)}.notes__head{font-weight:800;margin-bottom:8px}.notes__row{display:flex;justify-content:space-between;gap:24px;padding:6px 0;font-size:13px}.notes__row .amt{font-weight:700;white-space:nowrap}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:22px}.card{border:1px solid var(--line);margin-bottom:22px;min-width:0}.card__head{padding:16px 18px;border-bottom:1px solid var(--line)}.card__head h3{margin:0;font-size:16px}.card__head small{font-size:12px;color:var(--muted)}.table{width:100%;border-collapse:collapse;font-size:12px}.table th{padding:11px 12px;background:var(--canvas);color:var(--muted);font-weight:700;text-align:start}.table td{padding:12px;border-bottom:1px solid var(--line)}.table tr:last-child td{border-bottom:0}.table .num{font-weight:700;font-variant-numeric:tabular-nums}.empty{padding:24px;text-align:center;font-size:13px;color:var(--muted)}.dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-inline-end:6px;background:var(--brand)}.rank{color:var(--muted)}.pill{display:inline-block;padding:2px 7px;border:1px solid var(--line);border-radius:5px;font-size:11px}.muted{color:var(--muted)}.glossary{margin-top:24px;padding:16px 18px;border:1px solid var(--line);font-size:12px;color:var(--muted)}.glossary strong{color:var(--ink)}.signature{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:36px}.signature .box{padding:24px 0 8px;border-bottom:1px solid var(--ink);font-size:13px;color:var(--muted)}.footer-note{margin-top:24px;padding-top:14px;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:11px}
@media(max-width:720px){.action-bar{margin:12px;flex-wrap:wrap}.action-bar__title{flex-basis:100%}.page{margin:12px}.brand{padding:22px 20px 0}.brand__row{flex-wrap:wrap}.brand__meta{width:100%;text-align:start}.body-pad{padding:20px}.brand__period,.kpis,.grid-2{grid-template-columns:1fr 1fr}.stmt__row{grid-template-columns:minmax(0,1fr) 120px 48px;gap:8px;font-size:12px}.kpi__val{font-size:18px}}
@media print{@page{size:A4;margin:12mm}html,body{background:#fff;font-size:11px}.action-bar,.no-print{display:none!important}.page{max-width:100%;margin:0;border:0}.brand{padding:0}.brand h1{font-size:24px}.body-pad{padding:18px 0 0}.brand__period{font-size:10px;gap:8px 18px}.brand__row{flex-wrap:nowrap}.brand__meta{width:auto;text-align:end}.kpi{padding:12px}.kpi__val{font-size:17px}.kpi__lbl{font-size:11px}.kpi__sub{font-size:10px}.section-title{font-size:16px;margin:20px 0 12px}.stmt__row{padding:9px 8px;grid-template-columns:minmax(0,1fr) 130px 50px;font-size:11px}.stmt__row.final{font-size:15px}.table{font-size:10px}.table td,.table th{padding:8px}.brand__period{grid-template-columns:repeat(3,1fr)}.kpis{grid-template-columns:repeat(4,1fr)}.grid-2{grid-template-columns:1fr 1fr}.brand,.kpis,.stmt__row,.card,.notes,.signature,tr{break-inside:avoid}.table thead{display:table-header-group}}
</style>
</head>
<body>

{{-- ═══════════════ ACTION BAR (hidden on print) ═══════════════ --}}
<div class="action-bar">
    <div class="action-bar__title">
        الأرباح والخسائر · {{ $brand['name'] }}
        <small>تقرير الفترة · جاهز للطباعة أو الحفظ PDF</small>
    </div>
    <button type="button" class="btn btn--primary" data-print-document>
        <span>🖨</span>
        <span>اطبع التقرير</span>
    </button>
    <button type="button" class="btn btn--ghost" data-close-document>
        <span>×</span>
        <span>إغلاق</span>
    </button>
</div>

<div class="page">

{{-- ═══════════════ BRAND HEADER (mirrors /portal/order hero) ═════ --}}
<div class="brand">
    <div class="brand__row">
        <div class="brand__logo">{{ mb_substr($brand['name'], 0, 1) }}</div>
        <div class="brand__head">
            <span class="brand__eyebrow">الأرباح والخسائر</span>
            <h1>{{ $brand['name'] }}</h1>
            <div class="brand__sub">
                @if($brand['legal']){{ $brand['legal'] }}@endif
                @if($brand['legal'] && $brand['tax']) · @endif
                @if($brand['tax']) رقم ضريبي {{ $brand['tax'] }} @endif
            </div>
        </div>
        <div class="brand__meta">
            <strong>{{ $branchName }}</strong><br>
            {{ \Carbon\Carbon::parse($period['from'])->locale('ar')->isoFormat('D MMMM YYYY') }}
            —
            {{ \Carbon\Carbon::parse($period['to'])->locale('ar')->isoFormat('D MMMM YYYY') }}<br>
            <span style="font-size:11.5px; opacity:.8;">صدر {{ $generatedAt->locale('ar')->isoFormat('D MMMM YYYY · HH:mm') }}</span>
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
                        <td class="num" style="color: var(--green-deep);">{{ $fmt($it->profit) }}</td>
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

        <dt>الربح من العمليات</dt>
        <dd>= الربح الإجمالي − المصروفات التشغيلية. مؤشر فعالية الإدارة اليومية.</dd>

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

<script>
    document.querySelector('[data-print-document]')?.addEventListener('click', () => window.print());
    document.querySelector('[data-close-document]')?.addEventListener('click', () => window.close());
</script>

</body>
</html>
