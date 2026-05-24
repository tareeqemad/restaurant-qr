@extends('layouts.admin')
@section('title', 'لوحة التقارير')

@php
    $maxTrend = max(1, (float) $trend->max('revenue'));
    $reportTiles = [
        [
            'title' => 'نهاية اليوم',
            'desc' => 'إقفال اليوم: المقبوض، حالات الفواتير، الورديات، أكثر الأصناف، واستهلاك المخزون.',
            'icon' => 'bi-calendar-check-fill',
            'href' => route('admin.reports.end-of-day'),
            'tone' => 'primary',
        ],
        [
            'title' => 'قائمة الدخل',
            'desc' => 'قراءة الربح الحقيقي بعد تكلفة المبيعات والهدر، مع أكثر الأصناف ربحية.',
            'icon' => 'bi-graph-up-arrow',
            'href' => route('admin.reports.profit-loss', compact('from', 'to')),
            'tone' => 'success',
        ],
        [
            'title' => 'ميزان المراجعة',
            'desc' => 'تحقق محاسبي يثبت أن مجموع المدين = مجموع الدائن لكل القيود اليومية.',
            'icon' => 'bi-columns-gap',
            'href' => route('admin.accounting.trial-balance'),
            'tone' => 'info',
        ],
        [
            'title' => 'هندسة المنيو',
            'desc' => 'صنّف الأصناف حسب الشعبية والربحية لتقرر التسعير، الترويج، أو الإيقاف.',
            'icon' => 'bi-diagram-3-fill',
            'href' => route('admin.reports.menu-engineering', compact('from', 'to')),
            'tone' => 'accent',
        ],
        [
            'title' => 'اقتراحات الشراء',
            'desc' => 'ماذا يجب شراؤه الآن، الكمية المقترحة، تكلفة الطلب، وأيام النفاد.',
            'icon' => 'bi-cart-plus-fill',
            'href' => route('admin.reports.reorder-suggestions'),
            'tone' => 'warning',
        ],
        [
            'title' => 'مخزون ومشتريات',
            'desc' => 'مركز إجراءات للنواقص، الاستلامات، فواتير الموردين، والفروقات بين الفاتورة والاستلام.',
            'icon' => 'bi-speedometer2',
            'href' => route('admin.inventory.dashboard'),
            'tone' => 'success',
        ],
        [
            'title' => 'تقييم المخزون',
            'desc' => 'قيمة المخزون الحالية أو التاريخية مع ABC لتحديد أولويات الجرد.',
            'icon' => 'bi-cash-stack',
            'href' => route('admin.reports.stock-valuation'),
            'tone' => 'danger',
        ],
        [
            'title' => 'المبيعات اليومية',
            'desc' => 'خط يومي للإيراد والفواتير والتحصيل ضمن الفترة المختارة.',
            'icon' => 'bi-bar-chart-line-fill',
            'href' => route('admin.reports.sales', compact('from', 'to')),
            'tone' => 'primary',
        ],
        [
            'title' => 'الأصناف والمخزون',
            'desc' => 'الأكثر بيعاً، استهلاك المكونات، والورديات في مكان واحد للمراجعة السريعة.',
            'icon' => 'bi-grid-1x2-fill',
            'href' => route('admin.reports.items', compact('from', 'to')),
            'tone' => 'muted',
        ],
    ];
@endphp

@section('content')
<x-admin.breadcrumb
    title="لوحة التقارير"
    icon="bi-speedometer2"
    subtitle="مركز قراءة سريع يحوّل أرقام المطعم إلى قرارات تشغيلية." />

<div class="data-panel-filters mb-3" style="background: white; border-radius: 14px; padding: 1rem; border: 1px solid rgba(var(--primary-rgb), .1);">
    <form class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-muted fw-bold">من تاريخ</label>
            <input type="date" name="from" value="{{ $from }}" class="form-control">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
            <input type="date" name="to" value="{{ $to }}" class="form-control">
        </div>
        <div class="col-md-6">
            <label class="form-label small text-muted fw-bold">اختصارات</label>
            <div class="btn-group w-100">
                <a href="?from={{ now()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">اليوم</a>
                <a href="?from={{ now()->subDays(6)->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">7 أيام</a>
                <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">الشهر</a>
            </div>
        </div>
        <div class="col-12 text-center mt-2">
            <button class="btn btn-primary px-5">
                <i class="bi bi-search"></i> استعلام
            </button>
        </div>
    </form>
