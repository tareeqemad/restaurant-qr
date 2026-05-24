@extends('layouts.admin')@section('title','استهلاك المخزن')
@php
    $totalIn = $rows->sum(fn($byType) => (float) ($byType->firstWhere('type', 'in')->qty ?? 0));
    $totalOut = $rows->sum(fn($byType) => (float) ($byType->firstWhere('type', 'out')->qty ?? 0));
    $totalWaste = $rows->sum(fn($byType) => (float) ($byType->firstWhere('type', 'waste')->qty ?? 0));
    $outCost = $rows->sum(fn($byType) => (float) ($byType->firstWhere('type', 'out')->total_cost ?? 0));
    $wasteCost = $rows->sum(fn($byType) => (float) ($byType->firstWhere('type', 'waste')->total_cost ?? 0));
@endphp
@section('content')
<x-admin.breadcrumb title="استهلاك المخزن" icon="bi-boxes"
    subtitle="حركة المكونات المستهلكة في فترة محددة"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

<x-admin.stat-rail :stats="[
    ['label' => 'إضافات', 'value' => number_format($totalIn, 2), 'icon' => 'bi-plus-circle', 'color' => 'success'],
    ['label' => 'استهلاك', 'value' => number_format($totalOut, 2), 'icon' => 'bi-arrow-up-right-circle', 'color' => 'accent'],
    ['label' => 'هدر', 'value' => number_format($totalWaste, 2), 'icon' => 'bi-trash3', 'color' => $totalWaste > 0 ? 'danger' : 'muted'],
    ['label' => 'تكلفة الاستهلاك', 'value' => \App\Helpers\Money::format($outCost), 'icon' => 'bi-cash-stack', 'color' => 'primary'],
    ['label' => 'تكلفة الهدر', 'value' => \App\Helpers\Money::format($wasteCost), 'icon' => 'bi-exclamation-triangle', 'color' => $wasteCost > 0 ? 'warning' : 'muted'],
]" />

<x-admin.data-panel title="حركة المكونات" icon="bi-boxes" :count="$rows->count()">
    <x-slot:filters>
        <form class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">من تاريخ</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted fw-bold">اختصارات</label>
                <div class="btn-group w-100">
                    <a href="?from={{ now()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">اليوم</a>
                    <a href="?from={{ now()->subDays(6)->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">7 أيام</a>
                    <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">الشهر</a>
                </div>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
            </div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>المكون</th>
                    <th>إضافات</th>
                    <th>استهلاك</th>
                    <th>هدر</th>
                    <th>تكلفة الاستهلاك</th>
                    <th>تكلفة الهدر</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $ingredientId => $byType)
                    @php
                        $ing = $byType->first()->ingredient;
                        $rBase = $ing->baseUnit?->code;
                        $inQty    = (float) ($byType->firstWhere('type', 'in')->qty ?? 0);
                        $outQty   = (float) ($byType->firstWhere('type', 'out')->qty ?? 0);
                        $wasteQty = (float) ($byType->firstWhere('type', 'waste')->qty ?? 0);
                    @endphp
                    <tr>
                        <td class="fw-bold">{{ $ing->name }}</td>
                        <td class="text-success" title="{{ number_format($inQty, 4) }} {{ $rBase }}">{{ \App\Helpers\QuantityFormatter::smart($inQty, $rBase) }}</td>
                        <td class="text-warning" title="{{ number_format($outQty, 4) }} {{ $rBase }}">{{ \App\Helpers\QuantityFormatter::smart($outQty, $rBase) }}</td>
                        <td class="text-danger" title="{{ number_format($wasteQty, 4) }} {{ $rBase }}">{{ \App\Helpers\QuantityFormatter::smart($wasteQty, $rBase) }}</td>
                        <td>{{ \App\Helpers\Money::format($byType->firstWhere('type', 'out')->total_cost ?? 0) }}</td>
                        <td class="{{ (float) ($byType->firstWhere('type', 'waste')->total_cost ?? 0) > 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                            {{ \App\Helpers\Money::format($byType->firstWhere('type', 'waste')->total_cost ?? 0) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <x-admin.empty-state
                            icon="bi-boxes"
                            title="لا توجد حركة مخزون"
                            message="لا توجد إضافات أو استهلاك ضمن الفترة المختارة." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.data-panel>
@endsection
