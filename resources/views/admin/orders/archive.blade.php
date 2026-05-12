@extends('layouts.admin')
@section('title', 'أرشيف الطلبات')

@php
    use App\Helpers\Money;
    $showBranchCol = (bool) session('view_all_branches');
    $f = $filters;
@endphp

@section('content')
<x-admin.breadcrumb title="أرشيف الطلبات" icon="bi-archive"
    subtitle="بحث وتصفية شاملة لكل الطلبات — حسب التاريخ، الحالة، المصدر، الطاولة، والمبلغ" />

{{-- ─── Stats ──────────────────────────────────────────────────── --}}
<x-admin.stat-rail :stats="[
    ['label' => 'عدد الطلبات',   'value' => number_format($stats['count']),         'icon' => 'bi-receipt-cutoff', 'color' => 'primary'],
    ['label' => 'إجمالي المبيعات','value' => Money::format($stats['gross']),       'icon' => 'bi-cash-stack',    'color' => 'success'],
    ['label' => 'متوسط الفاتورة', 'value' => Money::format($stats['avg']),         'icon' => 'bi-graph-up',      'color' => 'accent'],
    ['label' => 'ملغاة',          'value' => number_format($stats['cancelled']),   'icon' => 'bi-x-octagon',     'color' => 'muted'],
]" />

{{-- ─── Prep-timing performance KPIs ─────────────────────────────
     Only renders when there's at least one measured order in the
     filtered window (i.e., something actually went through preparing).
     For drinks-only / cancelled-heavy queries we skip the row to avoid
     "0% on time" looking like a fire-alarm when really there's nothing
     to measure. --}}
@if(($timingStats['measured'] ?? 0) > 0)
    @php
        $delayMin = (float) $timingStats['avg_delay'];
        $pct      = (float) ($timingStats['on_time_pct'] ?? 0);
        $delayLbl = $delayMin >= 0
            ? '+' . number_format($delayMin, 1) . ' د'
            : number_format($delayMin, 1) . ' د';
        // Color logic: on_time_pct → traffic-light
        //   ≥ 80% green · 50-79% amber · < 50% red
        $pctColor = $pct >= 80 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
        $delayColor = $delayMin <= 0 ? 'success' : ($delayMin <= 5 ? 'warning' : 'danger');
    @endphp
    <x-admin.stat-rail :stats="[
        ['label' => 'طلبات تم قياسها',
         'value' => number_format($timingStats['measured']),
         'icon'  => 'bi-stopwatch',
         'color' => 'info'],
        ['label' => 'وصلت في الوقت',
         'value' => $pct . '%',
         'icon'  => 'bi-check2-circle',
         'color' => $pctColor],
        ['label' => 'متوسط الوقت الفعلي',
         'value' => number_format($timingStats['avg_actual'], 1) . ' د',
         'icon'  => 'bi-clock-history',
         'color' => 'primary'],
        ['label' => 'متوسط الفرق (متأخر +)',
         'value' => $delayLbl,
         'icon'  => $delayMin > 0 ? 'bi-arrow-up-circle' : 'bi-arrow-down-circle',
         'color' => $delayColor],
    ]" />
@endif

