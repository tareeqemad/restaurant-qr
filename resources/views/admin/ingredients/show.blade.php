@extends('layouts.admin')
@section('title', 'كرت الصنف: '.$ingredient->name)

@php
    use App\Helpers\QuantityFormatter;
    use App\Helpers\Money;
    $baseCode = $ingredient->baseUnit?->code;
    $isLow    = $ingredient->track_stock && $stock <= (float) $ingredient->reorder_threshold && $stock > 0;
    $isOut    = $ingredient->track_stock && $stock <= 0;
    $statusLabel = $isOut ? ['نفد', 'danger', 'bi-x-octagon-fill']
                  : ($isLow ? ['منخفض', 'warning', 'bi-exclamation-triangle-fill']
                            : ['آمن', 'success', 'bi-check-circle-fill']);
    // Weekly average — last 30 days ÷ 30 × 7
    $weeklyAvg = $consumed30 / 30 * 7;
    // Days of stock left at current burn rate
    $dailyBurn = $consumed30 / 30;
    $daysLeft  = $dailyBurn > 0 ? floor($stock / $dailyBurn) : null;
@endphp

@section('content')
<x-admin.breadcrumb
    :title="'كرت الصنف: '.$ingredient->name"
    icon="bi-card-text"
    :crumbs="[['label' => 'المكونات', 'url' => route('admin.ingredients.index')]]">
    <x-slot:actions>
        <div class="d-flex flex-wrap align-items-center gap-2">
            {{-- Primary actions first — what the user is most likely here for. --}}
            <a href="{{ route('admin.ingredients.edit', $ingredient) }}" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil"></i> تعديل
            </a>
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#cardAdjust">
                <i class="bi bi-arrow-down-up"></i> تسجيل حركة
            </button>

            {{-- Adjacent-ingredient navigation. Skip-to-first / skip-to-last
                 are intentionally NOT here — they're keyboard-only (Ctrl+Home
                 / Ctrl+End) because their filled-triangle icons read as heavy
                 black squares next to lighter buttons. The position counter
                 sits next to the group as plain text rather than a disabled
                 button so it doesn't compete visually. --}}
            <div class="vr d-none d-md-inline-block"></div>
            <div class="btn-group btn-group-sm" role="group" aria-label="التنقل بين الأصناف">
                <a href="{{ $nav['prev'] ? route('admin.ingredients.show', $nav['prev']) : '#' }}"
                   class="btn btn-outline-secondary {{ ! $nav['prev'] ? 'disabled' : '' }}"
                   title="السابق (Ctrl+→)" data-ing-nav="prev">
                    <i class="bi bi-chevron-right"></i> السابق
                </a>
                <a href="{{ $nav['next'] ? route('admin.ingredients.show', $nav['next']) : '#' }}"
                   class="btn btn-outline-secondary {{ ! $nav['next'] ? 'disabled' : '' }}"
                   title="التالي (Ctrl+←)" data-ing-nav="next">
                    التالي <i class="bi bi-chevron-left"></i>
                </a>
            </div>
            <small class="text-muted fw-bold" dir="ltr">
                {{ $nav['index'] }} / {{ $nav['total'] }}
            </small>
        </div>
    </x-slot:actions>
</x-admin.breadcrumb>

{{-- ────────── Hero header ────────── --}}
<div class="card mb-3" style="border-radius:14px; overflow:hidden;">
    <div class="card-body" style="background: linear-gradient(135deg, rgba(15,71,49,.04), rgba(15,71,49,.01));">
        <div class="row align-items-center g-3">
            <div class="col-md-6">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:56px; height:56px; border-radius:14px; background:rgba(15,71,49,.1); display:flex; align-items:center; justify-content:center; color:#0f4731; font-size:1.6rem;">
                        <i class="bi bi-box-seam-fill"></i>
                    </div>
                    <div>
                        <h4 class="mb-1 fw-bold">{{ $ingredient->name }}</h4>
                        <small class="text-muted">
                            <code>{{ $ingredient->sku }}</code>
                            @if($ingredient->name_en) · {{ $ingredient->name_en }} @endif
                            · الوحدة: <strong>{{ $ingredient->baseUnit?->name }} ({{ $baseCode }})</strong>
                            @if($ingredient->supplier)
                                · المورد: {{ $ingredient->supplier->name }}
                            @endif
                        </small>
                        <div class="mt-1">
                            <span class="badge bg-{{ $statusLabel[1] }}"><i class="bi {{ $statusLabel[2] }}"></i> {{ $statusLabel[0] }}</span>
                            @if($ingredient->is_composite)
                                <span class="badge bg-info"><i class="bi bi-diagram-3"></i> مكوّن مركّب</span>
                            @endif
                            @if($ingredient->yield_pct && (float) $ingredient->yield_pct < 100)
                                <span class="badge bg-secondary">نسبة استفادة: {{ rtrim(rtrim((string) $ingredient->yield_pct, '0'), '.') }}%</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="text-center p-2" style="background:white; border-radius:10px; border:1px solid rgba(15,71,49,.1);">
                            <small class="text-muted d-block">المخزون الحالي</small>
                            <strong class="d-block fs-5 text-{{ $isOut ? 'danger' : ($isLow ? 'warning' : 'primary') }}"
                                    title="{{ number_format($stock, 4) }} {{ $baseCode }}">
                                {{ QuantityFormatter::smart($stock, $baseCode) }}
                            </strong>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center p-2" style="background:white; border-radius:10px; border:1px solid rgba(15,71,49,.1);">
                            <small class="text-muted d-block">القيمة</small>
                            <strong class="d-block fs-5">{{ Money::format($value) }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ────────── Stat strip ────────── --}}
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card text-center"><div class="card-body p-3">
            <small class="text-muted d-block mb-1">استُلم آخر 30 يوم</small>
            <strong class="d-block text-success" title="{{ number_format($received30, 4) }} {{ $baseCode }}">
                {{ QuantityFormatter::smart($received30, $baseCode) }}
            </strong>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card text-center"><div class="card-body p-3">
            <small class="text-muted d-block mb-1">استُهلك آخر 30 يوم</small>
            <strong class="d-block text-primary" title="{{ number_format($consumed30, 4) }} {{ $baseCode }}">
                {{ QuantityFormatter::smart($consumed30, $baseCode) }}
            </strong>
            <small class="text-muted">≈ {{ QuantityFormatter::smart($weeklyAvg, $baseCode) }}/أسبوع</small>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card text-center {{ $wasted30 > 0 ? 'border-warning' : '' }}"><div class="card-body p-3">
            <small class="text-muted d-block mb-1">هدر آخر 30 يوم</small>
            <strong class="d-block {{ $wasted30 > 0 ? 'text-warning' : 'text-muted' }}" title="{{ number_format($wasted30, 4) }} {{ $baseCode }}">
                {{ QuantityFormatter::smart($wasted30, $baseCode) }}
            </strong>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card text-center {{ $daysLeft !== null && $daysLeft < 7 ? 'border-danger' : '' }}"><div class="card-body p-3">
            <small class="text-muted d-block mb-1">يكفي تقريباً</small>
            @if($daysLeft !== null)
                <strong class="d-block {{ $daysLeft < 7 ? 'text-danger' : 'text-primary' }}">{{ (int) $daysLeft }} يوم</strong>
                <small class="text-muted">بمعدل الاستهلاك الحالي</small>
            @else
                <strong class="d-block text-muted">—</strong>
                <small class="text-muted">لا استهلاك مسجّل</small>
            @endif
        </div></div>
    </div>
