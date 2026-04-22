@extends('layouts.admin')
@section('title', 'اقتراحات الشراء')

@section('content')
<x-admin.breadcrumb title="اقتراحات الشراء" icon="bi-cart-plus-fill"
    subtitle="ماذا تشتري من من بكم — بناءً على المخزون المنخفض والاستهلاك"
    :crumbs="[['label' = />

<x-admin.stat-rail :stats="[
    ['label' => 'مكونات تحتاج إعادة طلب', 'value' => $totalItems,                          'icon' => 'bi-exclamation-triangle-fill', 'color' => $totalItems > 0 ? 'accent' : 'success'],
    ['label' => 'عدد الموردين',              'value' => $bySupplier->count(),                 'icon' => 'bi-truck',                      'color' => 'primary'],
    ['label' => 'إجمالي التكلفة المقترحة',   'value' => \App\Helpers\Money::format($totalCost),'icon' => 'bi-cash-coin',                 'color' => 'accent'],
    ['label' => 'منطق الاقتراح',              'value' => 'استهلاك 30 يوم',                     'icon' => 'bi-calculator',                'color' => 'muted'],
]" />

@if($totalItems === 0)
    <x-admin.empty-state
        icon="bi-check-circle-fill"
        title="المخزون في حالة جيدة"
        message="لا يوجد مكونات وصلت حد إعادة الطلب. كل شي فوق العتبة." />
@else

<div class="alert" style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
    <i class="bi bi-info-circle-fill"></i>
    المنطق: الكمية المقترحة = <strong>max</strong>(استهلاك 30 يوم السابقة، <strong>2×</strong> حد الطلب).
    يمكنك إنشاء أمر شراء جديد لكل مورد بضغطة واحدة وتعديل الكميات عند الحاجة.
</div>

@foreach($bySupplier as $supplierId => $items)
    @php
        $supplier = $items->first()->supplier;
        $groupCost = $items->sum('estimated_cost');
    @endphp

    <x-admin.data-panel
        :title="$supplier?->name ?? 'بدون مورد محدد'"
        :count="$items->count()"
        icon="bi-truck">
        <x-slot:actions>
            <span class="fw-bold" style="color: var(--primary);">
                الإجمالي: {{ \App\Helpers\Money::format($groupCost) }}
            </span>
            @if($supplier)
                <a href="{{ route('admin.purchase-orders.create', ['supplier_id' => $supplier->id]) }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> أنشئ PO
                </a>
            @endif
        </x-slot:actions>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>المكوّن</th>
                        <th>المخزون الحالي</th>
                        <th>حد الطلب</th>
                        <th>استهلاك 30 يوم</th>
                        <th>الكمية المقترحة</th>
                        <th>التكلفة التقديرية</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $ing)
                        <tr>
                            <td class="fw-bold">
                                {{ $ing->name }}
                                <small class="text-muted d-block">{{ $ing->baseUnit?->code }}</small>
                            </td>
                            <td>
                                <span class="badge bg-danger">
                                    {{ number_format((float) $ing->current_stock, 2) }}
                                </span>
                            </td>
                            <td>{{ number_format((float) $ing->reorder_threshold, 2) }}</td>
                            <td>{{ number_format((float) $ing->used_30d, 2) }}</td>
                            <td class="fw-bold" style="color: var(--primary); font-variant-numeric: tabular-nums;">
                                {{ number_format((float) $ing->suggested_qty, 2) }}
                            </td>
                            <td class="fw-bold" style="color: var(--accent-dark);">
                                {{ \App\Helpers\Money::format($ing->estimated_cost) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td colspan="5" class="text-end fw-bold">إجمالي مورد {{ $supplier?->name ?? '—' }}</td>
                        <td class="fw-bold" style="color: var(--primary);">
                            {{ \App\Helpers\Money::format($groupCost) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-admin.data-panel>
@endforeach

@endif

@push('styles')
<style>
@media print {
    .app-sidebar, .app-header, .breadcrumb-list, .btn { display: none !important; }
    .app-content { margin: 0 !important; }
}
</style>
@endpush
@endsection
