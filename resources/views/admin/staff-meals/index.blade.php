@extends('layouts.admin')
@section('title', 'بدل وجبات الموظفين')

@section('content')
<x-admin.breadcrumb
    title="بدل وجبات الموظفين"
    icon="bi-cup-hot-fill"
    :subtitle="'شهر ' . $month->translatedFormat('F Y')">
    <x-slot:actions>
        <a href="{{ route('admin.staff-meals.quick_consume') }}" class="btn btn-warning">
            <i class="bi bi-cup-straw"></i> استهلاك سريع
        </a>
    </x-slot:actions>
</x-admin.breadcrumb>

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label small text-muted fw-bold">الشهر</label>
        <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-control">
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary w-100"><i class="bi bi-search"></i> عرض</button>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card text-center"><div class="card-body">
            <small class="text-muted d-block">موظفون لهم بدل</small>
            <h4 class="mb-0 text-primary">{{ $totals['staff_count'] }}</h4>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card text-center"><div class="card-body">
            <small class="text-muted d-block">إجمالي الحدود</small>
            <h4 class="mb-0">{{ \App\Helpers\Money::format($totals['total_allowance']) }}</h4>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card text-center"><div class="card-body">
            <small class="text-muted d-block">استهلاك الشهر</small>
            <h4 class="mb-0 text-accent">{{ \App\Helpers\Money::format($totals['total_used']) }}</h4>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card text-center {{ $totals['over_limit_count'] > 0 ? 'border-danger' : '' }}"><div class="card-body">
            <small class="text-muted d-block">متجاوزون الحد</small>
            <h4 class="mb-0 {{ $totals['over_limit_count'] > 0 ? 'text-danger' : 'text-success' }}">
                {{ $totals['over_limit_count'] }}
            </h4>
        </div></div>
    </div>
</div>

<x-admin.data-panel title="حسابات الموظفين" icon="bi-people-fill" :count="$rows->count()">
    @if($rows->count())
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الموظف</th>
                        <th class="text-end">الحد الشهري</th>
                        <th class="text-end">المُستهلك هذا الشهر</th>
                        <th class="text-end">المتبقي</th>
                        <th class="text-end">التجاوز</th>
                        <th class="text-end">إجمالي المستحق</th>
                        <th class="text-center" style="width:170px">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        @php
                            $s = $r['summary'];
                            $over = $s['overflow'] > 0;
                            $rowClass = $over ? 'table-danger' : ($s['used'] > 0 ? '' : 'text-muted');
                        @endphp
                        <tr class="{{ $rowClass }}">
                            <td>
                                <strong>{{ $r['user']->name }}</strong>
                                <small class="text-muted d-block">{{ $r['user']->role }}</small>
                            </td>
                            <td class="text-end">{{ \App\Helpers\Money::format($s['allowance']) }}</td>
                            <td class="text-end fw-bold">{{ \App\Helpers\Money::format($s['used']) }}</td>
                            <td class="text-end fw-bold {{ ($s['remaining'] ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                                {{ \App\Helpers\Money::format($s['remaining'] ?? 0) }}
                            </td>
                            <td class="text-end fw-bold text-danger">
                                @if($over) +{{ \App\Helpers\Money::format($s['overflow']) }} @else — @endif
                            </td>
                            <td class="text-end fw-bold text-warning">{{ \App\Helpers\Money::format($s['outstanding']) }}</td>
                            <td class="text-center">
                                <a href="{{ route('admin.staff-meals.show', $r['user']) }}" class="btn btn-sm btn-primary">
                                    <i class="bi bi-list-ul"></i> تفاصيل + تسوية
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-center py-5 text-muted">
            <i class="bi bi-cup-hot fs-1 d-block mb-2"></i>
            لا موظفين لهم بدل وجبات مفعّل. عدّل ملف الموظف وضِف قيمة لـ"بدل الوجبات الشهري".
        </div>
    @endif
</x-admin.data-panel>
@endsection
