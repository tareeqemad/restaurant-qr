@extends('layouts.admin')
@section('title', 'المبيعات حسب المنصة')

@section('content')
<x-admin.breadcrumb title="المبيعات حسب المنصة" icon="bi-truck"
    subtitle="توزيع الإيرادات على قنوات البيع (طاولة، Talabat، Careem، ...)"
    :crumbs="[['label' => 'التقارير']]" />

<div class="data-panel-filters mb-3" style="background: white; border-radius: 14px; padding: 1rem; border: 1px solid rgba(var(--primary-rgb),.1);">
    <form class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-muted fw-bold">من</label>
            <input type="date" name="from" value="{{ $from }}" class="form-control">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted fw-bold">إلى</label>
            <input type="date" name="to" value="{{ $to }}" class="form-control">
        </div>
        <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تحديث</button></div>
        <div class="col-md-3">
            <div class="btn-group w-100">
                <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">الشهر</a>
                <a href="?from={{ now()->subDays(7)->toDateString() }}&to={{ now()->toDateString() }}"  class="btn btn-light btn-sm">7 أيام</a>
                <a href="?from={{ now()->toDateString() }}&to={{ now()->toDateString() }}"             class="btn btn-light btn-sm">اليوم</a>
            </div>
        </div>
    </form>
</div>

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي الطلبات',  'value' => $totals['order_count'],                                        'icon' => 'bi-receipt',   'color' => 'primary'],
    ['label' => 'الإيراد الخام',    'value' => \App\Helpers\Money::format($totals['gross_revenue']),          'icon' => 'bi-cash-stack','color' => 'accent'],
    ['label' => 'عمولات المنصات',    'value' => \App\Helpers\Money::format($totals['commission_paid']),        'icon' => 'bi-percent',   'color' => 'danger'],
    ['label' => 'الصافي للمطعم',     'value' => \App\Helpers\Money::format($totals['net_revenue']),           'icon' => 'bi-graph-up-arrow', 'color' => 'success'],
]" />

@if($rows->isEmpty())
    <x-admin.empty-state
        icon="bi-truck"
        title="لا توجد طلبات"
        message="لا توجد مبيعات في الفترة المحددة." />
@else

{{-- Visual pie-ish breakdown --}}
<x-admin.data-panel title="توزيع القنوات" icon="bi-pie-chart-fill">
    <div class="p-3">
        <div class="platform-bars">
            @foreach($rows as $r)
                @php
                    $pct = $totals['gross_revenue'] > 0 ? ((float) $r->gross_revenue / $totals['gross_revenue']) * 100 : 0;
                @endphp
                <div class="platform-row">
                    <div class="platform-head">
                        <div class="platform-chip" style="background: {{ $r->color }};">
                            <i class="bi {{ $r->icon }}"></i>
                            {{ $r->label }}
                        </div>
                        <div class="platform-value">
                            <span class="fw-bold" style="color: var(--primary); font-size: 1.1rem;">{{ \App\Helpers\Money::format($r->gross_revenue) }}</span>
                            <span class="text-muted small"> ({{ number_format($pct, 1) }}%)</span>
                        </div>
                    </div>
                    <div class="platform-bar">
                        <div class="platform-bar-fill" style="width: {{ $pct }}%; background: {{ $r->color }};"></div>
                    </div>
                    <div class="platform-meta">
                        <span class="text-muted small">{{ $r->order_count }} طلب</span>
                        @if($r->avg_commission_pct > 0)
                            <span class="text-muted small">
                                · عمولة {{ number_format($r->avg_commission_pct, 1) }}%
                                · عمولة مدفوعة: <strong class="text-danger">{{ \App\Helpers\Money::format($r->commission_paid) }}</strong>
                                · صافي: <strong style="color: var(--primary);">{{ \App\Helpers\Money::format($r->net_revenue) }}</strong>
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-admin.data-panel>

{{-- Detail table --}}
<x-admin.data-panel title="التفاصيل" icon="bi-table">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>المنصة</th>
                    <th>عدد الطلبات</th>
                    <th>الإيراد الخام</th>
                    <th>متوسط العمولة %</th>
                    <th>العمولة المدفوعة</th>
                    <th>الصافي</th>
                    <th>متوسط قيمة الطلب</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                    <tr>
                        <td>
                            <span class="platform-chip" style="background: {{ $r->color }};">
                                <i class="bi {{ $r->icon }}"></i> {{ $r->label }}
                            </span>
                        </td>
                        <td class="fw-bold">{{ $r->order_count }}</td>
                        <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($r->gross_revenue) }}</td>
                        <td>{{ number_format($r->avg_commission_pct, 1) }}%</td>
                        <td class="fw-bold" style="color: #b91c1c;">{{ \App\Helpers\Money::format($r->commission_paid) }}</td>
                        <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($r->net_revenue) }}</td>
                        <td>{{ \App\Helpers\Money::format($r->order_count > 0 ? $r->gross_revenue / $r->order_count : 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td class="fw-bold">الإجمالي</td>
                    <td class="fw-bold">{{ $totals['order_count'] }}</td>
                    <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($totals['gross_revenue']) }}</td>
                    <td></td>
                    <td class="fw-bold" style="color: #b91c1c;">{{ \App\Helpers\Money::format($totals['commission_paid']) }}</td>
                    <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($totals['net_revenue']) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-admin.data-panel>

@endif

@push('styles')
<style>
.platform-bars { display: flex; flex-direction: column; gap: 1rem; }
.platform-row { display: flex; flex-direction: column; gap: 6px; }
.platform-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.platform-chip {
    color: white;
    padding: 5px 14px;
    border-radius: 99px;
    font-size: .85rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 6px rgba(0,0,0,.15);
}
.platform-bar {
    width: 100%;
    height: 12px;
    background: #f3f4f6;
    border-radius: 99px;
    overflow: hidden;
}
.platform-bar-fill {
    height: 100%;
    border-radius: 99px;
    transition: width .4s ease;
}
.platform-meta { font-size: .8rem; }
</style>
@endpush
@endsection
