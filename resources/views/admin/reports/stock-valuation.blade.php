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
    subtitle="قيمة كل صنف = كميته × تكلفته. يدعم نقطة زمنية في الماضي لإقفال الشهور المحاسبية."
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

{{-- ═══════════ Branch context chip + scope summary ═══════════ --}}
<div class="sv-context mb-3">
    <div class="sv-context__main">
        <i class="bi {{ $branchId ? 'bi-shop' : 'bi-building-fill' }}"></i>
        <span>التقرير معروض لـ:</span>
        <strong>{{ $branchName }}</strong>
        @if($branchId)
            <small class="text-muted ms-2">— كميات هذا الفرع فقط بأسعاره الفعلية</small>
        @else
            <small class="text-muted ms-2">— مجموع كل الفروع (Σ كمية الفرع × سعر الفرع)</small>
        @endif
    </div>
    <small class="text-muted">
        <i class="bi bi-info-circle"></i>
        غيّر الفرع من المبدّل أعلى الصفحة لرؤية تقييم فرع آخر.
    </small>
</div>

{{-- ═══════════ Help banner — explains the columns ═══════════ --}}
{{-- Native <details> instead of Alpine — works even if JS fails to load
     and the disclosure widget is built into every browser. Open by default
     so the user sees the column explanations immediately on first visit. --}}
<details class="sv-help mb-3" open>
    <summary class="sv-help__head">
        <span class="sv-help__title">
            <i class="bi bi-info-circle-fill"></i>
            كيف أقرأ هذا التقرير؟ (شرح الأعمدة)
        </span>
        <i class="bi bi-chevron-down sv-help__caret"></i>
    </summary>
    <div class="sv-help__body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="sv-help__card">
                    <h6><i class="bi bi-cash-stack text-primary"></i> الأعمدة المالية</h6>
                    <ul>
                        <li><b>الكمية</b>: المخزون الفعلي للصنف في الفرع المعروض.</li>
                        <li><b>سعر الوحدة</b>: متوسط مرجَّح من دفعات الشراء النشطة بهذا الفرع.</li>
                        <li><b>قيمة الصنف</b> = الكمية × سعر الوحدة. هذا هو المال المحبوس في هذا المكوّن.</li>
                        <li><b>حصة %</b>: حصة هذا الصنف من إجمالي قيمة المخزون.</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="sv-help__card">
                    <h6><i class="bi bi-pie-chart-fill text-warning"></i> «تراكمي %» وتحليل ABC</h6>
                    <ul>
                        <li><b>تراكمي %</b>: مجموع الحصص من أعلى صنف لهذا الصنف.</li>
                        <li>مثال: لو وصل لـ 80% عند الصف العاشر → أول 10 أصناف تشكّل 80% من قيمة مخزونك.</li>
                        <li><span class="badge bg-danger">A</span> أهم 80% من القيمة — جردها <b>أسبوعياً</b> وراقب تكلفتها.</li>
                        <li><span class="badge bg-warning text-dark">B</span> الـ 15% التالية — جردها <b>شهرياً</b>.</li>
                        <li><span class="badge bg-success">C</span> آخر 5% — جردها <b>كل ربع سنة</b>.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</details>

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
        <a href="{{ route('admin.reports.stock-valuation', array_merge(request()->query(), ['export' => 'xlsx'])) }}"
           class="btn btn-sm btn-success"
           title="تنزيل التقرير كملف Excel — ورقتان: ملخص + تفاصيل بأعمدة منفصلة">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> تصدير Excel
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
            {{-- ABC summary mini-bar --}}
            <div class="col-md-8">
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
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary btn-sm px-5"><i class="bi bi-search"></i> استعلام</button>
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
                        <th class="text-end" title="المخزون الفعلي للصنف في الفرع المعروض">
                            الكمية <i class="bi bi-info-circle text-muted small"></i>
                        </th>
                        <th class="text-end" title="متوسط مرجَّح من دفعات الشراء النشطة بهذا الفرع">
                            سعر الوحدة <i class="bi bi-info-circle text-muted small"></i>
                        </th>
                        <th class="text-end" title="الكمية × سعر الوحدة — المال المحبوس في هذا المكوّن">
                            قيمة الصنف <i class="bi bi-info-circle text-muted small"></i>
                        </th>
                        <th class="text-end" title="حصة هذا الصنف من إجمالي قيمة المخزون = القيمة ÷ الإجمالي × 100">
                            حصة % <i class="bi bi-info-circle text-muted small"></i>
                        </th>
                        <th class="text-end" title="مجموع الحصص من أعلى صنف لهذا الصنف. مثال: لو وصل لـ 80% عند الصف العاشر فأول 10 أصناف تشكّل 80% من مخزونك (نقطة باريتو).">
                            تراكمي % <i class="bi bi-info-circle text-muted small"></i>
                        </th>
                        <th title="A = أهم 80% من القيمة، B = التالي 15%، C = آخر 5%">
                            فئة ABC <i class="bi bi-info-circle text-muted small"></i>
                        </th>
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
                                {{ \App\Helpers\Qty::format($r->qty) }}
                                <small class="text-muted">{{ $r->unit_code }}</small>
                            </td>
                            <td class="text-end">{{ \App\Helpers\Qty::format($r->unit_cost) }}</td>
                            <td class="text-end fw-bold text-primary">{{ number_format((float) $r->value, 2) }} ₪</td>
                            <td class="text-end fs-13">{{ number_format($sharePct, 2) }}%</td>
                            <td class="text-end fs-13"
                                title="بعد هذا الصنف، تكون قد غطّيت {{ number_format($r->cumulative_pct, 1) }}% من قيمة مخزونك">
                                <div class="sv-cum">
                                    <span class="sv-cum__pct">{{ number_format($r->cumulative_pct, 1) }}%</span>
                                    <div class="progress sv-cum__bar">
                                        <div class="progress-bar bg-{{ $abc['color'] }}" style="width: {{ min(100, $r->cumulative_pct) }}%"></div>
                                    </div>
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

