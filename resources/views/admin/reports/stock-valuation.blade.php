@extends('layouts.admin')
@section('title', 'تقييم المخزون')

@php
    $abcMeta = [
        'A' => ['color' => 'danger',  'label' => 'A — أهم 80%'],
        'B' => ['color' => 'warning', 'label' => 'B — التالي 15%'],
        'C' => ['color' => 'success', 'label' => 'C — آخر 5%'],
    ];
    $effectiveDate = $asOf ?: now()->toDateString();
    $isHistorical  = (bool) $asOf;
@endphp

@section('content')
<x-admin.breadcrumb
    title="تقييم المخزون"
    icon="bi-cash-stack"
    subtitle="قيمة كل صنف = كميته × تكلفته. يدعم نقطة زمنية في الماضي لإقفال الشهور المحاسبية." />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي قيمة المخزون', 'value' => number_format($totalValue, 2).' ₪',                                'icon' => 'bi-cash-stack',           'color' => 'primary'],
    ['label' => 'عدد الأصناف',           'value' => $rowCount,                                                          'icon' => 'bi-collection-fill',     'color' => 'info'],
    ['label' => 'قيمة المنخفض المخزون',  'value' => number_format($lowStockValue, 2).' ₪',                              'icon' => 'bi-exclamation-triangle', 'color' => 'warning'],
    ['label' => 'فئة A (الأهم)',          'value' => $abcCounts['A'],                                                    'icon' => 'bi-star-fill',           'color' => 'danger'],
    ['label' => 'قيمة فئة A',            'value' => number_format($abcValues['A'], 2).' ₪',                              'icon' => 'bi-cash',                 'color' => 'danger'],
    ['label' => 'كـ %',                    'value' => $totalValue > 0 ? number_format(($abcValues['A']/$totalValue)*100,1).'%' : '0%', 'icon' => 'bi-pie-chart-fill', 'color' => 'accent'],
]" />

<x-admin.data-panel
    title="{{ $isHistorical ? 'صورة المخزون كما كان في '.$asOf : 'صورة المخزون الحالية' }}"
    icon="bi-table"
    :count="$rowCount">
    <x-slot:actions>
        <a href="{{ route('admin.reports.stock-valuation', array_merge(request()->query(), ['export' => 'csv'])) }}"
           class="btn btn-sm btn-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> تنزيل CSV
        </a>
        @if($isHistorical)
            <a href="{{ route('admin.reports.stock-valuation') }}" class="btn btn-sm btn-light">
                <i class="bi bi-arrow-clockwise me-1"></i> الصورة الحالية
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fs-12 mb-1">تقييم بتاريخ <small class="text-muted">(اتركه فارغاً للمخزون الحالي)</small></label>
                <input type="date" name="as_of" value="{{ $asOf }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> طبّق</button>
            </div>

            {{-- ABC summary mini-bar --}}
            <div class="col-md-6">
                <div class="d-flex gap-2 align-items-center justify-content-end">
                    @foreach($abcMeta as $k => $m)
                        <span class="badge bg-{{ $m['color'] }}-transparent text-{{ $m['color'] }} fs-12">
                            {{ $m['label'] }}:
                            {{ $abcCounts[$k] }} صنف
                            ({{ number_format($abcValues[$k], 2) }} ₪)
                        </span>
                    @endforeach
                </div>
            </div>
        </form>
    </x-slot:filters>

    @if($isHistorical)
        <div class="alert alert-info">
            <i class="bi bi-clock-history"></i>
            <strong>الوضع التاريخي:</strong>
            الكميات أُعيد بناؤها بإعادة عكس كل حركات ما بعد {{ $asOf }}.
            التكلفة = آخر تكلفة 'in' في أو قبل التاريخ.
        </div>
    @endif

    @if($rows->isEmpty())
        <x-admin.empty-state
            icon="bi-box-seam"
            title="لا أصناف للتقييم"
            message="لا يوجد مكونات نشطة مع تتبع مخزون." />
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>#</th>
                        <th>المكوّن</th>
                        <th>المورّد</th>
                        <th class="text-end">الكمية</th>
                        <th class="text-end">سعر الوحدة</th>
                        <th class="text-end">قيمة الصنف</th>
                        <th class="text-end">حصة %</th>
                        <th class="text-end">تراكمي %</th>
                        <th>فئة ABC</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $r)
                        @php
                            $sharePct = $totalValue > 0 ? ($r->value / $totalValue) * 100 : 0;
                            $abc = $abcMeta[$r->abc_class] ?? ['color' => 'secondary', 'label' => '—'];
                        @endphp
                        <tr class="{{ $r->is_low_stock ? 'table-warning' : '' }}">
                            <td class="text-muted">{{ $i + 1 }}</td>
                            <td>
                                <div class="fw-semibold">{{ $r->name }}</div>
                                @if($r->sku)
                                    <small class="text-muted">{{ $r->sku }}</small>
                                @endif
                            </td>
                            <td class="fs-12">{{ $r->supplier ?? '—' }}</td>
                            <td class="text-end {{ $r->qty <= 0 ? 'text-danger' : '' }}">
                                {{ number_format((float) $r->qty, 4) }}
                                <small class="text-muted">{{ $r->unit_code }}</small>
                            </td>
                            <td class="text-end">{{ number_format((float) $r->unit_cost, 4) }}</td>
                            <td class="text-end fw-bold text-primary">{{ number_format((float) $r->value, 2) }} ₪</td>
                            <td class="text-end fs-13">{{ number_format($sharePct, 2) }}%</td>
                            <td class="text-end fs-13">
                                <div class="d-inline-block position-relative" style="width: 80px;">
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-{{ $abc['color'] }}" style="width: {{ min(100, $r->cumulative_pct) }}%"></div>
                                    </div>
                                    <small class="text-muted">{{ number_format($r->cumulative_pct, 1) }}%</small>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-{{ $abc['color'] }}">{{ $r->abc_class }}</span>
                            </td>
                            <td>
                                <a href="{{ route('admin.vendor-prices.ingredient', $r->ingredient_id) }}"
                                   class="text-decoration-none fs-13" title="تاريخ الأسعار">
                                    <i class="bi bi-graph-up-arrow"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-light">
                    <tr>
                        <td colspan="5" class="text-end fw-bold">الإجمالي</td>
                        <td class="text-end fw-bold text-primary fs-15">{{ number_format($totalValue, 2) }} ₪</td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-3 fs-12 text-muted">
            <i class="bi bi-info-circle"></i>
            <strong>ABC:</strong>
            تحليل باريتو — صنف A يمثل أهم 80% من القيمة (يستحق أعلى تركيز إداري).
            صنف B يمثل التالي 15%. صنف C يمثل آخر 5%.
            استخدمها لتحديد أولوية الجرد، التفاوض مع المورّدين، ومتابعة الهدر.
        </div>
    @endif
</x-admin.data-panel>
@endsection
