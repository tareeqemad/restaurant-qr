@extends('layouts.admin')
@section('title', 'الهدر')

@php
    /**
     * $reasons      → keyed by `id` for lookup against waste_reason_lookup_id (FK).
     * $reasonsByCode → keyed by `code` for fallback lookup against the legacy
     *                  `waste_reason` string column (old rows pre-FK-migration).
     *
     * The dropdown filter sends the lookup ID (number); the controller filters
     * on the FK column `waste_reason_lookup_id`. The breakdown query also
     * groups by FK so reports survive renames in the lookups admin.
     *
     * Color helpers — hex (#xxxxxx) from the lookups admin → inline styles.
     */
    $reasonsByCode = $reasons->keyBy('code');
    $reasonStyle = function ($lookup) {
        $hex = $lookup?->color ?: '#64748b';
        return "background:{$hex}1a;color:{$hex};border:1px solid {$hex}40;";
    };
    $reasonProgressBg = function ($lookup) {
        $hex = $lookup?->color ?: '#64748b';
        return "background:{$hex};";
    };
@endphp

@section('content')
<x-admin.breadcrumb
    title="الهدر"
    icon="bi-trash3-fill"
    subtitle="سجل خسائر المطبخ — الانتهاء، التلف، الانسكاب، فقد التحضير. كل سطر يخصم من المخزون ومن دفعة محددة." />

<x-admin.stat-rail :stats="[
    ['label' => 'عدد الأحداث',      'value' => $stats['count'],                                              'icon' => 'bi-list-ul',           'color' => 'primary'],
    ['label' => 'تكلفة الفترة',     'value' => number_format($stats['total_cost'], 2).' ₪',                  'icon' => 'bi-cash-coin',         'color' => 'danger'],
    ['label' => 'أحداث اليوم',      'value' => $stats['today_count'],                                        'icon' => 'bi-calendar-day-fill', 'color' => 'warning'],
    ['label' => 'تكلفة اليوم',      'value' => number_format($stats['today_cost'], 2).' ₪',                  'icon' => 'bi-cash',              'color' => 'accent'],
]" />

<div class="row g-3 mb-3">
    {{-- Reason breakdown --}}
    <div class="col-lg-5">
        <x-admin.data-panel title="حسب السبب" icon="bi-pie-chart-fill">
            @if($byReason->isEmpty())
                <p class="text-muted text-center py-3 mb-0">لا أحداث في الفترة.</p>
            @else
                <div class="vstack gap-2">
                    @foreach($byReason as $r)
                        @php
                            // Prefer the FK id (most rows post-migration), fall back to
                            // matching the legacy string column to a code-keyed lookup.
                            $lookup = $r->lookup_id ? ($reasons[$r->lookup_id] ?? null) : null;
                            if (! $lookup && $r->legacy_code) {
                                $lookup = $reasonsByCode[$r->legacy_code] ?? null;
                            }
                            $pct    = $stats['total_cost'] > 0 ? ($r->total_cost / $stats['total_cost']) * 100 : 0;
                            $hex    = $lookup?->color ?: '#64748b';
                            $icon   = $lookup?->icon  ?: 'bi-question-circle';
                            $label  = $lookup?->label ?: ($r->legacy_code ?: 'غير محدد');
                        @endphp
                        <div class="d-flex align-items-center gap-3 p-2 rounded bg-light">
                            <i class="bi {{ $icon }} fs-20" style="color: {{ $hex }};"></i>
                            <div class="flex-fill">
                                <div class="d-flex justify-content-between fw-semibold fs-13">
                                    <span>{{ $label }}</span>
                                    <span>{{ number_format((float) $r->total_cost, 2) }} ₪</span>
                                </div>
                                <div class="progress mt-1" style="height: 4px;">
                                    <div class="progress-bar"
                                         style="width: {{ $pct }}%; {{ $reasonProgressBg($lookup) }}"></div>
                                </div>
                                <small class="text-muted">{{ $r->count }} حدث · {{ number_format($pct, 1) }}%</small>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.data-panel>
    </div>

    {{-- Top wasted ingredients --}}
    <div class="col-lg-7">
        <x-admin.data-panel title="أكثر المكونات هدراً" icon="bi-bar-chart-fill">
            @if($topIngredients->isEmpty())
                <p class="text-muted text-center py-3 mb-0">لا بيانات.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>المكوّن</th>
                                <th class="text-end">عدد الأحداث</th>
                                <th class="text-end">إجمالي الكمية</th>
                                <th class="text-end">إجمالي التكلفة</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topIngredients as $ing)
                                <tr>
                                    <td class="fw-semibold">{{ $ing->name }}</td>
                                    <td class="text-end">{{ $ing->event_count }}</td>
                                    <td class="text-end">{{ \App\Helpers\Qty::format($ing->qty) }}</td>
                                    <td class="text-end fw-semibold text-danger">{{ number_format((float) $ing->total_cost, 2) }} ₪</td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.waste.create', ['ingredient_id' => $ing->id]) }}"
                                           class="btn btn-sm btn-outline-danger" title="سجّل هدر جديد">
                                            <i class="bi bi-plus-lg"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>
