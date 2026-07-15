@extends('layouts.admin')
@section('title', 'جرد ' . $count->number)

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/stock-count-workspace.css') }}?v={{ filemtime(public_path('assets/dashtic/css/stock-count-workspace.css')) }}">
@endpush

@php
    $statusMap = [
        'draft'     => ['label' => 'مسودة — قيد العدّ', 'icon' => 'bi-pencil-square', 'cls' => 'sc-st--draft'],
        'finalized' => ['label' => 'مُعتمد — تم تطبيق التسويات', 'icon' => 'bi-check-circle-fill', 'cls' => 'sc-st--final'],
        'cancelled' => ['label' => 'ملغي', 'icon' => 'bi-x-circle-fill', 'cls' => 'sc-st--cancel'],
    ];
    $st = $statusMap[$count->status] ?? ['label' => $count->status, 'icon' => 'bi-question-circle', 'cls' => 'sc-st--draft'];

    $items     = $count->items;
    $counted   = $items->whereNotNull('counted_qty')->count();
    $shortages = $items->filter(fn ($i) => $i->counted_qty !== null && (float) $i->variance < -0.0001)->count();
    $overages  = $items->filter(fn ($i) => $i->counted_qty !== null && (float) $i->variance > 0.0001)->count();
    $matches   = $items->filter(fn ($i) => $i->counted_qty !== null && abs((float) $i->variance) <= 0.0001)->count();
    $netCost   = (float) $items->sum('variance_cost');
    $currency  = config('restaurant.currency_symbol', '₪');
@endphp

@section('content')
<x-admin.breadcrumb
    title="جرد {{ $count->number }}"
    icon="bi-clipboard-check"
    subtitle="مقارنة المخزون المتوقع بالكمية الفعلية في المخزن وتسوية الفروقات" />

{{-- ═══════════════════ Hero header ═══════════════════ --}}
<div class="sc-hero {{ $st['cls'] }} mb-3">
    <div class="sc-hero__bg" aria-hidden="true"></div>
    <div class="sc-hero__inner">
        <div class="sc-hero__main">
            <div class="sc-hero__num">
                <i class="bi bi-clipboard-data"></i>
                <span>{{ $count->number }}</span>
            </div>
            <div class="sc-hero__meta">
                <div class="sc-meta-row">
                    <i class="bi bi-calendar-event"></i>
                    <span class="sc-meta-label">تاريخ الجرد:</span>
                    <strong>{{ optional($count->count_date)->format('Y-m-d') ?? '—' }}</strong>
                </div>
                <div class="sc-meta-row">
                    <i class="bi bi-geo-alt-fill"></i>
                    <span class="sc-meta-label">موقع التخزين:</span>
                    <strong>{{ $count->storageLocation?->name ?? 'إجمالي الفرع' }}</strong>
                </div>
                <div class="sc-meta-row">
                    <i class="bi bi-person-fill"></i>
                    <span class="sc-meta-label">أنشأه:</span>
                    <strong>{{ $count->creator?->name ?? '—' }}</strong>
                    <small class="ms-2" style="color: rgba(255,255,255,.65)">· {{ $count->created_at?->diffForHumans() }}</small>
                </div>
                @if($count->status === 'finalized')
                    <div class="sc-meta-row">
                        <i class="bi bi-check-circle-fill"></i>
                        <span class="sc-meta-label">اعتمده:</span>
                        <strong>{{ $count->finalizer?->name ?? '—' }}</strong>
                        <small class="ms-2" style="color: rgba(255,255,255,.65)">· {{ $count->finalized_at?->format('Y-m-d H:i') ?? '' }}</small>
                    </div>
                @endif
                @if($count->status === 'cancelled' && $count->cancelled_at)
                    <div class="sc-meta-row">
                        <i class="bi bi-x-circle-fill"></i>
                        <span class="sc-meta-label">أُلغي بتاريخ:</span>
                        <strong>{{ $count->cancelled_at->format('Y-m-d H:i') }}</strong>
                    </div>
                @endif
            </div>
        </div>
        <div class="sc-hero__side">
            <div class="sc-status-pill">
                <i class="bi {{ $st['icon'] }}"></i>
                <span>{{ $st['label'] }}</span>
            </div>
            <div class="sc-hero__actions">
                <a href="{{ route('admin.stock-counts.export.xlsx', $count) }}"
                   class="btn btn-light btn-sm"
                   title="تصدير Excel — ملخص + كل البنود + الفروقات فقط">
                    <i class="bi bi-file-earmark-spreadsheet"></i> تصدير Excel
                </a>
                <a href="{{ route('admin.stock-counts.index') }}" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-right"></i> الجرود
                </a>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════ Help banner ═══════════════════ --}}
