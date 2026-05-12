@extends('portal.layout')
@section('title', 'الرئيسية')

@section('content')

{{-- Live announcement banner — shows the most recent published campaign
     that's within its schedule window AND visible to this branch. The
     view stays silent if there's nothing active. --}}
@php
    $liveAnnouncement = \App\Models\Announcement::activeNow()
        ->forCustomer($customer)
        ->latest('published_at')
        ->first();
@endphp
@if($liveAnnouncement)
    @php $c = $liveAnnouncement->color ?: '#F9A825'; @endphp
    <a href="{{ $liveAnnouncement->cta_url ?: route('portal.notifications.index') }}"
       class="pf-promo" style="--promo-color: {{ $c }};">
        <span class="pf-promo__icon">
            <i class="bi {{ $liveAnnouncement->icon ?: 'bi-megaphone-fill' }}"></i>
        </span>
        <span class="pf-promo__body">
            <strong>{{ $liveAnnouncement->title }}</strong>
            <small>{{ \Illuminate\Support\Str::limit($liveAnnouncement->body, 100) }}</small>
        </span>
        @if($liveAnnouncement->cta_text)
            <span class="pf-promo__cta">
                {{ $liveAnnouncement->cta_text }}
                <i class="bi bi-arrow-left"></i>
            </span>
        @else
            <i class="bi bi-arrow-left pf-promo__arrow"></i>
        @endif
    </a>

    <style>
    .pf-promo {
        display: flex; align-items: center; gap: 14px;
        padding: 14px 18px;
        background: linear-gradient(135deg, color-mix(in srgb, var(--promo-color) 12%, white) 0%, color-mix(in srgb, var(--promo-color) 4%, white) 100%);
        border: 1.5px solid color-mix(in srgb, var(--promo-color) 30%, transparent);
        border-radius: 16px;
        text-decoration: none;
        color: var(--ink);
        margin-bottom: 18px;
        transition: transform .15s, box-shadow .15s;
        position: relative;
        overflow: hidden;
    }
    .pf-promo:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 22px color-mix(in srgb, var(--promo-color) 18%, transparent);
    }
    .pf-promo::before {
        content: ''; position: absolute;
        top: -30px; inset-inline-end: -30px;
        width: 120px; height: 120px;
        background: color-mix(in srgb, var(--promo-color) 15%, transparent);
        border-radius: 50%;
        pointer-events: none;
    }
    .pf-promo__icon {
        width: 46px; height: 46px;
        background: var(--promo-color); color: #fff;
        border-radius: 12px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1.25rem; flex-shrink: 0;
        position: relative; z-index: 1;
    }
    .pf-promo__body { flex: 1; min-width: 0; position: relative; z-index: 1; }
    .pf-promo__body strong { display: block; font-size: 1rem; font-weight: 800; color: var(--ink); line-height: 1.3; }
    .pf-promo__body small { font-size: .82rem; color: var(--ink-2); line-height: 1.5; }
    .pf-promo__cta {
        background: var(--promo-color); color: #fff;
        padding: 8px 16px; border-radius: 10px;
        font-weight: 700; font-size: .85rem;
        flex-shrink: 0; white-space: nowrap;
        display: inline-flex; align-items: center; gap: 4px;
        position: relative; z-index: 1;
    }
    .pf-promo__arrow { color: var(--promo-color); font-size: 1.2rem; flex-shrink: 0; position: relative; z-index: 1; }
    @media (max-width: 480px) {
        .pf-promo { flex-wrap: wrap; }
        .pf-promo__cta { width: 100%; justify-content: center; }
    }
    </style>
@endif

<div class="pf-card">
    <h1 class="pf-title">أهلاً، {{ $customer->name }} 👋</h1>
    <p class="pf-subtitle">جاهز لحجز طاولتك القادمة؟</p>

    <div class="pf-stat-grid">
        <div class="pf-stat">
            <div class="pf-stat__value">{{ $stats['upcoming'] }}</div>
            <div class="pf-stat__label">حجوزات قادمة</div>
        </div>
        <div class="pf-stat">
            <div class="pf-stat__value">{{ $stats['completed'] }}</div>
            <div class="pf-stat__label">زيارات مكتملة</div>
        </div>
        <div class="pf-stat">
            <div class="pf-stat__value">{{ $stats['cancelled'] }}</div>
            <div class="pf-stat__label">حجوزات ملغاة</div>
        </div>
    </div>

    <a href="{{ route('portal.reservations.create') }}" class="pf-btn pf-btn--block">
        <i class="bi bi-calendar-plus"></i> احجز طاولة
    </a>
</div>

@if($nextRes)
    <div class="pf-section-head">
        <h2>حجزك القادم</h2>
        <a href="{{ route('portal.reservations.index') }}" class="pf-link-bare" style="font-size:.85rem;">
            كل الحجوزات →
        </a>
    </div>

    <div class="pf-res">
        <div class="pf-res__date">
            <div class="pf-res__day">{{ $nextRes->reserved_for->format('d') }}</div>
            <div class="pf-res__month">{{ $nextRes->reserved_for->translatedFormat('M') }}</div>
        </div>
        <div class="pf-res__body">
            <div class="pf-res__head">
                <span class="pf-res__branch">{{ $nextRes->branch->name }}</span>
                <span class="pf-pill pf-pill--{{ $nextRes->status->color() }}">{{ $nextRes->status->label() }}</span>
            </div>
            <div class="pf-res__meta">
                <span><i class="bi bi-clock"></i> {{ $nextRes->reserved_for->format('H:i') }}</span>
                <span><i class="bi bi-people"></i> {{ $nextRes->party_size }} ضيوف</span>
                <span class="pf-res__ref">{{ $nextRes->reference }}</span>
            </div>
        </div>
    </div>
@else
    <div class="pf-section-head">
        <h2>حجزك القادم</h2>
    </div>
    <div class="pf-card">
        <div class="pf-empty">
            <i class="bi bi-calendar2-event"></i>
            <div>لا توجد حجوزات قادمة. ابدأ بحجز طاولتك الآن.</div>
        </div>
    </div>
@endif

{{-- Active orders — still in flight ─────────────────────────────── --}}
@if($activeOrders->isNotEmpty())
    <div class="pf-section-head">
        <h2>طلبات نشطة <span class="pf-badge-num">{{ $orderStats['active'] }}</span></h2>
        <a href="{{ route('portal.order.history') }}" class="pf-link-bare" style="font-size:.85rem;">
            كل الطلبات →
        </a>
    </div>

    <div class="pf-orders">
        @foreach($activeOrders as $order)
            @php
                $src = $order->table_session_id !== null ? 'dine_in'
                    : ($order->order_type === 'delivery' ? 'delivery'
                    : ($order->order_type === 'takeaway' ? 'takeaway' : 'other'));
                $srcMeta = [
                    'dine_in'  => ['icon' => 'bi-grid-3x3-gap-fill', 'label' => 'في المطعم'],
                    'takeaway' => ['icon' => 'bi-bag-fill',          'label' => 'استلام · من التطبيق'],
                    'delivery' => ['icon' => 'bi-truck',             'label' => 'توصيل · من التطبيق'],
                    'other'    => ['icon' => 'bi-bag-check-fill',    'label' => 'طلب'],
                ][$src];
            @endphp
            <a href="{{ route('portal.order.placed', $order) }}" class="pf-order pf-order--active">
                <div class="pf-order__icon">
                    <i class="bi {{ $srcMeta['icon'] }}"></i>
                </div>
                <div class="pf-order__body">
                    <div class="pf-order__head">
                        <span class="pf-order__num">{{ $order->number }}</span>
                        <span class="pf-pill pf-pill--{{ $order->statusColor() }}" data-active>
                            {{ $order->statusLabel() }}
                        </span>
                    </div>
                    <div class="pf-order__meta">
                        <span><i class="bi {{ $srcMeta['icon'] }}"></i> {{ $srcMeta['label'] }}</span>
                        <span><i class="bi bi-shop"></i> {{ $order->branch?->name ?? '—' }}</span>
                        <span><i class="bi bi-clock"></i> {{ $order->created_at->diffForHumans() }}</span>
                        <span><i class="bi bi-currency-exchange"></i>
                            {{ number_format((float) $order->total, 2) }} {{ config('restaurant.currency_symbol', '₪') }}
                        </span>
                    </div>
                </div>
                <i class="bi bi-chevron-left pf-order__arrow"></i>
            </a>
        @endforeach
    </div>
@endif

{{-- Recent orders ──────────────────────────────────────────────── --}}
<div class="pf-section-head">
    <h2>طلباتك السابقة</h2>
    @if($recentOrders->isNotEmpty())
        <a href="{{ route('portal.order.history') }}" class="pf-link-bare" style="font-size:.85rem;">
            عرض الكل ({{ $orderStats['total'] }}) →
        </a>
    @endif
</div>

@if($recentOrders->isNotEmpty())
    <div class="pf-orders">
        @foreach($recentOrders as $order)
            @php
                $src = $order->table_session_id !== null ? 'dine_in'
                    : ($order->order_type === 'delivery' ? 'delivery'
                    : ($order->order_type === 'takeaway' ? 'takeaway' : 'other'));
                $srcMeta = [
                    'dine_in'  => ['icon' => 'bi-grid-3x3-gap-fill', 'label' => 'في المطعم'],
                    'takeaway' => ['icon' => 'bi-bag-fill',          'label' => 'استلام · من التطبيق'],
                    'delivery' => ['icon' => 'bi-truck',             'label' => 'توصيل · من التطبيق'],
                    'other'    => ['icon' => 'bi-receipt',           'label' => 'طلب'],
                ][$src];
            @endphp
            <a href="{{ route('portal.order.placed', $order) }}" class="pf-order">
                <div class="pf-order__icon pf-order__icon--past">
                    <i class="bi {{ $srcMeta['icon'] }}"></i>
                </div>
                <div class="pf-order__body">
                    <div class="pf-order__head">
                        <span class="pf-order__num">{{ $order->number }}</span>
                        <span class="pf-pill pf-pill--{{ $order->statusColor() }}">
                            {{ $order->statusLabel() }}
                        </span>
                    </div>
                    <div class="pf-order__meta">
                        <span><i class="bi {{ $srcMeta['icon'] }}"></i> {{ $srcMeta['label'] }}</span>
                        <span><i class="bi bi-shop"></i> {{ $order->branch?->name ?? '—' }}</span>
                        <span><i class="bi bi-calendar"></i> {{ $order->created_at->translatedFormat('j M, H:i') }}</span>
                        <span><i class="bi bi-currency-exchange"></i>
                            {{ number_format((float) $order->total, 2) }} {{ config('restaurant.currency_symbol', '₪') }}
                        </span>
                    </div>
                </div>
                <i class="bi bi-chevron-left pf-order__arrow"></i>
            </a>
        @endforeach
    </div>
@else
    <div class="pf-card">
        <div class="pf-empty">
            <i class="bi bi-bag"></i>
            <div>لم تطلب أي شيء بعد. تصفّح القائمة وابدأ طلبك الأول.</div>
            <a href="{{ route('portal.order.branches') }}" class="pf-btn pf-btn--sm mt-3">
                <i class="bi bi-shop"></i> اطلب الآن
            </a>
        </div>
    </div>
@endif

<style>
    .pf-badge-num {
        display: inline-flex; align-items: center; justify-content: center;
        background: rgba(15, 71, 49, .12);
        color: var(--primary, #0f4731);
        min-width: 1.5rem; height: 1.5rem;
        padding: 0 .45rem;
        border-radius: 999px;
        font-size: .7rem; font-weight: 800;
        margin-inline-start: .35rem;
    }
    .pf-orders { display: flex; flex-direction: column; gap: .55rem; margin-bottom: 1rem; }
    .pf-order {
        display: flex; align-items: center; gap: .8rem;
        padding: .8rem .9rem;
        background: #fff;
        border: 1px solid rgba(15, 71, 49, .1);
        border-radius: 12px;
        text-decoration: none;
        color: inherit;
        transition: all .15s ease;
    }
    .pf-order:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 14px rgba(15, 71, 49, .08);
        border-color: rgba(15, 71, 49, .25);
    }
    .pf-order--active {
        border-color: rgba(184, 135, 42, .35);
        background: linear-gradient(135deg, rgba(184, 135, 42, .04), rgba(184, 135, 42, .01));
    }
    .pf-order__icon {
        width: 42px; height: 42px;
        border-radius: 10px;
        background: rgba(15, 71, 49, .08);
        color: var(--primary, #0f4731);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; flex-shrink: 0;
    }
    .pf-order--active .pf-order__icon {
        background: rgba(184, 135, 42, .15);
        color: #b8872a;
    }
    .pf-order__icon--past {
        background: rgba(108, 117, 125, .1);
        color: #6c757d;
    }
    .pf-order__body { flex-grow: 1; min-width: 0; }
    .pf-order__head {
        display: flex; align-items: center; justify-content: space-between;
        gap: .5rem; margin-bottom: .25rem;
    }
    .pf-order__num {
        font-weight: 800; font-size: .92rem;
        color: var(--primary, #0f4731);
        font-family: ui-monospace, SFMono-Regular, monospace;
    }
    .pf-order__meta {
        display: flex; flex-wrap: wrap; gap: .35rem .75rem;
        font-size: 12px; color: #6c757d;
    }
    .pf-order__meta i { margin-inline-end: 2px; }
    .pf-order__arrow {
        color: #cbd5e1; font-size: .9rem; flex-shrink: 0;
    }
    .pf-btn--sm { padding: .35rem .9rem; font-size: 13px; }
</style>
@endsection
