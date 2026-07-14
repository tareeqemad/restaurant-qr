@extends('layouts.admin')
@section('title', $station->name)

@php
    // ── Wall mode (?tv=1) — chrome-free rendering for wall-mounted tablets.
    //    Same layout, same route: CSS keyed off a class on <html> hides the
    //    admin header/nav/breadcrumb/footer so ~100% of pixels are tickets.
    $tvMode = request()->boolean('tv');
@endphp

@if($tvMode)
    @push('head-scripts')
        {{-- Stamp the class on <html> BEFORE first paint — adding it to
             <body> from the scripts stack would flash the full admin chrome
             for a beat on slow wall tablets. --}}
        <script>document.documentElement.classList.add('kds-tv');</script>
    @endpush
@endif

@section('content')
<x-admin.breadcrumb
    title="شاشة {{ $station->name }}"
    icon="{{ $station->icon ?: 'ri-fire-fill' }}"
    subtitle="عرض الطلبات الحية للمحطة — مصمم للسرعة وكثافة الطلبات">
    <x-slot:actions>
        {{-- Keeps sort/table query params so the wall screen opens in the
             exact state the chef configured before mounting the tablet. --}}
        <a href="{{ request()->fullUrlWithQuery(['tv' => 1]) }}" class="btn btn-primary">
            <i class="bi bi-tv"></i> وضع الشاشة
        </a>
    </x-slot:actions>
</x-admin.breadcrumb>

<livewire:admin.kitchen-board
    :station-id="$station->id"
    :station-code="$station->code"
    :station-name="$station->name"
    :station-color="$station->color ?: '#166534'" />

@if($tvMode)
    {{-- Floating dock — the only chrome left in wall mode: the exit pill
         plus a one-tap fullscreen toggle. requestFullscreen() demands a
         user gesture anyway, so a single tap at shift start is the
         cheapest possible ceremony. --}}
    <div class="kds-tv-dock">
        <a href="{{ request()->fullUrlWithoutQuery('tv') }}" class="kds-tv-exit" title="الخروج من وضع الشاشة">
            <i class="bi bi-box-arrow-right"></i>
            <span>خروج من وضع الشاشة</span>
        </a>
        <button type="button" id="kds-tv-fullscreen" class="kds-tv-exit kds-tv-fs"
                title="ملء الشاشة — نقرة واحدة عند بداية الوردية، ثم ثبّت المتصفح بوضع الكشك">
            <i class="bi bi-arrows-fullscreen"></i>
            <span>ملء الشاشة</span>
        </button>
    </div>
