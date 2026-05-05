@extends('customer.layout')
@section('title','تتبع الطلب')

@push('styles')
<style>
.track-wrap {
    max-width: 560px;
    margin: 1.5rem auto;
    padding: 0 1rem 6rem;
}

/* Hero card per order */
.track-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 12px 40px rgba(31,71,51,.12), 0 0 0 1px rgba(184,135,42,.08);
    padding: 2rem 1.25rem 1.25rem;
    margin-bottom: 1.25rem;
    position: relative;
    overflow: hidden;
}
.track-card::before {
    content: '';
    position: absolute;
    top: 0; right: 0; left: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--accent) 0%, var(--brand) 50%, var(--accent) 100%);
}

/* Header: title + number */
.track-head { text-align: center; margin-bottom: 1.25rem; }
.track-title { font-size: 1.35rem; font-weight: 900; color: var(--brand-dark); margin: 0 0 8px; letter-spacing: -.01em; }
.track-number {
    display: inline-block;
    font-family: 'Courier New', monospace;
    color: var(--accent-dark);
    font-weight: 800;
    font-size: .85rem;
    background: var(--accent-soft);
    padding: 5px 14px;
    border-radius: 99px;
    letter-spacing: 1.5px;
}
.track-meta { color: var(--muted); font-size: .8rem; margin-top: 8px; }

/* Progress stepper — 3 numbered circles with gold active */
.stepper {
    display: flex;
    justify-content: space-between;
    margin: 1.5rem 6% 1.75rem;
    position: relative;
}
.stepper-bg, .stepper-fill {
    position: absolute;
    top: 22px;
    height: 3px;
    border-radius: 99px;
}
.stepper-bg {
    right: 8%; left: 8%;
    background: #e5e7eb;
}
.stepper-fill {
    right: 8%;
    background: linear-gradient(90deg, var(--brand) 0%, var(--accent) 100%);
    transition: width .6s cubic-bezier(.4,0,.2,1);
}
.step {
    display: flex; flex-direction: column; align-items: center;
    flex: 1; position: relative; z-index: 1;
}
.step-circle {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: white;
    border: 2px solid #e5e7eb;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; color: #9ca3af; font-size: 1.05rem;
    transition: all .3s cubic-bezier(.34,1.56,.64,1);
}
.step.done .step-circle {
    background: var(--brand);
    border-color: var(--brand);
    color: white;
}
.step.done .step-circle::after {
    content: '✓';
    position: absolute;
    font-size: 1.2rem;
}
.step.done .step-circle > span { display: none; }
.step.current .step-circle {
    background: var(--accent);
    border-color: var(--accent);
    color: var(--brand-dark);
    box-shadow: 0 6px 18px rgba(184,135,42,.5);
    animation: step-pulse 2s infinite;
    transform: scale(1.08);
}
@keyframes step-pulse {
    0%,100% { box-shadow: 0 6px 18px rgba(184,135,42,.5), 0 0 0 0 rgba(184,135,42,.5); }
    50% { box-shadow: 0 6px 18px rgba(184,135,42,.5), 0 0 0 10px rgba(184,135,42,0); }
}
.step-label {
    margin-top: 10px; font-size: .75rem; font-weight: 700;
    color: var(--muted); text-align: center; max-width: 80px;
}
.step.done .step-label,
.step.current .step-label {
    color: var(--brand-dark);
}

/* Items list (compact) */
.track-items { margin: 0 -.25rem; }
.track-item {
    padding: 10px 12px;
    margin: 6px 0;
    background: #fafaf7;
    border-radius: 12px;
    border-right: 3px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}
