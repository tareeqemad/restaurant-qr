@extends('layouts.admin')
@section('title', 'تاريخ سعر '.$ingredient->name)

@section('content')
<x-admin.breadcrumb
    title="تاريخ سعر: {{ $ingredient->name }}"
    icon="bi-graph-up-arrow"
    subtitle="كل سعر تم تسجيله لهذا المكون عبر كل المورّدين — بالوحدة الأساسية ({{ $ingredient->baseUnit?->code ?? '—' }})." />

<x-admin.stat-rail :stats="[
    ['label' => 'آخر سعر', 'value' => $latest ? \App\Helpers\Qty::format($latest->unit_price_in_base).' ₪' : '—', 'icon' => 'bi-tag-fill', 'color' => 'primary'],
    ['label' => 'أدنى سعر مسجّل', 'value' => $rows->isEmpty() ? '—' : \App\Helpers\Qty::format($minPrice).' ₪', 'icon' => 'bi-arrow-down-circle-fill', 'color' => 'success'],
    ['label' => 'أعلى سعر مسجّل', 'value' => $rows->isEmpty() ? '—' : \App\Helpers\Qty::format($maxPrice).' ₪', 'icon' => 'bi-arrow-up-circle-fill', 'color' => 'danger'],
    ['label' => 'مورّدون مختلفون', 'value' => $bySupplier->count(), 'icon' => 'bi-truck', 'color' => 'accent'],
    ['label' => 'عدد الملاحظات', 'value' => $rows->count(), 'icon' => 'bi-collection-fill', 'color' => 'info'],
]" />

@if($rows->isEmpty())
    <x-admin.data-panel title="بدون سجلات" icon="bi-info-circle">
        <x-admin.empty-state
            icon="bi-graph-up"
            title="لا يوجد سجل أسعار لهذا المكون"
            message="يبدأ التسجيل تلقائياً مع أول استلام أمر شراء." />
    </x-admin.data-panel>
@else
    {{-- Per-supplier mini-cards --}}
    <div class="row g-3 mb-3">
        @foreach($bySupplier as $entry)
            @php $latestRow = $entry['latest']; @endphp
            <div class="col-md-6 col-lg-4">
                <div class="card custom-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="mb-1 fw-semibold">{{ $entry['supplier']?->name ?? 'مورد محذوف' }}</h6>
                                <small class="text-muted">{{ $entry['points']->count() }} ملاحظة</small>
                            </div>
                            <span class="badge bg-{{ $latestRow->changeColor() }}-transparent text-{{ $latestRow->changeColor() }}">
                                {{ $latestRow->changeLabel() }}
                            </span>
                        </div>
                        <div class="fs-22 fw-bold text-primary mb-1">
                            {{ \App\Helpers\Qty::format($latestRow->unit_price_in_base) }} ₪
                            <small class="text-muted fs-12 fw-normal">/ {{ $ingredient->baseUnit?->code }}</small>
                        </div>
                        <small class="text-muted">
                            آخر تحديث: {{ $latestRow->observed_at?->diffForHumans() }}
                        </small>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Full history table --}}
    <x-admin.data-panel title="كل الملاحظات" icon="bi-clock-history" :count="$rows->count()">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>المورّد</th>
                        <th class="text-end">السعر (مدفوع)</th>
                        <th class="text-end">السعر (وحدة أساسية)</th>
                        <th class="text-end">التغير</th>
                        <th>المصدر</th>
                        <th>المسجّل</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        <tr>
                            <td>
                                <div class="fw-semibold fs-13">{{ optional($r->observed_at)->format('Y-m-d H:i') }}</div>
                                <small class="text-muted">{{ optional($r->observed_at)->diffForHumans() }}</small>
                            </td>
                            <td>{{ $r->supplier?->name ?? '—' }}</td>
                            <td class="text-end">
                                {{ \App\Helpers\Qty::format($r->unit_price) }}
                                <small class="text-muted">/ {{ $r->unit?->code }}</small>
                            </td>
                            <td class="text-end fw-semibold">
                                {{ \App\Helpers\Qty::format($r->unit_price_in_base) }} ₪
                            </td>
                            <td class="text-end">
                                <span class="badge bg-{{ $r->changeColor() }}-transparent text-{{ $r->changeColor() }}">
                                    {{ $r->changeLabel() }}
                                </span>
                            </td>
                            <td>
                                @if($r->purchase_order_id)
                                    <a href="{{ route('admin.purchase-orders.show', $r->purchase_order_id) }}"
                                       class="text-decoration-none fs-12">
                                        <i class="bi bi-bag-fill"></i>
                                        PO #{{ $r->purchase_order_id }}
                                    </a>
                                @else
                                    <span class="badge bg-light text-muted">{{ $r->source }}</span>
                                @endif
                            </td>
                            <td class="fs-12 text-muted">{{ $r->recordedBy?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.data-panel>
@endif
@endsection
