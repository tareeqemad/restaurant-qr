@extends('layouts.admin')
@section('title','المكونات')

@php
    // When a branch is active, show per-branch numbers (sum of ingredient_stock
    // across the branch's storage_locations). Owner-level views with no
    // branch context fall back to the global current_stock.
    $activeBranchId = \App\Support\BranchContext::current();
    $activeBranchName = $activeBranchId ? \App\Models\Branch::find($activeBranchId)?->name : null;
@endphp

@section('content')
<x-admin.breadcrumb title="المكونات والمخزون" icon="bi-basket2-fill"
    subtitle="{{ $activeBranchName ? 'مخزون فرع «'.$activeBranchName.'»' : 'مخزون شامل لكل الفروع' }}" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي المكونات', 'value' => $stats['total'],     'icon' => 'bi-basket2-fill',     'color' => 'primary'],
    ['label' => 'مخزون صحي',        'value' => $stats['healthy'],   'icon' => 'bi-check-circle-fill','color' => 'success'],
    ['label' => 'مخزون منخفض',      'value' => $stats['low_stock'], 'icon' => 'bi-exclamation-triangle-fill','color' => 'accent'],
    ['label' => 'نفد المخزون',      'value' => $stats['out_stock'], 'icon' => 'bi-x-octagon-fill',  'color' => 'danger'],
]" />

<x-admin.data-panel title="قائمة المكونات" :count="$ingredients->total()" icon="bi-basket2-fill">
    <x-slot:actions>
        <a href="{{ route('admin.ingredients.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> مكون جديد
        </a>
        @if(request()->hasAny(['search', 'low_stock']))
            <a href="{{ route('admin.ingredients.index') }}" class="btn btn-light">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-6"><input name="search" value="{{ request('search') }}" class="form-control" placeholder="🔍 ابحث باسم المكون"></div>
            <div class="col-md-4">
                <label class="d-flex align-items-center gap-2 h-100">
                    <input type="checkbox" name="low_stock" value="1" @checked(request('low_stock'))>
                    عرض المخزون المنخفض فقط
                </label>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تطبيق</button></div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>الاسم</th>
                    <th>SKU</th>
                    <th>المخزون</th>
                    <th>حد الطلب</th>
                    <th>الوحدة</th>
                    <th>التكلفة/وحدة</th>
                    <th>تتبع</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($ingredients as $ing)
                    @php
                        // Per-branch view when a branch is active; global otherwise.
                        $stock     = $activeBranchId ? $ing->stockAtBranch($activeBranchId)             : (float) $ing->current_stock;
                        $threshold = $activeBranchId ? $ing->reorderThresholdAtBranch($activeBranchId)  : (float) $ing->reorder_threshold;
                        $cost      = $activeBranchId ? $ing->costAtBranch($activeBranchId)              : (float) $ing->cost_per_unit;
                        $low       = $ing->track_stock && $threshold > 0 && $stock <= $threshold;
                    @endphp
                    <tr class="{{ $low ? 'table-warning' : '' }}">
                        <td class="fw-bold">
                            {{ $ing->name }}
                            @if($low)<span class="badge bg-danger ms-1">منخفض</span>@endif
                        </td>
                        <td>{{ $ing->sku }}</td>
                        <td>{{ number_format($stock, 2) }}</td>
                        <td>{{ number_format($threshold, 2) }}</td>
                        <td>{{ $ing->baseUnit->code ?? '' }}</td>
                        <td>
                            {{ number_format($cost, 4) }}
                            @if($activeBranchId && abs($cost - (float) $ing->cost_per_unit) > 0.0001)
                                <small class="text-muted d-block fs-11" title="السعر المتوسط العام">
                                    عام: {{ number_format((float) $ing->cost_per_unit, 4) }}
                                </small>
                            @endif
                        </td>
                        <td>{{ $ing->track_stock ? 'نعم' : 'لا' }}</td>
                        <td>
                            {{-- أيقونة `bi-arrow-up-down` غير موجودة في الخط المُجمَّع،
                                 فكان الزر يظهر أخضر بلا glyph. استبدلتها بـ
                                 `bi-plus-slash-minus` (يعرض رمز ±) وهو أوضح
                                 للمعنى: زيادة أو نقصان مخزون. --}}
                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#adjust{{ $ing->id }}" title="تسجيل حركة مخزون">
                                <i class="bi bi-plus-slash-minus"></i>
                            </button>
                            <a href="{{ route('admin.vendor-prices.ingredient', $ing) }}"
                               class="btn btn-sm btn-info" title="تاريخ الأسعار من المورّدين">
                                <i class="bi bi-graph-up-arrow"></i>
                            </a>
                            <a href="{{ route('admin.ingredients.edit', $ing) }}" class="btn btn-sm btn-light" title="تعديل"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('admin.ingredients.destroy', $ing) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف؟')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <div class="modal fade" id="adjust{{ $ing->id }}" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="{{ route('admin.ingredients.adjust', $ing) }}" method="POST">
                                    @csrf
                                    <div class="modal-header">
                                        <h5>تعديل مخزون: {{ $ing->name }}</h5>
                                        <button class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>المخزون الحالي: <strong>{{ number_format((float)$ing->current_stock, 2) }} {{ $ing->baseUnit->code }}</strong></p>
                                        <div class="mb-2">
                                            <label class="form-label">نوع الحركة</label>
                                            <select name="type" class="form-select" required>
                                                <option value="in">إضافة (شراء)</option>
                                                <option value="out">خصم (استهلاك)</option>
                                                <option value="waste">هدر</option>
                                                <option value="adjustment">تعديل يدوي</option>
                                            </select>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6"><label class="form-label">الكمية</label><input type="number" step="0.0001" name="quantity" class="form-control" required></div>
                                            <div class="col-6">
                                                <label class="form-label">الوحدة</label>
                                                <select name="unit_id" class="form-select" required>
                                                    @foreach(\App\Models\Unit::where('unit_type', $ing->baseUnit?->unit_type)->get() as $u)
                                                        <option value="{{ $u->id }}" @selected($u->id===$ing->base_unit_id)>{{ $u->name }} ({{ $u->code }})</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-2 mt-2"><label class="form-label">التكلفة للوحدة (اختياري)</label><input type="number" step="0.0001" name="unit_cost" value="{{ $ing->cost_per_unit }}" class="form-control"></div>
                                        <div class="mb-2"><label class="form-label">السبب *</label><input name="reason" class="form-control" required></div>
                                    </div>
                                    <div class="modal-footer"><button class="btn btn-primary">تسجيل</button></div>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <tr><td colspan="8">
                        <x-admin.empty-state
                            icon="bi-basket2-fill"
                            title="ما في مكونات بعد"
                            message="أضف المكونات لتفعيل خصم المخزون التلقائي من الوصفات.">
                            <x-slot:cta>
                                <a href="{{ route('admin.ingredients.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> أضف مكوناً
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $ingredients->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