@endif

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
        border-inline-start: 4px solid var(--source-color, #d97706) !important;
    }
    .kb-ready-card--external .kb-ready-table {
        background: color-mix(in srgb, var(--source-color, #d97706) 12%, white) !important;
    }
    .kb-ready-card--external .kb-ready-table small {
        color: var(--source-color, #d97706) !important;
        font-weight: 700;
        display: inline-flex !important;
        align-items: center;
        gap: 4px;
    }
    .kb-ready-card--external .kb-ready-table strong {
        color: var(--source-color, #d97706) !important;
        font-size: 1.1rem !important;
    }
    .kb-ready-customer {
        display: block;
        margin-top: 2px;
        font-size: .72rem;
        font-weight: 700;
        color: #475569;
    }

    /* ── Wall mode (?tv=1) ───────────────────────────────────────
       Kill every admin chrome element so a wall-mounted tablet
       spends its pixels on tickets. Pure CSS keyed off the .kds-tv
       class the head script stamps on <html> — no separate layout
       file to drift out of sync with layouts/admin. */
    .kds-tv .app-header,
    .kds-tv .app-sidebar,
    .kds-tv .page-header,
    .kds-tv footer.footer,
    .kds-tv .scrollToTop { display: none !important; }

    .kds-tv .main-content.app-content {
        margin: 0 !important;
        padding-top: .6rem !important;
        min-height: 100vh;
    }
    .kds-tv .app-content > .container-fluid {
        max-width: 100% !important;
        padding-inline: .6rem !important;
    }

    /* Floating dock (exit + fullscreen) — deliberately small and
       translucent so it never competes with tickets, but still easy
       to tap. The dock owns the fixed positioning so the pills can
       sit side by side without hardcoding offsets. */
    .kds-tv-dock {
        position: fixed;
        inset-block-end: 14px;
        inset-inline-start: 14px;
        z-index: 1080;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .kds-tv-exit {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        border-radius: 999px;
        background: rgba(15, 23, 42, .72);
        color: #fff !important;
        font-size: .78rem;
        font-weight: 700;
        text-decoration: none;
        opacity: .55;
        backdrop-filter: blur(4px);
        transition: opacity .15s ease;
    }
    .kds-tv-exit:hover { opacity: 1; }
    /* Fullscreen toggle reuses the pill look; a <button> doesn't inherit
       the anchor styling so it needs its own resets. */
    .kds-tv-fs {
        border: 0;
        cursor: pointer;
        font-family: inherit;
    }

    /* ── Wall-mode typography ────────────────────────────────────
       Hiding the chrome (above) frees the pixels; this block makes
       them readable from where cooks actually stand — 1-2 m away
       from a wall-mounted tablet, not 50 cm from a desk monitor.
       `html.kds-tv` out-ranks the component's single-class rules by
       specificity, so no !important war with relax-components.css. */

    /* Bigger type needs wider tickets to keep the same proportions —
       at 1280px this trades 3 narrow columns for 2 readable ones. */
    html.kds-tv .kb-tickets {
        grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
        gap: 1.1rem;
    }

    /* Item rows — the text the cook actually reads per dish. */
    html.kds-tv .kb-item-qty  { font-size: 1.4rem; width: 44px; }
    html.kds-tv .kb-item-name { font-size: 1.35rem; line-height: 1.35; }
    html.kds-tv .kb-item-note {
        font-size: 1.35rem;
        line-height: 1.4;
        padding: 4px 10px;
    }
    html.kds-tv .kb-item-mods { gap: 6px; margin-top: 4px; }
    html.kds-tv .kb-item-mods span { font-size: 1.05rem; padding: 2px 10px; }

    /* Timing chips — age, target prep, live elapsed, ETA, orphan tag. */
    html.kds-tv .kb-age-chip,
    html.kds-tv .kb-eta-chip,
    html.kds-tv .kb-prep-tag,
    html.kds-tv .kb-elapsed-tag,
    html.kds-tv .kb-orphan-tag { font-size: 1rem; }
    html.kds-tv .kb-age-chip strong { font-size: 1.2rem; }
    html.kds-tv .kb-age-chip i,
    html.kds-tv .kb-eta-chip i,
    html.kds-tv .kb-prep-tag i,
    html.kds-tv .kb-elapsed-tag i,
    html.kds-tv .kb-orphan-tag i { font-size: .9rem; }

    /* Whole-order note band under the items. */
    html.kds-tv .kb-card-note { font-size: 1.05rem; }

    /* Touch targets — 48px is the reliable minimum for taps with
       wet/greasy kitchen fingers on a wall tablet. */
    html.kds-tv .kb-item-btn {
        width: 48px;
        height: 48px;
        min-height: 48px;
        font-size: 1.25rem;
    }
    /* The undo variant carries a text label («تراجع») — the fixed 48px
       square above would crush it. Let it grow with its content. */
    html.kds-tv .kb-item-btn-undo {
        width: auto;
        padding-inline: 14px;
        font-size: 1rem;
    }
    html.kds-tv .kb-undo-mini {
        min-height: 44px;
        padding-inline: 12px;
        font-size: .95rem;
    }
    html.kds-tv .kb-ready-serve {
        min-height: 48px;
        padding-inline: 16px;
        font-size: 1.05rem;
    }
    html.kds-tv .kb-card-btn {
        min-height: 48px;
        font-size: 1.05rem;
    }
    html.kds-tv .kb-item-badge { font-size: 1.4rem; }
    html.kds-tv .kb-cancel-menu { min-width: 280px; }
    html.kds-tv .kb-cancel-opt { min-height: 48px; }
    html.kds-tv .kb-cancel-opt strong { font-size: 1rem; }
    html.kds-tv .kb-cancel-opt small  { font-size: .85rem; }

    /* Ready strip — gets called out across the kitchen, so it scales too. */
    html.kds-tv .kb-ready-strip > header h4 { font-size: 1.15rem; }
    html.kds-tv .kb-ready-items { font-size: 1.15rem; }
    html.kds-tv .kb-ready-meta  { font-size: 1.1rem; }
    html.kds-tv .kb-ready-customer { font-size: 1rem; }
    html.kds-tv .kb-ready-table strong { font-size: 1.7rem; }
    html.kds-tv .kb-ready-table small  { font-size: .8rem; }
    html.kds-tv .kb-ready-card { min-width: 260px; max-width: 340px; }
</style>
@endpush

@push('scripts')
{{-- @livewireScripts bundles Alpine in Livewire v4 — loading a second
     copy from CDN caused "Alpine already initialized" warnings and, worse,
     broke some wire:click DOM updates so actions saved but the UI didn't
     refresh until the page was reloaded. --}}
@livewireScripts

<script>
/* ── Session-death + network guard (all modes) ──────────────────────
   A KDS runs unattended: when the session dies (SESSION_LIFETIME) the
   next poll 401/419s and Livewire's default reaction is a full-screen
   ENGLISH dialog while the board silently freezes — and the green
   «متصل» dot keeps pulsing. Cooks can't act on that. Instead:
     - 401/419  → full-screen Arabic overlay + reload button. The board
       is hidden, which stops wire:poll.visible cold (its visibility
       check no longer sees the element) — no more doomed requests
       hammering a dead session.
     - network drop / 5xx → slim red top banner that clears itself on
       the next successful request.
   Vanilla JS + inline styles on purpose: this must render even when
   the network — and therefore any asset pipeline — is the thing
   that's failing. */
document.addEventListener('livewire:init', () => {
    let sessionDead = false;

    const banner = document.createElement('div');
    banner.setAttribute('dir', 'rtl');
    banner.style.cssText = 'position:fixed;top:0;right:0;left:0;z-index:2147483000;display:none;'
        + 'background:#b91c1c;color:#fff;text-align:center;font-weight:800;font-size:1rem;'
        + 'padding:10px 16px;box-shadow:0 2px 10px rgba(0,0,0,.35);';
    banner.innerHTML = '<i class="bi bi-wifi-off"></i> انقطع الاتصال بالخادم — تُعاد المحاولة تلقائياً';
    document.body.appendChild(banner);

    const showSessionOverlay = () => {
        if (sessionDead) return;
        sessionDead = true;
        document.querySelectorAll('.kb-wrap').forEach((el) => { el.style.display = 'none'; });
        banner.style.display = 'none';
        const overlay = document.createElement('div');
        overlay.setAttribute('dir', 'rtl');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483001;display:flex;flex-direction:column;'
            + 'align-items:center;justify-content:center;gap:18px;background:rgba(15,23,42,.96);color:#fff;'
            + 'text-align:center;padding:24px;';
        overlay.innerHTML =
            '<div style="font-size:3.2rem;line-height:1">⏰</div>'
            + '<div style="font-size:1.6rem;font-weight:900">انتهت الجلسة — سجّل الدخول من جديد</div>'
            + '<div style="font-size:1.05rem;opacity:.8">توقّف تحديث الشاشة. أعد تحميل الصفحة وسجّل الدخول لاستئناف العرض.</div>'
            + '<button type="button" onclick="window.location.reload()" style="border:0;cursor:pointer;'
            + 'background:#16a34a;color:#fff;font-weight:800;font-size:1.15rem;padding:14px 34px;'
            + 'border-radius:12px;min-height:48px">إعادة تحميل الصفحة</button>';
        document.body.appendChild(overlay);
    };

    Livewire.hook('request', ({ fail, succeed }) => {
        succeed(() => { if (!sessionDead) banner.style.display = 'none'; });
        fail(({ status, preventDefault }) => {
            if (status === 401 || status === 419) {
                preventDefault();   // suppress Livewire's English "page expired" confirm
                showSessionOverlay();
            } else if (!status || status >= 500) {
                preventDefault();   // suppress the full-screen English error modal
                if (!sessionDead) banner.style.display = 'block';
            }
        });
    });
});
</script>
@endpush

@if($tvMode)
    @push('scripts')
    <script>
    /* ── Wall-tablet hardening (tv mode only) ─────────────────────── */
    (function () {
        // 1) Screen Wake Lock — a tablet that dozes off mid-service is a
        //    dark, silent KDS and nobody notices until food runs late.
        //    Chromium tablets support the API; elsewhere this no-ops and
        //    the device's own kiosk "stay awake" setting is the fallback.
        let lock = null;
        const acquire = () => {
            if (!('wakeLock' in navigator)) return;
            navigator.wakeLock.request('screen')
                .then((l) => {
                    lock = l;
                    // The OS silently releases the lock when the tab hides
                    // or battery saver kicks in — null it so the handler
                    // below knows to re-acquire.
                    l.addEventListener('release', () => { lock = null; });
                })
                .catch(() => {});   // denied (battery saver) — nothing to do
        };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && !lock) acquire();
        });
        acquire();

        // 2) Fullscreen toggle for the dock button.
        const btn = document.getElementById('kds-tv-fullscreen');
        if (!btn) return;
        btn.addEventListener('click', () => {
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            } else {
                document.documentElement.requestFullscreen().catch(() => {});
            }
        });
        document.addEventListener('fullscreenchange', () => {
            const on = !!document.fullscreenElement;
            btn.querySelector('i').className = on ? 'bi bi-fullscreen-exit' : 'bi bi-arrows-fullscreen';
            btn.querySelector('span').textContent = on ? 'إنهاء ملء الشاشة' : 'ملء الشاشة';
        });
    })();
    </script>
    @endpush
@endif
@endsection