{{-- ─── Filter card (collapsible advanced section) ──────────────── --}}
<div class="archive-filters">
    <form method="GET" id="archiveFilters">
        <div class="archive-filters__bar">
            <div class="archive-filters__search">
                <i class="bi bi-search"></i>
                <input type="text" name="search" value="{{ $f['search'] }}"
                       placeholder="رقم الطلب، العميل، الهاتف، الملاحظات، المرجع الخارجي…"
                       autocomplete="off">
                @if($f['search'])
                    <button type="button" class="archive-filters__clear-search"
                            onclick="this.previousElementSibling.value=''; this.closest('form').submit();">
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                @endif
            </div>

            <div class="archive-filters__dates">
                <label>من
                    <input type="date" name="from" value="{{ $f['from'] }}">
                </label>
                <label>إلى
                    <input type="date" name="to" value="{{ $f['to'] }}">
                </label>
            </div>

        </div>

        <div class="archive-filters__actions justify-content-center mt-3">
            <button type="submit" class="btn btn-primary px-5">
                <i class="bi bi-funnel-fill"></i> استعلام
            </button>
            <button type="button" class="btn btn-light"
                    onclick="document.getElementById('archiveAdvanced').classList.toggle('is-open')">
                <i class="bi bi-sliders"></i> فلاتر متقدّمة
            </button>
            @if(request()->hasAny(['search', 'status', 'source', 'order_type', 'table_id', 'min_total', 'max_total', 'sort', 'dir', 'from', 'to', 'delayed_only']))
                <a href="{{ route('admin.orders.archive') }}" class="btn btn-outline-danger">
                    <i class="bi bi-x-circle"></i> مسح
                </a>
            @endif
        </div>

        {{-- Advanced filters (collapsed by default) --}}
        <div class="archive-filters__advanced
                    {{ array_filter([$f['status'], $f['source'], $f['table_id'], $f['min_total'], $f['max_total']]) ? 'is-open' : '' }}"
             id="archiveAdvanced">

            {{-- Statuses — multi-select chips --}}
            <div class="archive-filters__group">
                <label class="archive-filters__label">حالة الطلب</label>
                <div class="status-chips">
                    @foreach($statuses as $st)
                        @php $checked = in_array($st->value, (array) $f['status'], true); @endphp
                        <label class="status-chip status-chip--{{ $st->color() }} {{ $checked ? 'is-checked' : '' }}">
                            <input type="checkbox" name="status[]" value="{{ $st->value }}" @checked($checked)>
                            <span>{{ $st->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="archive-filters__row">
                {{-- Source --}}
                <div class="archive-filters__group">
                    <label class="archive-filters__label">المصدر</label>
                    <select name="source" class="form-select">
                        <option value="">— كل المصادر —</option>
                        @foreach($sources as $sr)
                            <option value="{{ $sr->value }}" @selected($f['source'] === $sr->value)>{{ $sr->label() }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Table --}}
                <div class="archive-filters__group">
                    <label class="archive-filters__label">نوع الطلب</label>
                    <select name="order_type" class="form-select">
                        <option value="">— كل الأنواع —</option>
                        @foreach($orderTypes as $key => $label)
                            <option value="{{ $key }}" @selected($f['order_type'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="archive-filters__group">
                    <label class="archive-filters__label">الطاولة</label>
                    <select name="table_id" class="form-select">
                        <option value="">— كل الطاولات —</option>
                        @foreach($tables as $t)
                            <option value="{{ $t->id }}" @selected((int) $f['table_id'] === (int) $t->id)>طاولة {{ $t->number }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Total range --}}
                <div class="archive-filters__group">
                    <label class="archive-filters__label">المبلغ</label>
                    <div class="archive-filters__range">
                        <input type="number" name="min_total" value="{{ $f['min_total'] }}"
                               class="form-control" placeholder="من" step="0.01" min="0">
                        <span>—</span>
                        <input type="number" name="max_total" value="{{ $f['max_total'] }}"
                               class="form-control" placeholder="إلى" step="0.01" min="0">
                    </div>
                </div>

                {{-- Sort --}}
                <div class="archive-filters__group">
                    <label class="archive-filters__label">الترتيب</label>
                    <div class="archive-filters__sort">
                        <select name="sort" class="form-select">
                            <option value="created_at" @selected($f['sort'] === 'created_at')>الوقت</option>
                            <option value="total"      @selected($f['sort'] === 'total')>المبلغ</option>
                            <option value="number"     @selected($f['sort'] === 'number')>الرقم</option>
                        </select>
                        <select name="dir" class="form-select">
                            <option value="desc" @selected($f['dir'] === 'desc')>تنازلياً</option>
                            <option value="asc"  @selected($f['dir'] === 'asc')>تصاعدياً</option>
                        </select>
                    </div>
                </div>

                {{-- Delayed-only quick filter — checkbox styled as a toggle.
                     Submits with the form on change so the manager doesn't
                     hunt for the "filter" button. Hidden 0 sends false when
                     unchecked. --}}
                <div class="archive-filters__group">
                    <label class="archive-filters__label">أداء التحضير</label>
                    <label class="archive-filters__toggle"
                           title="عرض الطلبات التي تأخّر فيها وقت التحضير الفعلي عن المتوقع">
                        <input type="hidden" name="delayed_only" value="0">
                        <input type="checkbox" name="delayed_only" value="1"
                               @checked(! empty($f['delayed_only']))
                               onchange="this.form.submit()">
                        <span>
                            <i class="bi bi-stopwatch-fill"></i>
                            المتأخرة فقط
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="archive-summary mb-3">
    <div>
        <span class="archive-summary__label">مكتملة</span>
        <strong class="text-success">{{ number_format($filteredStats['completed']) }}</strong>
    </div>
    <div>
        <span class="archive-summary__label">طلبات خارجية</span>
        <strong>{{ number_format($filteredStats['external']) }}</strong>
    </div>
    <div>
        <span class="archive-summary__label">عمولات منصات</span>
        <strong class="text-danger">{{ Money::format($filteredStats['commission']) }}</strong>
    </div>
    <div>
        <span class="archive-summary__label">صافي بعد العمولة</span>
        <strong class="text-primary">{{ Money::format($filteredStats['net']) }}</strong>
    </div>
</div>

@if($sourceBreakdown->isNotEmpty())
    <div class="archive-source-rail mb-3">
        @foreach($sourceBreakdown as $row)
            @php $src = \App\Enums\OrderSource::tryFrom($row->order_source ?? ''); @endphp
            <a href="{{ route('admin.orders.archive', array_merge(request()->query(), ['source' => $row->order_source])) }}"
               class="archive-source-chip text-decoration-none"
               style="{{ $src ? 'border-color:'.$src->color().'40;color:'.$src->color().';background:'.$src->color().'12;' : '' }}">
                @if($src)<i class="bi {{ $src->icon() }}"></i>@endif
                <span>{{ $src?->label() ?? ($row->order_source ?: 'غير محدد') }}</span>
                <strong>{{ Money::format($row->total) }}</strong>
                <em>{{ number_format($row->count) }}</em>
            </a>
        @endforeach
    </div>
@endif

{{-- ─── Results table ──────────────────────────────────────────── --}}
<x-admin.data-panel title="النتائج" :count="$orders->total()" icon="bi-table">
    <div class="table-responsive">
        <table class="table align-middle archive-table">
            <thead class="bg-light">
                <tr>
                    <th>الرقم</th>
                    @if($showBranchCol)<th>الفرع</th>@endif
                    <th>التاريخ</th>
                    <th>العميل</th>
                    <th>المصدر</th>
                    <th>النوع</th>
                    <th>الطاولة</th>
                    <th>الأصناف</th>
                    <th>المبلغ</th>
                    <th>الصافي</th>
                    <th>الحالة</th>
                    <th title="مقارنة وقت التحضير المتوقع بالفعلي. الأخضر = في الوقت / مبكّر، الأصفر = تأخير ≤ 5 د، الأحمر = تأخير أكبر.">
                        وقت التحضير <i class="bi bi-info-circle text-muted small"></i>
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $o)
                    @php
                        $st  = \App\Enums\OrderStatus::tryFrom($o->status);
                        $src = \App\Enums\OrderSource::tryFrom($o->order_source ?? '');
                    @endphp
                    <tr>
                        <td><code class="archive-num">{{ $o->number }}</code></td>
                        @if($showBranchCol)
                            <td><x-admin.branch-tag :branch="$o->branch" /></td>
                        @endif
                        <td>
                            <div>{{ $o->created_at->format('Y/m/d') }}</div>
                            <small class="text-muted">{{ $o->created_at->format('H:i') }}</small>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $o->customer_name ?: '—' }}</div>
                            @if($o->customer_phone)
                                <small class="text-muted">{{ $o->customer_phone }}</small>
                            @elseif($o->external_reference)
                                <small class="text-muted">مرجع: {{ $o->external_reference }}</small>
                            @endif
                        </td>
                        <td>
                            @if($src)
                                <span class="badge"
                                      style="background: {{ $src->color() }}20; color: {{ $src->color() }};">
                                    <i class="bi {{ $src->icon() }}"></i> {{ $src->label() }}
                                </span>
                                @if($o->external_reference)
                                    <small class="text-muted d-block">{{ $o->external_reference }}</small>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $orderTypes[$o->order_type] ?? $o->order_type }}</td>
                        <td>{{ $o->table?->number ?? '—' }}</td>
                        <td>
                            <span class="badge bg-light text-dark">
                                {{ $o->items_count ?? $o->items?->count() ?? 0 }}
                            </span>
                        </td>
                        <td class="fw-bold">{{ Money::format($o->total) }}</td>
                        <td>
                            <span class="archive-net">{{ Money::format($o->netRevenue()) }}</span>
                            @if((float) $o->platform_commission_pct > 0)
                                <small class="text-danger d-block">{{ number_format((float) $o->platform_commission_pct, 1) }}%</small>
                            @endif
                        </td>
                        <td>
                            @if($st)
                                <span class="badge bg-{{ $st->color() }}">{{ $st->label() }}</span>
                            @endif
                        </td>
                        {{-- Prep-timing cell — three pieces of info:
                             expected (faint), actual (bold), variance (badge).
                             Empty dash when the order never entered preparing
                             (cancelled early, drinks-only, etc.). --}}
                        <td>
                            @php
                                $estMin    = (int) ($o->estimated_prep_minutes ?? 0);
                                $hasStart  = $o->prep_started_at !== null;
                                $hasReady  = $o->ready_at !== null;
                                $isCooking = $hasStart && ! $hasReady;
                                // Use signed diff and reject anything where
                                // ready_at landed BEFORE prep_started_at —
                                // that means the order skipped (or was forced
                                // through) preparing and the data is bogus.
                                $rawActual = ($hasStart && $hasReady)
                                    ? (int) round($o->prep_started_at->diffInMinutes($o->ready_at, false))
                                    : null;
                                $actualMin = ($rawActual !== null && $rawActual >= 0) ? $rawActual : null;
                                $isBogus   = $rawActual !== null && $rawActual < 0;
                                $delta     = ($actualMin !== null && $estMin > 0)
                                    ? $actualMin - $estMin
                                    : null;
                                // Variance bucket:
                                //   ≤ 0   → on-time / early (green)
                                //   1-5  → minor delay (amber)
                                //   > 5  → significant delay (red)
                                $varClass = $delta === null
                                    ? 'arx-var--none'
                                    : ($delta <= 0
                                        ? 'arx-var--good'
                                        : ($delta <= 5 ? 'arx-var--warn' : 'arx-var--bad'));
                            @endphp
                            @if($actualMin !== null)
                                <div class="arx-timing">
                                    <div class="arx-timing__line">
                                        <span class="arx-timing__est" title="متوقع">{{ $estMin > 0 ? $estMin . 'د' : '—' }}</span>
                                        <span class="arx-timing__sep">→</span>
                                        <span class="arx-timing__act" title="فعلي">{{ $actualMin }}د</span>
                                    </div>
                                    @if($delta !== null)
                                        <span class="arx-var {{ $varClass }}"
                                              title="{{ $delta > 0 ? 'متأخر بـ' : 'مبكّر بـ' }} {{ abs($delta) }} دقيقة">
                                            <i class="bi {{ $delta > 0 ? 'bi-arrow-up-short' : ($delta < 0 ? 'bi-arrow-down-short' : 'bi-check2') }}"></i>
                                            {{ $delta === 0 ? 'في الوقت' : ($delta > 0 ? '+' . $delta : $delta) . 'د' }}
                                        </span>
                                    @endif
                                </div>
                            @elseif($isCooking)
                                <small class="text-warning">
                                    <i class="bi bi-fire"></i>
                                    قيد التحضير منذ
                                    {{ (int) max(1, round($o->prep_started_at->diffInMinutes(now()))) }}د
                                </small>
                            @elseif($isBogus)
                                <small class="text-muted" title="‫ready_at‬ سابق ل‫prep_started_at‬ — بيانات غير متّسقة">
                                    <i class="bi bi-exclamation-triangle"></i> —
                                </small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.orders.show', $o) }}" class="btn btn-sm btn-light" title="تفاصيل">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showBranchCol ? 13 : 12 }}">
                            <x-admin.empty-state icon="bi-archive"
                                title="لا توجد نتائج"
                                message="جرّب تعديل الفلاتر أو توسيع نطاق التواريخ." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($orders->hasPages())
        <x-slot:footer>{{ $orders->links() }}</x-slot:footer>
    @endif
