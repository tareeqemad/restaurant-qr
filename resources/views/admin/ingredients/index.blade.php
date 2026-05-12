@extends('layouts.admin')
@section('title','المكونات')

@php
    // When a branch is active, show per-branch numbers (sum of ingredient_stock
    // across the branch's storage_locations). Owner-level views with no
    // branch context fall back to the global current_stock.
    $activeBranchId = \App\Support\BranchContext::current();
    $activeBranchName = $activeBranchId ? \App\Models\Branch::find($activeBranchId)?->name : null;
@endphp

@section('content')
<x-admin.breadcrumb title="المكونات والمخزون" icon="bi-basket2-fill"
    subtitle="{{ $activeBranchName ? 'مخزون فرع «'.$activeBranchName.'»' : 'مخزون شامل لكل الفروع' }}" />

@php
    // Each card links back to the index with its filter applied. Clicking
    // the active card a second time clears it. The `search` value is
    // preserved so users don't lose their query.
    $linkFor = function (?string $status) use ($statusFilter) {
        $params = request()->only('search');
        if ($status && $statusFilter !== $status) $params['status'] = $status;
        return route('admin.ingredients.index', $params);
    };
    $activeMark = fn ($key) => ($statusFilter === $key) || (!$statusFilter && $key === null) ? ' • نشط' : '';
@endphp
<style>
    .stat-rail-item[href] { transition: transform .15s ease, box-shadow .15s ease; cursor: pointer; }
    .stat-rail-item[href]:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.07); }

    /* ─── Per-location stock chips (column "المواقع") ──────────────── */
    .loc-chips {
        display: flex; flex-wrap: wrap; gap: 4px;
        max-width: 220px;
    }
    .loc-chip {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 2px 8px;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #14532d;
        border-radius: 99px;
        font-size: .72rem; font-weight: 600;
        line-height: 1.5;
        white-space: nowrap;
    }
    .loc-chip i { font-size: .7rem; color: #16a34a; }
    .loc-chip__name { font-weight: 700; }
    .loc-chip__qty {
        background: #fff;
        border: 1px solid #bbf7d0;
        padding: 0 6px;
        border-radius: 99px;
        font-variant-numeric: tabular-nums;
    }

    /* ─── Row action buttons — single horizontal line, never wrap ─── */
    .ing-actions-cell {
        white-space: nowrap;
        min-width: 165px;
        text-align: end;
    }
    .ing-actions {
        display: inline-flex;
        flex-wrap: nowrap;
        gap: 4px;
        align-items: center;
        justify-content: flex-end;
    }
    .ing-actions .ing-act-btn {
        width: 32px; height: 32px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        line-height: 1;
    }
    .ing-actions .ing-act-btn i { font-size: .9rem; }
    .ing-actions form { display: inline-flex; }
</style>
<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي المكونات' . $activeMark(null),
        'value' => $stats['total'],
        'icon' => 'bi-basket2-fill',
        'color' => 'primary',
        'link' => $linkFor(null)],
    ['label' => 'مخزون صحي' . $activeMark('healthy'),
        'value' => $stats['healthy'],
        'icon' => 'bi-check-circle-fill',
        'color' => 'success',
        'link' => $linkFor('healthy')],
    ['label' => 'مخزون منخفض' . $activeMark('low'),
        'value' => $stats['low_stock'],
        'icon' => 'bi-exclamation-triangle-fill',
        'color' => 'accent',
        'link' => $linkFor('low')],
    ['label' => 'نفد المخزون' . $activeMark('out'),
        'value' => $stats['out_stock'],
        'icon' => 'bi-x-octagon-fill',
        'color' => 'danger',
        'link' => $linkFor('out')],
]" />

