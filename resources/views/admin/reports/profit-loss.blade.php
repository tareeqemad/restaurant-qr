@extends('layouts.admin')
@section('title', 'تقرير الأرباح والخسائر')

@section('content')
<x-admin.breadcrumb title="تقرير الأرباح والخسائر (P&L)" icon="bi-graph-up-arrow"
    subtitle="الإيرادات مقابل التكاليف = الربح الحقيقي"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

@php
    // Surface the per-branch cost toggle ONLY when in branch context.
    // Owner-level "all branches" view doesn't have a single per-branch cost
    // to fall back on, so we just hide the toggle there.
    $branchId      = \App\Support\BranchContext::current();
    $perBranchCost = $branchId && request()->boolean('per_branch_cost');
@endphp

<div class="data-panel-filters mb-3" style="background: white; border-radius: 14px; padding: 1rem; border: 1px solid rgba(var(--primary-rgb), .1);">
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
                <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}{{ $perBranchCost ? '&per_branch_cost=1' : '' }}" class="btn btn-light btn-sm">الشهر</a>
                <a href="?from={{ now()->subDays(6)->toDateString() }}&to={{ now()->toDateString() }}{{ $perBranchCost ? '&per_branch_cost=1' : '' }}"    class="btn btn-light btn-sm">7 أيام</a>
                <a href="?from={{ now()->toDateString() }}&to={{ now()->toDateString() }}{{ $perBranchCost ? '&per_branch_cost=1' : '' }}"              class="btn btn-light btn-sm">اليوم</a>
            </div>
        </div>
        <div class="col-12 text-center mt-2">
            <button class="btn btn-primary px-5">
                <i class="bi bi-search"></i> استعلام
            </button>
        </div>

        @if($branchId)
            {{-- Per-branch cost toggle: re-cost every sold item using the
                 branch-specific recipe cost (per-branch ingredient avg).
                 More accurate when branches buy from different suppliers
                 at different prices, at the cost of a heavier compute. --}}
            <div class="col-12 mt-2">
                <label class="d-inline-flex align-items-center gap-2 p-2 rounded"
                       style="background: rgba(var(--accent-rgb), .08); border: 1px solid rgba(var(--accent-rgb), .25); cursor: pointer;">
                    <input type="checkbox"
                           name="per_branch_cost"
                           value="1"
                           class="form-check-input m-0"
                           @checked($perBranchCost)
                           onchange="this.form.submit()">
                    <span class="fw-semibold">
                        <i class="bi bi-building text-accent"></i>
                        احسب COGS بسعر هذا الفرع
                    </span>
                    <small class="text-muted ms-2">
                        (يستخدم الـ weighted-average للمكوّنات في dieser الفرع بدلاً من السعر العام — أدقّ للـ P&L الفرعي)
                    </small>
                </label>
                @if($perBranchCost)
                    <span class="badge bg-accent-transparent text-accent ms-2">
                        <i class="bi bi-check-circle-fill"></i> مفعّل
                    </span>
                @endif
            </div>
        @endif
    </form>
</div>