</x-admin.data-panel>

@push('styles')
<style>
    /* ╔══════════════════════════════════════════════════════════════╗
       ║  Orders archive — filter card + status chips                   ║
       ╚══════════════════════════════════════════════════════════════╝ */
    .archive-filters {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 14px 16px;
        margin-bottom: 16px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }

    .archive-filters__bar {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 10px;
        align-items: center;
    }
    @media (max-width: 992px) {
        .archive-filters__bar { grid-template-columns: 1fr; }
    }

    /* Search input — pill with icon */
    .archive-filters__search {
        position: relative;
    }
    .archive-filters__search > i.bi-search {
        position: absolute;
        inset-inline-start: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 1rem;
        pointer-events: none;
    }
    .archive-filters__search input {
        width: 100%;
        height: 42px;
        padding: 0 38px 0 14px;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        font: inherit;
        font-size: .92rem;
    }
    .archive-filters__search input:focus {
        outline: none;
        background: #fff;
        border-color: rgba(var(--primary-rgb), .55);
        box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .12);
    }
    .archive-filters__clear-search {
        position: absolute;
        inset-inline-end: 10px;
        top: 50%;
        transform: translateY(-50%);
        border: 0; background: transparent;
        color: #9ca3af;
        cursor: pointer;
        padding: 4px;
        line-height: 1;
    }
    .archive-filters__clear-search:hover { color: #ef4444; }

    /* Date range */
    .archive-filters__dates {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #f9fafb;
        padding: 5px 8px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
    }
    .archive-filters__dates label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin: 0;
        font-size: .82rem;
        font-weight: 600;
        color: #4b5563;
    }
    .archive-filters__dates input[type="date"] {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        padding: 6px 10px;
        font: inherit;
        font-size: .85rem;
    }

    .archive-filters__actions {
        display: inline-flex;
        gap: 6px;
        align-items: center;
        flex-wrap: wrap;
    }

    /* Advanced section — collapses by default, expands when toggled or
       when any advanced filter is active. */
    .archive-filters__advanced {
        max-height: 0;
        overflow: hidden;
        transition: max-height .25s ease, padding-top .2s ease;
        padding-top: 0;
    }
    .archive-filters__advanced.is-open {
        max-height: 600px;
        padding-top: 14px;
        margin-top: 12px;
        border-top: 1px dashed #e5e7eb;
    }

    .archive-filters__group { margin-bottom: 12px; }
    .archive-filters__label {
        display: block;
        font-size: .78rem;
        font-weight: 700;
        color: #4b5563;
        margin-bottom: 6px;
    }
    .archive-filters__row {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 12px;
    }
    @media (max-width: 992px) {
        .archive-filters__row { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 576px) {
        .archive-filters__row { grid-template-columns: 1fr; }
    }

    .archive-filters__range {
        display: flex; align-items: center; gap: 6px;
    }
    .archive-filters__range span { color: #9ca3af; font-weight: 700; }

    .archive-filters__sort {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 6px;
    }

    /* Status chips — multi-select toggleable pills */
    .status-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .status-chip {
        --chip-color: #6b7280;
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        background: #fff;
        border: 1.5px solid #e5e7eb;
        border-radius: 99px;
        font-size: .8rem;
        font-weight: 700;
        color: #4b5563;
        cursor: pointer;
        user-select: none;
        transition: all .12s ease;
        margin: 0;
    }
    .status-chip input { display: none; }
    .status-chip:hover { border-color: #d1d5db; background: #f9fafb; }
    .status-chip.is-checked {
        background: var(--bs-warning, #fbbf24);
        border-color: var(--bs-warning, #fbbf24);
        color: #fff;
    }
    .status-chip--success.is-checked  { background: #10b981; border-color: #10b981; color: #fff; }
    .status-chip--info.is-checked     { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    .status-chip--primary.is-checked  { background: rgb(var(--primary-rgb)); border-color: rgb(var(--primary-rgb)); color: #fff; }
    .status-chip--warning.is-checked  { background: #f59e0b; border-color: #f59e0b; color: #fff; }
    .status-chip--danger.is-checked   { background: #ef4444; border-color: #ef4444; color: #fff; }
    .status-chip--dark.is-checked     { background: #1f2937; border-color: #1f2937; color: #fff; }
    .status-chip--secondary.is-checked{ background: #6b7280; border-color: #6b7280; color: #fff; }

    /* Table polish */
    .archive-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
    }
    .archive-summary > div {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fff;
        padding: 10px 12px;
        min-width: 0;
    }
    .archive-summary__label {
        display: block;
        color: #6b7280;
        font-size: .76rem;
        margin-bottom: 3px;
    }
    .archive-summary strong,
    .archive-net {
        font-family: ui-monospace, monospace;
        font-weight: 800;
    }
    .archive-source-rail {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .archive-source-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fff;
        color: #374151;
        padding: 7px 11px;
        font-size: .82rem;
        font-weight: 700;
    }
    .archive-source-chip strong {
        font-family: ui-monospace, monospace;
    }
    .archive-source-chip em {
        background: rgba(255,255,255,.7);
        border-radius: 99px;
        color: #6b7280;
        font-style: normal;
        padding: 1px 7px;
        font-size: .72rem;
    }
    .archive-num {
        font-family: ui-monospace, monospace;
        font-size: .85rem;
        background: #f3f4f6;
        padding: 3px 8px;
        border-radius: 5px;
        color: #1f2937;
    }
    @media (max-width: 767.98px) {
        .archive-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    /* ─── Prep-timing column — compact 2-line cell ───────────────── */
    .arx-timing {
        display: flex; flex-direction: column;
        gap: 2px;
        line-height: 1.3;
    }
    .arx-timing__line {
        display: inline-flex; align-items: center; gap: 4px;
        font-variant-numeric: tabular-nums;
        font-size: .82rem;
    }
    .arx-timing__est { color: #94a3b8; font-weight: 500; }
    .arx-timing__sep { color: #cbd5e1; font-size: .7rem; }
    .arx-timing__act { color: #0f172a; font-weight: 800; }
    .arx-var {
        display: inline-flex; align-items: center; gap: 2px;
        padding: 1px 7px;
        border-radius: 99px;
        font-size: .7rem;
        font-weight: 700;
        white-space: nowrap;
        width: fit-content;
    }
    .arx-var i { font-size: .8rem; }
    .arx-var--good { background: #d1fae5; color: #065f46; }
    .arx-var--warn { background: #fef3c7; color: #92400e; }
    .arx-var--bad  { background: #fee2e2; color: #991b1b; }
    .arx-var--none { background: #f1f5f9; color: #475569; }

    /* ─── Delayed-only toggle (filter card) ──────────────────────── */
    .archive-filters__toggle {
        display: inline-flex; align-items: center; gap: .55rem;
        padding: .5rem .85rem;
        background: #fff;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        cursor: pointer;
        font-size: .85rem;
        font-weight: 600;
        color: #475569;
        margin: 0;
        transition: border-color .15s, background .15s, color .15s;
        user-select: none;
    }
    .archive-filters__toggle:hover { border-color: #cbd5e1; background: #f8fafc; }
    .archive-filters__toggle input[type="checkbox"] {
        margin: 0;
        accent-color: #dc2626;
        width: 16px; height: 16px;
    }
    .archive-filters__toggle:has(input:checked) {
        border-color: #fca5a5;
        background: #fef2f2;
        color: #991b1b;
    }
    .archive-filters__toggle:has(input:checked) i { color: #dc2626; }
</style>
@endpush

@push('scripts')
<script>
/**
 * Toggle .is-checked on the status chip when the hidden checkbox flips,
 * and submit the form when the user clicks any chip — feels instant
 * even though we're using a normal HTML form.
 */
document.querySelectorAll('.status-chip input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', (e) => {
        e.target.closest('.status-chip').classList.toggle('is-checked', e.target.checked);
    });
});
</script>
@endpush
@endsection
