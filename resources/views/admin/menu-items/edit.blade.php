@extends('layouts.admin')
@section('title', 'تعديل: '.$item->name)

@section('content')
<x-admin.breadcrumb title="تعديل: {{ $item->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'الأصناف', 'url' => route('admin.menu-items.index')]]" />

@php
    $price  = (float) $item->price;
    $cost   = (float) $item->cost;
    $profit = $price - $cost;
    $margin = $price > 0 ? ($profit / $price) * 100 : 0;
    $marginColor = $margin >= 60 ? 'success' : ($margin >= 30 ? 'accent' : 'danger');
    $breakdown = app(\App\Services\RecipeCostService::class)->breakdownForMenuItem($item);
@endphp

@if($cost > 0 || $breakdown['lines'])
<x-admin.stat-rail :stats="[
    ['label' => 'سعر البيع',   'value' => \App\Helpers\Money::format($price), 'icon' => 'bi-tag-fill',        'color' => 'primary'],
    ['label' => 'تكلفة الوصفة', 'value' => \App\Helpers\Money::format($cost),  'icon' => 'bi-calculator',      'color' => 'muted'],
    ['label' => 'الربح',        'value' => \App\Helpers\Money::format($profit),'icon' => 'bi-graph-up-arrow',  'color' => $marginColor],
    ['label' => 'هامش الربح',   'value' => number_format($margin, 1).'%',      'icon' => 'bi-percent',         'color' => $marginColor],
]" />
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.menu-items.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.menu-items.update', $item) }}">
                    @method('PUT')
                    @include('admin.menu-items._form')
                </form>
</x-admin.data-panel>
    </div>

    {{-- Recipe cost breakdown sidebar --}}
    <div class="col-lg-4">
        <x-admin.data-panel title="تحليل التكلفة" icon="bi-calculator">
            <div class="p-3">
                @if(empty($breakdown['lines']))
                    <x-admin.empty-state icon="bi-calculator" message="أضف مكونات وصفة لحساب التكلفة تلقائياً" compact />
                @else
                    <div class="small text-muted mb-2">
                        <i class="bi bi-info-circle"></i>
                        تُحسب التكلفة تلقائياً عند تعديل الوصفة أو تغيير سعر أحد المكونات.
                    </div>
                    @foreach($breakdown['lines'] as $line)
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-dashed">
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="fw-bold small text-truncate">
                                    @if($line['is_optional'])
                                        <i class="bi bi-asterisk" style="color:var(--accent); font-size:.7em;" title="اختياري — غير محسوب"></i>
                                    @endif
                                    {{ $line['ingredient'] }}
                                </div>
                                <div class="text-muted" style="font-size:.72rem;">
                                    {{-- bdi isolates each number/unit so RTL doesn't
                                         scramble "1 pcs × 0.20" into "pcs × 0.2 1". --}}
                                    <bdi>{{ \App\Helpers\Qty::format($line['quantity']) }} {{ $line['unit'] }}</bdi>
                                    <span class="mx-1">×</span>
                                    <bdi>{{ \App\Helpers\Money::format($line['cost_per_unit']) }} / {{ $line['unit'] }}</bdi>
                                </div>
                            </div>
                            <div class="fw-bold" style="color: {{ $line['is_optional'] ? '#9ca3af' : 'var(--primary)' }}; font-variant-numeric: tabular-nums;">
                                {{ \App\Helpers\Money::format($line['line_cost']) }}
                            </div>
                        </div>
                    @endforeach
                    <div class="d-flex justify-content-between align-items-center py-2 mt-2"
                         style="border-top: 2px solid var(--accent); font-size: 1rem;">
                        <strong style="color: var(--primary);">إجمالي التكلفة</strong>
                        <strong style="color: var(--primary); font-variant-numeric: tabular-nums;">
                            {{ \App\Helpers\Money::format($breakdown['total']) }}
                        </strong>
                    </div>
                @endif
            </div>
        </x-admin.data-panel>
    </div>
</div>
@endsection
