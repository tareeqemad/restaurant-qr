@php
    /**
     * Attendance pill — visually paired with the branch switcher next
     * to it. Same 42px height, same rounded-pill shape, same hover lift.
     *
     *   - OUT: indigo gradient clock-in state with door-in icon.
     *   - IN: emerald pulse + two-line live ticker.
     *
     * Click while IN → confirm modal (sweetalert2 if available, else
     * native confirm) → POST clock-out.
     */
    $u = auth()->user();
    if (! $u) return;
    $open = $u->openAttendance();
@endphp

<div class="header-element atx">
    @if($open)
        <form action="{{ route('admin.attendance.clock-out') }}" method="POST" class="atx-form"
              onsubmit="return confirm(@js(__('admin.common.clock_out_confirm')));">
            @csrf
            <button type="submit"
                    class="atx-btn atx-btn--in"
                    title="{{ __('admin.common.clock_out_title', ['branch' => $open->branch->localizedName(), 'time' => $open->clock_in_at->format('H:i')]) }}">
                <span class="atx-btn__pulse" aria-hidden="true">
                    <span class="atx-btn__dot"></span>
                </span>
                <span class="atx-btn__body">
                    <span class="atx-btn__top">{{ __('admin.common.present_now') }}</span>
                    <span class="atx-btn__time"
                          data-atx-ticker
                          data-atx-start="{{ $open->clock_in_at->toIso8601String() }}"
                          data-atx-break="{{ (int) $open->break_minutes }}">
                        {{ $open->durationLabel() }}
                    </span>
                </span>
            </button>
        </form>
    @else
        <form action="{{ route('admin.attendance.clock-in') }}" method="POST" class="atx-form">
            @csrf
            <button type="submit" class="atx-btn atx-btn--out" title="{{ __('admin.common.clock_in_title') }}">
                <span class="atx-btn__icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.4"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                </span>
                <span class="atx-btn__body">
                    <span class="atx-btn__top">{{ __('admin.common.clock_in_short') }}</span>
                    <span class="atx-btn__label">{{ __('admin.common.clock_in') }}</span>
                </span>
            </button>
        </form>
    @endif
</div>