</div>

{{-- ────────── Tabs ────────── --}}
<div class="card">
    <div class="card-header p-0">
        <ul class="nav nav-tabs" role="tablist" style="border-bottom:none;">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-overview"><i class="bi bi-bar-chart-line"></i> نظرة عامة</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-general"><i class="bi bi-info-circle"></i> المعلومات العامة</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-locations"><i class="bi bi-geo-alt-fill"></i> المواقع ({{ $locations->count() }})</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-batches"><i class="bi bi-box2-fill"></i> الدفعات ({{ $batches->count() }})</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-movements"><i class="bi bi-arrow-down-up"></i> الحركات ({{ $movements->total() }})</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-recipes"><i class="bi bi-basket2"></i> يُستخدم في ({{ $usedInMenuItems->count() + $usedInComposites->count() }})</a></li>
            @if($ingredient->is_composite)
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-formula"><i class="bi bi-diagram-3"></i> معادلة التصنيع ({{ $ingredient->subRecipe->count() }})</a></li>
            @endif
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-invoices"><i class="bi bi-receipt-cutoff"></i> فواتير الصنف ({{ $purchaseLines->count() }})</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-vendors"><i class="bi bi-tags"></i> سعر المورد ({{ $vendorPrices->count() }})</a></li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">

            {{-- ── Overview: 30-day daily consumption chart ── --}}
            <div class="tab-pane fade show active" id="tab-overview">
                <h6 class="text-muted mb-3"><i class="bi bi-graph-up"></i> الاستهلاك اليومي — آخر 30 يوم</h6>
                <div style="height:240px;">
                    <canvas id="consumption-chart"
                            data-series='@json($dailySeries)'
                            data-unit="{{ $baseCode }}"></canvas>
                </div>
                <hr>
                <div class="row g-2 small text-muted">
                    <div class="col-md-6">
                        <i class="bi bi-arrow-down-circle text-success"></i>
                        آخر استلام: {{ $lastIn ? $lastIn->occurred_at->diffForHumans() : 'لا يوجد' }}
                        @if($lastIn) · <strong>{{ QuantityFormatter::smart((float) $lastIn->quantity_in_base, $baseCode) }}</strong> @endif
                    </div>
                    <div class="col-md-6">
                        <i class="bi bi-arrow-up-circle text-primary"></i>
                        آخر استهلاك: {{ $lastOut ? $lastOut->occurred_at->diffForHumans() : 'لا يوجد' }}
                        @if($lastOut) · <strong>{{ QuantityFormatter::smart((float) $lastOut->quantity_in_base, $baseCode) }}</strong> @endif
                    </div>
                </div>
            </div>

            {{-- ── General info ──
                 Shows every catalog field on this ingredient as a clean
                 read-only "spec sheet". Mirrors the layout the user sees
                 on the edit form so jumping back to تعديل feels familiar.
            --}}
            <div class="tab-pane fade" id="tab-general">
                <div class="row g-4">
                    {{-- Catalog identity --}}
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3"><i class="bi bi-tag"></i> الهوية</h6>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-5">SKU</dt>
                            <dd class="col-sm-7"><code>{{ $ingredient->sku }}</code></dd>

                            <dt class="col-sm-5">الاسم (عربي)</dt>
                            <dd class="col-sm-7">{{ $ingredient->name }}</dd>

                            <dt class="col-sm-5">الاسم (إنجليزي)</dt>
                            <dd class="col-sm-7">{{ $ingredient->name_en ?: '—' }}</dd>

                            <dt class="col-sm-5">المورد الافتراضي</dt>
                            <dd class="col-sm-7">{{ $ingredient->supplier?->name ?? '—' }}</dd>

                            <dt class="col-sm-5">الحالة</dt>
                            <dd class="col-sm-7">
                                @if($ingredient->active)
                                    <span class="badge bg-success">نشط</span>
                                @else
                                    <span class="badge bg-secondary">معطّل</span>
                                @endif
                                @if($ingredient->track_stock)
                                    <span class="badge bg-info">يتتبَّع المخزون</span>
                                @else
                                    <span class="badge bg-light text-dark border">لا يتتبَّع</span>
                                @endif
                            </dd>

                            <dt class="col-sm-5">آخر تحديث</dt>
                            <dd class="col-sm-7">
                                <small>{{ $ingredient->updated_at?->format('Y-m-d H:i') ?? '—' }}</small>
                            </dd>
                        </dl>
                    </div>

                    {{-- Costing + thresholds --}}
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3"><i class="bi bi-cash-coin"></i> التكلفة والإعدادات</h6>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-5">الوحدة الأساسية</dt>
                            <dd class="col-sm-7">{{ $ingredient->baseUnit?->name }} ({{ $baseCode }})</dd>

                            <dt class="col-sm-5">تكلفة الوحدة</dt>
                            <dd class="col-sm-7">{{ Money::format($ingredient->cost_per_unit) }} / {{ $baseCode }}</dd>

                            @if($ingredient->yield_pct && (float) $ingredient->yield_pct < 100)
                                <dt class="col-sm-5">نسبة الاستفادة</dt>
                                <dd class="col-sm-7">
                                    {{ rtrim(rtrim((string) $ingredient->yield_pct, '0'), '.') }}%
                                    <small class="text-muted d-block">
                                        التكلفة الفعلية: {{ Money::format($ingredient->effectiveCostPerUnit()) }} / {{ $baseCode }}
                                    </small>
                                </dd>
                            @endif

                            <dt class="col-sm-5">حد إعادة الطلب</dt>
                            <dd class="col-sm-7">
                                @if((float) $ingredient->reorder_threshold > 0)
                                    {{ QuantityFormatter::smart((float) $ingredient->reorder_threshold, $baseCode) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </dd>

                            <dt class="col-sm-5">نوع المكوّن</dt>
                            <dd class="col-sm-7">
                                @if($ingredient->is_composite)
                                    <span class="badge bg-info"><i class="bi bi-diagram-3"></i> مركّب (يحوي وصفة)</span>
                                @else
                                    <span class="badge bg-light text-dark border">خام</span>
                                @endif
                            </dd>

                            @if($ingredient->is_composite && $ingredient->composite_yield)
                                <dt class="col-sm-5">ناتج التصنيع</dt>
                                <dd class="col-sm-7">
                                    {{ QuantityFormatter::smart((float) $ingredient->composite_yield, $baseCode) }}
                                </dd>
                            @endif
                        </dl>
                    </div>

                    {{-- Alternate pack sizes — surface them on the spec sheet
                         so the user doesn't need to dig into the edit form
                         to remember what "كرتون = 24 علبة" looks like. --}}
                    @if($ingredient->units->isNotEmpty())
                        <div class="col-12">
                            <h6 class="text-muted mb-2"><i class="bi bi-box-seam"></i> وحدات الشراء البديلة</h6>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>الوحدة</th>
                                            <th class="text-end">المعامل ← {{ $baseCode }}</th>
                                            <th>الباركود</th>
                                            <th class="text-end">سعر الشراء</th>
                                            <th class="text-end">سعر البيع</th>
                                            <th>افتراضي</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($ingredient->units as $u)
                                            <tr>
                                                <td><strong>{{ $u->name }}</strong></td>
                                                <td class="text-end"><code>×{{ rtrim(rtrim((string) $u->factor_to_base, '0'), '.') }}</code></td>
                                                <td><small class="text-muted">{{ $u->barcode ?: '—' }}</small></td>
                                                <td class="text-end">{{ $u->purchase_price !== null ? Money::format($u->purchase_price) : '—' }}</td>
                                                <td class="text-end">{{ $u->sale_price !== null ? Money::format($u->sale_price) : '—' }}</td>
                                                <td>
                                                    @if($u->is_default_purchase)
                                                        <span class="badge bg-success"><i class="bi bi-check"></i></span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    @if($ingredient->notes)
                        <div class="col-12">
                            <h6 class="text-muted mb-2"><i class="bi bi-sticky"></i> ملاحظات</h6>
                            <div class="alert alert-light border mb-0" style="white-space:pre-line;">{{ $ingredient->notes }}</div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ── Locations ── --}}
            <div class="tab-pane fade" id="tab-locations">
                @if($locations->isEmpty())
                    <div class="text-center text-muted py-4">لا يوجد رصيد في أي موقع.</div>
                @else
                    <table class="table align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>الموقع</th>
                                <th>الفرع</th>
                                <th class="text-end">الكمية</th>
                                <th class="text-end">حد الطلب</th>
                                <th class="text-end">القيمة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($locations as $loc)
                                @php $locValue = (float) $loc->quantity * (float) $ingredient->cost_per_unit; @endphp
                                <tr>
                                    <td><strong>{{ $loc->location?->name ?? '—' }}</strong></td>
                                    <td><small class="text-muted">{{ $loc->location?->branch?->name ?? '—' }}</small></td>
                                    <td class="text-end fw-bold" title="{{ number_format((float) $loc->quantity, 4) }} {{ $baseCode }}">
                                        {{ QuantityFormatter::smart((float) $loc->quantity, $baseCode) }}
                                    </td>
                                    <td class="text-end text-muted">
                                        {{ $loc->reorder_threshold > 0 ? QuantityFormatter::smart((float) $loc->reorder_threshold, $baseCode) : '—' }}
                                    </td>
                                    <td class="text-end">{{ Money::format($locValue) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- ── Batches (FIFO + expiry) ── --}}
            <div class="tab-pane fade" id="tab-batches">
                @if($batches->isEmpty())
                    <div class="text-center text-muted py-4">لا توجد دفعات مسجّلة لهذا المكوّن.</div>
                @else
                    <table class="table align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>الدفعة</th>
                                <th>الموقع</th>
                                <th class="text-end">المتبقي / الأصلي</th>
                                <th class="text-end">تكلفة الوحدة</th>
                                <th>تاريخ الصلاحية</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($batches as $b)
                                @php
                                    $isExpired = $b->expiry_date && $b->expiry_date->isPast();
                                    $nearExpiry = $b->expiry_date && ! $isExpired && $b->expiry_date->diffInDays(now()) <= 7;
                                @endphp
                                <tr class="{{ $isExpired ? 'table-danger' : ($nearExpiry ? 'table-warning' : '') }}">
                                    <td><code>{{ $b->batch_number ?: '#'.$b->id }}</code></td>
                                    <td><small>{{ $b->storageLocation?->name ?? '—' }}</small></td>
                                    <td class="text-end" title="{{ number_format((float) $b->remaining_qty, 4) }} / {{ number_format((float) $b->initial_qty, 4) }} {{ $baseCode }}">
                                        <strong>{{ QuantityFormatter::smart((float) $b->remaining_qty, $baseCode) }}</strong>
                                        <small class="text-muted">/ {{ QuantityFormatter::smart((float) $b->initial_qty, $baseCode) }}</small>
                                    </td>
                                    <td class="text-end">{{ Money::format($b->unit_cost) }}</td>
                                    <td>
                                        @if($b->expiry_date)
                                            <small>{{ $b->expiry_date->format('Y-m-d') }}</small>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isExpired)
                                            <span class="badge bg-danger">منتهي</span>
                                        @elseif($nearExpiry)
                                            <span class="badge bg-warning text-dark">قارب الانتهاء</span>
                                        @elseif((float) $b->remaining_qty <= 0)
                                            <span class="badge bg-secondary">نفدت</span>
                                        @else
                                            <span class="badge bg-success">نشطة</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- ── Movements log ── --}}
            <div class="tab-pane fade" id="tab-movements">
                @if($movements->isEmpty())
                    <div class="text-center text-muted py-4">لا حركات مخزون لهذا المكوّن.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>النوع</th>
                                    <th class="text-end">الكمية</th>
                                    <th class="text-end">الرصيد بعد</th>
                                    <th>السبب</th>
                                    <th>المستخدم</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($movements as $m)
                                    @php
                                        $typeBadge = match($m->type) {
                                            'in'         => ['إدخال', 'success', '+'],
                                            'out'        => ['خصم',   'primary', '−'],
                                            'waste'      => ['هدر',   'danger',  '−'],
                                            'return'     => ['إرجاع','info',    '+'],
                                            'adjustment' => ['تسوية','secondary',''],
                                            default      => [$m->type, 'light', ''],
                                        };
                                    @endphp
                                    <tr>
                                        <td><small>{{ $m->occurred_at->format('Y-m-d H:i') }}</small></td>
                                        <td><span class="badge bg-{{ $typeBadge[1] }}">{{ $typeBadge[0] }}</span></td>
                                        <td class="text-end fw-bold"
                                            title="{{ number_format((float) $m->quantity_in_base, 4) }} {{ $baseCode }}">
                                            {{ $typeBadge[2] }}{{ QuantityFormatter::smart((float) $m->quantity_in_base, $baseCode) }}
                                        </td>
                                        <td class="text-end text-muted"
                                            title="{{ number_format((float) $m->stock_after, 4) }} {{ $baseCode }}">
                                            {{ QuantityFormatter::smart((float) $m->stock_after, $baseCode) }}
                                        </td>
                                        <td><small>{{ $m->reason ?? '—' }}</small></td>
                                        <td><small class="text-muted">{{ $m->user?->name ?? '—' }}</small></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $movements->links() }}</div>
                @endif
            </div>

            {{-- ── Recipes using this ingredient ── --}}
            <div class="tab-pane fade" id="tab-recipes">
                @if($usedInMenuItems->isEmpty() && $usedInComposites->isEmpty())
                    <div class="text-center text-muted py-4">هذا المكوّن غير مستخدم في أي وصفة بعد.</div>
                @else
                    @if($usedInMenuItems->isNotEmpty())
                        <h6 class="text-muted mb-2">في أصناف القائمة ({{ $usedInMenuItems->count() }})</h6>
                        <table class="table table-sm align-middle mb-4">
                            <thead class="bg-light">
                                <tr>
                                    <th>الصنف</th>
                                    <th>الفئة</th>
                                    <th class="text-end">الكمية بالوصفة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($usedInMenuItems as $line)
                                    <tr>
                                        <td>
                                            <strong>{{ $line->menuItem?->name }}</strong>
                                            <small class="text-muted d-block">{{ $line->menuItem?->sku }}</small>
                                        </td>
                                        <td><small>{{ $line->menuItem?->category?->name ?? '—' }}</small></td>
                                        <td class="text-end fw-bold">
                                            {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                                            {{ $line->ingredientUnit?->name ?? $line->unit?->code ?? $baseCode }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if($usedInComposites->isNotEmpty())
                        <h6 class="text-muted mb-2">في مكوّنات مركّبة ({{ $usedInComposites->count() }})</h6>
                        <table class="table table-sm align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>المكوّن المركّب</th>
                                    <th class="text-end">الكمية</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($usedInComposites as $line)
                                    <tr>
                                        <td>
                                            <strong>{{ $line->parentIngredient?->name }}</strong>
                                            <small class="text-muted d-block">{{ $line->parentIngredient?->sku }}</small>
                                        </td>
                                        <td class="text-end fw-bold">
                                            {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                                            {{ $line->ingredientUnit?->name ?? $line->unit?->code ?? $baseCode }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>

            {{-- ── Manufacturing formula (composite ingredients only) ──
                 The sub-recipe that produces this composite, plus a small
                 cost breakdown so the user can see "this 1L of tomato
                 sauce really costs me ₪X". Edits happen on the existing
                 edit screen — this tab is read-only on purpose to keep
                 the stock card a viewing surface.
            --}}
            @if($ingredient->is_composite)
                <div class="tab-pane fade" id="tab-formula">
                    @if($ingredient->subRecipe->isEmpty())
                        <div class="alert alert-warning d-flex align-items-center gap-2">
                            <i class="bi bi-exclamation-triangle"></i>
                            <div>
                                هذا مكوّن مركّب لكن لم يتم تعريف وصفة فرعية بعد.
                                <a href="{{ route('admin.ingredients.edit', $ingredient) }}" class="alert-link">أضِف المكوّنات الفرعية</a>
                                ليتمكن النظام من حساب التكلفة الفعلية وخصم المخزون تلقائياً.
                            </div>
                        </div>
                    @else
                        @php
                            $totalCost = 0.0;
                            $yieldQty  = (float) ($ingredient->composite_yield ?: 1);
                        @endphp
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-muted mb-0"><i class="bi bi-diagram-3"></i> وصفة تصنيع {{ QuantityFormatter::smart($yieldQty, $baseCode) }} من {{ $ingredient->name }}</h6>
                            <a href="{{ route('admin.ingredients.edit', $ingredient) }}#sub-recipe" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil-square"></i> تعديل الوصفة
                            </a>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th>المكوّن الفرعي</th>
                                        <th class="text-end">الكمية</th>
                                        <th class="text-end">تكلفة الوحدة</th>
                                        <th class="text-end">تكلفة السطر</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($ingredient->subRecipe as $line)
                                        @php
                                            $subIng = $line->ingredient;
                                            $subBase = $subIng?->baseUnit?->code;
                                            // Resolve the line quantity into the sub-ingredient's base
                                            // unit so cost math is honest even when the recipe is
                                            // written in tbsp / scoops / etc.
                                            try {
                                                $qtyInBase = $line->quantityInBase();
                                            } catch (\Throwable $e) {
                                                $qtyInBase = (float) $line->quantity;
                                            }
                                            $unitCost = $subIng ? $subIng->effectiveCostPerUnit() : 0;
                                            $lineCost = $qtyInBase * $unitCost;
                                            $totalCost += $lineCost;
                                        @endphp
                                        <tr>
                                            <td>
                                                <a href="{{ route('admin.ingredients.show', $subIng) }}" class="text-decoration-none fw-bold">
                                                    {{ $subIng?->name ?? '—' }}
                                                </a>
                                                <small class="text-muted d-block">{{ $subIng?->sku }}</small>
                                            </td>
                                            <td class="text-end">
                                                {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                                                {{ $line->ingredientUnit?->name ?? $line->unit?->code ?? $subBase }}
                                                <small class="text-muted d-block">= {{ QuantityFormatter::smart($qtyInBase, $subBase) }}</small>
                                            </td>
                                            <td class="text-end">{{ Money::format($unitCost) }} / {{ $subBase }}</td>
                                            <td class="text-end fw-bold">{{ Money::format($lineCost) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-light fw-bold">
                                    <tr>
                                        <td colspan="3" class="text-end">إجمالي تكلفة دفعة الإنتاج</td>
                                        <td class="text-end text-primary">{{ Money::format($totalCost) }}</td>
                                    </tr>
                                    @if($yieldQty > 0)
                                        <tr>
                                            <td colspan="3" class="text-end">تكلفة الوحدة المُصنَّعة ({{ $baseCode }})</td>
                                            <td class="text-end text-success">{{ Money::format($totalCost / $yieldQty) }}</td>
                                        </tr>
                                    @endif
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            {{-- ── Item invoices (PO + supplier invoice history) ──
                 The user wanted a single screen to "review every purchase
                 invoice this item ever appeared on" without bouncing
                 between the PO list and the supplier-invoice list. We
                 join the two on the PO line so each row shows:
                   PO# · supplier · ordered qty · received qty · invoice#
                 in chronological order (latest first).
            --}}
            <div class="tab-pane fade" id="tab-invoices">
                @if($purchaseLines->isEmpty())
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-receipt fs-3 d-block mb-2"></i>
                        لا توجد فواتير شراء سابقة لهذا الصنف.
                    </div>
                @else
                    <div class="alert alert-light border d-flex align-items-center gap-2 small mb-3">
                        <i class="bi bi-info-circle text-primary"></i>
                        كل صف يربط أمر شراء بفاتورة المورد المقابلة. الكميات معروضة بوحدة السطر
                        الأصلية كما أُدخلت في النظام، مع تحويلها داخل قسم "المتسلَّم".
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>أمر الشراء</th>
                                    <th>المورد</th>
                                    <th class="text-end">الكمية المطلوبة</th>
                                    <th class="text-end">المتسلَّم</th>
                                    <th class="text-end">سعر الوحدة</th>
                                    <th class="text-end">الإجمالي</th>
                                    <th>فاتورة المورد</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($purchaseLines as $line)
                                    @php
                                        $po       = $line->purchaseOrder;
                                        $unitName = $line->ingredientUnit?->name ?? $line->unit?->code ?? $baseCode;
                                        $invoices = $line->supplierInvoiceItems->pluck('supplierInvoice')->filter()->unique('id');
                                    @endphp
                                    <tr>
                                        <td><small>{{ $po?->expected_at?->format('Y-m-d') ?? $po?->created_at?->format('Y-m-d') ?? '—' }}</small></td>
                                        <td>
                                            <a href="{{ route('admin.purchase-orders.show', $po) }}" class="text-decoration-none fw-bold">
                                                {{ $po?->number }}
                                            </a>
                                            <span class="badge bg-{{ $po?->statusColor() }} ms-1">{{ $po?->statusLabel() }}</span>
                                        </td>
                                        <td><small>{{ $po?->supplier?->name ?? '—' }}</small></td>
                                        <td class="text-end">
                                            {{ rtrim(rtrim((string) $line->quantity_ordered, '0'), '.') }} {{ $unitName }}
                                        </td>
                                        <td class="text-end">
                                            <strong>{{ rtrim(rtrim((string) $line->quantity_received, '0'), '.') }}</strong>
                                            <small class="text-muted">/ {{ rtrim(rtrim((string) $line->quantity_ordered, '0'), '.') }}</small>
                                            @if($line->isFullyReceived())
                                                <i class="bi bi-check-circle-fill text-success ms-1" title="استلام مكتمل"></i>
                                            @elseif((float) $line->quantity_received > 0)
                                                <i class="bi bi-hourglass-split text-warning ms-1" title="استلام جزئي"></i>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ Money::format($line->unit_price) }}</td>
                                        <td class="text-end fw-bold">{{ Money::format($line->subtotal) }}</td>
                                        <td>
                                            @if($invoices->isEmpty())
                                                <span class="text-muted">—</span>
                                            @else
                                                @foreach($invoices as $inv)
                                                    <a href="{{ route('admin.supplier-invoices.show', $inv) }}"
                                                       class="text-decoration-none d-block">
                                                        <small><code>{{ $inv->number }}</code></small>
                                                        <small class="badge bg-{{ $inv->status === 'paid' ? 'success' : ($inv->status === 'partially_paid' ? 'warning text-dark' : 'secondary') }}">
                                                            {{ $inv->statusLabel() }}
                                                        </small>
                                                    </a>
                                                @endforeach
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="6" class="text-end">إجمالي مشتريات هذا الصنف (آخر 100 سطر)</td>
                                    <td class="text-end text-primary">{{ Money::format($purchaseLines->sum('subtotal')) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Vendor prices ── --}}
            <div class="tab-pane fade" id="tab-vendors">
                @if($vendorPrices->isEmpty())
                    <div class="text-center text-muted py-4">لا تاريخ أسعار لهذا المكوّن.</div>
                @else
                    <table class="table table-sm align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>التاريخ</th>
                                <th>المورد</th>
                                <th class="text-end">سعر الوحدة الأساسية</th>
                                <th class="text-end">تغيّر</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($vendorPrices as $p)
                                <tr>
                                    <td><small>{{ $p->observed_at?->format('Y-m-d') }}</small></td>
                                    <td>{{ $p->supplier?->name ?? '—' }}</td>
                                    <td class="text-end fw-bold">{{ Money::format($p->unit_price_in_base) }}</td>
                                    <td class="text-end">
                                        @if($p->change_pct !== null)
                                            <span class="badge {{ $p->change_pct > 0 ? 'bg-danger' : ($p->change_pct < 0 ? 'bg-success' : 'bg-secondary') }}">
                                                {{ $p->change_pct > 0 ? '+' : '' }}{{ number_format((float) $p->change_pct, 1) }}%
                                            </span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const canvas = document.getElementById('consumption-chart');
    if (! canvas) return;
    const series = JSON.parse(canvas.dataset.series || '[]');
    const unit   = canvas.dataset.unit || '';
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: series.map(p => p.date.slice(5)),  // MM-DD only
            datasets: [{
                label: 'استهلاك يومي (' + unit + ')',
                data: series.map(p => p.qty),
                backgroundColor: 'rgba(15, 71, 49, .55)',
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } },
                x: { ticks: { font: { size: 10 } } },
            },
        },
    });
})();

