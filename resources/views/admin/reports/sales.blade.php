@extends('layouts.admin')
@section('title', 'تقرير المبيعات')

@php
    $maxPaid = max(1, (float) $rows->max('paid'));
@endphp

@section('content')
<x-admin.breadcrumb title="المبيعات اليومية" icon="bi-cash-coin"
    subtitle="قراءة يومية للتحصيل والفواتير والفجوة بين المفوتر والمقبوض"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

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
    ['label' => 'المقبوض', 'value' => \App\Helpers\Money::format($totals['paid']), 'icon' => 'bi-wallet2', 'color' => 'success'],
    ['label' => 'المفوتر', 'value' => \App\Helpers\Money::format($totals['total']), 'icon' => 'bi-receipt', 'color' => 'primary'],
    ['label' => 'نسبة التحصيل', 'value' => number_format($collectionRate, 1).'%', 'icon' => 'bi-check2-circle', 'color' => $collectionRate >= 95 ? 'success' : ($collectionRate >= 80 ? 'accent' : 'danger')],
    ['label' => 'متوسط اليوم', 'value' => \App\Helpers\Money::format($averageDaily), 'icon' => 'bi-calendar2-week', 'color' => 'accent'],
    ['label' => 'متوسط الفاتورة', 'value' => \App\Helpers\Money::format($averageInvoice), 'icon' => 'bi-calculator', 'color' => 'info'],
    ['label' => 'غير محصل', 'value' => \App\Helpers\Money::format($collectionGap), 'icon' => 'bi-exclamation-circle', 'color' => $collectionGap > 0 ? 'warning' : 'muted'],
]" />

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <x-admin.data-panel title="خط الأيام" icon="bi-bar-chart-line-fill">
            @if($rows->isEmpty())
                <x-admin.empty-state
                    icon="bi-cash-coin"
                    title="ما في مبيعات"
                    message="لا يوجد بيانات مبيعات ضمن الفترة المختارة." />
            @else
                <div class="sales-pulse">
                    @foreach($rows->sortBy('day') as $r)
                        @php $height = max(8, ((float) $r->paid / $maxPaid) * 100); @endphp
                        <div class="sales-pulse-day" title="{{ $r->day }} - {{ \App\Helpers\Money::format($r->paid) }}">
                            <div class="sales-pulse-bar-wrap">
                                <div class="sales-pulse-bar" style="height: {{ $height }}%;"></div>
                            </div>
                            <span>{{ \Carbon\Carbon::parse($r->day)->format('d/m') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.data-panel>
    </div>

    <div class="col-lg-4">
        <x-admin.data-panel title="قراءة سريعة" icon="bi-lightbulb-fill">
            <div class="sales-insights">
                <div class="sales-insight">
                    <span>أفضل يوم</span>
                    <strong>{{ $bestDay?->day ? \Carbon\Carbon::parse($bestDay->day)->format('Y-m-d') : '—' }}</strong>
                    <small>{{ $bestDay ? \App\Helpers\Money::format($bestDay->paid) : 'لا يوجد بيانات' }}</small>
                </div>
                <div class="sales-insight">
                    <span>أهدأ يوم</span>
                    <strong>{{ $quietDay?->day ? \Carbon\Carbon::parse($quietDay->day)->format('Y-m-d') : '—' }}</strong>
                    <small>{{ $quietDay ? \App\Helpers\Money::format($quietDay->paid) : 'لا يوجد بيانات' }}</small>
                </div>
                <div class="sales-insight">
                    <span>ضريبة وخدمة</span>
                    <strong>{{ \App\Helpers\Money::format($totals['tax'] + $totals['service']) }}</strong>
                    <small>ضمن إجمالي الفترة</small>
                </div>
            </div>
        </x-admin.data-panel>
    </div>
</div>

<x-admin.data-panel title="تفاصيل المبيعات اليومية" icon="bi-table" :count="$rows->count()">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>اليوم</th>
                    <th>فواتير</th>
                    <th>فرعي</th>
                    <th>ضريبة</th>
                    <th>خدمة</th>
                    <th>الإجمالي</th>
                    <th>المدفوع</th>
                    <th>تحصيل</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    @php
                        $rate = (float) $r->total > 0 ? ((float) $r->paid / (float) $r->total) * 100 : 0;
                    @endphp
                    <tr>
                        <td class="fw-bold">{{ $r->day }}</td>
                        <td>{{ $r->invoices_count }}</td>
                        <td>{{ \App\Helpers\Money::format($r->subtotal) }}</td>
                        <td>{{ \App\Helpers\Money::format($r->tax) }}</td>
                        <td>{{ \App\Helpers\Money::format($r->service) }}</td>
                        <td class="fw-bold">{{ \App\Helpers\Money::format($r->total) }}</td>
                        <td class="text-success fw-bold">{{ \App\Helpers\Money::format($r->paid) }}</td>
                        <td>
                            <span class="badge bg-{{ $rate >= 95 ? 'success' : ($rate >= 80 ? 'warning' : 'danger') }}-transparent text-{{ $rate >= 95 ? 'success' : ($rate >= 80 ? 'warning' : 'danger') }}">
                                {{ number_format($rate, 1) }}%
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">
                        <x-admin.empty-state
                            icon="bi-cash-coin"
                            title="ما في مبيعات"
                            message="لا يوجد بيانات مبيعات ضمن الفترة المختارة." />
                    </td></tr>
                @endforelse
            </tbody>
            @if($rows->count())
                <tfoot class="bg-light fw-bold">
                    <tr>
                        <td>المجموع</td>
                        <td>{{ $totals['invoices_count'] }}</td>
                        <td>{{ \App\Helpers\Money::format($totals['subtotal']) }}</td>
                        <td>{{ \App\Helpers\Money::format($totals['tax']) }}</td>
                        <td>{{ \App\Helpers\Money::format($totals['service']) }}</td>
                        <td>{{ \App\Helpers\Money::format($totals['total']) }}</td>
                        <td class="text-success">{{ \App\Helpers\Money::format($totals['paid']) }}</td>
                        <td>{{ number_format($collectionRate, 1) }}%</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-admin.data-panel>

@push('styles')
<style>
.sales-pulse {
    min-height: 260px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(34px, 1fr));
    gap: 8px;
    align-items: end;
}
.sales-pulse-day {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    min-width: 0;
}
.sales-pulse-bar-wrap {
    width: 100%;
    height: 225px;
    border-radius: 8px;
    background: rgba(var(--primary-rgb), .06);
    display: flex;
    align-items: end;
    overflow: hidden;
}
.sales-pulse-bar {
    width: 100%;
    min-height: 8px;
    background: linear-gradient(180deg, var(--primary), var(--accent));
    border-radius: 8px 8px 0 0;
}
.sales-pulse-day span {
    font-size: .72rem;
    font-weight: 700;
    color: #78716c;
}
.sales-insights {
    display: grid;
    gap: .75rem;
}
.sales-insight {
    border: 1px solid rgba(var(--primary-rgb), .1);
    border-radius: 8px;
    padding: .85rem;
    display: grid;
    gap: 2px;
    background: #fff;
}
.sales-insight span {
    color: #78716c;
    font-size: .8rem;
    font-weight: 800;
}
.sales-insight strong {
    color: var(--primary);
    font-size: 1.05rem;
}
.sales-insight small {
    color: #64748b;
}
</style>
@endpush
@endsection