{{-- Inline <style> — see branch_switcher.blade.php for why @push doesn't work here. --}}
@once
<style>
    /* ╔════════════════════════════════════════════════════════════╗
       ║  Attendance — pill button, paired with branch switcher       ║
       ╚════════════════════════════════════════════════════════════╝ */
    .atx { display: inline-flex; margin-inline-end: 8px; }
    .atx-form { margin: 0; display: inline-flex; }

    .atx-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        height: 42px;
        padding: 0 16px 0 12px;
        border: 0;
        border-radius: 999px;
        font: inherit;
        line-height: 1;
        cursor: pointer;
        white-space: nowrap;
        position: relative;
        overflow: hidden;
        transition:
            transform .15s cubic-bezier(.34, 1.56, .64, 1),
            box-shadow .2s ease,
            filter .15s ease;
    }
    .atx-btn:active { transform: translateY(1px) scale(.98); }
    .atx-btn:focus-visible { outline: none; }

    /* Diagonal shimmer on hover */
    .atx-btn::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(110deg,
            transparent 35%,
            rgba(255, 255, 255, .25) 50%,
            transparent 65%);
        transform: translateX(-100%);
        transition: transform .65s ease;
        pointer-events: none;
    }
    .atx-btn:hover::before { transform: translateX(100%); }

    /* Two-line text stack — matches the branch switcher trigger */
    .atx-btn__body {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-start;
        line-height: 1;
        gap: 3px;
        min-width: 0;
    }
    .atx-btn__top {
        font-size: .62rem;
        font-weight: 700;
        letter-spacing: .04em;
        opacity: .82;
    }
    .atx-btn__time {
        font-size: .9rem;
        font-weight: 900;
        font-variant-numeric: tabular-nums;
        font-feature-settings: "tnum";
        letter-spacing: -.01em;
    }
    .atx-btn__label {
        font-size: .85rem;
        font-weight: 800;
    }
    .atx-btn__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px; height: 30px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .2);
        flex-shrink: 0;
    }

    /* ─── OUT state — indigo, professional ─────────────────────── */
    .atx-btn--out {
        background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);
        color: #fff;
        box-shadow:
            0 4px 14px -3px rgba(79, 70, 229, .45),
            inset 0 1px 0 rgba(255, 255, 255, .25),
            inset 0 -2px 0 rgba(0, 0, 0, .08);
    }
    .atx-btn--out:hover {
        transform: translateY(-2px);
        filter: brightness(1.08);
        box-shadow:
            0 8px 22px -3px rgba(79, 70, 229, .55),
            inset 0 1px 0 rgba(255, 255, 255, .28),
            inset 0 -2px 0 rgba(0, 0, 0, .08);
    }
    .atx-btn--out:focus-visible {
        box-shadow:
            0 0 0 4px rgba(79, 70, 229, .25),
            0 4px 14px -3px rgba(79, 70, 229, .45);
    }

    /* ─── IN state — vivid emerald, alive ──────────────────────── */
    .atx-btn--in {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: #fff;
        box-shadow:
            0 4px 14px -3px rgba(16, 185, 129, .55),
            inset 0 1px 0 rgba(255, 255, 255, .28),
            inset 0 -2px 0 rgba(0, 0, 0, .1);
    }
    .atx-btn--in:hover {
        transform: translateY(-2px);
        filter: brightness(1.06);
        box-shadow:
            0 8px 22px -3px rgba(16, 185, 129, .65),
            inset 0 1px 0 rgba(255, 255, 255, .32),
            inset 0 -2px 0 rgba(0, 0, 0, .1);
    }
    .atx-btn--in:focus-visible {
        box-shadow:
            0 0 0 4px rgba(16, 185, 129, .3),
            0 4px 14px -3px rgba(16, 185, 129, .55);
    }

    /* Pulse "live" indicator — sits where the avatar sits on the branch chip */
    .atx-btn__pulse {
        position: relative;
        width: 30px; height: 30px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .2);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .atx-btn__dot {
        width: 10px; height: 10px;
        border-radius: 50%;
        background: #fff;
        position: relative;
        box-shadow: 0 0 0 2px rgba(255, 255, 255, .4);
    }
    .atx-btn__dot::after {
        content: '';
        position: absolute;
        inset: -4px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .5);
        animation: atxPulse 1.6s ease-out infinite;
    }
    @keyframes atxPulse {
        0%   { transform: scale(.5); opacity: 1; }
        100% { transform: scale(2);  opacity: 0; }
    }

    /* ─── Mobile — compact ─────────────────────────────────────── */
    @media (max-width: 768px) {
        .atx-btn { height: 40px; padding: 0 8px 0 6px; gap: 6px; }
        .atx-btn__top { display: none; }
        .atx-btn__icon, .atx-btn__pulse { width: 26px; height: 26px; }
        .atx-btn__label, .atx-btn__time { font-size: .82rem; }
    }
</style>

@push('scripts')
<script>
(function () {
    const fmt = (seconds) => {
        const m = Math.max(0, Math.floor(seconds / 60));
        const h = Math.floor(m / 60);
        const r = m % 60;
        if (h === 0) return @json(__('admin.common.minutes_short')).replace(':count', r);
        if (r === 0) return @json(__('admin.common.hours_short')).replace(':count', h);
        return @json(__('admin.common.hours_minutes_short')).replace(':hours', h).replace(':minutes', r);
    };
    const tick = () => {
        const now = Date.now();
        document.querySelectorAll('[data-atx-ticker]').forEach(el => {
            const start = new Date(el.dataset.atxStart).getTime();
            if (Number.isNaN(start)) return;
            const breakMins = parseInt(el.dataset.atxBreak || '0', 10) || 0;
            const seconds = Math.floor((now - start) / 1000) - breakMins * 60;
            el.textContent = fmt(seconds);
        });
    };
    tick();
    setInterval(tick, 1000);
})();
</script>
@endpush
@endonce
