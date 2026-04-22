@extends('layouts.admin')
@section('title', 'حركات المخزون')

@section('content')
<x-admin.breadcrumb title="حركات المخزون" icon="bi-boxes"
    subtitle="سجل الإدخالات والإخراجات والهدر والتعديلات" />

<x-admin.stat-rail :stats="[
    ['label' => 'حركات اليوم', 'value' => $stats['today'], 'icon' => 'bi-activity',          'color' => 'primary'],
    ['label' => 'إدخالات',      'value' => $stats['in'],    'icon' => 'bi-box-arrow-in-down', 'color' => 'success'],
    ['label' => 'إخراجات',      'value' => $stats['out'],   'icon' => 'bi-box-arrow-up',      'color' => 'accent'],
    ['label' => 'هدر',           'value' => $stats['waste'], 'icon' => 'bi-trash3-fill',       'color' => 'danger'],
]" />

<x-admin.data-panel title="سجل الحركات" :count="$movements->total()" icon="bi-boxes">
    <x-slot:actions>
        @if(request()->hasAny(['type', 'ingredient_id', 'from', 'to']))
            <a href="{{ route('admin.inventory.index') }}" class="btn btn-light"><i class="bi bi-x-circle"></i> مسح الفلاتر</a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-3">
                <select name="type" class="form-select">
                    <option value="">كل الأنواع</option>
                    <option value="in"         @selected(request('type')==='in')>إضافة</option>
                    <option value="out"        @selected(request('type')==='out')>خصم</option>
                    <option value="waste"      @selected(request('type')==='waste')>هدر</option>
                    <option value="adjustment" @selected(request('type')==='adjustment')>تعديل</option>
                    <option value="return"     @selected(request('type')==='return')>إرجاع</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="ingredient_id" class="form-select">
                    <option value="">كل المكونات</option>
                    @foreach($ingredients as $i)
                        <option value="{{ $i->id }}" @selected(request('ingredient_id')==$i->id)>{{ $i->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control" placeholder="من"></div>
            <div class="col-md-2"><input type="date" name="to"   value="{{ request('to')   }}" class="form-control" placeholder="إلى"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تطبيق</button></div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>التاريخ</th>
                    <th>المكون</th>
                    <th>النوع</th>
                    <th>الكمية</th>
                    <th>قبل</th>
                    <th>بعد</th>
                    <th>التكلفة</th>
                    <th>السبب</th>
                    <th>بواسطة</th>
                </tr>
            </thead>
            <tbody>
                @forelse($movements as $m)
                    <tr>
                        <td>{{ $m->occurred_at->format('Y-m-d H:i') }}</td>
                        <td class="fw-bold">{{ $m->ingredient->name }}</td>
                        <td>
                            @switch($m->type)
                                @case('in')     <span class="badge bg-success">إضافة</span> @break
                                @case('out')    <span class="badge bg-warning">خصم</span> @break
                                @case('waste')  <span class="badge bg-danger">هدر</span> @break
                                @case('return') <span class="badge bg-info">إرجاع</span> @break
                                @default        <span class="badge bg-secondary">{{ $m->type }}</span>
                            @endswitch
                        </td>
                        <td>{{ number_format((float)$m->quantity_in_base, 2) }} {{ $m->ingredient->baseUnit->code ?? '' }}</td>
                        <td>{{ number_format((float)$m->stock_before, 2) }}</td>
                        <td>{{ number_format((float)$m->stock_after, 2) }}</td>
                        <td>{{ number_format((float)$m->total_cost, 4) }}</td>
                        <td>{{ $m->reason }}</td>
                        <td>{{ $m->user?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">
                        <x-admin.empty-state
                            icon="bi-boxes"
                            title="ما في حركات مخزن"
                            message="تظهر هنا كل حركات الإدخال والإخراج والهدر بمجرد حدوثها." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $movements->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