<x-admin.data-panel title="قائمة المكونات" :count="$ingredients->total()" icon="bi-basket2-fill">
    <x-slot:actions>
        <a href="{{ route('admin.ingredients.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> مكون جديد
        </a>
        <a href="{{ route('admin.ingredients.index', array_merge(request()->only(['search', 'status', 'storage_location_id']), ['export' => 'xlsx'])) }}"
           class="btn btn-success" title="تنزيل القائمة الحالية كملف Excel">
            <i class="bi bi-file-earmark-excel"></i> تصدير Excel
        </a>
        @if(request()->hasAny(['search', 'status', 'low_stock']))
            <a href="{{ route('admin.ingredients.index') }}" class="btn btn-light">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-4">
                <input name="search" value="{{ request('search') }}" class="form-control"
                       placeholder="🔍 ابحث باسم المكون">
            </div>
            <div class="col-md-4">
                <select name="storage_location_id" class="form-select"
                        title="فلترة المكونات حسب موقع التخزين">
                    <option value="">📍 كل المواقع</option>
                    @foreach($filterLocations as $loc)
                        <option value="{{ $loc->id }}"
                                @selected((string) request('storage_location_id') === (string) $loc->id)>
                            {{ $loc->name }}{{ $loc->code ? " ({$loc->code})" : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="">— كل الحالات —</option>
                    <option value="healthy" @selected($statusFilter === 'healthy')>مخزون صحي فقط</option>
                    <option value="low"     @selected($statusFilter === 'low')>مخزون منخفض فقط</option>
                    <option value="out"     @selected($statusFilter === 'out')>المخزون النافد فقط</option>
                </select>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
                @if(request()->hasAny(['search', 'storage_location_id', 'status']))
                    <a href="{{ route('admin.ingredients.index') }}" class="d-block mt-1 small text-muted">
                        مسح الفلاتر
                    </a>
                @endif
            </div>
        </form>
    </x-slot:filters>

    @php $currency = config('restaurant.currency_symbol', '₪'); @endphp

    {{-- Total capital tied up in inventory for the current view (branch + filter). --}}
    <div class="alert d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3"
         style="background: linear-gradient(135deg, rgba(15,71,49,.06), rgba(15,71,49,.02)); border: 1px solid rgba(15,71,49,.12); border-radius: .5rem;">
        <div>
            <i class="bi bi-coin text-accent me-1" style="font-size: 1.25rem;"></i>
            <strong>قيمة المخزون الإجمالية</strong>
            <small class="text-muted d-block mt-1" style="font-size: 12px; line-height: 1.4;">
                مجموع (المخزون × التكلفة) لكل المكونات
                @if($activeBranchName) في فرع «{{ $activeBranchName }}» @else عبر كل الفروع @endif
                @if($statusFilter)
                    — مفلترة حسب «{{ ['healthy' => 'صحي', 'low' => 'منخفض', 'out' => 'نفد'][$statusFilter] ?? '' }}»
                @endif.
                هذا هو رأس المال المحبوس في المخزون.
            </small>
        </div>
        <div class="fs-3 fw-bold text-accent">
            {{ number_format((float) $totalInventoryValue, 2) }}
            <small class="text-muted" style="font-size: 14px;">{{ $currency }}</small>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>الاسم</th>
                    <th>SKU</th>
                    <th>المخزون</th>
                    <th>
                        المواقع
                        <i class="bi bi-info-circle text-muted small" style="cursor: help;"
                           title="مواقع التخزين التي تحتوي هذا المكوّن مع كميته في كل موقع. عند اختيار فرع معيّن تظهر مواقع هذا الفرع فقط."></i>
                    </th>
                    <th>حد الطلب</th>
                    <th>الوحدة</th>
                    <th>
                        التكلفة/وحدة
                        <i class="bi bi-info-circle text-muted small" style="cursor: help;"
                           title="تكلفة شراء الوحدة الواحدة. متوسط مرجَّح من دفعات الاستلام النشطة في الفرع المعروض."></i>
                    </th>
                    <th>
                        قيمة المخزون
                        <i class="bi bi-info-circle text-muted small" style="cursor: help;"
                           title="المخزون × التكلفة. المال المحبوس في هذا المكوّن داخل المخازن — مفيد لجرد التقييم وحساب رأس المال الدوّار."></i>
                    </th>
                    <th>تتبع</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($ingredients as $ing)
                    @php
                        // Per-branch view when a branch is active; otherwise the
                        // cross-branch sum of ingredient_stock (the per-location
                        // truth table). Avoids leaking any historical drift in
                        // the denormalized current_stock column.
                        $stock     = $activeBranchId ? $ing->stockAtBranch($activeBranchId)             : $ing->trackedStock();
                        $threshold = $activeBranchId ? $ing->reorderThresholdAtBranch($activeBranchId)  : (float) $ing->reorder_threshold;

                        // Value = SUM(per-branch stock × per-branch cost) for the
                        // all-branches view, so the table and banner agree with
                        // what you'd see by switching to each branch one at a
                        // time. Cost is then the blended rate so the user can
                        // verify cost × stock = value.
                        $value     = $activeBranchId ? $ing->valueAtBranch($activeBranchId) : $ing->trackedValue();
                        $cost      = $activeBranchId
                                        ? $ing->costAtBranch($activeBranchId)
                                        : ($stock > 0 ? $value / $stock : (float) $ing->cost_per_unit);

                        // Three discrete states: نفد (=0), منخفض (>0 but at-or-under
                        // threshold), or صحي. Out-of-stock no longer rides under
                        // the "low" badge — that mismatch was confusing the KPI
                        // counts vs the row badges.
                        $statusKey = ! $ing->track_stock
                            ? 'untracked'
                            : ($stock <= 0
                                ? 'out'
                                : ($threshold > 0 && $stock <= $threshold ? 'low' : 'healthy'));
                    @endphp
                    <tr class="@if($statusKey === 'out') table-danger @elseif($statusKey === 'low') table-warning @endif">
                        <td class="fw-bold">
                            {{ $ing->name }}
                            @if($statusKey === 'out')
                                <span class="badge bg-danger ms-1">نفد</span>
                            @elseif($statusKey === 'low')
                                <span class="badge bg-warning text-dark ms-1">منخفض</span>
                            @endif
                        </td>
                        <td>{{ $ing->sku }}</td>
                        <td>{{ number_format($stock, 2) }}</td>
                        <td>
                            {{-- Per-location breakdown — chips show location name +
                                 quantity. Empty when nothing is stocked anywhere
                                 visible (no chips, faint dash instead). --}}
                            @if($ing->stocks->isEmpty())
                                <span class="text-muted small">—</span>
                            @else
                                <div class="loc-chips">
                                    @foreach($ing->stocks->sortByDesc('quantity') as $st)
                                        <span class="loc-chip"
                                              title="{{ $st->location?->name ?? 'موقع محذوف' }} — {{ number_format((float) $st->quantity, 2) }} {{ $ing->baseUnit?->code }}">
                                            <i class="bi bi-geo-alt-fill"></i>
                                            <span class="loc-chip__name">{{ $st->location?->name ?? '—' }}</span>
                                            <span class="loc-chip__qty">{{ number_format((float) $st->quantity, 2) }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td>{{ number_format($threshold, 2) }}</td>
                        <td>{{ $ing->baseUnit->code ?? '' }}</td>
                        <td>
                            {{-- Use up to 4 decimals so cheap ingredients (e.g. بصل @ 0.001 ₪/g)
                                 don't display as "0". Trim trailing zeros for readability. --}}
                            {{ rtrim(rtrim(number_format($cost, 4, '.', ','), '0'), '.') }}
                            <small class="text-muted">{{ $currency }}</small>
                            @if($activeBranchId && abs($cost - (float) $ing->cost_per_unit) > 0.0001)
                                <small class="text-muted d-block fs-11" title="السعر المتوسط العام">
                                    عام: {{ rtrim(rtrim(number_format((float) $ing->cost_per_unit, 4, '.', ','), '0'), '.') }}
                                </small>
                            @endif
                        </td>
                        <td class="fw-semibold"
                            title="{{ number_format($stock, 2) }} {{ $ing->baseUnit->code ?? '' }} × {{ rtrim(rtrim(number_format($cost, 4, '.', ','), '0'), '.') }} {{ $currency }} = {{ number_format($value, 2) }} {{ $currency }}">
                            {{ number_format($value, 2) }}
                            <small class="text-muted">{{ $currency }}</small>
                        </td>
                        <td>{{ $ing->track_stock ? 'نعم' : 'لا' }}</td>
                        <td class="ing-actions-cell">
                            {{-- Flex row with no-wrap so the four icon buttons stay
                                 in a single neat line. Without this, in RTL the
                                 inline-buttons wrap to a 2×2 grid the moment the
                                 column gets squeezed. --}}
                            <div class="ing-actions">
                                <button class="btn btn-sm btn-success ing-act-btn"
                                        data-bs-toggle="modal" data-bs-target="#adjust{{ $ing->id }}"
                                        title="تسجيل حركة مخزون">
                                    <i class="bi bi-plus-slash-minus"></i>
                                </button>
                                <a href="{{ route('admin.vendor-prices.ingredient', $ing) }}"
                                   class="btn btn-sm btn-info ing-act-btn"
                                   title="تاريخ الأسعار من المورّدين">
                                    <i class="bi bi-graph-up-arrow"></i>
                                </a>
                                <a href="{{ route('admin.ingredients.edit', $ing) }}"
                                   class="btn btn-sm btn-light ing-act-btn"
                                   title="تعديل">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="{{ route('admin.ingredients.destroy', $ing) }}" method="POST"
                                      onsubmit="return confirm('حذف؟')" class="m-0">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger ing-act-btn" title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <div class="modal fade" id="adjust{{ $ing->id }}" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="{{ route('admin.ingredients.adjust', $ing) }}" method="POST">
                                    @csrf
                                    <div class="modal-header">
                                        <h5>تعديل مخزون: {{ $ing->name }}</h5>
                                        <button class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>المخزون الحالي: <strong>{{ number_format((float)$ing->current_stock, 2) }} {{ $ing->baseUnit->code }}</strong></p>
                                        <div class="mb-2">
                                            <label class="form-label">نوع الحركة</label>
                                            <select name="type" class="form-select" required>
                                                <option value="in">إضافة (شراء)</option>
                                                <option value="out">خصم (استهلاك)</option>
                                                <option value="waste">هدر</option>
                                                <option value="adjustment">تعديل يدوي</option>
                                            </select>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6"><label class="form-label">الكمية</label><input type="number" step="0.0001" name="quantity" class="form-control" required></div>
                                            <div class="col-6">
                                                <label class="form-label">الوحدة</label>
                                                <select name="unit_id" class="form-select" required>
                                                    @foreach(\App\Models\Unit::where('unit_type', $ing->baseUnit?->unit_type)->get() as $u)
                                                        <option value="{{ $u->id }}" @selected($u->id===$ing->base_unit_id)>{{ $u->name }} ({{ $u->code }})</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-2 mt-2"><label class="form-label">التكلفة للوحدة (اختياري)</label><input type="number" step="0.0001" name="unit_cost" value="{{ $ing->cost_per_unit }}" class="form-control"></div>
                                        <div class="mb-2"><label class="form-label">السبب *</label><input name="reason" class="form-control" required></div>
                                    </div>
                                    <div class="modal-footer"><button class="btn btn-primary">تسجيل</button></div>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <tr><td colspan="9">
                        <x-admin.empty-state
                            icon="bi-basket2-fill"
                            title="ما في مكونات بعد"
                            message="أضف المكونات لتفعيل خصم المخزون التلقائي من الوصفات.">
                            <x-slot:cta>
                                <a href="{{ route('admin.ingredients.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> أضف مكوناً
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $ingredients->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