<div class="sc-help mb-3" x-data="{ open: {{ $count->isEditable() ? 'true' : 'false' }} }">
    <button type="button" class="sc-help__head" @click="open = !open" :aria-expanded="open">
        <span class="sc-help__title">
            <i class="bi bi-info-circle-fill"></i>
            ما هو الجرد وكيف أستخدم هذه الشاشة؟
        </span>
        <i class="bi" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
    </button>
    <div class="sc-help__body" x-show="open" x-cloak>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="sc-help__card">
                    <div class="sc-help__num">١</div>
                    <h6>ما هو الجرد؟</h6>
                    <p>
                        مقارنة بين <b>كمية النظام</b> (ما يُفترض موجوداً حسب سجلاتك) و
                        <b>الكمية الفعلية</b> (ما عددته بيدك في المخزن). الفرق يكشف الهدر،
                        السرقة، الاستلام غير المُسجَّل، أو الأخطاء الإدارية.
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="sc-help__card">
                    <div class="sc-help__num">٢</div>
                    <h6>كيف أعدّ المكونات؟</h6>
                    <p>
                        ادخل إلى المخزن، عدّ كل مكون فعلياً، واكتب الرقم في عمود
                        «الكمية الفعلية». النظام يحفظ تلقائياً عند الخروج من الحقل.
                        <span class="text-success">
                            <i class="bi bi-check2"></i> أخضر = محفوظ
                        </span>
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="sc-help__card">
                    <div class="sc-help__num">٣</div>
                    <h6>ماذا يعني الفرق؟</h6>
                    <ul class="sc-help__list">
                        <li>
                            <span class="sc-tag sc-tag--short">نقص</span>
                            الفعلي أقل من النظام — قد يدل على هدر/سرقة
                        </li>
                        <li>
                            <span class="sc-tag sc-tag--over">زيادة</span>
                            الفعلي أكبر من النظام — استلام غير مسجّل
                        </li>
                        <li>
                            <span class="sc-tag sc-tag--match">مطابقة</span>
                            لا فرق — كل شي تمام
                        </li>
                    </ul>
                </div>
            </div>
            @if($count->isEditable())
                <div class="col-12">
                    <div class="sc-help__warn">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>
                            <b>عند الضغط على «اعتماد الجرد»:</b>
                            النظام سيُنشئ حركة «تعديل مخزون» لكل مكون فيه فرق ليطابق الكمية الفعلية.
                            <b>هذا التغيير دائم</b> — راجع الفروقات جيداً قبل الاعتماد.
                            المكونات اللي «لم تُعد» تبقى كما هي ولا تتأثر.
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- ═══════════════════ Post-finalize result card ═══════════════════ --}}
@if($count->status === 'finalized')
    <div class="sc-result mb-3">
        <div class="sc-result__head">
            <i class="bi bi-check-circle-fill"></i>
            <span>تم تطبيق التسويات على المخزون</span>
        </div>
        <div class="row g-2 mt-1">
            <div class="col-md-3">
                <div class="sc-res-stat">
                    <span class="sc-res-stat__label">عُدّت</span>
                    <strong>{{ $counted }}</strong>
                </div>
            </div>
            <div class="col-md-2">
                <div class="sc-res-stat sc-res-stat--ok">
                    <span class="sc-res-stat__label">مطابقة</span>
                    <strong>{{ $matches }}</strong>
                </div>
            </div>
            <div class="col-md-2">
                <div class="sc-res-stat sc-res-stat--down">
                    <span class="sc-res-stat__label">نقص</span>
                    <strong>{{ $shortages }}</strong>
                </div>
            </div>
            <div class="col-md-2">
                <div class="sc-res-stat sc-res-stat--up">
                    <span class="sc-res-stat__label">زيادة</span>
                    <strong>{{ $overages }}</strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="sc-res-stat sc-res-stat--{{ $netCost < 0 ? 'down' : ($netCost > 0 ? 'up' : 'ok') }}">
                    <span class="sc-res-stat__label">صافي القيمة</span>
                    <strong>{{ ($netCost >= 0 ? '+' : '') . number_format($netCost, 2) }} {{ $currency }}</strong>
                </div>
            </div>
        </div>
    </div>
