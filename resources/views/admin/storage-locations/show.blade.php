@extends('layouts.admin')
@section('title', $location->name)

@section('content')
<x-admin.breadcrumb title="{{ $location- />

<x-admin.data-panel title="المكونات في {{ $location->name }}" :count="$stocks->count()" icon="bi-boxes">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>المكوّن</th>
                    <th>الكمية</th>
                    <th>حد الطلب (موقع)</th>
                    <th>التكلفة/وحدة</th>
                    <th>القيمة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stocks as $s)
                    @php
                        $ing = $s->ingredient;
                        $value = (float) $s->quantity * (float) ($ing?->cost_per_unit ?? 0);
                        $low = $s->isLowStock();
                    @endphp
                    <tr class="{{ $low ? 'table-warning' : '' }}">
                        <td class="fw-bold">{{ $ing?->name ?? '—' }}</td>
                        <td>{{ number_format((float) $s->quantity, 4) }} {{ $ing?->baseUnit?->code }}</td>
                        <td>{{ number_format((float) $s->reorder_threshold, 4) }}</td>
                        <td>{{ number_format((float) ($ing?->cost_per_unit ?? 0), 4) }}</td>
                        <td class="fw-bold" style="color:var(--primary);">{{ \App\Helpers\Money::format($value) }}</td>
                        <td>
                            @if($low)
                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> منخفض</span>
                            @else
                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> جيد</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <x-admin.empty-state icon="bi-boxes" message="ما في مكونات في هذا الموقع" compact />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.data-panel>
@endsection
