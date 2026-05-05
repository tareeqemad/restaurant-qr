@extends('layouts.admin')
@section('title','تقرير نهاية اليوم')
@section('content')
<x-admin.breadcrumb title="تقرير نهاية اليوم — {{ $date }}" icon="bi-calendar-check"
    subtitle="ملخص شامل للمبيعات والنقدية والخصومات"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]">
    <x-slot:actions>
        <button type="button" class="btn btn-light" onclick="window.print()">
            <i class="bi bi-printer"></i> طباعة
        </button>
    </x-slot:actions>
</x-admin.breadcrumb>

<div class="data-panel-filters mb-3" style="background: white; border-radius: 14px; padding: 1rem; border: 1px solid rgba(var(--primary-rgb), .1);">
    <form class="row g-2 align-items-end">
        <div class="col-md-4 mx-auto">
            <label class="form-label small text-muted fw-bold">تاريخ الإقفال</label>
            <input type="date" name="date" value="{{ $date }}" class="form-control">
        </div>
        <div class="col-12 text-center mt-2">
            <button class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
        </div>
    </form>
</div>

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي المقبوض', 'value' => \App\Helpers\Money::format($summary['total_collected']), 'icon' => 'bi-wallet2', 'color' => 'success'],
    ['label' => 'إجمالي الفواتير', 'value' => \App\Helpers\Money::format($summary['total_billed']), 'icon' => 'bi-receipt', 'color' => 'primary'],
    ['label' => 'المبيعات بدون ضريبة', 'value' => \App\Helpers\Money::format($summary['gross_sales']), 'icon' => 'bi-cash-stack', 'color' => 'accent'],
    ['label' => 'عدد الطلبات', 'value' => $summary['orders_count'], 'icon' => 'bi-bag-check', 'color' => $summary['orders_cancelled'] > 0 ? 'warning' : 'info'],
    ['label' => 'الفواتير المدفوعة', 'value' => $summary['invoices_paid'].' / '.$summary['invoices_count'], 'icon' => 'bi-check-circle', 'color' => 'success'],
    ['label' => 'الخصومات', 'value' => \App\Helpers\Money::format($summary['discount_total']), 'icon' => 'bi-tag', 'color' => $summary['discount_total'] > 0 ? 'warning' : 'muted'],
]" />

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header"><strong><i class="bi bi-cash"></i> الدفعات حسب الطريقة</strong></div>
        <div class="card-body p-0">
            <table class="table mb-0"><thead class="bg-light"><tr><th>الطريقة</th><th>عدد</th><th>الإجمالي</th></tr></thead><tbody>
                @forelse($byMethod as $method => $data)
                    <tr><td>
                        @switch($method)@case('cash')كاش @break @case('card')كارد @break @case('transfer')حوالة @break @case('app')تطبيق @break @case('credit')دين @break @endswitch
                    </td><td>{{ $data['count'] }}</td><td class="fw-bold">{{ \App\Helpers\Money::format($data['total']) }}</td></tr>
                @empty<tr><td colspan="3" class="text-center text-muted py-3">لا دفعات</td></tr>@endforelse
                </tbody><tfoot class="bg-light fw-bold"><tr><td>الإجمالي</td><td>{{ $byMethod->sum('count') }}</td><td>{{ \App\Helpers\Money::format($byMethod->sum('total')) }}</td></tr></tfoot>
            </table>
        </div></div>

        <div class="card mb-3"><div class="card-header"><strong><i class="bi bi-receipt"></i> حالات الفواتير</strong></div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between"><span>مدفوعة كاملة</span><strong class="text-success">{{ $summary['invoices_paid'] }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>غير مدفوعة/جزئية</span><strong class="text-warning">{{ $summary['invoices_unpaid'] }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>شطب</span><strong class="text-dark">{{ $summary['invoices_writeoff'] }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>الخصومات</span><strong>{{ \App\Helpers\Money::format($summary['discount_total']) }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>الضريبة المحصّلة</span><strong>{{ \App\Helpers\Money::format($summary['tax_total']) }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>رسوم الخدمة</span><strong>{{ \App\Helpers\Money::format($summary['service_total']) }}</strong></li>
        </ul></div>

        @if($shifts->count())
            <div class="card"><div class="card-header"><strong><i class="bi bi-clock"></i> ورديات اليوم</strong></div>
            <table class="table mb-0"><thead class="bg-light"><tr><th>موظف</th><th>الفترة</th><th>الإجمالي</th><th>الفرق</th></tr></thead><tbody>
            @foreach($shifts as $s)
                <tr><td>{{ $s->user->name }}</td>
                    <td><small>{{ $s->opened_at->format('H:i') }} - {{ $s->closed_at?->format('H:i') ?? 'مفتوح' }}</small></td>
                    <td class="fw-bold">{{ \App\Helpers\Money::format($s->total_sales) }}</td>
                    <td class="{{ (float)$s->cash_variance < 0 ? 'text-danger' : 'text-success' }}">{{ number_format((float)$s->cash_variance, 2) }}</td>
                </tr>
            @endforeach
            </tbody></table></div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card"><div class="card-header"><strong><i class="bi bi-trophy"></i> أكثر الأصناف مبيعاً</strong></div>
        <div class="card-body p-0">
        <table class="table mb-0"><thead class="bg-light"><tr><th>#</th><th>الصنف</th><th>كمية</th><th>إجمالي</th></tr></thead><tbody>
            @forelse($topItems as $i => $r)
                <tr><td>{{ $i + 1 }}</td><td class="fw-bold">{{ $r->name_snapshot }}</td><td>{{ number_format((float)$r->qty, 1) }}</td><td>{{ \App\Helpers\Money::format($r->total) }}</td></tr>
            @empty<tr><td colspan="4" class="text-center text-muted py-3">—</td></tr>@endforelse
        </tbody></table>
        </div></div>
    </div>

    <div class="col-lg-3">
        <div class="card"><div class="card-header"><strong><i class="bi bi-boxes"></i> استهلاك المخزون</strong></div>
        <div class="card-body p-0">
        <table class="table mb-0"><thead class="bg-light"><tr><th>المكون</th><th>كمية</th><th>تكلفة</th></tr></thead><tbody>
            @php $totalCost = 0; @endphp
            @forelse($inventoryUsage as $name => $rows)
                @php
                    $qty = $rows->sum('qty');
                    $cost = $rows->sum('cost');
                    $totalCost += $cost;
                @endphp
                <tr><td class="small">{{ $name }}</td><td>{{ number_format((float)$qty, 1) }}</td><td class="small">{{ number_format((float)$cost, 2) }}</td></tr>
            @empty<tr><td colspan="3" class="text-center text-muted py-3">—</td></tr>@endforelse
        </tbody>
        @if($totalCost > 0)
            <tfoot class="bg-light"><tr><td colspan="2"><strong>تكلفة الاستهلاك</strong></td><td class="fw-bold">{{ number_format($totalCost, 2) }}</td></tr></tfoot>
        @endif
        </table>
        </div></div>
    </div>
</div>
@endsection