@endif

@if($count->status === 'cancelled')
    <div class="sc-cancelled mb-3">
        <i class="bi bi-x-octagon-fill"></i>
        <div>
            <b>هذا الجرد ملغي.</b>
            لم يتم تطبيق أي تسوية على المخزون. يمكنك مراجعة البنود للأرشفة فقط.
            @if($count->notes)
                <small class="d-block mt-1 text-muted">{{ $count->notes }}</small>
            @endif
        </div>
    </div>
@endif

{{-- ═══════════════════ Workspace (Livewire) ═══════════════════ --}}
<livewire:admin.stock-count-workspace :count="$count" />
@endsection

@push('styles')
<style>
    .sc-hero {
        position: relative;
        border-radius: 16px;
        overflow: hidden;
        color: #fff;
        box-shadow: 0 8px 28px -10px rgba(15, 71, 49, .35);
    }
    .sc-hero__bg {
        position: absolute; inset: 0;
        background: linear-gradient(135deg, #0f4731 0%, #1c5e44 60%, #14532d 100%);
        z-index: 0;
    }
    .sc-st--final .sc-hero__bg { background: linear-gradient(135deg, #14532d 0%, #16a34a 100%); }
    .sc-st--cancel .sc-hero__bg { background: linear-gradient(135deg, #7f1d1d 0%, #b91c1c 100%); }
    .sc-st--draft .sc-hero__bg::after {
        content: '';
        position: absolute; inset: 0;
        background: radial-gradient(circle at top right, rgba(249, 168, 37, .25), transparent 50%);
    }
    .sc-hero__inner {
        position: relative; z-index: 1;
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
        gap: 1.5rem; padding: 1.25rem 1.5rem;
    }
    .sc-hero__main { display: flex; align-items: center; gap: 1.5rem; flex: 1; min-width: 260px; }
    .sc-hero__num {
        display: inline-flex; align-items: center; gap: .55rem;
        font-size: 1.6rem; font-weight: 900;
        background: rgba(255,255,255,.14);
        padding: .5rem 1rem; border-radius: 12px;
        border: 1px solid rgba(255,255,255,.2);
        font-variant-numeric: tabular-nums;
    }
    .sc-hero__num i { font-size: 1.3rem; opacity: .9; }
    .sc-hero__meta { display: flex; flex-direction: column; gap: .35rem; }
    .sc-meta-row {
        display: flex; align-items: center; gap: .4rem;
        font-size: .9rem; color: rgba(255,255,255,.95);
    }
    .sc-meta-row i { font-size: .95rem; opacity: .85; min-width: 18px; }
    .sc-meta-label { color: rgba(255,255,255,.7); font-weight: 500; }
    .sc-meta-row strong { font-weight: 800; }

    .sc-hero__side { display: flex; flex-direction: column; align-items: flex-end; gap: .65rem; }
    .sc-status-pill {
        display: inline-flex; align-items: center; gap: .45rem;
        background: rgba(255,255,255,.14);
        border: 1px solid rgba(255,255,255,.25);
        padding: .45rem .85rem; border-radius: 99px;
        font-weight: 700; font-size: .85rem;
        backdrop-filter: blur(8px);
    }
    .sc-status-pill i { font-size: 1rem; }
    .sc-hero__actions { display: flex; gap: .4rem; flex-wrap: wrap; }

    .sc-help {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        overflow: hidden;
    }
    .sc-help__head {
        display: flex; align-items: center; justify-content: space-between;
        width: 100%; border: 0; background: linear-gradient(90deg, #f0fdf4 0%, #fff 100%);
        padding: .85rem 1.1rem;
        font-weight: 700; cursor: pointer;
        transition: background .15s;
    }
    .sc-help__head:hover { background: linear-gradient(90deg, #dcfce7 0%, #fff 100%); }
    .sc-help__title { display: inline-flex; align-items: center; gap: .55rem; color: #0f4731; }
    .sc-help__title i { color: #16a34a; font-size: 1.1rem; }
    .sc-help__body { padding: 1rem 1.1rem; border-top: 1px solid #f1f5f9; }
    .sc-help__card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        height: 100%;
        position: relative;
    }
    .sc-help__num {
        position: absolute; top: -10px; inset-inline-end: 12px;
        width: 28px; height: 28px;
        display: inline-flex; align-items: center; justify-content: center;
        background: #16a34a; color: #fff;
        border-radius: 50%;
        font-weight: 900; font-size: .85rem;
    }
    .sc-help__card h6 { font-weight: 800; color: #0f4731; margin-bottom: .5rem; }
    .sc-help__card p { font-size: .82rem; color: #475569; margin: 0; line-height: 1.6; }
    .sc-help__list { list-style: none; padding: 0; margin: 0; font-size: .82rem; color: #475569; }
    .sc-help__list li { display: flex; align-items: center; gap: .5rem; padding: .25rem 0; }
    .sc-tag {
        display: inline-block; padding: 1px 8px; border-radius: 99px;
        font-size: .7rem; font-weight: 700; min-width: 50px; text-align: center;
    }
    .sc-tag--short { background: #fee2e2; color: #b91c1c; }
    .sc-tag--over  { background: #fef3c7; color: #b45309; }
    .sc-tag--match { background: #d1fae5; color: #065f46; }
    .sc-help__warn {
        display: flex; align-items: flex-start; gap: .75rem;
        background: #fef3c7;
        border: 1px solid #fcd34d;
        border-radius: 10px;
        padding: .85rem 1rem;
        color: #78350f;
        font-size: .85rem;
        line-height: 1.6;
    }
    .sc-help__warn i { font-size: 1.3rem; color: #d97706; flex-shrink: 0; margin-top: 2px; }

    .sc-result {
        background: linear-gradient(135deg, #ecfdf5 0%, #fff 100%);
        border: 1px solid #a7f3d0;
        border-radius: 14px;
        padding: 1rem 1.25rem;
    }
    .sc-result__head {
        display: inline-flex; align-items: center; gap: .55rem;
        font-weight: 800; color: #065f46; font-size: 1rem;
    }
    .sc-result__head i { font-size: 1.3rem; color: #16a34a; }
    .sc-res-stat {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: .55rem .75rem;
        text-align: center;
    }
    .sc-res-stat__label { display: block; font-size: .72rem; color: #64748b; font-weight: 600; margin-bottom: 2px; }
    .sc-res-stat strong { font-size: 1.05rem; font-weight: 900; color: #0f172a; }
    .sc-res-stat--ok   { border-color: #a7f3d0; }
    .sc-res-stat--ok strong   { color: #065f46; }
    .sc-res-stat--down { border-color: #fecaca; }
    .sc-res-stat--down strong { color: #b91c1c; }
    .sc-res-stat--up   { border-color: #fcd34d; }
    .sc-res-stat--up strong   { color: #b45309; }

    .sc-cancelled {
        display: flex; align-items: flex-start; gap: .75rem;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 12px;
        padding: 1rem 1.1rem;
        color: #7f1d1d;
        font-size: .9rem;
    }
    .sc-cancelled i { font-size: 1.5rem; color: #dc2626; flex-shrink: 0; }

    [x-cloak] { display: none !important; }

    @media (max-width: 768px) {
        .sc-hero__inner { flex-direction: column; align-items: stretch; }
        .sc-hero__main { flex-direction: column; align-items: stretch; gap: 1rem; }
        .sc-hero__num { justify-content: center; }
        .sc-hero__side { align-items: stretch; }
        .sc-status-pill { justify-content: center; }
    }
</style>
@endpush

@push('scripts')
@livewireScripts
<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('flash', (e) => {
            const payload = Array.isArray(e) ? e[0] : e;
            if (window.showNotification) {
                window.showNotification('', payload.message, payload.type);
            }
        });
    });
</script>
@endpush