</div>

<x-admin.stat-rail :stats="[
    ['label' => 'الإيراد المحصل', 'value' => \App\Helpers\Money::format($revenue), 'icon' => 'bi-cash-stack', 'color' => 'primary'],
    ['label' => 'الربح الإجمالي', 'value' => \App\Helpers\Money::format($grossProfit), 'icon' => 'bi-graph-up-arrow', 'color' => $grossProfit >= 0 ? 'success' : 'danger'],
    ['label' => 'هامش الربح', 'value' => number_format($marginPct, 1).'%', 'icon' => 'bi-percent', 'color' => $marginPct >= 45 ? 'success' : ($marginPct >= 30 ? 'accent' : 'danger')],
    ['label' => 'متوسط الفاتورة', 'value' => \App\Helpers\Money::format($averageTicket), 'icon' => 'bi-receipt', 'color' => 'accent'],
    ['label' => 'طلبات الفترة', 'value' => $ordersCount, 'icon' => 'bi-bag-check', 'color' => 'info'],
    ['label' => 'الهدر', 'value' => \App\Helpers\Money::format($wasteCost), 'icon' => 'bi-trash3', 'color' => $wasteCost > 0 ? 'warning' : 'muted'],
]" />

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <x-admin.data-panel title="نبض الإيراد اليومي" icon="bi-activity">
            <div class="reports-trend">
                @foreach($trend as $day)
                    @php $height = $maxTrend > 0 ? max(6, ($day->revenue / $maxTrend) * 100) : 6; @endphp
                    <div class="reports-trend-day" title="{{ $day->day }} - {{ \App\Helpers\Money::format($day->revenue) }}">
                        <div class="reports-trend-bar-wrap">
                            <div class="reports-trend-bar" style="height: {{ $height }}%;"></div>
                        </div>
                        <span>{{ $day->label }}</span>
                    </div>
                @endforeach
            </div>
            <div class="d-flex justify-content-between flex-wrap gap-2 mt-3 small text-muted">
                <span>متوسط اليوم: <strong style="color: var(--primary);">{{ \App\Helpers\Money::format($dailyAverage) }}</strong></span>
                <span>عدد الفواتير: <strong style="color: var(--primary);">{{ $invoiceCount }}</strong></span>
                <span>عمولات المنصات: <strong class="text-danger">{{ \App\Helpers\Money::format($commissionPaid) }}</strong></span>
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-xl-4">
        <x-admin.data-panel title="تنبيهات تنفيذية" icon="bi-lightning-charge-fill">
            <div class="reports-alerts">
                @foreach($alerts as $alert)
                    <a class="reports-alert reports-alert-{{ $alert['tone'] }}" href="{{ $alert['link'] ?: 'javascript:void(0)' }}">
                        <i class="bi {{ $alert['icon'] }}"></i>
                        <span>
                            <strong>{{ $alert['title'] }}</strong>
                            <small>{{ $alert['body'] }}</small>
                        </span>
                    </a>
                @endforeach
            </div>
        </x-admin.data-panel>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <x-admin.data-panel title="خارطة التقارير" icon="bi-compass-fill">
            <div class="report-tile-grid">
                @foreach($reportTiles as $tile)
                    <a class="report-tile report-tile-{{ $tile['tone'] }}" href="{{ $tile['href'] }}">
                        <span class="report-tile-icon"><i class="bi {{ $tile['icon'] }}"></i></span>
                        <span class="report-tile-body">
                            <strong>{{ $tile['title'] }}</strong>
                            <small>{{ $tile['desc'] }}</small>
                        </span>
                        <i class="bi bi-arrow-left report-tile-arrow"></i>
                    </a>
                @endforeach
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-xl-5">
        <x-admin.data-panel title="أصناف تصنع الربح" icon="bi-trophy-fill" :count="$topItems->count()">
            @if($topItems->isEmpty())
                <x-admin.empty-state
                    icon="bi-trophy"
                    title="لا توجد مبيعات أصناف"
                    message="اختر فترة فيها طلبات مكتملة لتظهر مساهمة الأصناف." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>الصنف</th>
                                <th class="text-end">الكمية</th>
                                <th class="text-end">الإيراد</th>
                                <th class="text-end">الربح</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topItems as $item)
                                <tr>
                                    <td class="fw-bold">{{ $item->name }}</td>
                                    <td class="text-end">{{ number_format((float) $item->qty, 0) }}</td>
                                    <td class="text-end">{{ \App\Helpers\Money::format($item->revenue) }}</td>
                                    <td class="text-end fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($item->profit) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>