.track-item.cancelled { opacity: .55; text-decoration: line-through; }
.track-item.ready { border-right-color: var(--brand); background: var(--brand-soft); }
.track-item.preparing { border-right-color: var(--accent); background: var(--accent-soft); }
.track-item-left { flex: 1; min-width: 0; }
.track-item-name { font-weight: 800; color: var(--ink); font-size: .92rem; }
.track-item-mods { font-size: .75rem; color: var(--muted); margin-top: 2px; }
.track-item-notes { font-size: .72rem; color: var(--warning); margin-top: 2px; font-style: italic; }
.track-item-right { text-align: left; flex-shrink: 0; }
.track-item-qty { font-weight: 800; color: var(--accent-dark); font-size: .95rem; }
.track-item-price { font-size: .75rem; color: var(--muted); }
.track-item-badge {
    display: inline-block; padding: 2px 8px; border-radius: 99px;
    font-size: .65rem; font-weight: 800;
}
.badge-pending { background: #f3f4f6; color: #6b7280; }
.badge-approved { background: #dbeafe; color: #1e40af; }
.badge-preparing { background: var(--accent-soft); color: var(--accent-dark); animation: pulse-accent 1.8s infinite; }
@keyframes pulse-accent { 0%,100% { opacity: 1; } 50% { opacity: .6; } }
.badge-ready { background: var(--brand-soft); color: var(--brand-dark); font-weight: 900; }
.badge-served { background: #e5e7eb; color: #374151; }
.badge-cancelled { background: #fee2e2; color: #991b1b; }

/* Summary bar */
.track-summary {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 1rem; padding: 14px 0 8px;
    border-top: 1.5px dashed var(--border);
    font-weight: 800;
}
.track-summary .amount { color: var(--accent-dark); font-size: 1.35rem; }
.track-summary .label { color: var(--muted); font-size: .85rem; font-weight: 600; }

/* Actions */
.track-actions { margin-top: 1rem; display: flex; flex-direction: column; gap: 8px; }
.btn-track-cancel {
    background: white;
    color: var(--danger);
    border: 2px solid var(--danger);
    border-radius: 14px;
    padding: 12px;
    font-weight: 800;
    width: 100%;
    transition: all .2s;
    font-size: .95rem;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-track-cancel:hover { background: var(--danger); color: white; }
.btn-track-primary {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    color: white; border: 0;
    border-radius: 14px;
    padding: 14px;
    font-weight: 800;
    width: 100%;
    font-size: 1rem;
    box-shadow: 0 8px 24px rgba(31,71,51,.35);
    transition: all .2s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    text-decoration: none;
}
.btn-track-primary:hover {
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(31,71,51,.45);
}

/* Cancelled state */
.track-cancelled {
    background: #fef2f2;
    color: #991b1b;
    padding: 1rem;
    border-radius: 12px;
    text-align: center;
    font-weight: 700;
    margin: 1rem 0;
    border: 1.5px dashed #ef4444;
}

/* Empty */
.track-empty {
    text-align: center;
    padding: 3rem 1rem;
    background: white;
    border-radius: 24px;
    box-shadow: 0 12px 40px rgba(31,71,51,.08);
}
.track-empty i { font-size: 5rem; color: var(--muted); opacity: .3; }

/* Completed orders (collapsed) */
.completed-toggle {
    background: transparent; border: 1.5px dashed var(--border);
    border-radius: 12px;
    padding: 10px 1rem;
    width: 100%;
    color: var(--muted);
    font-weight: 700;
    font-size: .85rem;
    margin-top: 1rem;
}
.completed-toggle:hover { background: #f9fafb; }

/* Grand total bar (if multiple orders) */
.grand-bar {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    color: white;
    padding: 1rem 1.25rem;
    border-radius: 18px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 12px 32px rgba(31,71,51,.3);
    border: 2px solid var(--accent);
    margin-bottom: 1rem;
}
.grand-bar .lbl { font-size: .85rem; opacity: .9; }
.grand-bar .amt { font-size: 1.4rem; font-weight: 900; }

/* Live indicator pill */
.live-pill {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 16px;
    background: white;
    border: 1px solid rgba(31,71,51,.1);
    border-radius: 99px;
    font-size: .78rem;
    font-weight: 700;
    color: var(--brand-dark);
    box-shadow: 0 4px 14px rgba(31,71,51,.08);
    margin: 0 auto 1rem;
    width: fit-content;
    transition: all .2s;
}
.live-pill .live-dot {
    width: 9px; height: 9px;
    border-radius: 50%;
    background: #10b981;
    position: relative;
    flex-shrink: 0;
}
.live-pill .live-dot::before {
    content: '';
    position: absolute;
    inset: -3px;
    border-radius: 50%;
    background: rgba(16,185,129,.4);
    animation: live-pulse 1.6s ease-out infinite;
}
@keyframes live-pulse {
    0%   { transform: scale(.8); opacity: .8; }
    100% { transform: scale(2);  opacity: 0; }
}
.live-pill.offline { color: #78716c; }
.live-pill.offline .live-dot { background: #9ca3af; }
.live-pill.offline .live-dot::before { display: none; }
.live-pill.refreshing {
    color: var(--accent-dark);
    border-color: rgba(184,135,42,.3);
    background: rgba(184,135,42,.05);
}
.live-pill.refreshing .live-dot {
    background: var(--accent);
    animation: live-spin 1s linear infinite;
}
@keyframes live-spin {
    to { transform: rotate(360deg); }
}
.live-pill.refreshing .live-dot::before { display: none; }
.live-pill .last-updated {
    font-size: .7rem;
    font-weight: 600;
    color: var(--muted);
    font-variant-numeric: tabular-nums;
}

@media (min-width: 640px) {
    .track-card { padding: 2.25rem 2rem 1.5rem; }
    .step-circle { width: 50px; height: 50px; font-size: 1.15rem; }
    .stepper-bg, .stepper-fill { top: 24px; }
}

/* Loyalty signup banner — shown above the order tracker for guests who
   left a phone but haven't accepted an account yet. The post-order wait
   is the calmest, lowest-friction moment to ask. */
.track-signup {
    display: flex;
    gap: .9rem;
    align-items: flex-start;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    background: linear-gradient(135deg, rgba(184, 135, 42, .14), rgba(255, 251, 235, .96));
    border: 1px solid rgba(184, 135, 42, .35);
    border-inline-start: 4px solid var(--accent);
    border-radius: 18px;
    box-shadow: 0 6px 20px rgba(184, 135, 42, .14);
}
.track-signup--success {
    background: linear-gradient(135deg, rgba(34, 197, 94, .14), rgba(240, 253, 244, .96));
    border-color: rgba(34, 197, 94, .4);
    border-inline-start-color: #166534;
    box-shadow: 0 6px 20px rgba(22, 101, 52, .14);
}
.track-signup-icon {
    flex-shrink: 0;
    width: 48px; height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    box-shadow: 0 4px 12px rgba(184, 135, 42, .3);
}
.track-signup--success .track-signup-icon {
    background: linear-gradient(135deg, #16a34a, #166534);
    box-shadow: 0 4px 12px rgba(22, 101, 52, .3);
}
.track-signup-body { flex: 1; min-width: 0; }
.track-signup-body strong {
    display: block;
    font-size: 1rem;
    color: var(--brand-dark);
    margin-bottom: .35rem;
    font-weight: 950;
    line-height: 1.3;
}
.track-signup-body p {
    margin: 0 0 .65rem;
    font-size: .85rem;
    color: var(--muted);
    font-weight: 600;
    line-height: 1.5;
}
.track-signup-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
    align-items: center;
}
.track-signup-btn {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .55rem 1.1rem;
    border: 0;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--brand), var(--brand-dark));
    color: #fff;
    font-weight: 900;
    font-size: .9rem;
    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease;
    box-shadow: 0 4px 12px rgba(31, 71, 51, .25);
}
.track-signup-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(31, 71, 51, .35); }
.track-signup-skip {
    background: transparent;
    border: 0;
    color: var(--muted);
    font-size: .8rem;
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
    padding: .5rem .25rem;
}
.track-signup-skip:hover { color: var(--brand-dark); }
.track-signup-pin {
    display: inline-block;
    margin: .35rem 0;
    padding: .4rem 1rem;
    background: #166534;
    color: #f0fdf4;
    border-radius: 10px;
    font-family: ui-monospace, Menlo, Consolas, monospace;
    font-weight: 950;
    font-size: 1.4rem;
    letter-spacing: 4px;
    user-select: all;
}
.track-signup-body small {
    display: block;
    margin-top: .35rem;
    color: var(--muted);
    font-size: .75rem;
    line-height: 1.5;
}
</style>
@endpush

@section('content')
{{-- The actual tracking UI lives in a Livewire component that polls itself
     via wire:poll.visible.5s (same pattern as kitchen/bar/tables/cashier).
     This page just provides the layout shell + the page-specific CSS in
     the @push('styles') block above. Zero custom JS polling, zero
     /track/status fetcher — everything goes through Livewire now. --}}
<div class="track-wrap">
    @php
        $signupPin = session('signup_pin');
        $alreadyDismissed = session('signup_dismissed_session_'.$session->id);
        $eligibleForSignup = ! $session->customer_id
            && ! empty($session->customer_phone)
            && ! $alreadyDismissed
            && ! $signupPin;
    @endphp

    @if($signupPin)
        {{-- One-shot reveal of the PIN we just generated. The diner needs it
             to log into the portal later — gone after this view. --}}
        <div class="track-signup track-signup--success">
            <div class="track-signup-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div class="track-signup-body">
                <strong>تمام يا {{ $signupPin['name'] }}! حسابك جاهز 🎉</strong>
                <p>رمز الدخول للزيارات القادمة:</p>
                <code class="track-signup-pin">{{ $signupPin['pin'] }}</code>
                <small>اكتب الرمز عندك. تقدر تدخل بوابة العملاء برقم جوالك ({{ $signupPin['phone'] }}) + هذا الرمز.</small>
            </div>
        </div>
    @elseif($eligibleForSignup)
        {{-- Banner shown only when:
              1. No customer_id linked to this session.
              2. The diner left a phone in the cart (we have something to use).
              3. They haven't already waved this off in this session.
             Cleanest moment to ask for signup — the order is in, food is on
             its way, they have nothing to do but stare at their phone. --}}
        <div class="track-signup">
            <div class="track-signup-icon"><i class="bi bi-stars"></i></div>
            <div class="track-signup-body">
                <strong>سجّل لتجمع نقاط ولاء على زياراتك القادمة</strong>
                <p>عندنا رقمك ({{ $session->customer_phone }}) — ضغطة وحدة وحسابك جاهز. مش حنطلب منك ولا حقل ثاني.</p>
                <div class="track-signup-actions">
                    <form method="POST" action="{{ route('customer.track.signup') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="track-signup-btn">
                            <i class="bi bi-person-plus-fill"></i> أنشئ لي حساب
                        </button>
                    </form>
                    <form method="POST" action="{{ route('customer.track.signup.dismiss') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="track-signup-skip">شكراً، مش الآن</button>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <livewire:customer.order-tracker :session-id="$session->id" />
</div>
@endsection
