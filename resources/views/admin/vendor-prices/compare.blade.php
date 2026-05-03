@extends('layouts.admin')
@section('title', 'مقارنة أسعار المورّدين')

@section('content')
<x-admin.breadcrumb
    title="مقارنة أسعار المورّدين"
    icon="bi-arrows-collapse"
    subtitle="آخر سعر لكل صنف عند كل مورّد جنباً إلى جنب — الأخضر هو الأرخص، الأحمر هو الأغلى." />

<x-admin.data-panel title="مصفوفة الأسعار" icon="bi-grid-3x3-gap-fill" :count="$ingredients->count()">
    @if($ingredients->isEmpty())
        <x-admin.empty-state
            icon="bi-table"
            title="لا بيانات للمقارنة"
            message="ستظهر المقارنة تلقائياً مع تراكم بيانات الاستلام عبر مورّدين متعددين." />
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle vendor-compare-table mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="position-sticky start-0 bg-light" style="z-index: 2;">المكوّن</th>
                        <th class="text-end">فجوة السعر</th>
                        @foreach($suppliers as $sup)
                            <th class="text-end">{{ $sup->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($ingredients as $ing)
                        <tr>
                            <td class="position-sticky start-0 bg-white" style="z-index: 1;">
                                <div class="fw-semibold">{{ $ing->name }}</div>
                                <small class="text-muted">/ {{ $ing->baseUnit?->code }}</small>
                            </td>
                            <td class="text-end">
                                @if(($ing->price_range_pct ?? 0) > 0.01)
                                    <span class="badge bg-{{ $ing->price_range_pct > 20 ? 'danger' : 'warning' }}-transparent text-{{ $ing->price_range_pct > 20 ? 'danger' : 'warning' }}">
                                        {{ number_format($ing->price_range_pct, 1) }}%
                                    </span>
                                @else
                                    <span class="text-muted fs-11">—</span>
                                @endif
                            </td>
                            @foreach($suppliers as $sup)
                                @php
                                    $row = $matrix[$ing->id][$sup->id] ?? null;
                                    $isCheapest = isset($ing->cheapest_supplier_id) && $ing->cheapest_supplier_id === $sup->id;
                                    $isPriciest = isset($ing->priciest_supplier_id) && $ing->priciest_supplier_id === $sup->id && $ing->cheapest_supplier_id !== $sup->id;
                                    $bg = $isCheapest ? 'bg-success-transparent' : ($isPriciest ? 'bg-danger-transparent' : '');
                                @endphp
                                <td class="text-end {{ $bg }}">
                                    @if($row)
                                        <div class="fw-semibold {{ $isCheapest ? 'text-success' : ($isPriciest ? 'text-danger' : '') }}">
                                            {{ number_format((float) $row->unit_price_in_base, 4) }}
                                        </div>
                                        <small class="text-muted fs-11" title="{{ optional($row->observed_at)->format('Y-m-d') }}">
                                            {{ optional($row->observed_at)->diffForHumans() }}
                                        </small>
                                    @else
                                        <span class="text-muted fs-11">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3 d-flex gap-3 fs-12 text-muted">
            <span><span class="badge bg-success-transparent text-success">أخضر</span> = الأرخص</span>
            <span><span class="badge bg-danger-transparent text-danger">أحمر</span> = الأغلى</span>
            <span><span class="badge bg-warning-transparent text-warning">فجوة &gt; 20%</span> = فرص توفير قوية</span>
        </div>
    @endif
</x-admin.data-panel>
@endsection

@push('styles')
<style>
    .vendor-compare-table th, .vendor-compare-table td { white-space: nowrap; }
</style>
@endpush
