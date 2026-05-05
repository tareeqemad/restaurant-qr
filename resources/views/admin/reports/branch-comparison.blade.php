@extends('layouts.admin')
@section('title', 'مقارنة الفروع')

@php
    $fmt = function ($value, $type) {
        if (is_null($value)) return '—';
        return match ($type) {
            'money'   => number_format((float) $value, 2) . ' ₪',
            'percent' => number_format((float) $value, 2) . '%',
            'int'     => (int) $value,
            default   => (string) $value,
        };
    };
@endphp

@section('content')
<x-admin.breadcrumb
    title="مقارنة الفروع"
    icon="bi-arrows-collapse"
    subtitle="نظرة جنباً إلى جنب على KPIs لكل فرع — الأخضر هو الأفضل، الأحمر يحتاج انتباه."
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

<x-admin.data-panel title="مصفوفة الـ KPIs" icon="bi-grid-3x3-gap-fill" :count="$rows->count()">
    <x-slot:actions>
        @if($rows->count() > 0)
            <a href="{{ route('admin.reports.branch-comparison', array_merge(request()->query(), ['export' => 'csv'])) }}"
               class="btn btn-sm btn-success">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> تنزيل CSV
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1">من</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1">إلى</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-6 text-md-end">
                <span class="badge bg-success-transparent text-success">أخضر = الأفضل</span>
                <span class="badge bg-danger-transparent text-danger">أحمر = الأسوأ</span>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary btn-sm px-5"><i class="bi bi-search"></i> استعلام</button>
            </div>
        </form>
    </x-slot:filters>

    @if($rows->isEmpty())
        <x-admin.empty-state
            icon="bi-building"
            title="لا فروع نشطة"
            message="فعّل فروعاً من شاشة إدارة الفروع لرؤية المقارنة." />
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle vendor-compare-table mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="position-sticky start-0 bg-light" style="z-index: 2; min-width: 220px;">الـ KPI</th>
                        @foreach($branches as $branch)
                            <th class="text-end" style="min-width: 160px;">
                                <div class="fw-bold">{{ $branch->name }}</div>
                                @if($branch->code)
                                    <small class="text-muted">{{ $branch->code }}</small>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($kpiDefs as $kpi)
                        <tr>
                            <td class="position-sticky start-0 bg-white fw-semibold" style="z-index: 1;">
                                {{ $kpi['label'] }}
                                @if($kpi['higher_is_better'] === false)
                                    <small class="text-muted d-block fs-11">↓ الأقل أفضل</small>
                                @elseif($kpi['higher_is_better'] === true)
                                    <small class="text-muted d-block fs-11">↑ الأعلى أفضل</small>
                                @endif
                            </td>
                            @foreach($rows as $idx => $row)
                                @php
                                    $value     = $row->{$kpi['key']};
                                    $isBest    = $kpi['best_idx']  !== null && $kpi['best_idx']  === $idx;
                                    $isWorst   = $kpi['worst_idx'] !== null && $kpi['worst_idx'] === $idx;
                                    $bg        = $isBest ? 'bg-success-transparent' : ($isWorst ? 'bg-danger-transparent' : '');
                                    $textColor = $isBest ? 'text-success fw-bold' : ($isWorst ? 'text-danger fw-bold' : '');
                                @endphp
                                <td class="text-end {{ $bg }} {{ $textColor }}">
                                    {{ $fmt($value, $kpi['fmt']) }}
                                    @if($isBest)
                                        <i class="bi bi-trophy-fill text-success ms-1" title="الأفضل"></i>
                                    @elseif($isWorst)
                                        <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="الأسوأ"></i>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3 fs-12 text-muted">
            <i class="bi bi-info-circle"></i>
            <strong>ملاحظات:</strong>
            «هامش الربح» يُحسب من (Revenue − COGS) / Revenue ضمن الفترة المختارة.
            «الهدر كـ % من COGS» مقياس صحة المطبخ — أقل من 3% ممتاز، 3-7% مقبول، فوق 7% يحتاج مراجعة.
            بعض الـ KPIs (المشتريات، POs، قيمة المخزون، COGS) لا تحمل اتجاه «أفضل» تلقائي لأنها تعتمد على حجم العمليات في كل فرع.
        </div>
    @endif
</x-admin.data-panel>
@endsection

@push('styles')
<style>
    .vendor-compare-table th, .vendor-compare-table td { white-space: nowrap; }
</style>
@endpush
