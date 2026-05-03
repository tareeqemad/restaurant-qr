@extends('layouts.admin')
@section('title', $supplier->name)

@section('content')
<x-admin.breadcrumb title="{{ $supplier- />

<div class="d-flex justify-content-end mb-3 gap-2">
    <a href="{{ route('admin.vendor-prices.supplier', $supplier) }}" class="btn btn-info">
        <i class="bi bi-graph-up-arrow me-1"></i>
        تاريخ الأسعار من هذا المورّد
    </a>
    <a href="{{ route('admin.vendor-prices.compare') }}" class="btn btn-outline-info">
        <i class="bi bi-arrows-collapse me-1"></i>
        مقارنة المورّدين
    </a>
</div>

<x-admin.stat-rail :stats="[
    ['label' => 'عدد المكونات', 'value' => $totals['ingredient_count'], 'icon' => 'bi-boxes',                'color' => 'primary'],
    ['label' => 'مخزون منخفض',   'value' => $totals['low_stock'],        'icon' => 'bi-exclamation-triangle', 'color' => 'danger'],
    ['label' => 'قيمة المخزون',  'value' => \App\Helpers\Money::format($totals['stock_value']), 'icon' => 'bi-cash-coin', 'color' => 'accent'],
    ['label' => 'الحالة',         'value' => $supplier->active ? 'فعّال' : 'متوقف', 'icon' => $supplier->active ? 'bi-check-circle-fill' : 'bi-pause-circle', 'color' => $supplier->active ? 'success' : 'muted'],
]" />

<div class="row g-3">
    {{-- Supplier card --}}
    <div class="col-xl-4">
        <x-admin.data-panel title="بيانات الاتصال" icon="bi-person-vcard">
            <div class="p-3">
                @php
                    // Positional destructure needs every row to have the same
                    // shape — PHP has no per-slot default. Normalise $ltr to false.
                    $rows = [
                        ['الشخص المسؤول', $supplier->contact_person, 'bi-person-circle', false],
                        ['الهاتف',         $supplier->phone,          'bi-telephone',    true],
                        ['البريد',         $supplier->email,          'bi-envelope',     true],
                        ['العنوان',        $supplier->address,        'bi-geo-alt',      false],
                        ['ملاحظات',        $supplier->notes,          'bi-journal-text', false],
                    ];
                @endphp
                @foreach($rows as [$label, $value, $icon, $ltr])
                    @if(!empty($value))
                        <div class="d-flex align-items-start gap-2 mb-3">
                            <div style="width:36px; height:36px; border-radius:10px; background:rgba(var(--accent-rgb),.12); color:var(--accent); display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="bi {{ $icon }}"></i>
                            </div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="text-muted small fw-bold">{{ $label }}</div>
                                <div class="fw-bold" @if($ldir ?? null)dir="ltr"@endif style="word-break:break-word;">{{ $value }}</div>
                            </div>
                        </div>
                    @endif
                @endforeach
                @if(!$supplier->contact_person && !$supplier->phone && !$supplier->email && !$supplier->address && !$supplier->notes)
                    <x-admin.empty-state icon="bi-person-vcard" message="لا توجد بيانات اتصال" compact />
                @endif
            </div>
        </x-admin.data-panel>
    </div>

    {{-- Ingredients list --}}
    <div class="col-xl-8">
        <x-admin.data-panel
            title="المكونات المرتبطة"
            :count="$supplier->ingredients->count()"
            icon="bi-boxes">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>المكوّن</th>
                            <th>SKU</th>
                            <th>المخزون</th>
                            <th>حد الطلب</th>
                            <th>التكلفة/وحدة</th>
                            <th>قيمة المخزون</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($supplier->ingredients as $ing)
                            <tr class="{{ $ing->isLowStock() ? 'table-warning' : '' }}">
                                <td>
                                    <a href="{{ route('admin.ingredients.edit', $ing) }}" class="fw-bold" style="color:var(--primary); text-decoration:none;">
                                        {{ $ing->name }}
                                    </a>
                                    @if($ing->isLowStock())
                                        <span class="badge bg-danger ms-1">منخفض</span>
                                    @endif
                                </td>
                                <td><code>{{ $ing->sku }}</code></td>
                                <td>{{ number_format((float)$ing->current_stock, 2) }} {{ $ing->baseUnit->code ?? '' }}</td>
                                <td>{{ number_format((float)$ing->reorder_threshold, 2) }}</td>
                                <td>{{ number_format((float)$ing->cost_per_unit, 4) }}</td>
                                <td class="fw-bold" style="color:var(--primary);">
                                    {{ \App\Helpers\Money::format((float)$ing->current_stock * (float)$ing->cost_per_unit) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">
                                <x-admin.empty-state
                                    icon="bi-boxes"
                                    title="ما في مكونات مرتبطة"
                                    message="اربط مكونات بهذا المورد من صفحة تعديل المكوّن.">
                                    <x-slot:cta>
                                        <a href="{{ route('admin.ingredients.index') }}" class="btn btn-primary">
                                            <i class="bi bi-boxes"></i> إدارة المكونات
                                        </a>
                                    </x-slot:cta>
                                </x-admin.empty-state>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-admin.data-panel>
    </div>
</div>
@endsection
