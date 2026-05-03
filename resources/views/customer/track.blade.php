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
</style>
@endpush

@section('content')
{{-- The actual tracking UI lives in a Livewire component that polls itself
     via wire:poll.visible.5s (same pattern as kitchen/bar/tables/cashier).
     This page just provides the layout shell + the page-specific CSS in
     the @push('styles') block above. Zero custom JS polling, zero
     /track/status fetcher — everything goes through Livewire now. --}}
<div class="track-wrap">
    <livewire:customer.order-tracker :session-id="$session->id" />
</div>
@endsection
