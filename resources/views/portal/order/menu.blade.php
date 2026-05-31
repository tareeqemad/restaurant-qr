@extends('portal.layout')
@section('title', __('portal.order.menu_title', ['branch' => $branch->localizedName()]))

@php
    $currency = config('restaurant.currency_symbol', '₪');
@endphp

@section('content')
<div class="pm-page" x-data="menuPage(@js([
    'addUrl'      => route('portal.order.cart.add', $branch),
    'removeUrl'   => route('portal.order.cart.remove', $branch),
    'checkoutUrl' => route('portal.order.checkout', $branch),
    'currency'    => $currency,
    'csrf'        => csrf_token(),
    'initialCart' => $cart,
    'initialCount'=> $cartCount,
    'initialTotal'=> (float) $cartTotal,
]))">

    {{-- Hero strip ──────────────────────────────────────── --}}
    <div class="pm-hero">
        <div class="pm-hero__top">
            <a href="{{ route('portal.order.branches') }}" class="pm-hero__back" title="{{ __('portal.order.change_branch') }}">
                <i class="bi bi-arrow-right"></i>
            </a>
            <div class="pm-hero__brand">
                <i class="bi bi-shop"></i>
                <span class="pm-hero__eyebrow">{{ __('portal.order.branch_menu') }}</span>
                <h1 class="pm-hero__title">{{ $branch->localizedName() }}</h1>
            </div>
        </div>
        <div class="pm-hero__meta">
            @if($categories->count() > 0)
                <span><i class="bi bi-grid"></i> {{ trans_choice('portal.order.sections_count', $categories->count(), ['count' => $categories->count()]) }}</span>
                <span class="pm-dot"></span>
                <span><i class="bi bi-egg-fried"></i> {{ trans_choice('portal.order.menu_items_count', $categories->sum(fn($c) => $c->menuItems->count()), ['count' => $categories->sum(fn($c) => $c->menuItems->count())]) }}</span>
            @endif
            @if($branch->phone)
                <span class="pm-dot"></span>
                <span dir="ltr"><i class="bi bi-telephone"></i> {{ $branch->phone }}</span>
            @endif
        </div>
    </div>

    {{-- Category quick-jump nav ─────────────────────────── --}}
    @if($categories->count() > 1)
        <nav class="pm-jump" aria-label="{{ __('portal.order.category_jump') }}">
            @foreach($categories as $cat)
                <a href="#cat-{{ $cat->id }}" class="pm-jump__link" @click.prevent="jumpTo({{ $cat->id }})">
                    @if($cat->icon)<i class="bi {{ $cat->icon }}"></i>@endif
                    {{ $cat->localizedName() }}
                    <small>{{ $cat->menuItems->count() }}</small>
                </a>
            @endforeach
        </nav>
    @endif

    {{-- Categories + items ─────────────────────────────── --}}
    @if($categories->isEmpty())
        <div class="pm-empty">
            <div class="pm-empty__shape"><i class="bi bi-emoji-frown"></i></div>
            <h2>{{ __('portal.order.empty_menu_title') }}</h2>
            <p>{{ __('portal.order.empty_menu_body') }}</p>
            <a href="{{ route('portal.order.branches') }}" class="pm-cta">
                <i class="bi bi-arrow-right"></i> {{ __('portal.order.choose_another_branch') }}
            </a>
        </div>
    @else
        @foreach($categories as $cat)
            <section class="pm-cat" id="cat-{{ $cat->id }}"
                     :class="{ 'is-collapsed': !isOpen({{ $cat->id }}) }">
                <header class="pm-cat__head" @click="toggleCat({{ $cat->id }})">
                    <h2>
                        @if($cat->icon)<i class="bi {{ $cat->icon }}"></i>@endif
                        {{ $cat->localizedName() }}
                    </h2>
                    <div class="pm-cat__head-right">
                        <span class="pm-cat__count">{{ trans_choice('portal.order.item_count', $cat->menuItems->count(), ['count' => $cat->menuItems->count()]) }}</span>
                        <button type="button" class="pm-cat__toggle" :aria-expanded="isOpen({{ $cat->id }})"
                                :title="isOpen({{ $cat->id }}) ? @js(__('portal.order.hide_items')) : @js(__('portal.order.show_items'))"
                                @click.stop="toggleCat({{ $cat->id }})">
                            <i class="bi" :class="isOpen({{ $cat->id }}) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                        </button>
                    </div>
                </header>

                <div class="pm-grid" x-show="isOpen({{ $cat->id }})" x-collapse>
                    @foreach($cat->menuItems as $item)
                        <article class="pm-item" :class="{ 'is-busy': busyItem === {{ $item->id }} }">
                            <div class="pm-item__media">
                                @if($item->image)
                                    <img src="{{ $item->imageUrl() }}" alt="{{ $item->localizedName() }}" loading="lazy">
                                @else
                                    <div class="pm-item__media--ph">
                                        <i class="bi bi-cup-straw"></i>
                                    </div>
                                @endif
                                @if($item->is_featured)
                                    <span class="pm-badge pm-badge--featured">
                                        <i class="bi bi-star-fill"></i> {{ __('portal.order.featured') }}
                                    </span>
                                @endif
                                @if($item->prep_time_minutes)
                                    <span class="pm-badge pm-badge--time">
                                        <i class="bi bi-clock"></i> {{ __('portal.order.minutes_short', ['count' => $item->prep_time_minutes]) }}
                                    </span>
                                @endif
                            </div>
                            <div class="pm-item__body">
                                <h3 class="pm-item__name">{{ $item->localizedName() }}</h3>
                                @if($item->localizedDescription())
                                    <p class="pm-item__desc">{{ $item->localizedDescription() }}</p>
                                @endif

                                @if($item->allergens && $item->allergens->isNotEmpty())
                                    <div class="pm-allergens" role="group" aria-label="{{ __('portal.order.allergens_aria') }}">
                                        <span class="pm-allergens__label">
                                            <i class="bi bi-exclamation-triangle-fill"></i>
                                            {{ __('portal.order.allergens_label') }}
                                        </span>
                                        @foreach($item->allergens->take(4) as $a)
                                            <span class="pm-allergen" title="{{ $a->localizedName() }}">
                                                <span class="pm-allergen__icon">{{ $a->icon }}</span>
                                                <span class="pm-allergen__name">{{ $a->localizedName() }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="pm-item__foot">
                                    <span class="pm-price">
                                        <strong>{{ rtrim(rtrim(number_format((float) $item->price, 2), '0'), '.') }}</strong>
                                        <small>{{ $currency }}</small>
                                    </span>
                                    <button type="button" class="pm-add"
                                            @click="addItem({{ $item->id }})"
                                            :disabled="busyItem === {{ $item->id }}"
                                            title="{{ __('portal.order.add_to_cart') }}">
                                        <i class="bi" :class="busyItem === {{ $item->id }} ? 'bi-check-lg' : 'bi-plus-lg'"></i>
                                    </button>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif

    {{-- Floating cart bar ─────────────────────────────── --}}
    <div class="pm-cart-bar" x-show="cart.count > 0" x-transition.opacity>
        <details class="pm-cart-details">
            <summary class="pm-cart-summary">
                <div class="pm-cart-summary__left">
                    <span class="pm-cart-icon">
                        <i class="bi bi-bag-fill"></i>
                        <span class="pm-cart-icon__count" x-text="cart.count"></span>
                    </span>
                    <div>
                        <div class="pm-cart-summary__title">{{ __('portal.order.cart_title') }}</div>
                        <div class="pm-cart-summary__sub">
                            <span x-text="cart.count"></span> {{ __('portal.order.items_label') }} ·
                            <strong><span x-text="formatPrice(cart.total)"></span> {{ $currency }}</strong>
                        </div>
                    </div>
                </div>
                <i class="bi bi-chevron-up pm-cart-summary__caret"></i>
            </summary>
            <div class="pm-cart-items">
                <template x-for="row in cart.rows" :key="row.id">
                    <div class="pm-cart-row">
                        <span class="pm-cart-qty">×<span x-text="row.quantity"></span></span>
                        <div class="pm-cart-name">
                            <span x-text="row.name"></span>
                            <template x-if="row.notes">
                                <small><i class="bi bi-chat-left-quote"></i> <span x-text="row.notes"></span></small>
                            </template>
                        </div>
                        <span class="pm-cart-sub" x-text="formatPrice(row.subtotal)"></span>
                        <button type="button" class="pm-cart-del" @click="removeItem(row.id)" title="{{ __('portal.order.delete') }}">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </template>
            </div>
        </details>
        <a :href="checkoutUrl" class="pm-checkout">
            <span class="pm-checkout__main">
                <i class="bi bi-arrow-left"></i>
                {{ __('portal.order.continue_to_checkout') }}
            </span>
            <span class="pm-checkout__price">
                <span x-text="formatPrice(cart.total)"></span> {{ $currency }}
            </span>
        </a>
    </div>

</div>

@push('styles')
<style>
    /* Prevent ANY rogue child from making the document horizontally
       scrollable. The category quick-nav is a horizontal scroller with
       its own overflow-x; without this guard the negative-margin chips
       inside it leak out and shove the page sideways — which in turn
       makes our position:fixed cart bar appear at the wrong x. */
    html, body { overflow-x: hidden; }

    .pm-page {
        max-width: 1100px;
        margin: 0 auto;
        padding: .5rem .8rem 8.5rem;
        overflow-x: hidden;
    }

    /* ── Hero ──────────────────────────────────────── */
    .pm-hero {
        position: relative;
        background: linear-gradient(135deg, var(--green-primary, #0f4731) 0%, #1c5e44 100%);
        color: #fff;
        border-radius: 18px;
        padding: 1.4rem 1.5rem 1.2rem;
        margin-bottom: 1.2rem;
        overflow: hidden;
    }
    .pm-hero::before {
        content: '';
        position: absolute;
        inset-inline-end: -30px; top: -30px;
        width: 180px; height: 180px;
        background: rgba(255,255,255,.08);
        border-radius: 50%;
        pointer-events: none;
    }
    .pm-hero__top { display: flex; align-items: center; gap: .85rem; margin-bottom: .65rem; position: relative; z-index: 1; }
    .pm-hero__back {
        width: 38px; height: 38px; border-radius: 50%;
        background: rgba(255,255,255,.2); backdrop-filter: blur(6px);
        color: #fff; display: inline-flex; align-items: center; justify-content: center;
        text-decoration: none; transition: all .15s; flex-shrink: 0;
    }
    .pm-hero__back:hover { background: rgba(255,255,255,.3); color: #fff; transform: translateX(2px); }
    .pm-hero__brand { flex-grow: 1; min-width: 0; }
    .pm-hero__brand > i {
        width: 44px; height: 44px;
        background: rgba(255,255,255,.18);
        border-radius: 12px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1.3rem; margin-bottom: .35rem;
    }
    .pm-hero__eyebrow { display: block; font-size: .75rem; font-weight: 600; opacity: .8; margin-bottom: .15rem; }
    .pm-hero__title { font-size: 1.5rem; font-weight: 800; margin: 0; line-height: 1.2; }
    .pm-hero__meta {
        display: flex; flex-wrap: wrap; gap: .35rem .55rem;
        font-size: .82rem; opacity: .9;
        position: relative; z-index: 1;
    }
    .pm-hero__meta i { margin-inline-end: 3px; }
    .pm-dot { width: 4px; height: 4px; background: rgba(255,255,255,.4); border-radius: 50%; align-self: center; }

    /* ── Category jump nav ───────────────────────────── */
    .pm-jump {
        display: flex; gap: .4rem;
        overflow-x: auto;
        margin-bottom: 1rem;
        padding-bottom: .5rem;
        -webkit-overflow-scrolling: touch;
        /* min-width: 0 lets the flex-track shrink; without it the chip
           track stretches to its content width and pushes the page out. */
        min-width: 0;
        max-width: 100%;
    }
    .pm-jump::-webkit-scrollbar { height: 4px; }
    .pm-jump::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 99px; }
    .pm-jump__link {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .5rem .9rem;
        background: #fff; border: 1px solid #e5e7eb; border-radius: 99px;
        text-decoration: none; color: #374151;
        font-size: .85rem; font-weight: 700;
        white-space: nowrap; flex-shrink: 0;
        transition: all .15s; cursor: pointer;
    }
    .pm-jump__link:hover {
        background: var(--green-primary, #0f4731);
        color: #fff; border-color: var(--green-primary, #0f4731);
        transform: translateY(-1px);
    }
    .pm-jump__link i { color: var(--green-primary, #0f4731); transition: color .15s; }
    .pm-jump__link:hover i { color: #fff; }
    .pm-jump__link small { background: rgba(15,71,49,.1); padding: 1px 7px; border-radius: 99px; font-size: .68rem; font-weight: 700; }
    .pm-jump__link:hover small { background: rgba(255,255,255,.25); color: #fff; }

    /* ── Category sections ────────────────────────── */
    .pm-cat { margin-bottom: 1.5rem; scroll-margin-top: 1rem; }
    .pm-cat__head {
        display: flex; align-items: center; justify-content: space-between;
        gap: .65rem; margin-bottom: .9rem;
        padding: .55rem .25rem;
        border-bottom: 2px dashed rgba(15,71,49,.12);
        cursor: pointer;
        user-select: none;
        transition: background .15s;
    }
    .pm-cat__head:hover { background: rgba(15,71,49,.025); border-bottom-style: solid; }
    .pm-cat.is-collapsed .pm-cat__head { margin-bottom: 0; border-bottom-color: rgba(15,71,49,.05); }
    .pm-cat__head h2 {
        font-size: 1.1rem; font-weight: 800;
        color: var(--ink, #1f2937);
        margin: 0;
        display: inline-flex; align-items: center; gap: .4rem;
    }
    .pm-cat__head h2 i {
        color: var(--green-primary, #0f4731);
        background: rgba(15,71,49,.08);
        width: 32px; height: 32px;
        border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1rem;
    }
    .pm-cat__head-right {
        display: inline-flex; align-items: center; gap: .5rem;
    }
    .pm-cat__count {
        font-size: .72rem; font-weight: 700;
        background: rgba(15,71,49,.08);
        color: var(--green-primary, #0f4731);
        padding: 3px 10px; border-radius: 99px;
    }
    .pm-cat__toggle {
        width: 34px; height: 34px;
        border: 1px solid rgba(15,71,49,.12);
        background: #fff;
        color: var(--green-primary, #0f4731);
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all .15s;
        font-size: .95rem;
    }
    .pm-cat__toggle:hover {
        background: var(--green-primary, #0f4731);
        color: #fff;
    }

    /* ── Item card grid ─────────────────────────── */
    .pm-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
        gap: .85rem;
    }
    .pm-item {
        position: relative;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        overflow: hidden;
        transition: all .18s ease;
        display: flex; flex-direction: column;
    }
    .pm-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px -6px rgba(15,71,49,.12);
        border-color: rgba(15,71,49,.25);
    }
    .pm-item.is-busy { animation: pm-pulse 1s ease; }
    @keyframes pm-pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(15,71,49,.4); }
        50%      { box-shadow: 0 0 0 8px rgba(15,71,49,0); }
    }
    .pm-item__media { position: relative; height: 160px; background: #f3f4f6; overflow: hidden; }
    .pm-item__media img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s ease; }
    .pm-item:hover .pm-item__media img { transform: scale(1.04); }
    .pm-item__media--ph {
        width: 100%; height: 100%;
        display: flex; align-items: center; justify-content: center;
        color: #cbd5e1; font-size: 3rem;
        background: linear-gradient(135deg, #f9fafb, #f3f4f6);
    }

    .pm-badge {
        position: absolute;
        display: inline-flex; align-items: center; gap: .25rem;
        font-size: .7rem; font-weight: 700;
        padding: 3px 9px; border-radius: 99px;
        backdrop-filter: blur(6px);
    }
    .pm-badge--featured { top: .55rem; inset-inline-start: .55rem; background: rgba(184, 135, 42, .9); color: #fff; }
    .pm-badge--time { bottom: .55rem; inset-inline-end: .55rem; background: rgba(255, 255, 255, .92); color: #1f2937; }
    .pm-badge--time i { color: var(--green-primary, #0f4731); }

    .pm-item__body { padding: .85rem 1rem 1rem; display: flex; flex-direction: column; flex-grow: 1; }
    .pm-item__name { font-size: 1rem; font-weight: 800; color: var(--ink, #1f2937); margin: 0 0 .35rem; line-height: 1.3; }
    .pm-item__desc {
        font-size: .82rem; color: #6b7280;
        margin: 0 0 .65rem; line-height: 1.5;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .pm-allergens {
        display: flex; flex-wrap: wrap; align-items: center; gap: .35rem;
        margin-bottom: .65rem;
        padding: .4rem .55rem;
        background: rgba(245, 158, 11, .07);
        border: 1px solid rgba(245, 158, 11, .25);
        border-radius: 8px;
    }
    .pm-allergens__label {
        display: inline-flex; align-items: center; gap: .3rem;
        width: 100%;
        font-size: .72rem; font-weight: 700; color: #92400e;
        margin-bottom: .15rem;
    }
    .pm-allergens__label i { font-size: .8rem; color: #d97706; }
    .pm-allergen {
        display: inline-flex; align-items: center; gap: .25rem;
        background: #fff;
        border: 1px solid rgba(245, 158, 11, .35);
        padding: .15rem .5rem;
        border-radius: 99px;
        font-size: .72rem; font-weight: 600; color: #92400e;
        line-height: 1.2;
    }
    .pm-allergen__icon { font-size: .85rem; }
    .pm-allergen__name { font-size: .7rem; }

    .pm-item__foot {
        margin-top: auto;
        display: flex; align-items: center; justify-content: space-between; gap: .5rem;
        padding-top: .55rem;
        border-top: 1px dashed #f3f4f6;
    }
    .pm-price { display: inline-flex; align-items: baseline; gap: .25rem; }
    .pm-price strong {
        font-size: 1.1rem; font-weight: 800;
        color: var(--green-primary, #0f4731);
        font-variant-numeric: tabular-nums;
    }
    .pm-price small { font-size: .72rem; color: #6b7280; font-weight: 600; }

    .pm-add {
        width: 40px; height: 40px;
        border: 0; border-radius: 50%;
        background: var(--green-primary, #0f4731);
        color: #fff; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1.05rem;
        transition: all .15s ease;
        box-shadow: 0 4px 10px -2px rgba(15,71,49,.35);
    }
    .pm-add:hover:not(:disabled) { transform: scale(1.08) rotate(90deg); background: #1c5e44; }
    .pm-add:disabled { background: #16a34a; cursor: wait; transform: scale(1.08); }

    /* ── Floating cart bar ─────────────────────────── */
    .pm-cart-bar {
        position: fixed;
        bottom: 1rem;
        left: 50%;
        transform: translateX(-50%);
        width: min(600px, calc(100% - 1.5rem));
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        box-shadow: 0 12px 40px -6px rgba(15, 23, 42, .25);
        z-index: 100;
        overflow: hidden;
    }
    @media (min-width: 768px) {
        .pm-cart-bar {
            left: auto;
            right: 1.5rem;
            transform: none;
            width: 380px;
        }
    }
    .pm-cart-details summary { list-style: none; cursor: pointer; }
    .pm-cart-details summary::-webkit-details-marker { display: none; }
    .pm-cart-summary { display: flex; align-items: center; justify-content: space-between; padding: .75rem .9rem; }
    .pm-cart-summary__left { display: flex; align-items: center; gap: .65rem; min-width: 0; flex-grow: 1; }
    .pm-cart-icon {
        position: relative;
        width: 38px; height: 38px;
        background: var(--green-primary, #0f4731); color: #fff;
        border-radius: 11px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .pm-cart-icon__count {
        position: absolute;
        top: -4px; inset-inline-start: -4px;
        background: #b8872a; color: #fff;
        font-size: .68rem; font-weight: 800;
        min-width: 18px; height: 18px;
        border-radius: 99px; padding: 0 5px;
        display: inline-flex; align-items: center; justify-content: center;
        border: 2px solid #fff;
    }
    .pm-cart-summary__title { font-weight: 800; font-size: .9rem; color: var(--ink, #1f2937); }
    .pm-cart-summary__sub { font-size: .76rem; color: #6b7280; }
    .pm-cart-summary__sub strong { color: var(--green-primary, #0f4731); font-variant-numeric: tabular-nums; }
    .pm-cart-summary__caret { color: #9ca3af; transition: transform .2s; flex-shrink: 0; }
    .pm-cart-details[open] .pm-cart-summary__caret { transform: rotate(180deg); }

    .pm-cart-items { max-height: 240px; overflow-y: auto; padding: 0 .9rem .35rem; border-top: 1px dashed #f3f4f6; }
    .pm-cart-items::-webkit-scrollbar { width: 4px; }
    .pm-cart-items::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 99px; }
    .pm-cart-row {
        display: flex; align-items: center; gap: .55rem;
        padding: .55rem 0;
        border-bottom: 1px dashed #f3f4f6;
        font-size: .85rem;
    }
    .pm-cart-row:last-child { border-bottom: 0; }
    .pm-cart-qty { font-weight: 800; color: var(--green-primary, #0f4731); min-width: 28px; }
    .pm-cart-name { flex-grow: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    .pm-cart-name small { display: block; font-size: .7rem; color: #6b7280; margin-top: 2px; }
    .pm-cart-sub { font-weight: 700; color: var(--ink, #1f2937); font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pm-cart-del { background: transparent; border: 0; color: #dc2626; cursor: pointer; padding: 4px 8px; border-radius: 6px; transition: background .15s; }
    .pm-cart-del:hover { background: rgba(220, 38, 38, .08); }

    .pm-checkout {
        display: flex; align-items: center; justify-content: space-between;
        padding: .95rem 1rem;
        background: linear-gradient(135deg, var(--green-primary, #0f4731), #1c5e44);
        color: #fff; text-decoration: none;
        font-weight: 800; font-size: .95rem;
        margin: .35rem .5rem .5rem;
        border-radius: 12px;
        transition: all .15s;
        min-height: 50px;
    }
    .pm-checkout:hover { color: #fff; transform: translateY(-1px); box-shadow: 0 6px 16px -3px rgba(15,71,49,.4); }
    .pm-checkout__main { display: inline-flex; align-items: center; gap: .45rem; }
    .pm-checkout__price {
        background: rgba(255,255,255,.2);
        padding: .35rem .8rem;
        border-radius: 99px;
        font-size: .88rem;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    /* Respect iOS safe-area so the floating bar isn't tucked behind the
       home indicator. Falls back to 0 on browsers without env() support. */
    .pm-cart-bar { padding-bottom: env(safe-area-inset-bottom, 0); }

    /* ── Mobile-specific tweaks (≤640px) ─────────── */
    @media (max-width: 640px) {
        .pm-page { padding: .4rem .55rem calc(11rem + env(safe-area-inset-bottom, 0)); }
        .pm-hero { padding: 1rem 1.1rem; border-radius: 14px; margin-bottom: .9rem; }
        .pm-hero__title { font-size: 1.15rem; }
        .pm-hero__brand > i { width: 38px; height: 38px; font-size: 1.1rem; margin-bottom: .25rem; }
        .pm-grid { grid-template-columns: 1fr; gap: .6rem; }
        .pm-item__media { height: 130px; }
        .pm-cat__head h2 { font-size: 1rem; }
        .pm-cat__head h2 i { width: 28px; height: 28px; font-size: .9rem; }
        .pm-cat__count { font-size: .68rem; padding: 2px 8px; }
        .pm-cat__toggle { width: 32px; height: 32px; }
        .pm-jump { gap: .3rem; margin-bottom: .8rem; }
        .pm-jump__link { padding: .4rem .7rem; font-size: .78rem; }

        /* Cart bar — full-width sticky at bottom on mobile. Base rule
           centres via `left: 50%; transform: translateX(-50%)`; we just
           expand width on small viewports. */
        .pm-cart-bar {
            bottom: .5rem;
            width: calc(100% - 1rem);
            border-radius: 14px;
            box-shadow: 0 -4px 20px rgba(15, 23, 42, .15), 0 12px 40px -6px rgba(15, 23, 42, .25);
        }
        .pm-cart-summary { padding: .7rem .85rem; }
        .pm-cart-summary__title { font-size: .85rem; }
        .pm-cart-summary__sub { font-size: .72rem; }
        .pm-cart-icon { width: 36px; height: 36px; }

        /* Checkout button — big primary CTA, never cropped */
        .pm-checkout {
            padding: 1rem .9rem;
            font-size: 1rem;
            margin: 0 .4rem .5rem;
            min-height: 56px;
            font-weight: 800;
        }
        .pm-checkout__main { gap: .55rem; }
        .pm-checkout__main i { font-size: 1.15rem; }
        .pm-checkout__price {
            padding: .4rem .85rem;
            font-size: .92rem;
            font-weight: 800;
        }

        /* Cart items list shorter on mobile so screen isn't fully covered when expanded */
        .pm-cart-items { max-height: 180px; }
    }

    @media (max-width: 380px) {
        .pm-checkout { font-size: .92rem; padding: .9rem .7rem; }
        .pm-checkout__main i { font-size: 1rem; }
        .pm-checkout__price { padding: .35rem .7rem; font-size: .85rem; }
        .pm-cart-summary__title { font-size: .82rem; }
    }

    /* Empty state */
    .pm-empty { background: #fff; border: 1px dashed #e5e7eb; border-radius: 16px; padding: 2.5rem 1.5rem; text-align: center; }
    .pm-empty__shape {
        width: 80px; height: 80px;
        border-radius: 50%;
        background: rgba(15, 71, 49, .06);
        color: var(--green-primary, #0f4731);
        display: flex; align-items: center; justify-content: center;
        font-size: 2.2rem; margin: 0 auto 1rem;
    }
    .pm-empty h2 { margin: 0 0 .35rem; font-size: 1.2rem; font-weight: 800; }
    .pm-empty p { color: #6b7280; max-width: 360px; margin: 0 auto 1rem; line-height: 1.55; }
    .pm-cta {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .65rem 1.2rem;
        background: var(--green-primary, #0f4731); color: #fff;
        border-radius: 99px; font-weight: 700; text-decoration: none;
    }
</style>
@endpush

@push('scripts')
@livewireScripts
<script>
window.menuPage = function (config) {
    return {
        addUrl:      config.addUrl,
        removeUrl:   config.removeUrl,
        checkoutUrl: config.checkoutUrl,
        currency:    config.currency,
        csrf:        config.csrf,

        cart: {
            rows:  config.initialCart || [],
            count: Number(config.initialCount) || 0,
            total: Number(config.initialTotal) || 0,
        },

        // Persisted between renders — categories are open by default,
        // toggling stores in localStorage so the user's preference sticks.
        openCats: new Set(),
        busyItem: 0,

        init() {
            // Read saved collapsed cats (if any). Default = all open.
            try {
                const raw = localStorage.getItem('pm_collapsed_cats');
                const collapsed = raw ? JSON.parse(raw) : [];
                // Mark all currently visible category sections as open by
                // default; collapsed ones come from storage.
                document.querySelectorAll('.pm-cat[id^="cat-"]').forEach(s => {
                    const id = parseInt(s.id.replace('cat-', ''), 10);
                    if (! collapsed.includes(id)) this.openCats.add(id);
                });
            } catch (e) {
                document.querySelectorAll('.pm-cat[id^="cat-"]').forEach(s => {
                    const id = parseInt(s.id.replace('cat-', ''), 10);
                    this.openCats.add(id);
                });
            }
        },

        isOpen(id) { return this.openCats.has(id); },
        toggleCat(id) {
            if (this.openCats.has(id)) this.openCats.delete(id);
            else                       this.openCats.add(id);
            this.persistCats();
        },
        persistCats() {
            const all = [...document.querySelectorAll('.pm-cat[id^="cat-"]')]
                .map(s => parseInt(s.id.replace('cat-', ''), 10));
            const collapsed = all.filter(id => ! this.openCats.has(id));
            try { localStorage.setItem('pm_collapsed_cats', JSON.stringify(collapsed)); } catch (e) {}
        },

        jumpTo(id) {
            // Force the section open before jumping so the jump-anchor is visible.
            this.openCats.add(id);
            this.persistCats();
            this.$nextTick(() => {
                const el = document.getElementById('cat-' + id);
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        },

        async addItem(menuItemId) {
            if (this.busyItem) return;
            this.busyItem = menuItemId;
            try {
                const fd = new FormData();
                fd.append('_token', this.csrf);
                fd.append('menu_item_id', menuItemId);
                fd.append('quantity', 1);
                const r = await fetch(this.addUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'include',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await r.json();
                if (! r.ok) throw new Error(data.message || @js(__('portal.order.add_failed')));
                this.cart = data.cart;
                if (window.showToast) window.showToast(data.message || @js(__('portal.order.added_to_cart')), 'success', 1500);
            } catch (e) {
                if (window.showToast) window.showToast(e.message || @js(__('portal.order.generic_error')), 'error');
            } finally {
                // Brief check-mark feedback then reset.
                setTimeout(() => { this.busyItem = 0; }, 700);
            }
        },

        async removeItem(rowId) {
            try {
                const fd = new FormData();
                fd.append('_token', this.csrf);
                fd.append('row_id', rowId);
                const r = await fetch(this.removeUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'include',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await r.json();
                this.cart = data.cart;
            } catch (e) {
                if (window.showToast) window.showToast(@js(__('portal.order.delete_failed')), 'error');
            }
        },

        formatPrice(n) {
            const v = Number(n) || 0;
            return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
};
</script>
@endpush
@endsection