{{-- KPI RAIL — the main P&L numbers --}}
<x-admin.stat-rail :stats="[
    ['label' => 'الإيرادات',       'value' => \App\Helpers\Money::format($revenue),      'icon' => 'bi-cash-stack',        'color' => 'primary'],
    ['label' => 'تكلفة المبيعات', 'value' => \App\Helpers\Money::format($cogs),         'icon' => 'bi-boxes',              'color' => 'muted'],
    ['label' => 'الربح الإجمالي', 'value' => \App\Helpers\Money::format($grossProfit),  'icon' => 'bi-graph-up-arrow',     'color' => $grossProfit >= 0 ? 'success' : 'danger'],
    ['label' => 'هامش الربح',      'value' => number_format($marginPct, 1).'%',         'icon' => 'bi-percent',            'color' => $marginPct >= 50 ? 'success' : ($marginPct >= 30 ? 'accent' : 'danger')],
]" />

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        {{-- Revenue vs COGS chart --}}
        <x-admin.data-panel title="الإيرادات مقابل التكاليف (يومياً)" icon="bi-bar-chart-line-fill">
            @php
                $maxVal = max($trend->pluck('revenue')->max(), $trend->pluck('cogs')->max()) ?: 1;
                $w = 720; $h = 260; $gap = 48; $n = $trend->count();
                $usableW = $w - $gap * 2;
                $cellW = $n > 1 ? $usableW / ($n - 1) : $usableW;
            @endphp
            <div class="p-3">
                <div class="mb-3 d-flex gap-3 flex-wrap">
                    <span class="d-inline-flex align-items-center gap-1">
                        <span style="width:14px; height:14px; background:var(--primary); border-radius:3px;"></span>
                        <small class="fw-bold">الإيرادات</small>
                    </span>
                    <span class="d-inline-flex align-items-center gap-1">
                        <span style="width:14px; height:14px; background:rgba(var(--accent-rgb),.4); border-radius:3px;"></span>
                        <small class="fw-bold">تكلفة المبيعات</small>
                    </span>
                    <span class="d-inline-flex align-items-center gap-1">
                        <span style="width:14px; height:14px; background:var(--accent); border-radius:3px;"></span>
                        <small class="fw-bold">الربح</small>
                    </span>
                </div>
                <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" style="width:100%; height:260px;">
                    {{-- grid lines --}}
                    @foreach([0.25, 0.5, 0.75] as $r)
                        <line x1="{{ $gap }}" y1="{{ $h - 40 - $r * ($h - 70) }}" x2="{{ $w - $gap }}" y2="{{ $h - 40 - $r * ($h - 70) }}"
                              stroke="rgba(31,71,51,.08)" stroke-dasharray="3 4"/>
                    @endforeach

                    @foreach($trend as $i => $d)
                        @php
                            $x = $gap + $i * $cellW;
                            $revH = ($d['revenue'] / $maxVal) * ($h - 70);
                            $cogH = ($d['cogs']    / $maxVal) * ($h - 70);
                            $profH = max(0, ($d['profit'] / $maxVal) * ($h - 70));
                        @endphp
                        {{-- Revenue bar --}}
                        <rect x="{{ $x - 14 }}" y="{{ $h - 40 - $revH }}" width="8" height="{{ $revH }}"
                              fill="var(--primary)" rx="2"/>
                        {{-- COGS bar --}}
                        <rect x="{{ $x - 4 }}" y="{{ $h - 40 - $cogH }}" width="8" height="{{ $cogH }}"
                              fill="rgba(184,135,42,.4)" rx="2"/>
                        {{-- Profit bar --}}
                        <rect x="{{ $x + 6 }}" y="{{ $h - 40 - $profH }}" width="8" height="{{ $profH }}"
                              fill="var(--accent)" rx="2"/>

                        <text x="{{ $x }}" y="{{ $h - 18 }}" font-size="11" text-anchor="middle" fill="#78716c" font-weight="700">
                            {{ \Carbon\Carbon::parse($d['date'])->format('d/m') }}
                        </text>
                    @endforeach
                </svg>
            </div>
        </x-admin.data-panel>
    </div>

    {{-- Summary breakdown card --}}
    <div class="col-xl-4">
        <x-admin.data-panel title="ملخص الحساب" icon="bi-calculator-fill">
            <div class="p-3">
                @php
                    // Build a bar-like visual where each row is a line item
                    $maxRow = max($revenue, $cogs, $wasteCost, $purchasesCost, 1);
                @endphp
                <div class="pl-row" style="padding: 10px 0; border-bottom: 1px dashed rgba(var(--primary-rgb), .1);">
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <span class="fw-bold" style="color: var(--primary);">
                            <i class="bi bi-arrow-down-circle-fill"></i> الإيرادات
                        </span>
                        <span class="fw-bold fs-5" style="color: var(--primary); font-variant-numeric: tabular-nums;">
                            {{ \App\Helpers\Money::format($revenue) }}
                        </span>
                    </div>
                    <small class="text-muted">{{ $invoiceCount }} فاتورة</small>
                </div>

                <div class="pl-row" style="padding: 10px 0; border-bottom: 1px dashed rgba(var(--primary-rgb), .1);">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="fw-bold text-muted">
                            <i class="bi bi-dash-circle-fill"></i> تكلفة المبيعات
                        </span>
                        <span class="fw-bold" style="color: #8a6920; font-variant-numeric: tabular-nums;">
                            − {{ \App\Helpers\Money::format($cogs) }}
                        </span>
                    </div>
                </div>

                <div class="pl-row" style="padding: 10px 0; border-bottom: 2px solid var(--accent); background: linear-gradient(90deg, rgba(var(--accent-rgb),.05), transparent);">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="fw-bold" style="color: var(--primary);">
                            <i class="bi bi-equals"></i> الربح الإجمالي
                        </span>
                        <span class="fw-bold fs-4" style="color: {{ $grossProfit >= 0 ? 'var(--primary)' : '#b91c1c' }}; font-variant-numeric: tabular-nums;">
                            {{ \App\Helpers\Money::format($grossProfit) }}
                        </span>
                    </div>
                </div>

                <div class="pl-row" style="padding: 10px 0; border-bottom: 1px dashed rgba(var(--primary-rgb), .1);">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="fw-bold" style="color: #b91c1c;">
                            <i class="bi bi-trash3-fill"></i> الهدر
                        </span>
                        <span class="fw-bold" style="color: #b91c1c; font-variant-numeric: tabular-nums;">
                            − {{ \App\Helpers\Money::format($wasteCost) }}
                        </span>
                    </div>
                </div>

                <div class="pl-row" style="padding: 14px 10px; margin-top: 6px; background: linear-gradient(135deg, var(--primary), var(--dark)); color: white; border-radius: 12px;">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="fw-bold">
                            <i class="bi bi-star-fill" style="color: var(--accent);"></i> صافي الربح التشغيلي
                        </span>
                        <span class="fw-bold fs-3" style="color: {{ $netOperating >= 0 ? '#e8c775' : '#fca5a5' }}; font-variant-numeric: tabular-nums;">
                            {{ \App\Helpers\Money::format($netOperating) }}
                        </span>
                    </div>
                </div>

                <hr style="border-color: rgba(var(--accent-rgb), .2);">
                <div class="d-flex justify-content-between text-muted small">
                    <span>
                        <i class="bi bi-box-arrow-in-down"></i> المشتريات (تدفق نقدي)
                    </span>
                    <span style="font-variant-numeric: tabular-nums;">
                        {{ \App\Helpers\Money::format($purchasesCost) }}
                    </span>
                </div>
            </div>
        </x-admin.data-panel>
    </div>
