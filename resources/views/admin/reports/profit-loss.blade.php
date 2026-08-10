@extends('layouts.admin')

@section('title', 'الأرباح والخسائر')

@php
    use App\Helpers\Money;

    $period = $r['period'];
    $sales = $r['sales'];
    $costs = $r['costs'];
    $profit = $r['profit'];
    $source = $period['source'] ?? 'ledger';
    $money = fn ($amount) => $source === 'ledger' ? Money::formatAccounting($amount) : Money::format($amount);
    $exportQs = http_build_query(array_filter([
        'from' => $period['from'],
        'to' => $period['to'],
        'source' => $source !== 'ledger' ? $source : null,
    ]));
@endphp

@section('content')
<x-admin.breadcrumb
    title="الأرباح والخسائر"
    icon="bi-graph-up-arrow"
    subtitle="هل المطعم ربح أم خسر خلال الفترة؟"
    :crumbs="[['label' => 'المحاسبة', 'url' => route('admin.accounting.index')]]" />

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-3">
                <label class="form-label small fw-bold">من</label>
                <input type="date" name="from" value="{{ $period['from'] }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">إلى</label>
                <input type="date" name="to" value="{{ $period['to'] }}" class="form-control">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
            </div>
            <div class="col-md-4 d-flex flex-wrap gap-2">
                <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-outline-secondary">هذا الشهر</a>
                <a href="?from={{ now()->subMonth()->startOfMonth()->toDateString() }}&to={{ now()->subMonth()->endOfMonth()->toDateString() }}" class="btn btn-outline-secondary">الشهر السابق</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="finance-kpi">
            <span>صافي المبيعات</span>
            <strong>{{ $money($sales['net_sales']) }}</strong>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="finance-kpi">
            <span>الربح الإجمالي</span>
            <strong>{{ $money($profit['gross_profit']) }}</strong>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        @php($totalCosts = $costs['cogs'] + $costs['waste'] + $costs['expenses'] + $costs['platform_commission'] + $costs['refunds'])
        <div class="finance-kpi">
            <span>إجمالي التكاليف</span>
            <strong>{{ $money($totalCosts) }}</strong>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="finance-kpi finance-kpi--result {{ $profit['net_profit'] < 0 ? 'is-loss' : '' }}">
            <span>صافي {{ $profit['net_profit'] < 0 ? 'الخسارة' : 'الربح' }}</span>
            <strong>{{ $money(abs($profit['net_profit'])) }}</strong>
            <small>الهامش {{ number_format(abs($profit['net_margin_pct']), 1) }}%</small>
        </div>
    </div>
</div>

<div class="card income-statement mb-3">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-receipt me-2"></i>ملخص الحساب</strong>
        <span class="text-muted small">{{ $period['from'] }} — {{ $period['to'] }}</span>
    </div>
    <div class="card-body p-0">
        <div class="finance-row">
            <span>إجمالي المبيعات</span>
            <strong>{{ $money($sales['gross_sales']) }}</strong>
        </div>
        <div class="finance-row is-deduction">
            <span>الخصومات</span>
            <strong>− {{ $money($sales['discounts_total']) }}</strong>
        </div>
        <div class="finance-row is-total">
            <span>صافي المبيعات</span>
            <strong>{{ $money($sales['net_sales']) }}</strong>
        </div>
        <div class="finance-row is-deduction">
            <span>تكلفة المواد المباعة</span>
            <strong>− {{ $money($costs['cogs']) }}</strong>
        </div>
        @if($costs['waste'] > 0)
            <div class="finance-row is-deduction">
                <span>الهدر</span>
                <strong>− {{ $money($costs['waste']) }}</strong>
            </div>
        @endif
        <div class="finance-row is-total">
            <span>الربح الإجمالي</span>
            <strong>{{ $money($profit['gross_profit']) }}</strong>
        </div>
        <div class="finance-row is-deduction">
            <span>المصاريف التشغيلية</span>
            <strong>− {{ $money($costs['expenses']) }}</strong>
        </div>
        @if($costs['platform_commission'] > 0)
            <div class="finance-row is-deduction">
                <span>عمولات التوصيل</span>
                <strong>− {{ $money($costs['platform_commission']) }}</strong>
            </div>
        @endif
        @if($costs['refunds'] > 0)
            <div class="finance-row is-deduction">
                <span>الاستردادات</span>
                <strong>− {{ $money($costs['refunds']) }}</strong>
            </div>
        @endif
        <div class="finance-row is-final {{ $profit['net_profit'] < 0 ? 'is-loss' : '' }}">
            <span>صافي {{ $profit['net_profit'] < 0 ? 'الخسارة' : 'الربح' }}</span>
            <strong>{{ $money(abs($profit['net_profit'])) }}</strong>
        </div>
    </div>
</div>

<details class="card">
    <summary class="card-header finance-more">
        <span><i class="bi bi-download me-2"></i>تصدير التقرير</span>
        <small>اختياري</small>
    </summary>
    <div class="card-body d-flex flex-wrap gap-2">
        <a href="{{ route('admin.reports.profit-loss.export.xlsx') }}?{{ $exportQs }}" class="btn btn-success">
            <i class="bi bi-file-earmark-excel-fill"></i> Excel
        </a>
        <a href="{{ route('admin.reports.profit-loss.export.pdf') }}?{{ $exportQs }}" target="_blank" class="btn btn-dark">
            <i class="bi bi-printer-fill"></i> طباعة / PDF
        </a>
    </div>
</details>

@push('styles')
<style>
.finance-kpi {
    min-height: 112px;
    display: grid;
    align-content: center;
    gap: .3rem;
    padding: 1rem;
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 10px;
    background: #fff;
}
.finance-kpi span { color: #64748b; font-size: .85rem; font-weight: 800; }
.finance-kpi strong { color: #0f172a; font-size: 1.4rem; }
.finance-kpi small { color: #64748b; }
.finance-kpi--result { border-color: rgba(16, 185, 129, .3); background: rgba(16, 185, 129, .07); }
.finance-kpi--result strong { color: #047857; }
.finance-kpi--result.is-loss { border-color: rgba(239, 68, 68, .3); background: rgba(239, 68, 68, .06); }
.finance-kpi--result.is-loss strong { color: #dc2626; }
.finance-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: .85rem 1rem;
    border-bottom: 1px solid #eef2f7;
}
.finance-row:last-child { border-bottom: 0; }
.finance-row.is-deduction { color: #b91c1c; }
.finance-row.is-total { background: #f8fafc; font-weight: 800; }
.finance-row.is-final { padding: 1rem; background: rgba(16, 185, 129, .08); color: #047857; font-size: 1.08rem; }
.finance-row.is-final.is-loss { background: rgba(239, 68, 68, .07); color: #dc2626; }
.finance-more {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    font-weight: 800;
    list-style: none;
}
.finance-more::-webkit-details-marker { display: none; }
.finance-more small { color: #64748b; font-weight: 500; }
@media print {
    .app-sidebar, .header, .breadcrumb-wrap, form, details { display: none !important; }
    .card, .finance-kpi { box-shadow: none !important; }
}
</style>
@endpush
@endsection
