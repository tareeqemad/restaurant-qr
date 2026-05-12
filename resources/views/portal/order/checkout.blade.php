@extends('portal.layout')
@section('title', 'إتمام الطلب')

@php
    $currency = config('restaurant.currency_symbol', '₪');
    $deliveryFee = $branch->deliveryFee();
    $isDelivery  = old('order_type') === 'delivery';
    $totalShown  = $cartTotal + ($isDelivery ? $deliveryFee : 0);
    $itemCount = collect($cart)->sum('quantity');
@endphp

@section('content')
<div class="co-page">

    {{-- Top: back link + breadcrumb steps ──────────────────── --}}
    <div class="co-top">
        <a href="{{ route('portal.order.menu', $branch) }}" class="co-back">
            <i class="bi bi-arrow-right"></i> عودة للقائمة
        </a>
        <div class="co-steps" aria-label="مراحل الطلب">
            <div class="co-step is-done">
                <span class="co-step-num"><i class="bi bi-check-lg"></i></span>
                <span class="co-step-label">السلة</span>
            </div>
            <div class="co-step-line is-done"></div>
            <div class="co-step is-active">
                <span class="co-step-num">2</span>
                <span class="co-step-label">إتمام الطلب</span>
            </div>
            <div class="co-step-line"></div>
            <div class="co-step">
                <span class="co-step-num">3</span>
                <span class="co-step-label">التأكيد</span>
            </div>
        </div>
    </div>

    {{-- Hero ─────────────────────────────────────────────── --}}
    <div class="co-hero">
        <div class="co-hero__left">
            <span class="co-hero__eyebrow">إتمام الطلب من</span>
            <h1 class="co-hero__title">{{ $branch->name }}</h1>
            <div class="co-hero__meta">
                <span><i class="bi bi-bag-check-fill"></i> {{ $itemCount }} صنف</span>
                <span class="co-dot"></span>
                <span><i class="bi bi-currency-exchange"></i> {{ number_format($cartTotal, 2) }} {{ $currency }}</span>
            </div>
        </div>
        <div class="co-hero__shape" aria-hidden="true">
            <i class="bi bi-bag-check-fill"></i>
        </div>
    </div>

    <form action="{{ route('portal.order.place', $branch) }}" method="POST" class="co-grid">
        @csrf

        <div class="co-form">

            {{-- Order type ─────────────────────────────────── --}}
            <section class="co-card">
                <div class="co-card__head">
                    <i class="bi bi-truck"></i>
                    <h2>كيف تريد استلام الطلب؟</h2>
                </div>
                <div class="co-types" id="poc-types">
                    <label class="co-type {{ old('order_type', 'takeaway') === 'takeaway' ? 'is-active' : '' }}">
                        <input type="radio" name="order_type" value="takeaway"
                               {{ old('order_type', 'takeaway') === 'takeaway' ? 'checked' : '' }} hidden>
                        <div class="co-type__icon">
                            <i class="bi bi-bag-fill"></i>
                        </div>
                        <div class="co-type__body">
                            <strong>أستلم بنفسي</strong>
                            <span>تأتي للفرع والطلب جاهز بالداخل</span>
                        </div>
                        <i class="bi bi-check-circle-fill co-type__check"></i>
                    </label>
                    <label class="co-type {{ old('order_type') === 'delivery' ? 'is-active' : '' }}">
                        <input type="radio" name="order_type" value="delivery"
                               {{ old('order_type') === 'delivery' ? 'checked' : '' }} hidden>
                        <div class="co-type__icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="co-type__body">
                            <strong>توصيل لعنواني</strong>
                            <span>المطعم يوصّل + رسوم {{ number_format($deliveryFee, 2) }} {{ $currency }}</span>
                        </div>
                        <i class="bi bi-check-circle-fill co-type__check"></i>
                    </label>
                </div>
                @error('order_type')<div class="co-error"><i class="bi bi-exclamation-circle"></i> {{ $message }}</div>@enderror
            </section>

            {{-- Delivery address ─────────────────────────── --}}
            <section class="co-card" id="poc-address" style="{{ $isDelivery ? '' : 'display:none;' }}">
                <div class="co-card__head">
                    <i class="bi bi-geo-alt-fill"></i>
                    <h2>عنوان التوصيل</h2>
                </div>
                <div class="co-field">
                    <textarea name="delivery_address" rows="3" class="co-input"
                              placeholder="الحي، الشارع، رقم البناية، أي علامة مميزة...">{{ old('delivery_address') }}</textarea>
                    <small class="co-hint"><i class="bi bi-info-circle"></i> كلما كان العنوان أوضح، كان التوصيل أسرع.</small>
                </div>
                @error('delivery_address')<div class="co-error"><i class="bi bi-exclamation-circle"></i> {{ $message }}</div>@enderror
            </section>

            {{-- Schedule ─────────────────────────────────── --}}
            <section class="co-card">
                <div class="co-card__head">
                    <i class="bi bi-clock-fill"></i>
                    <h2>وقت التحضير</h2>
                    <span class="co-card__hint">اختياري</span>
                </div>
                <div class="co-field">
                    <input type="datetime-local" name="scheduled_for" class="co-input"
                           value="{{ old('scheduled_for') }}"
                           min="{{ now()->addMinutes(15)->format('Y-m-d\TH:i') }}">
                    <small class="co-hint"><i class="bi bi-info-circle"></i> اتركه فارغاً ليبدأ التحضير فوراً.</small>
                </div>
                @error('scheduled_for')<div class="co-error"><i class="bi bi-exclamation-circle"></i> {{ $message }}</div>@enderror
            </section>

            {{-- Notes ────────────────────────────────────── --}}
            <section class="co-card">
                <div class="co-card__head">
                    <i class="bi bi-chat-square-text-fill"></i>
                    <h2>ملاحظات للمطعم</h2>
                    <span class="co-card__hint">اختياري</span>
                </div>
                <div class="co-field">
                    <textarea name="customer_notes" rows="2" class="co-input"
                              placeholder="بدون بصل، حار قليل، تحضير سريع، ...">{{ old('customer_notes') }}</textarea>
                </div>
                @error('customer_notes')<div class="co-error"><i class="bi bi-exclamation-circle"></i> {{ $message }}</div>@enderror
            </section>

            {{-- Customer card ────────────────────────────── --}}
            <section class="co-card co-customer">
                <div class="co-customer__avatar">
                    {{ mb_substr($customer->name, 0, 1) }}
                </div>
                <div class="co-customer__info">
                    <span class="co-customer__label"><i class="bi bi-person-check-fill"></i> الطلب باسم</span>
                    <strong>{{ $customer->name }}</strong>
                    <span class="co-customer__phone" dir="ltr">{{ $customer->phone }}</span>
                </div>
            </section>

        </div>

        {{-- Sticky summary ─────────────────────────────── --}}
        <aside class="co-summary">
            <div class="co-summary__head">
                <h3><i class="bi bi-receipt"></i> ملخص الطلب</h3>
                <span class="co-summary__count">{{ $itemCount }} صنف</span>
            </div>

            <div class="co-summary__items">
                @foreach($cart as $row)
                    <div class="co-item">
                        @if(!empty($row['image']))
                            <div class="co-item__img" style="background-image:url('{{ $row['image'] }}');"></div>
                        @else
                            <div class="co-item__img co-item__img--ph"><i class="bi bi-egg-fried"></i></div>
                        @endif
                        <div class="co-item__body">
                            <div class="co-item__head">
                                <span class="co-item__qty">×{{ $row['quantity'] }}</span>
                                <span class="co-item__name">{{ $row['name'] }}</span>
                            </div>
                            @if(!empty($row['modifiers']))
                                <div class="co-item__mods">
                                    @foreach($row['modifiers'] as $m)
                                        <span>{{ $m['name'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                            @if(!empty($row['notes']))
                                <div class="co-item__note">
                                    <i class="bi bi-chat-left-quote"></i> {{ $row['notes'] }}
                                </div>
                            @endif
                        </div>
                        <div class="co-item__price">
                            {{ number_format($row['subtotal'], 2) }}
                            <small>{{ $currency }}</small>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="co-summary__totals">
                <div class="co-line">
                    <span>الفرعي</span>
                    <span>{{ number_format($cartTotal, 2) }} {{ $currency }}</span>
                </div>
                <div class="co-line" id="poc-fee-row" style="{{ $isDelivery ? '' : 'display:none;' }}">
                    <span><i class="bi bi-truck"></i> رسوم التوصيل</span>
                    <span>{{ number_format($deliveryFee, 2) }} {{ $currency }}</span>
                </div>
                <div class="co-line co-line--grand">
                    <span>الإجمالي</span>
                    <strong>
                        <span id="poc-total-display">{{ number_format($totalShown, 2) }}</span>
                        {{ $currency }}
                    </strong>
                </div>
            </div>

            <button type="submit" class="co-submit">
                <span class="co-submit__main">
                    <i class="bi bi-check-circle-fill"></i>
                    تأكيد الطلب
                </span>
                <span class="co-submit__price">
                    <span id="poc-submit-price">{{ number_format($totalShown, 2) }}</span> {{ $currency }}
                </span>
            </button>

            <div class="co-trust">
                <span><i class="bi bi-cash-coin"></i> الدفع نقداً عند الاستلام</span>
                <span><i class="bi bi-shield-check"></i> طلبك محفوظ ومتتبَّع</span>
            </div>

            <span id="poc-subtotal" data-value="{{ $cartTotal }}" hidden></span>
            <span id="poc-delivery" data-value="{{ $deliveryFee }}" hidden></span>
        </aside>
    </form>

</div>

@push('styles')
<style>
    /* ── Reset/Base ──────────────────────────────────── */
    .co-page {
        max-width: 1180px;
        margin: 0 auto;
        padding: 1rem .8rem 4rem;
    }
    .co-page * { box-sizing: border-box; }

    /* ── Top bar (back + steps) ──────────────────────── */
    .co-top {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .co-back {
        display: inline-flex; align-items: center; gap: .35rem;
        color: #6b7280; font-size: .9rem; font-weight: 600;
        text-decoration: none; transition: color .15s;
    }
    .co-back:hover { color: var(--green-primary, #0f4731); }
    .co-steps {
        display: flex; align-items: center; gap: .35rem;
    }
    .co-step {
        display: flex; align-items: center; gap: .4rem;
        color: #9ca3af;
    }
    .co-step-num {
        width: 28px; height: 28px;
        display: flex; align-items: center; justify-content: center;
        background: #f3f4f6; color: #9ca3af;
        border-radius: 50%;
        font-weight: 800; font-size: .82rem;
        transition: all .2s;
    }
    .co-step-label { font-size: .8rem; font-weight: 700; }
    .co-step.is-active .co-step-num { background: var(--green-primary, #0f4731); color: #fff; box-shadow: 0 0 0 4px rgba(15,71,49,.12); }
    .co-step.is-active .co-step-label,
    .co-step.is-active { color: var(--green-primary, #0f4731); }
    .co-step.is-done .co-step-num { background: rgba(15,71,49,.15); color: var(--green-primary, #0f4731); }
    .co-step.is-done .co-step-label { color: #4b5563; }
    .co-step-line {
        width: 28px; height: 2px;
        background: #e5e7eb; border-radius: 99px;
    }
    .co-step-line.is-done { background: rgba(15,71,49,.45); }
    @media (max-width: 600px) {
        .co-step-label { display: none; }
        .co-step-line { width: 18px; }
    }

    /* ── Hero ────────────────────────────────────────── */
    .co-hero {
        display: flex; align-items: center; justify-content: space-between;
        background: linear-gradient(135deg, var(--green-primary, #0f4731) 0%, #1c5e44 100%);
        color: #fff;
        border-radius: 16px;
        padding: 1.4rem 1.5rem;
        margin-bottom: 1.4rem;
        position: relative;
        overflow: hidden;
    }
    .co-hero__eyebrow {
        font-size: .8rem; font-weight: 600; opacity: .85;
        display: block; margin-bottom: .15rem;
    }
    .co-hero__title {
        font-size: 1.5rem; font-weight: 800; margin: 0 0 .4rem;
        line-height: 1.2;
    }
    .co-hero__meta {
        display: flex; align-items: center; gap: .5rem;
        font-size: .85rem; opacity: .92; flex-wrap: wrap;
    }
    .co-hero__meta i { margin-inline-end: 3px; }
    .co-dot { width: 4px; height: 4px; background: rgba(255,255,255,.4); border-radius: 50%; }
    .co-hero__shape {
        width: 72px; height: 72px;
        border-radius: 50%;
        background: rgba(255,255,255,.12);
        display: flex; align-items: center; justify-content: center;
        font-size: 2rem; flex-shrink: 0;
    }

    /* ── Layout grid ────────────────────────────────── */
    .co-grid {
        display: grid;
        grid-template-columns: 1fr 360px;
        gap: 1.2rem;
        align-items: start;
    }
    @media (max-width: 980px) {
        .co-grid { grid-template-columns: 1fr; }
    }

    .co-form { display: flex; flex-direction: column; gap: 1rem; }

    /* ── Card ─────────────────────────────────────── */
    .co-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 1.1rem 1.25rem;
        transition: border-color .15s, box-shadow .15s;
    }
    .co-card:focus-within {
        border-color: rgba(15,71,49,.35);
        box-shadow: 0 0 0 4px rgba(15,71,49,.06);
    }
    .co-card__head {
        display: flex; align-items: center; gap: .5rem;
        margin-bottom: .85rem;
    }
    .co-card__head i {
        color: var(--green-primary, #0f4731);
        font-size: 1.1rem;
    }
    .co-card__head h2 {
        font-size: 1rem; font-weight: 800; margin: 0;
        color: var(--ink, #1f2937);
        flex-grow: 1;
    }
    .co-card__hint {
        font-size: .72rem; font-weight: 600;
        background: #f3f4f6; color: #6b7280;
        padding: 2px 8px; border-radius: 99px;
    }

    /* ── Type cards ─────────────────────────────── */
    .co-types {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: .7rem;
    }
    @media (max-width: 600px) {
        .co-types { grid-template-columns: 1fr; }
    }
    .co-type {
        position: relative;
        cursor: pointer;
        padding: 1rem .9rem;
        display: flex; align-items: center; gap: .75rem;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        transition: all .18s ease;
        background: #fff;
    }
    .co-type:hover {
        border-color: rgba(15,71,49,.3);
        background: #fafffa;
    }
    .co-type.is-active {
        border-color: var(--green-primary, #0f4731);
        background: #f0fdf4;
        box-shadow: 0 4px 14px -3px rgba(15,71,49,.18);
    }
    .co-type__icon {
        width: 44px; height: 44px;
        border-radius: 10px;
        background: #f3f4f6;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        transition: all .18s;
    }
    .co-type__icon i { font-size: 1.4rem; color: #6b7280; transition: color .18s; }
    .co-type.is-active .co-type__icon { background: var(--green-primary, #0f4731); }
    .co-type.is-active .co-type__icon i { color: #fff; }
    .co-type__body { flex-grow: 1; min-width: 0; }
    .co-type__body strong {
        display: block;
        font-size: .95rem; color: var(--ink, #1f2937);
        margin-bottom: 2px; font-weight: 800;
    }
    .co-type__body span {
        display: block;
        font-size: .76rem; color: #6b7280; line-height: 1.4;
    }
    .co-type__check {
        position: absolute;
        top: .55rem; inset-inline-end: .75rem;
        font-size: 1.15rem;
        color: var(--green-primary, #0f4731);
        opacity: 0;
        transition: opacity .18s, transform .18s;
        transform: scale(.5);
    }
    .co-type.is-active .co-type__check { opacity: 1; transform: scale(1); }

    /* ── Form fields ───────────────────────────── */
    .co-field { display: flex; flex-direction: column; gap: .35rem; }
    .co-input {
        width: 100%;
        padding: .65rem .85rem;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fff;
        font-size: .92rem; line-height: 1.5;
        color: var(--ink, #1f2937);
        transition: all .15s;
        font-family: inherit;
        resize: vertical;
    }
    .co-input:focus {
        outline: none;
        border-color: var(--green-primary, #0f4731);
        box-shadow: 0 0 0 3px rgba(15,71,49,.1);
    }
    .co-hint {
        display: inline-flex; align-items: center; gap: .25rem;
        font-size: .76rem; color: #6b7280;
    }
    .co-error {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .8rem; color: #b02a37;
        margin-top: .55rem;
        padding: .4rem .65rem;
        background: rgba(220, 53, 69, .08);
        border-radius: 8px;
    }

    /* ── Customer card ───────────────────────────── */
    .co-customer {
        display: flex; align-items: center; gap: 1rem;
        padding: 1rem 1.2rem;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        border-color: rgba(15,71,49,.18);
    }
    .co-customer__avatar {
        width: 50px; height: 50px;
        background: var(--green-primary, #0f4731);
        color: #fff;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem; font-weight: 800;
        flex-shrink: 0;
    }
    .co-customer__info { flex-grow: 1; min-width: 0; }
    .co-customer__label {
        display: inline-flex; align-items: center; gap: .25rem;
        font-size: .72rem; color: #6b7280; font-weight: 600;
    }
    .co-customer__info strong {
        display: block; font-size: 1rem; color: var(--ink, #1f2937);
        margin: 2px 0;
    }
    .co-customer__phone {
        display: block; font-size: .82rem; color: #6b7280;
        font-family: ui-monospace, SFMono-Regular, monospace;
    }

    /* ── Summary aside ──────────────────────────── */
    .co-summary {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 1.1rem 1.25rem;
        position: sticky;
        top: 80px;
    }
    .co-summary__head {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: .9rem;
        padding-bottom: .8rem;
        border-bottom: 1px solid #f3f4f6;
    }
    .co-summary__head h3 {
        font-size: 1rem; font-weight: 800; margin: 0;
        color: var(--ink, #1f2937);
        display: inline-flex; align-items: center; gap: .35rem;
    }
    .co-summary__head h3 i { color: var(--green-primary, #0f4731); }
    .co-summary__count {
        font-size: .72rem; font-weight: 700;
        background: rgba(15,71,49,.1); color: var(--green-primary, #0f4731);
        padding: 3px 9px; border-radius: 99px;
    }
    .co-summary__items {
        display: flex; flex-direction: column; gap: .65rem;
        max-height: 320px; overflow-y: auto;
        padding-inline-end: .25rem;
        margin-inline-end: -.25rem;
    }
    .co-summary__items::-webkit-scrollbar { width: 4px; }
    .co-summary__items::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

    .co-item {
        display: flex; gap: .7rem;
        padding-bottom: .65rem;
        border-bottom: 1px dashed #f3f4f6;
    }
    .co-item:last-child { border-bottom: 0; padding-bottom: 0; }
    .co-item__img {
        width: 44px; height: 44px;
        border-radius: 8px;
        background-size: cover; background-position: center;
        background-color: #f3f4f6;
        flex-shrink: 0;
        border: 1px solid #f3f4f6;
    }
    .co-item__img--ph {
        display: flex; align-items: center; justify-content: center;
        color: #9ca3af; font-size: 1.1rem;
    }
    .co-item__body { flex-grow: 1; min-width: 0; }
    .co-item__head { display: flex; align-items: baseline; gap: .35rem; }
    .co-item__qty {
        font-weight: 800; font-size: .8rem;
        color: var(--green-primary, #0f4731);
        flex-shrink: 0;
    }
    .co-item__name {
        font-weight: 700; font-size: .87rem; color: var(--ink, #1f2937);
        line-height: 1.3;
    }
    .co-item__mods {
        display: flex; flex-wrap: wrap; gap: 4px;
        margin-top: 3px;
    }
    .co-item__mods span {
        font-size: .68rem; color: #6b7280;
        background: #f3f4f6;
        padding: 1px 6px;
        border-radius: 4px;
    }
    .co-item__note {
        font-size: .72rem; color: #6b7280;
        margin-top: 3px;
        font-style: italic;
    }
    .co-item__note i { font-size: .65rem; opacity: .7; }
    .co-item__price {
        font-weight: 700;
        color: var(--ink, #1f2937);
        font-variant-numeric: tabular-nums;
        font-size: .88rem;
        flex-shrink: 0;
        white-space: nowrap;
    }
    .co-item__price small {
        font-size: .68rem; color: #6b7280; font-weight: 500; margin-inline-start: 2px;
    }

    .co-summary__totals {
        display: flex; flex-direction: column; gap: .35rem;
        margin: 1rem 0;
        padding: .85rem 0;
        border-top: 1px solid #f3f4f6;
        border-bottom: 1px solid #f3f4f6;
    }
    .co-line {
        display: flex; justify-content: space-between; align-items: baseline;
        font-size: .85rem; color: #6b7280;
    }
    .co-line--grand {
        font-size: 1.05rem;
        color: var(--ink, #1f2937);
        margin-top: .35rem;
        padding-top: .65rem;
        border-top: 1px dashed #e5e7eb;
    }
    .co-line--grand strong {
        font-size: 1.4rem;
        font-weight: 800;
        color: var(--green-primary, #0f4731);
        font-variant-numeric: tabular-nums;
    }

    .co-submit {
        width: 100%;
        display: flex; align-items: center; justify-content: space-between;
        gap: .75rem;
        padding: 1rem 1.2rem;
        border: 0;
        border-radius: 12px;
        cursor: pointer;
        background: linear-gradient(135deg, var(--green-primary, #0f4731) 0%, #1c5e44 100%);
        color: #fff;
        font-weight: 800;
        font-size: 1rem;
        box-shadow: 0 6px 18px -4px rgba(15,71,49,.4);
        transition: all .15s ease;
    }
    .co-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 24px -4px rgba(15,71,49,.5);
    }
    .co-submit:active { transform: translateY(0); }
    .co-submit__main {
        display: inline-flex; align-items: center; gap: .5rem;
    }
    .co-submit__main i { font-size: 1.15rem; }
    .co-submit__price {
        background: rgba(255,255,255,.2);
        padding: .35rem .8rem;
        border-radius: 99px;
        font-size: .9rem;
        font-variant-numeric: tabular-nums;
    }

    .co-trust {
        display: flex; flex-direction: column; gap: .35rem;
        margin-top: .85rem;
        padding-top: .85rem;
        border-top: 1px solid #f3f4f6;
    }
    .co-trust span {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .75rem; color: #6b7280;
    }
    .co-trust i { color: var(--green-primary, #0f4731); }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const types = document.getElementById('poc-types');
    const addr  = document.getElementById('poc-address');
    if (!types || !addr) return;

    const feeRow      = document.getElementById('poc-fee-row');
    const totalEl     = document.getElementById('poc-total-display');
    const submitEl    = document.getElementById('poc-submit-price');
    const subtotalNum = parseFloat(document.getElementById('poc-subtotal')?.dataset.value || '0');
    const deliveryNum = parseFloat(document.getElementById('poc-delivery')?.dataset.value || '0');

    const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const apply = (isDelivery) => {
        addr.style.display = isDelivery ? '' : 'none';
        if (feeRow) feeRow.style.display = isDelivery ? '' : 'none';
        const total = subtotalNum + (isDelivery ? deliveryNum : 0);
        if (totalEl)  totalEl.textContent  = fmt(total);
        if (submitEl) submitEl.textContent = fmt(total);
    };

    types.addEventListener('change', (e) => {
        if (e.target.name !== 'order_type') return;
        const isDelivery = e.target.value === 'delivery';
        apply(isDelivery);
        types.querySelectorAll('.co-type').forEach(el => el.classList.remove('is-active'));
        e.target.closest('.co-type')?.classList.add('is-active');
    });
})();
</script>
@endpush
@endsection