</div>

<x-admin.data-panel title="سجل الأحداث" icon="bi-list-check" :count="$movements->total()">
    <x-slot:actions>
        <a href="{{ route('admin.waste.create') }}" class="btn btn-sm btn-danger">
            <i class="bi bi-plus-circle"></i> سجّل هدر جديد
        </a>
    </x-slot:actions>

    <x-slot:filters>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1">من</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1">إلى</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1 d-flex align-items-center justify-content-between">
                    <span>السبب</span>
                    @can('viewAny', \App\Models\Lookup::class)
                        <a href="{{ route('admin.lookups.index', ['group' => 'waste_reasons']) }}" target="_blank"
                           class="text-muted text-decoration-none" title="إدارة أسباب الهدر من شاشة الثوابت">
                            <i class="bi bi-gear-fill"></i>
                        </a>
                    @endcan
                </label>
                <select name="reason" class="form-select form-select-sm">
                    <option value="">كل الأسباب</option>
                    @foreach($reasons as $id => $r)
                        <option value="{{ $id }}" @selected((string) request('reason') === (string) $id)>{{ $r->label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1">المكوّن</label>
                <select name="ingredient_id" class="form-select form-select-sm" data-relax-choice data-choice-search-placeholder="ابحث عن مكوّن...">
                    <option value="">كل المكونات</option>
                    @foreach($ingredients as $i)
                        <option value="{{ $i->id }}" @selected((string) request('ingredient_id') === (string) $i->id)>{{ $i->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1">الموقع</label>
                <select name="storage_location_id" class="form-select form-select-sm" data-relax-choice data-choice-search-placeholder="ابحث عن موقع...">
                    <option value="">كل المواقع</option>
                    @foreach($storageLocations as $location)
                        <option value="{{ $location->id }}" @selected((string) request('storage_location_id') === (string) $location->id)>
                            {{ $location->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary btn-sm px-5"><i class="bi bi-search"></i> استعلام</button>
            </div>
        </form>
    </x-slot:filters>

    @if($movements->isEmpty())
        <x-admin.empty-state
            icon="bi-check-circle"
            title="لا أحداث هدر بهذه الفلاتر"
            message="ربما هذا خبر سار — أو فلاتر صارمة. جرّب توسيع المدى أو إزالة بعض الفلاتر." />
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>الوقت</th>
                        <th>المكوّن</th>
                        <th>السبب</th>
                        <th>الموقع</th>
                        <th>الدفعة</th>
                        <th class="text-end">الكمية</th>
                        <th class="text-end">التكلفة</th>
                        <th>المسجِّل</th>
                        <th>ملاحظة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($movements as $m)
                        @php
                            // Prefer the relation if loaded (FK id → Lookup row).
                            // Fall back to the legacy string column matched against the
                            // code-keyed lookup map for any pre-migration rows.
                            $lookup = $m->wasteReasonLookup
                                ?? ($m->waste_reason ? ($reasonsByCode[$m->waste_reason] ?? null) : null);
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold fs-13">{{ optional($m->occurred_at)->format('Y-m-d H:i') }}</div>
                                <small class="text-muted">{{ optional($m->occurred_at)->diffForHumans() }}</small>
                            </td>
                            <td class="fw-semibold">{{ $m->ingredient?->name ?? '—' }}</td>
                            <td>
                                @if($lookup)
                                    <span class="badge" style="{{ $reasonStyle($lookup) }}">
                                        <i class="bi {{ $lookup->icon ?: 'bi-tag' }} me-1"></i>{{ $lookup->label }}
                                    </span>
                                @else
                                    <span class="badge bg-secondary-transparent">{{ $m->waste_reason ?: '—' }}</span>
                                @endif
                            </td>
                            <td>
                                @if($m->storageLocation)
                                    <span class="badge bg-primary-transparent text-primary fs-11">
                                        <i class="bi bi-geo-alt me-1"></i>{{ $m->storageLocation->name }}
                                    </span>
                                @else
                                    <span class="badge bg-light text-muted fs-11">عام</span>
                                @endif
                            </td>
                            <td>
                                @if($m->batch)
                                    <span class="fs-12">
                                        #{{ $m->batch->batch_number ?: $m->batch->id }}
                                        @if($m->batch->expiry_date)
                                            · <small class="text-muted">{{ $m->batch->expiry_date->format('Y-m-d') }}</small>
                                        @endif
                                    </span>
                                @else
                                    <span class="text-muted fs-12">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                {{ \App\Helpers\Qty::format($m->quantity_in_base) }}
                                <small class="text-muted">{{ $m->ingredient?->baseUnit?->code }}</small>
                            </td>
                            <td class="text-end fw-semibold text-danger">{{ number_format((float) $m->total_cost, 2) }} ₪</td>
                            <td class="fs-12 text-muted">{{ $m->user?->name ?? '—' }}</td>
                            <td class="fs-12 text-muted" style="max-width: 200px; white-space: normal;">{{ $m->reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <x-slot:footer>
        {{ $movements->links() }}
    </x-slot:footer>
</x-admin.data-panel>
@endsection
