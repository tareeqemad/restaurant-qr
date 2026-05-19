@extends('layouts.admin')
@section('title', 'مقارنة الاستهلاك النظري والفعلي')

@section('content')
<x-admin.breadcrumb
    title="مقارنة الاستهلاك النظري والفعلي"
    icon="bi-bar-chart-line"
    subtitle="يكشف الفروقات بين ما تستهلكه الوصفات نظرياً وما تم خصمه فعلياً من المخزن — أداة كشف الهدر أو السرقة" />

<div class="alert alert-info d-flex align-items-start gap-3 mb-3">
    <i class="bi bi-info-circle-fill fs-4 mt-1"></i>
    <div>
        <strong class="d-block mb-1">كيف نقرأ هذا التقرير؟</strong>
        <div class="small">
            <strong>النظري</strong> = الكمية اللي لازم تنخصم حسب وصفات الأصناف المباعة.<br>
            <strong>الفعلي</strong> = الكمية اللي انخصمت فعلاً من المخزن (حركات الطلبات).<br>
            <strong>الفرق</strong> = الفعلي − النظري:
            <span class="text-danger fw-bold">موجب</span> = استهلاك زائد (هدر/سرقة/تقدير وصفة قاصر) ·
            <span class="text-success fw-bold">سالب</span> = استهلاك أقل (وصفة مبالغ فيها أو نقص بالخدمة) ·
            <strong>صفر</strong> = صحي.
        </div>
    </div>
</div>

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label small text-muted fw-bold">من تاريخ</label>
        <input type="date" name="from" value="{{ $from }}" class="form-control">
    </div>
    <div class="col-md-3">
        <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
        <input type="date" name="to" value="{{ $to }}" class="form-control">
    </div>
    <div class="col-md-3">
        <button class="btn btn-primary w-100"><i class="bi bi-search"></i> استعلام</button>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <small class="text-muted d-block mb-1">تكلفة الاستهلاك النظري</small>
                <h4 class="text-primary mb-0">{{ \App\Helpers\Money::format($summary['total_theoretical_cost']) }}</h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <small class="text-muted d-block mb-1">تكلفة الاستهلاك الفعلي</small>
                <h4 class="text-info mb-0">{{ \App\Helpers\Money::format($summary['total_actual_cost']) }}</h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center {{ $summary['total_variance_cost'] > 0.5 ? 'border-danger' : ($summary['total_variance_cost'] < -0.5 ? 'border-warning' : 'border-success') }}">
            <div class="card-body">
                <small class="text-muted d-block mb-1">فرق التكلفة</small>
                <h4 class="mb-0 {{ $summary['total_variance_cost'] > 0.5 ? 'text-danger' : ($summary['total_variance_cost'] < -0.5 ? 'text-warning' : 'text-success') }}">
                    {{ $summary['total_variance_cost'] >= 0 ? '+' : '' }}{{ \App\Helpers\Money::format($summary['total_variance_cost']) }}
                </h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <small class="text-muted d-block mb-1">قيمة الهدر الموثّق</small>
                <h4 class="text-warning mb-0">{{ \App\Helpers\Money::format($summary['total_waste_cost']) }}</h4>
            </div>
        </div>
    </div>
</div>

<x-admin.data-panel title="التفاصيل لكل مكوّن" icon="bi-list-columns"
                    :count="count($rows)">
    @if(count($rows))
        <div class="alert alert-light border mb-2 small">
            <i class="bi bi-info-circle"></i>
            في الفترة المختارة: <strong>{{ $summary['items_in_range'] }}</strong> صنف مباع
            ضمن <strong>{{ $summary['orders_in_range'] }}</strong> طلب.
            القائمة مرتّبة حسب أكبر فرق تكلفة.
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>المكوّن</th>
                        <th class="text-end">النظري</th>
                        <th class="text-end">الفعلي</th>
                        <th class="text-end">الهدر</th>
                        <th class="text-end">الفرق</th>
                        <th class="text-end">% الفرق</th>
                        <th class="text-end">تكلفة الفرق</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        @php
                            $vCost = (float) $r['variance_cost'];
                            $vClass = abs($vCost) < 0.5 ? '' : ($vCost > 0 ? 'table-danger' : 'table-warning');
                        @endphp
                        <tr class="{{ $vClass }}">
                            <td>
                                <strong>{{ $r['ingredient']->name }}</strong>
                                @if($r['ingredient']->is_composite)
                                    <span class="badge bg-info ms-1" title="مكوّن مركّب — يُحلَّل تلقائياً">مركّب</span>
                                @endif
                                <small class="text-muted d-block">{{ $r['ingredient']->sku }}</small>
                            </td>
                            <td class="text-end">{{ number_format($r['theoretical'], 2) }} {{ $r['unit_code'] }}</td>
                            <td class="text-end">{{ number_format($r['actual'], 2) }} {{ $r['unit_code'] }}</td>
                            <td class="text-end text-muted">{{ number_format($r['waste'], 2) }} {{ $r['unit_code'] }}</td>
                            <td class="text-end fw-bold {{ $r['variance'] > 0 ? 'text-danger' : ($r['variance'] < 0 ? 'text-warning' : 'text-muted') }}">
                                {{ $r['variance'] > 0 ? '+' : '' }}{{ number_format($r['variance'], 2) }} {{ $r['unit_code'] }}
                            </td>
                            <td class="text-end">
                                @if($r['variance_pct'] !== null)
                                    <span class="badge {{ abs($r['variance_pct']) < 5 ? 'bg-success' : (abs($r['variance_pct']) < 15 ? 'bg-warning' : 'bg-danger') }}">
                                        {{ $r['variance_pct'] > 0 ? '+' : '' }}{{ $r['variance_pct'] }}%
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end fw-bold {{ $vCost > 0 ? 'text-danger' : ($vCost < 0 ? 'text-warning' : '') }}">
                                {{ $vCost >= 0 ? '+' : '' }}{{ \App\Helpers\Money::format($vCost) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-center py-4 text-muted">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
            لا حركات استهلاك أو مبيعات في هذه الفترة.
        </div>
    @endif
</x-admin.data-panel>
@endsection
