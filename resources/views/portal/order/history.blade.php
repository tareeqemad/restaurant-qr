@extends('portal.layout')
@section('title', __('portal.order.history_title'))

@php
    $currency = config('restaurant.currency_symbol', '₪');
    $activeStatuses = \App\Enums\OrderStatus::active();
    $activeCount = $orders->whereIn('status', $activeStatuses)->count();
    $totalSpent = $orders->where('status', \App\Enums\OrderStatus::Completed->value)
        ->sum(fn ($o) => (float) $o->total)
        + $orders->where('status', \App\Enums\OrderStatus::Delivered->value)
        ->sum(fn ($o) => (float) $o->total);
@endphp

@section('content')
<div class="poh-page">

    {{-- Hero strip with primary CTA ─────────────────────── --}}
    <div class="poh-hero">
        <div class="poh-hero__text">
            <span class="poh-hero__eyebrow"><i class="bi bi-bag-heart-fill"></i> {{ __('portal.order.hero_eyebrow') }}</span>
            <h1 class="poh-hero__title">{{ __('portal.order.hero_title') }}</h1>
            <p class="poh-hero__sub">
                {{ __('portal.order.hero_subtitle') }}
            </p>
            <a href="{{ route('portal.order.branches') }}" class="poh-cta">
                <i class="bi bi-bag-plus-fill"></i>
                <span>{{ __('portal.order.order_now') }}</span>
                <i class="bi bi-arrow-left poh-cta__arrow"></i>
            </a>
        </div>
        <div class="poh-hero__art" aria-hidden="true">
            <i class="bi bi-bag-plus-fill"></i>
        </div>
    </div>

    {{-- Quick stats ────────────────────────────────────── --}}
    @if($orders->total() > 0)
        <div class="poh-stats">
            <div class="poh-stat">
                <span class="poh-stat__icon poh-stat__icon--all"><i class="bi bi-receipt"></i></span>
                <div>
                    <div class="poh-stat__value">{{ $orders->total() }}</div>
                    <div class="poh-stat__label">{{ __('portal.order.total_orders') }}</div>
                </div>
            </div>
            <div class="poh-stat">
                <span class="poh-stat__icon poh-stat__icon--active"><i class="bi bi-clock-history"></i></span>
                <div>
                    <div class="poh-stat__value">{{ $activeCount }}</div>
                    <div class="poh-stat__label">{{ __('portal.order.in_progress') }}</div>
                </div>
            </div>
            <div class="poh-stat">
                <span class="poh-stat__icon poh-stat__icon--spent"><i class="bi bi-coin"></i></span>
                <div>
                    <div class="poh-stat__value">{{ number_format($totalSpent, 2) }} <small>{{ $currency }}</small></div>
                    <div class="poh-stat__label">{{ __('portal.order.total_spent') }}</div>
                </div>
            </div>
        </div>
    @endif

    {{-- Orders list ────────────────────────────────────── --}}
    @if($orders->isEmpty())
        <div class="poh-empty">
            <div class="poh-empty__shape"><i class="bi bi-bag"></i></div>
            <h2>{{ __('portal.order.empty_history_title') }}</h2>
            <p>{{ __('portal.order.empty_history_body') }}</p>
            <a href="{{ route('portal.order.branches') }}" class="poh-cta poh-cta--block">
                <i class="bi bi-bag-plus-fill"></i>
                {{ __('portal.order.start_first_order') }}
                <i class="bi bi-arrow-left poh-cta__arrow"></i>
            </a>
        </div>
    @else
        <div class="poh-list-head">
            <h2>{{ __('portal.order.previous_orders') }}</h2>
        </div>

        <div class="poh-list">
            @foreach($orders as $o)
                @php
                    $isActive = in_array($o->status, $activeStatuses, true);
                    // Source = how the order was placed:
                    //   - dine_in (table_session_id present) = in-restaurant session
                    //   - takeaway = pickup from branch (app order)
                    //   - delivery = delivery (app order)
                    $source = match (true) {
                        $o->table_session_id !== null => 'dine_in',
                        $o->order_type === 'takeaway' => 'takeaway',
                        $o->order_type === 'delivery' => 'delivery',
                        default                       => 'other',
                    };
                    $sourceMeta = [
                        'dine_in'  => ['icon' => 'bi-grid-3x3-gap-fill', 'label' => __('portal.order.source_dine_in'), 'sub' => __('portal.order.source_table'), 'tone' => 'mint'],
                        'takeaway' => ['icon' => 'bi-bag-fill',          'label' => __('portal.order.source_takeaway'), 'sub' => __('portal.order.source_from_app'), 'tone' => 'gold'],
                        'delivery' => ['icon' => 'bi-truck',             'label' => __('portal.order.source_delivery'), 'sub' => __('portal.order.source_from_app'), 'tone' => 'blue'],
                        'other'    => ['icon' => 'bi-receipt',           'label' => __('portal.order.source_other'), 'sub' => '', 'tone' => 'mint'],
                    ][$source];
                @endphp
                <a href="{{ route('portal.order.placed', $o) }}" class="poh-row {{ $isActive ? 'poh-row--active' : '' }}">
                    <div class="poh-row__icon poh-icon--{{ $sourceMeta['tone'] }}">
                        <i class="bi {{ $sourceMeta['icon'] }}"></i>
                    </div>
                    <div class="poh-row__body">
                        <div class="poh-row__head">
                            <span class="poh-row__num">{{ $o->number }}</span>
                            <span class="poh-source-pill poh-source--{{ $sourceMeta['tone'] }}">
                                <i class="bi {{ $sourceMeta['icon'] }}"></i>
                                {{ $sourceMeta['label'] }}
                                @if($sourceMeta['sub'])
                                    <small>· {{ $sourceMeta['sub'] }}@if($source === 'dine_in' && $o->table) {{ $o->table->number }}@endif</small>
                                @endif
                            </span>
                            <span class="poh-row__status poh-status--{{ $o->status }}">
                                {{ $o->statusLabel() }}
                            </span>
                        </div>
                        <div class="poh-row__meta">
                            <span><i class="bi bi-shop"></i> {{ $o->branch->localizedName() }}</span>
                            <span><i class="bi bi-basket"></i> {{ trans_choice('portal.order.item_count', $o->items->count(), ['count' => $o->items->count()]) }}</span>
                            <span><i class="bi bi-clock"></i> {{ $o->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                    <div class="poh-row__total">
                        <span>{{ number_format($o->total, 2) }}</span>
                        <small>{{ $currency }}</small>
                    </div>
                    <i class="bi bi-chevron-left poh-row__arrow"></i>
                </a>
            @endforeach
        </div>

        <div class="mt-3">{{ $orders->links() }}</div>
    @endif

</div>

@push('styles')
<style>
    .poh-page { display: flex; flex-direction: column; gap: 1.2rem; }

    /* ── Hero ───────────────────────────────────────── */
    .poh-hero {
        position: relative;
        display: flex; align-items: center; justify-content: space-between;
        gap: 1.5rem;
        background: linear-gradient(135deg, var(--green-primary, #0f4731) 0%, #1c5e44 100%);
        color: #fff;
        border-radius: 18px;
        padding: 1.6rem 1.8rem;
        overflow: hidden;
    }
    .poh-hero__text { position: relative; z-index: 1; max-width: 100%; }
    .poh-hero__eyebrow {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .78rem; font-weight: 700;
        background: rgba(255,255,255,.18); backdrop-filter: blur(4px);
        padding: .25rem .75rem; border-radius: 99px;
        margin-bottom: .65rem;
    }
    .poh-hero__title {
        font-size: 1.7rem; font-weight: 800; line-height: 1.2;
        margin: 0 0 .35rem;
    }
    .poh-hero__sub {
        font-size: .92rem; opacity: .9;
        margin: 0 0 1rem; line-height: 1.5; max-width: 480px;
    }
    .poh-hero__art {
        flex-shrink: 0;
        width: 90px; height: 90px;
        background: rgba(255,255,255,.13);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 2.6rem;
    }
    @media (max-width: 600px) {
        .poh-hero { padding: 1.3rem 1.2rem; }
        .poh-hero__art { display: none; }
        .poh-hero__title { font-size: 1.4rem; }
    }

    /* ── CTA button ─────────────────────────────────── */
    .poh-cta {
        display: inline-flex; align-items: center; gap: .55rem;
        background: #fff;
        color: var(--green-primary, #0f4731);
        padding: .8rem 1.4rem;
        border-radius: 99px;
        font-weight: 800; font-size: 1rem;
        text-decoration: none;
        box-shadow: 0 6px 18px -3px rgba(0,0,0,.18);
        transition: all .18s ease;
    }
    .poh-cta:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 24px -3px rgba(0,0,0,.22);
        color: var(--green-primary, #0f4731);
    }
    .poh-cta__arrow { transition: transform .2s ease; }
    .poh-cta:hover .poh-cta__arrow { transform: translateX(-3px); }
    .poh-cta--block { display: flex; justify-content: center; margin: 1rem auto 0; max-width: 320px; }

    /* ── Stats ──────────────────────────────────────── */
    .poh-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: .8rem;
    }
    .poh-stat {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 1rem 1.1rem;
        display: flex; align-items: center; gap: .85rem;
    }
    .poh-stat__icon {
        width: 44px; height: 44px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .poh-stat__icon--all    { background: rgba(15, 71, 49, .1);  color: var(--green-primary, #0f4731); }
    .poh-stat__icon--active { background: rgba(184, 135, 42, .12); color: #b8872a; }
    .poh-stat__icon--spent  { background: rgba(46, 125, 50, .1);  color: #2e7d32; }
    .poh-stat__value {
        font-size: 1.45rem; font-weight: 800; line-height: 1.1;
        color: var(--ink, #1f2937);
        font-variant-numeric: tabular-nums;
    }
    .poh-stat__value small { font-size: .65rem; color: #6b7280; font-weight: 600; }
    .poh-stat__label { font-size: .8rem; color: #6b7280; margin-top: 2px; }

    /* ── List ───────────────────────────────────────── */
    .poh-list-head { margin-top: .3rem; }
    .poh-list-head h2 {
        font-size: 1.05rem; font-weight: 800;
        color: var(--ink, #1f2937);
        margin: 0;
    }
    .poh-list { display: flex; flex-direction: column; gap: .65rem; }
    .poh-row {
        display: grid;
        grid-template-columns: auto 1fr auto auto;
        gap: .85rem;
        align-items: center;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: .9rem 1.1rem;
        text-decoration: none;
        color: inherit;
        transition: all .15s ease;
    }
    .poh-row:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px -3px rgba(15,71,49,.1);
        border-color: rgba(15,71,49,.25);
        color: inherit;
    }
    .poh-row--active {
        background: linear-gradient(135deg, rgba(184, 135, 42, .04), rgba(184, 135, 42, .01));
        border-color: rgba(184, 135, 42, .3);
    }
    .poh-row__icon {
        width: 44px; height: 44px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .poh-icon--mint  { background: rgba(15, 71, 49, .08);  color: var(--green-primary, #0f4731); }
    .poh-icon--gold  { background: rgba(184, 135, 42, .12); color: #b8872a; }
    .poh-icon--blue  { background: rgba(37, 99, 235, .1);   color: #2563eb; }
    .poh-row--active .poh-row__icon {
        background: rgba(184, 135, 42, .18);
        color: #b8872a;
    }

    /* Source pill — tells the diner at a glance whether they ordered
       from inside the restaurant (dine-in) or from the app (takeaway /
       delivery). Sub-label adds the table number for dine-in. */
    .poh-source-pill {
        display: inline-flex; align-items: center; gap: .25rem;
        font-size: .7rem; font-weight: 700;
        padding: 2px 8px;
        border-radius: 99px;
        line-height: 1.6;
    }
    .poh-source-pill i { font-size: .75rem; }
    .poh-source-pill small { opacity: .85; font-weight: 600; }
    .poh-source--mint  { background: rgba(15, 71, 49, .1);   color: var(--green-primary, #0f4731); }
    .poh-source--gold  { background: rgba(184, 135, 42, .14); color: #8a6920; }
    .poh-source--blue  { background: rgba(37, 99, 235, .12);  color: #1d4ed8; }
    .poh-row__body { min-width: 0; }
    .poh-row__head {
        display: flex; align-items: center; gap: .55rem;
        flex-wrap: wrap;
        margin-bottom: .25rem;
    }
    .poh-row__num {
        font-weight: 800;
        color: var(--ink, #1f2937);
        font-family: ui-monospace, SFMono-Regular, monospace;
        font-size: .9rem;
    }
    .poh-row__status {
        font-size: .72rem; font-weight: 700;
        padding: 2px 9px; border-radius: 99px;
    }
    .poh-status--pending   { background: #fef3c7; color: #92400e; }
    .poh-status--approved,
    .poh-status--preparing,
    .poh-status--ready,
    .poh-status--delivered { background: #dbeafe; color: #1e40af; }
    .poh-status--completed { background: #dcfce7; color: #166534; }
    .poh-status--cancelled { background: #fee2e2; color: #991b1b; }

    .poh-row__meta {
        display: flex; flex-wrap: wrap; gap: .35rem .8rem;
        font-size: .76rem; color: #6b7280;
    }
    .poh-row__meta i { margin-inline-end: 2px; }
    .poh-row__total {
        font-weight: 800;
        color: var(--green-primary, #0f4731);
        font-variant-numeric: tabular-nums;
        font-size: 1.05rem;
        white-space: nowrap;
        text-align: end;
    }
    .poh-row__total small { font-size: .68rem; color: #6b7280; font-weight: 500; margin-inline-start: 2px; }
    .poh-row__arrow { color: #cbd5e1; font-size: .9rem; flex-shrink: 0; }

    @media (max-width: 600px) {
        .poh-row { grid-template-columns: auto 1fr auto; gap: .65rem; padding: .8rem .9rem; }
        .poh-row__arrow { display: none; }
        .poh-row__total { font-size: .95rem; }
    }

    /* ── Empty state ────────────────────────────────── */
    .poh-empty {
        background: #fff;
        border: 1px dashed #e5e7eb;
        border-radius: 16px;
        padding: 2.5rem 1.5rem;
        text-align: center;
    }
    .poh-empty__shape {
        width: 80px; height: 80px;
        border-radius: 50%;
        background: rgba(15, 71, 49, .06);
        color: var(--green-primary, #0f4731);
        display: flex; align-items: center; justify-content: center;
        font-size: 2.2rem;
        margin: 0 auto 1rem;
    }
    .poh-empty h2 {
        font-size: 1.25rem; font-weight: 800;
        color: var(--ink, #1f2937);
        margin: 0 0 .35rem;
    }
    .poh-empty p {
        color: #6b7280; font-size: .92rem;
        max-width: 380px; margin: 0 auto;
        line-height: 1.55;
    }
</style>
@endpush
@endsection