</div>

<x-admin.data-panel title="مزيج قنوات البيع" icon="bi-diagram-3-fill" :count="$sourceMix->count()">
    @if($sourceMix->isEmpty())
        <x-admin.empty-state
            icon="bi-bag"
            title="لا توجد قنوات بيع"
            message="لا يوجد طلبات ضمن الفترة المختارة." />
    @else
        <div class="source-mix-grid">
            @foreach($sourceMix as $source)
                @php
                    $pct = $revenue > 0 ? ((float) $source->revenue / $revenue) * 100 : 0;
                @endphp
                <div class="source-mix-row">
                    <div class="source-mix-head">
                        <span class="source-chip" style="background: {{ $source->color }};">
                            <i class="bi {{ $source->icon }}"></i>
                            {{ $source->label }}
                        </span>
                        <strong>{{ \App\Helpers\Money::format($source->revenue) }}</strong>
                    </div>
                    <div class="source-mix-bar">
                        <span style="width: {{ min(100, $pct) }}%; background: {{ $source->color }};"></span>
                    </div>
                    <small class="text-muted">{{ $source->orders_count }} طلب · {{ number_format($pct, 1) }}% من الإيراد المحصل</small>
                </div>
            @endforeach
        </div>
    @endif
</x-admin.data-panel>

@push('styles')
<style>
.reports-trend {
    min-height: 260px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(34px, 1fr));
    gap: 8px;
    align-items: end;
}
.reports-trend-day {
    min-width: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}
.reports-trend-bar-wrap {
    width: 100%;
    height: 220px;
    border-radius: 8px;
    background: linear-gradient(180deg, rgba(var(--primary-rgb), .05), rgba(var(--accent-rgb), .08));
    display: flex;
    align-items: end;
    overflow: hidden;
}
.reports-trend-bar {
    width: 100%;
    min-height: 6px;
    background: linear-gradient(180deg, var(--primary), var(--accent));
    border-radius: 8px 8px 0 0;
}
.reports-trend-day span {
    font-size: .72rem;
    font-weight: 700;
    color: #78716c;
}
.reports-alerts,
.report-tile-grid,
.source-mix-grid {
    display: grid;
    gap: .75rem;
}
.reports-alert,
.report-tile {
    text-decoration: none;
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 8px;
    padding: .85rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    background: #fff;
    transition: transform .15s ease, border-color .15s ease;
}
.reports-alert:hover,
.report-tile:hover {
    transform: translateY(-1px);
    border-color: rgba(var(--accent-rgb), .45);
}
.reports-alert > i,
.report-tile-icon {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 38px;
    background: rgba(var(--primary-rgb), .08);
    color: var(--primary);
    font-size: 1.05rem;
}
.reports-alert span,
.report-tile-body {
    min-width: 0;
    display: grid;
    gap: 2px;
}
.reports-alert strong,
.report-tile strong {
    color: var(--primary);
}
.reports-alert small,
.report-tile small {
    color: #6b7280;
    line-height: 1.45;
}
.reports-alert-danger > i { color: #b91c1c; background: rgba(185, 28, 28, .08); }
.reports-alert-warning > i { color: #8a6920; background: rgba(var(--accent-rgb), .12); }
.reports-alert-info > i { color: #0369a1; background: rgba(3, 105, 161, .08); }
.reports-alert-success > i { color: #15803d; background: rgba(21, 128, 61, .08); }
.report-tile-grid {
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
}
.report-tile-arrow {
    margin-inline-start: auto;
    color: #94a3b8;
}
.source-mix-row {
    border: 1px solid rgba(var(--primary-rgb), .1);
    border-radius: 8px;
    padding: .8rem;
}
.source-mix-head {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    align-items: center;
}
.source-chip {
    color: white;
    padding: 5px 12px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 800;
    font-size: .85rem;
}
.source-mix-bar {
    height: 9px;
    border-radius: 99px;
    background: #eef2f7;
    overflow: hidden;
    margin: .65rem 0 .35rem;
}
.source-mix-bar span {
    display: block;
    height: 100%;
    border-radius: inherit;
}
</style>
@endpush
@endsection