/**
 * Keyboard navigation between ingredients on the stock card.
 *   Ctrl+Home       → first ingredient
 *   Ctrl+End        → last ingredient
 *   Ctrl+ArrowRight → previous (Arabic / RTL: right means "back")
 *   Ctrl+ArrowLeft  → next     (Arabic / RTL: left means "forward")
 *
 * We read the destination from the matching `data-ing-nav` button in
 * the breadcrumb so disabled-state and URL generation stay in one
 * place (the Blade-rendered buttons). If the button is disabled (no
 * adjacent ingredient), the shortcut is a no-op.
 *
 * Guards:
 *   • Only fires with Ctrl pressed — never hijacks plain arrow keys.
 *   • Skips when focus is in an input / textarea / contenteditable so
 *     editors keep their cursor movement.
 */
(function () {
    function isTyping(el) {
        if (! el) return false;
        const tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    }

    function go(direction) {
        const btn = document.querySelector('[data-ing-nav="' + direction + '"]');
        if (! btn || btn.classList.contains('disabled')) return;
        const href = btn.getAttribute('href');
        if (href && href !== '#') window.location.href = href;
    }

    document.addEventListener('keydown', function (e) {
        if (! e.ctrlKey || e.altKey || e.shiftKey || e.metaKey) return;
        if (isTyping(document.activeElement)) return;

        switch (e.key) {
            case 'Home':       e.preventDefault(); go('first'); break;
            case 'End':        e.preventDefault(); go('last');  break;
            case 'ArrowRight': e.preventDefault(); go('prev');  break;
            case 'ArrowLeft':  e.preventDefault(); go('next');  break;
        }
    });
})();
</script>
@endpush
@endsection
