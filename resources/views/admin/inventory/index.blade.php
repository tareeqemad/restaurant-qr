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
    ['label' => 'إرجاع',         'value' => $stats['return'], 'icon' => 'bi-arrow-counterclockwise', 'color' => 'info'],
    ['label' => 'تعديل',         'value' => $stats['adjustment'], 'icon' => 'bi-sliders2', 'color' => 'secondary'],
]" />

<x-admin.data-panel title="سجل الحركات" :count="$movements->total()" icon="bi-boxes">
    <x-slot:actions>
        @if(request()->hasAny(['type', 'ingredient_id', 'storage_location_id', 'reference_type', 'from', 'to']))
            <a href="{{ route('admin.inventory.index') }}" class="btn btn-light"><i class="bi bi-x-circle"></i> مسح الفلاتر</a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2 align-items-end">
            <div class="col-md-2">
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
                <select name="ingredient_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن مكوّن...">
                    <option value="">كل المكونات</option>
                    @foreach($ingredients as $i)
                        <option value="{{ $i->id }}" @selected(request('ingredient_id')==$i->id)>{{ $i->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="storage_location_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن موقع...">
                    <option value="">كل المواقع</option>
                    @foreach($storageLocations as $location)
                        <option value="{{ $location->id }}" @selected(request('storage_location_id')==$location->id)>
                            {{ $location->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="reference_type" class="form-select">
                    <option value="">كل المصادر</option>
                    @foreach($referenceTypes as $class => $label)
                        <option value="{{ $class }}" @selected(request('reference_type')===$class)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1"><input type="date" name="from" value="{{ request('from') }}" class="form-control" placeholder="من"></div>
            <div class="col-md-1"><input type="date" name="to"   value="{{ request('to')   }}" class="form-control" placeholder="إلى"></div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
            </div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>التاريخ</th>
                    <th>المكون</th>
                    <th>النوع</th>
                    <th>الموقع</th>
                    <th>الدفعة</th>
                    <th>الكمية</th>
                    <th>بعد</th>
                    <th>التكلفة</th>
                    <th>المرجع</th>
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
                                @case('adjustment') <span class="badge bg-secondary">تعديل</span> @break
                                @default        <span class="badge bg-secondary">{{ $m->type }}</span>
                            @endswitch
                            @if($m->waste_reason && $reasonEnum = $m->wasteReasonEnum())
                                <span class="badge bg-{{ $reasonEnum->color() }}-transparent text-{{ $reasonEnum->color() }} fs-11">
                                    {{ $reasonEnum->label() }}
                                </span>
                            @endif
                        </td>
                        <td>
                            @if($m->storageLocation)
                                <span class="badge bg-primary-transparent text-primary fs-11">
                                    <i class="bi bi-geo-alt me-1"></i>
                                    {{ $m->storageLocation->name }}
                                </span>
                            @else
                                <span class="badge bg-light text-muted fs-11">عام</span>
                            @endif
                        </td>
                        <td>
                            @if($m->batch)
                                <span class="badge bg-info-transparent text-info fs-11" title="ربط الـ FIFO">
                                    <i class="bi bi-box2-fill me-1"></i>
                                    #{{ $m->batch->batch_number ?: $m->batch_id }}
                                </span>
                            @else
                                <span class="text-muted fs-12">—</span>
                            @endif
                        </td>
                        <td>{{ number_format((float)$m->quantity_in_base, 2) }} {{ $m->ingredient->baseUnit->code ?? '' }}</td>
                        <td>{{ number_format((float)$m->stock_after, 2) }}</td>
                        <td>{{ \App\Helpers\Qty::format($m->total_cost) }}</td>
                        <td>
                            @php($ref = $m->reference_meta)
                            @if($ref['url'])
                                <a href="{{ $ref['url'] }}" class="badge bg-success-transparent text-success fs-11">
                                    <i class="bi {{ $ref['icon'] }} me-1"></i>{{ $ref['label'] }}
                                </a>
                            @else
                                <span class="badge bg-light text-muted fs-11">
                                    <i class="bi {{ $ref['icon'] }} me-1"></i>{{ $ref['label'] }}
                                </span>
                            @endif
                        </td>
                        <td class="fs-12">{{ $m->reason }}</td>
                        <td>{{ $m->user?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11">
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