@push('styles')
<style>
    /* ─── Branch context chip ─────────────────────────────────────── */
    .sv-context {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
        gap: .75rem;
        background: linear-gradient(90deg, #f0fdf4 0%, #fff 100%);
        border: 1px solid #bbf7d0;
        border-radius: 12px;
        padding: .65rem 1rem;
    }
    .sv-context__main {
        display: inline-flex; align-items: center; gap: .5rem;
        font-size: .9rem; color: #14532d;
    }
    .sv-context__main i { font-size: 1.1rem; color: #16a34a; }
    .sv-context__main strong { color: #0f172a; font-size: 1rem; }

    /* ─── Help banner — native <details>/<summary> ─────────────────── */
    .sv-help {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        overflow: hidden;
    }
    /* Strip the browser's default disclosure triangle */
    .sv-help > summary { list-style: none; }
    .sv-help > summary::-webkit-details-marker { display: none; }
    .sv-help > summary::marker { display: none; content: ''; }

    .sv-help__head {
        display: flex; align-items: center; justify-content: space-between;
        background: linear-gradient(90deg, #eff6ff 0%, #fff 100%);
        padding: .85rem 1.1rem;
        font-weight: 700; cursor: pointer;
        transition: background .15s;
        user-select: none;
    }
    .sv-help__head:hover { background: linear-gradient(90deg, #dbeafe 0%, #fff 100%); }
    .sv-help__title { display: inline-flex; align-items: center; gap: .55rem; color: #1e3a8a; }
    .sv-help__title i { color: #2563eb; font-size: 1.1rem; }

    /* Rotate the caret to reflect open/closed state */
    .sv-help__caret {
        color: #64748b;
        transition: transform .2s ease;
    }
    .sv-help[open] .sv-help__caret { transform: rotate(180deg); }

    .sv-help__body { padding: 1rem 1.1rem; border-top: 1px solid #f1f5f9; }
    .sv-help__card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        height: 100%;
    }
    .sv-help__card h6 {
        font-weight: 800; color: #0f172a; margin-bottom: .65rem;
        display: inline-flex; align-items: center; gap: .35rem;
    }
    .sv-help__card ul {
        list-style: none; padding: 0; margin: 0;
        font-size: .82rem; color: #475569; line-height: 1.7;
    }
    .sv-help__card ul li { padding: 2px 0; }
    .sv-help__card ul li b { color: #0f172a; }

    /* ─── Cumulative % cell — clearer than the original ─────────── */
    .sv-cum {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 3px;
        min-width: 80px;
    }
    .sv-cum__pct {
        font-weight: 800;
        font-size: .82rem;
        font-variant-numeric: tabular-nums;
        color: #0f172a;
    }
    .sv-cum__bar { width: 80px; height: 6px; }

    [x-cloak] { display: none !important; }
</style>
@endpush