</div>

{{-- Top profitable items --}}
<x-admin.data-panel title="الأكثر ربحية" :count="$topProfit->count()" icon="bi-trophy-fill">
    @if($topProfit->isEmpty())
        <x-admin.empty-state
            icon="bi-trophy"
            title="ما في بيانات ربحية"
            message="تأكد من وجود مبيعات في الفترة المختارة ومن حساب تكاليف أصناف المنيو من الوصفات." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>#</th>
                        <th>الصنف</th>
                        <th>الكمية</th>
                        <th>الإيرادات</th>
                        <th>التكلفة</th>
                        <th>الربح</th>
                        <th>هامش</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($topProfit as $idx => $item)
                        @php
                            $rev = (float) $item->revenue;
                            $margin = $rev > 0 ? ($item->profit / $rev) * 100 : 0;
                            $mColor = $margin >= 60 ? 'var(--primary)' : ($margin >= 30 ? '#8a6920' : '#b91c1c');
                        @endphp
                        <tr>
                            <td>
                                <span class="top-item-rank {{ $idx === 0 ? 'first' : '' }}">{{ $idx + 1 }}</span>
                            </td>
                            <td class="fw-bold">{{ $item->name }}</td>
                            <td>{{ number_format((float) $item->qty, 0) }}</td>
                            <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($item->revenue) }}</td>
                            <td class="text-muted">{{ \App\Helpers\Money::format($item->cogs) }}</td>
                            <td class="fw-bold" style="color: {{ $mColor }};">{{ \App\Helpers\Money::format($item->profit) }}</td>
                            <td>
                                <span class="badge" style="background: rgba({{ $margin >= 30 ? 'var(--primary-rgb)' : '185,28,28' }}, .12); color: {{ $mColor }};">
                                    {{ number_format($margin, 1) }}%
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin.data-panel>

@push('styles')
<style>
@media print {
    .app-sidebar, .app-header, .breadcrumb-list, .data-panel-filters form, .btn { display: none !important; }
    .app-content { margin: 0 !important; }
}
</style>
@endpush
@endsection
