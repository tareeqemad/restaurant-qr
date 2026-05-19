@extends('layouts.admin')
@section('title', $station->name)

@section('content')
<x-admin.breadcrumb
    title="شاشة {{ $station->name }}"
    icon="{{ $station->icon ?: 'ri-fire-fill' }}"
    subtitle="عرض الطلبات الحية للمحطة — مصمم للسرعة وكثافة الطلبات" />

<livewire:admin.kitchen-board
    :station-id="$station->id"
    :station-code="$station->code"
    :station-name="$station->name"
    :station-color="$station->color ?: '#1f4733'" />

@push('styles')
{{-- ── Polished header buttons ─────────────────────────────────
     The default `kb-tool` / `kb-sort-btn` use rgba(0,0,0,.18) which
     reads as a black smear on the dark-green station header. We
     replace that with frosted-glass white at low opacity so the
     icons stay legible while keeping the kitchen-floor aesthetic.
   ──────────────────────────────────────────────────────────── --}}
<style>
    .kb-header { gap: 1.25rem !important; }

    .kb-header-tools {
        gap: .55rem !important;
        flex-wrap: wrap;
    }

    /* Sort group container (clock / fire / grid) */
    .kb-sort-group {
        background: rgba(255, 255, 255, .12) !important;
        backdrop-filter: blur(6px);
        border: 1px solid rgba(255, 255, 255, .18);
        padding: 4px !important;
        border-radius: 10px !important;
    }
    .kb-sort-btn {
        color: rgba(255, 255, 255, .82) !important;
        width: 34px !important; height: 34px !important;
        border-radius: 7px !important;
        transition: all .15s ease;
    }
    .kb-sort-btn:hover {
        background: rgba(255, 255, 255, .18) !important;
        color: #fff !important;
        transform: translateY(-1px);
    }
    .kb-sort-btn.is-active {
        background: var(--accent, #b8872a) !important;
        color: #fff !important;
        box-shadow: 0 3px 10px rgba(184, 135, 42, .45);
    }

    /* Sound toggle — biggest affordance, frosted glass + clear label */
    .kb-sound-btn {
        background: rgba(255, 255, 255, .12) !important;
        border: 1px solid rgba(255, 255, 255, .22) !important;
        backdrop-filter: blur(6px);
        height: 38px;
        padding: 0 .85rem !important;
        gap: .45rem !important;
        color: #fff !important;
        font-weight: 700;
        transition: all .15s ease;
    }
    .kb-sound-btn:hover {
        background: rgba(255, 255, 255, .2) !important;
        transform: translateY(-1px);
    }
    .kb-sound-btn.is-off {
        background: rgba(220, 38, 38, .25) !important;
        border-color: rgba(254, 202, 202, .3) !important;
        color: #fff !important;
    }
    .kb-sound-btn.is-on {
        background: rgba(34, 197, 94, .28) !important;
        border-color: rgba(187, 247, 208, .35) !important;
        color: #fff !important;
    }
    .kb-sound-btn i { font-size: 1.05rem; }
    .kb-sound-label { font-size: .8rem !important; font-weight: 800 !important; letter-spacing: 0; }

    /* Live status dot — softer container so it doesn't feel orphaned */
    .kb-live-dot {
        margin-inline-start: .1rem;
    }

    /* Header tone: stay inside the station's own colour band, but tone
       it down so vivid stations (kitchen=#ef4444, grill=#dc2626) don't
       feel like a fire alarm. We layer:
         1. A soft slate wash to drop the saturation (≈ −15% perceived)
         2. A diagonal white highlight to give shape
         3. The raw station colour underneath
       Result: red stays clearly red, blue stays clearly blue, but every
       hue feels deeper and easier on the eye over a long shift. */
    .kb-header--compact {
        padding: .9rem 1.1rem !important;
        background:
            linear-gradient(135deg, rgba(255, 255, 255, .14) 0%, rgba(255, 255, 255, 0) 55%),
            linear-gradient(180deg, rgba(30, 41, 59, .18), rgba(30, 41, 59, .22)),
            var(--station-color, rgb(var(--primary-rgb))) !important;
        border-radius: 14px;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, .12),
            0 4px 12px rgba(15, 23, 42, .08);
    }
    /* Pulsing border for overload — replaces the harsher red flash on
       the whole card. Just a calm dashed accent on the leading edge. */
    .kb-header--compact.kb-load-red {
        border-inline-start-color: rgba(255, 255, 255, .85) !important;
        animation: kb-pulse-overload 2s ease-in-out infinite;
    }
    @keyframes kb-pulse-overload {
        0%, 100% { box-shadow: inset 0 1px 0 rgba(255, 255, 255, .12), 0 4px 12px rgba(15, 23, 42, .08); }
        50%      { box-shadow: inset 0 1px 0 rgba(255, 255, 255, .25), 0 4px 18px rgba(15, 23, 42, .18); }
    }
    .kb-header--compact .kb-icon {
        background: rgba(255, 255, 255, .15) !important;
        border: 1px solid rgba(255, 255, 255, .15);
        width: 50px !important; height: 50px !important;
    }

    /* Active-tables filter chips — keep them visible with the new tone */
    .kb-table-filter {
        background: rgba(15, 71, 49, .04);
        padding: .55rem .8rem !important;
        border-radius: 10px;
    }

    /* Generic kb-tool override (any other tools that land on the header) */
    .kb-tool {
        background: rgba(255, 255, 255, .12) !important;
        border: 1px solid rgba(255, 255, 255, .18);
        color: #fff !important;
    }
    .kb-tool:hover { background: rgba(255, 255, 255, .2) !important; }

    @media (max-width: 640px) {
        .kb-header--compact { padding: .7rem .8rem !important; }
        .kb-sort-btn { width: 32px !important; height: 32px !important; }
        .kb-sound-btn { padding: 0 .65rem !important; }
    }

    /* External-order pickup strip — colour-codes the source so the chef
       knows at a glance whether a "ready" ticket is going to a courier vs.
       a customer waiting at the counter. */
    .kb-ready-card--external {
        border-inline-start: 4px solid var(--source-color, #b97818) !important;
    }
    .kb-ready-card--external .kb-ready-table {
        background: color-mix(in srgb, var(--source-color, #b97818) 12%, white) !important;
    }
    .kb-ready-card--external .kb-ready-table small {
        color: var(--source-color, #b97818) !important;
        font-weight: 700;
        display: inline-flex !important;
        align-items: center;
        gap: 4px;
    }
    .kb-ready-card--external .kb-ready-table strong {
        color: var(--source-color, #b97818) !important;
        font-size: 1.1rem !important;
    }
    .kb-ready-customer {
        display: block;
        margin-top: 2px;
        font-size: .72rem;
        font-weight: 700;
        color: #475569;
    }
</style>
@endpush

@push('scripts')
{{-- @livewireScripts bundles Alpine in Livewire v4 — loading a second
     copy from CDN caused "Alpine already initialized" warnings and, worse,
     broke some wire:click DOM updates so actions saved but the UI didn't
     refresh until the page was reloaded. --}}
@livewireScripts
@endpush
@endsection
