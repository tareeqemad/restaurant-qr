@extends('layouts.admin')
@section('title', 'أسعار '.$supplier->name)

@section('content')
<x-admin.breadcrumb
    title="أسعار المورّد: {{ $supplier->name }}"
    icon="bi-truck"
    subtitle="آخر سعر لكل صنف اشتريناه من هذا المورّد + التغير خلال آخر 30 يوم." />

<x-admin.data-panel title="أسعار {{ $supplier->name }}" icon="bi-tags-fill" :count="$count">
    <x-slot:actions>
        <a href="{{ route('admin.suppliers.show', $supplier) }}" class="btn btn-sm btn-light">
            <i class="bi bi-arrow-right"></i> ملف المورّد
        </a>
        <a href="{{ route('admin.vendor-prices.compare') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-arrows-collapse"></i> مقارنة كل المورّدين
        </a>
    </x-slot:actions>

    @if($rows->isEmpty())
        <x-admin.empty-state
            icon="bi-tag"
            title="لا أسعار مسجّلة"
            message="سيظهر تاريخ الأسعار هنا تلقائياً مع أول استلام أمر شراء من هذا المورّد." />
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>المكوّن</th>
                        <th class="text-end">آخر سعر مدفوع</th>
                        <th class="text-end">سعر الوحدة الأساسية</th>
                        <th class="text-end">آخر تغير (مقابل الاستلام السابق)</th>
                        <th class="text-end">تغير 30 يوم</th>
                        <th>آخر استلام</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php
                            $latest = $row['latest'];
                            $delta30 = $row['delta30'];
                            $delta30Color = $delta30 === null
                                ? 'secondary'
                                : (abs($delta30) < 0.01 ? 'secondary' : ($delta30 > 0 ? 'danger' : 'success'));
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $latest->ingredient?->name }}</div>
                                <small class="text-muted">SKU: {{ $latest->ingredient?->sku ?? '—' }}</small>
                            </td>
                            <td class="text-end">
                                {{ \App\Helpers\Qty::format($latest->unit_price) }}
                                <small class="text-muted">/ {{ $latest->unit?->code }}</small>
                            </td>
                            <td class="text-end fw-semibold text-primary">
                                {{ \App\Helpers\Qty::format($latest->unit_price_in_base) }} ₪
                                <small class="text-muted fs-11">/ {{ $latest->ingredient?->baseUnit?->code }}</small>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-{{ $latest->changeColor() }}-transparent text-{{ $latest->changeColor() }}">
                                    {{ $latest->changeLabel() }}
                                </span>
                            </td>
                            <td class="text-end">
                                @if($delta30 === null)
                                    <span class="text-muted fs-12">—</span>
                                @else
                                    <span class="badge bg-{{ $delta30Color }}-transparent text-{{ $delta30Color }}">
                                        {{ $delta30 > 0 ? '+' : '' }}{{ number_format($delta30, 2) }}%
                                    </span>
                                @endif
                            </td>
                            <td class="fs-12 text-muted">
                                {{ optional($latest->observed_at)->format('Y-m-d') }}
                                <br>
                                <span class="fs-11">{{ optional($latest->observed_at)->diffForHumans() }}</span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.vendor-prices.ingredient', $latest->ingredient) }}"
                                   class="btn btn-sm btn-link text-decoration-none">
                                    <i class="bi bi-clock-history"></i> تاريخ كامل
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin.data-panel>
@endsection
