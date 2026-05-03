@php
    /**
     * Branch switcher — clean, Apple/Linear-style workspace picker.
     *
     * Trigger: rounded pill chip with avatar + name + chevron.
     * Dropdown: 320px white card with header label + scrollable
     * branch list + "manage" footer. Each row is avatar + name +
     * optional city, with a checkmark on the active branch.
     *
     * Important: Dashtic's global stylesheet has a `button { ... }`
     * override that applies a white background and ~12px padding to
     * every <button>. We use high-specificity selectors + !important
     * on the resets to keep our pill rows clean.
     */
    $u = auth()->user();
    if (! $u) {
        return;
    }

    $available = $u->isOwnerLevel()
        ? \App\Models\Branch::where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
        : $u->branches()
            ->where('is_active', true)
            ->orderBy('branches.display_order')
            ->orderBy('branches.name')
            ->get();

    if ($available->count() === 0) {
        return;
    }

    $isAllMode    = $u->isOwnerLevel() && session('view_all_branches');
    $activeId     = $isAllMode ? null : (session('active_branch_id') ?? \App\Support\BranchContext::current());
    $activeBranch = $activeId ? ($available->firstWhere('id', $activeId) ?? $available->first()) : null;
    $isSingle     = $available->count() === 1 && ! $u->isOwnerLevel();

    $initial = function (\App\Models\Branch $branch): string {
        $name = trim($branch->name);
        return $name === '' ? '?' : mb_substr($name, 0, 1, 'UTF-8');
    };
    $hue = fn (\App\Models\Branch $branch) => ($branch->id * 47) % 360;
@endphp

<div class="header-element bsx">
    @if($isSingle)
        <span class="bsx-trigger is-static" title="فرعك الحالي">
            <span class="bsx-avatar" style="--hue: {{ $hue($activeBranch) }};">
                {{ $initial($activeBranch) }}
            </span>
            <span class="bsx-trigger__name">{{ $activeBranch->name }}</span>
        </span>
    @else
        <a href="#" class="bsx-trigger {{ $isAllMode ? 'bsx-trigger--all' : '' }}" role="button"
           data-bs-toggle="dropdown" data-bs-auto-close="outside"
           aria-expanded="false" aria-label="تبديل الفرع">
            @if($isAllMode)
                <span class="bsx-avatar bsx-avatar--all">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                        <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.6"/>
                        <path d="M1.5 8h13M8 1.5c2 2 2 11 0 13M8 1.5c-2 2-2 11 0 13"
                              stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                    </svg>
                </span>
                <span class="bsx-trigger__name">كل الفروع</span>
            @else
                <span class="bsx-avatar" style="--hue: {{ $hue($activeBranch) }};">
                    {{ $initial($activeBranch) }}
                </span>
                <span class="bsx-trigger__name">{{ $activeBranch->name }}</span>
            @endif
            <svg class="bsx-trigger__chev" width="11" height="11" viewBox="0 0 12 12" fill="none">
                <path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor"
                      stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>

        <div class="dropdown-menu dropdown-menu-end bsx-menu">
            <div class="bsx-menu__head">اختيار الفرع</div>

            <div class="bsx-menu__list">
                @if($u->isOwnerLevel())
                    <form action="{{ route('admin.branches.switch.all') }}"
                          method="POST" class="bsx-menu__form">
                        @csrf
                        <button type="submit"
                                class="bsx-row bsx-row--all {{ $isAllMode ? 'is-active' : '' }}"
                                {{ $isAllMode ? 'disabled' : '' }}>
                            <span class="bsx-avatar bsx-avatar--all">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                    <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.6"/>
                                    <path d="M1.5 8h13M8 1.5c2 2 2 11 0 13M8 1.5c-2 2-2 11 0 13"
                                          stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                </svg>
                            </span>
                            <span class="bsx-row__body">
                                <span class="bsx-row__name">كل الفروع</span>
                                <span class="bsx-row__meta">عرض موحّد لكل البيانات</span>
                            </span>
                            @if($isAllMode)
                                <svg class="bsx-row__check" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                    <path d="M2.5 7L5.5 10L11.5 4" stroke="currentColor"
                                          stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            @endif
                        </button>
                    </form>
                @endif

                @foreach($available as $branch)
                    @php
                        $isActive = ! $isAllMode && $activeBranch && $branch->id === $activeBranch->id;
                        // Suppress city if it's already mentioned in the branch name
                        // (e.g. "الفرع الرئيسي - خانيونس" + city "خانيونس" → don't repeat).
                        $showCity = $branch->city && mb_stripos($branch->name, $branch->city) === false;
                    @endphp
                    <form action="{{ route('admin.branches.switch', $branch) }}"
                          method="POST" class="bsx-menu__form">
                        @csrf
                        <button type="submit"
                                class="bsx-row {{ $isActive ? 'is-active' : '' }}"
                                {{ $isActive ? 'disabled' : '' }}
                                style="--hue: {{ $hue($branch) }};">
                            <span class="bsx-avatar">{{ $initial($branch) }}</span>
                            <span class="bsx-row__body">
                                <span class="bsx-row__name">{{ $branch->name }}</span>
                                @if($showCity)
                                    <span class="bsx-row__meta">{{ $branch->city }}</span>
                                @endif
                            </span>
                            @if($isActive)
                                <svg class="bsx-row__check" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                    <path d="M2.5 7L5.5 10L11.5 4" stroke="currentColor"
                                          stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>

            @can('viewAny', \App\Models\Branch::class)
                <a href="{{ route('admin.branches.index') }}" class="bsx-menu__manage">
                    <i class="bi bi-gear-fill"></i>
                    <span>إدارة الفروع</span>
                </a>
            @endcan
        </div>
    @endif
</div>

{{--
  Inline <style> rather than @push('styles') because Blade's @stack
  in the layout's <head> is rendered BEFORE the header partial is
  included, so anything pushed from here is dropped silently. Inline
  styles in the body are HTML5-compliant and load reliably.
--}}
@once
<style>
    /* ╔════════════════════════════════════════════════════════════╗
       ║  Branch switcher — clean workspace-picker style              ║
       ║  Note: Dashtic forces button { bg:white; padding:.75rem }    ║
       ║  globally, so .bsx-row uses !important on the resets.        ║
       ╚════════════════════════════════════════════════════════════╝ */

    .bsx { position: relative; display: inline-flex; margin-inline-end: 8px; }

    /* ─── Trigger pill ─────────────────────────────────────────── */
    .bsx-trigger {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        height: 40px;
        padding: 0 12px 0 8px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        color: #111827;
        font-size: .86rem;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        max-width: 240px;
        transition: background-color .15s, border-color .15s, box-shadow .15s;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }
    .bsx-trigger::after { display: none; }
    .bsx-trigger:hover:not(.is-static),
    .bsx-trigger[aria-expanded="true"] {
        background: #f9fafb;
        border-color: #d1d5db;
        box-shadow: 0 4px 12px -3px rgba(15, 23, 42, .08);
        color: #111827;
    }
    .bsx-trigger.is-static { cursor: default; }
    .bsx-trigger:focus-visible {
        outline: none;
        border-color: rgb(var(--accent-rgb));
        box-shadow: 0 0 0 3px rgba(var(--accent-rgb), .25);
    }

    .bsx-trigger__name {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1;
    }
    .bsx-trigger__chev {
        flex-shrink: 0;
        color: #9ca3af;
        transition: transform .2s ease;
    }
    .bsx-trigger[aria-expanded="true"] .bsx-trigger__chev { transform: rotate(180deg); }

    .bsx-trigger--all {
        background: linear-gradient(135deg,
            rgba(var(--primary-rgb), .07),
            rgba(var(--accent-rgb), .07));
        border-color: rgba(var(--primary-rgb), .35);
    }
    .bsx-trigger--all .bsx-trigger__name { color: rgb(var(--primary-rgb)); }

    /* ─── Avatar ───────────────────────────────────────────────── */
    .bsx-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        flex-shrink: 0;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg,
            hsl(var(--hue, 150) 50% 52%) 0%,
            hsl(var(--hue, 150) 55% 38%) 100%);
        color: #fff;
        font-size: .82rem;
        font-weight: 800;
        line-height: 1;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .25);
    }
    .bsx-avatar--all {
        background: linear-gradient(135deg,
            rgb(var(--primary-rgb)) 0%,
            rgb(var(--accent-rgb)) 100%);
    }

    /* ─── Dropdown card ────────────────────────────────────────── */
    .bsx-menu {
        min-width: 320px !important;
        max-width: 360px;
        padding: 0 !important;
        margin-top: 8px !important;
        background: #fff !important;
        border: 1px solid rgba(15, 23, 42, .08) !important;
        border-radius: 14px !important;
        box-shadow:
            0 4px 6px -2px rgba(15, 23, 42, .06),
            0 18px 36px -8px rgba(15, 23, 42, .16) !important;
        overflow: hidden;
    }

    /* Section label at the top */
    .bsx-menu__head {
        padding: 10px 14px 6px;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .04em;
        color: #9ca3af;
        text-transform: none;
    }

    .bsx-menu__list {
        padding: 0 6px 6px;
        display: flex;
        flex-direction: column;
        gap: 2px;
        max-height: 360px;
        overflow-y: auto;
    }
    .bsx-menu__list::-webkit-scrollbar { width: 6px; }
    .bsx-menu__list::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 3px; }

    .bsx-menu__form { margin: 0 !important; padding: 0 !important; display: block; }

    /* ─── Row — high-specificity overrides for Dashtic's button{} ─ */
    .bsx-menu .bsx-row,
    button.bsx-row {
        display: flex !important;
        align-items: center !important;
        gap: 11px !important;
        width: 100% !important;
        padding: 8px 10px !important;
        background: transparent !important;
        background-color: transparent !important;
        border: 0 !important;
        border-block-end: 0 !important;
        border-radius: 9px !important;
        color: #1f2937 !important;
        text-align: start !important;
        cursor: pointer !important;
        font: inherit !important;
        font-size: .88rem !important;
        font-weight: 600 !important;
        line-height: 1.2 !important;
        box-shadow: none !important;
        transition: background-color .12s !important;
    }
    .bsx-menu .bsx-row:hover:not(:disabled),
    button.bsx-row:hover:not(:disabled) {
        background: #f3f4f6 !important;
        background-color: #f3f4f6 !important;
    }
    .bsx-menu .bsx-row:disabled,
    button.bsx-row:disabled {
        cursor: default !important;
        opacity: 1 !important;
    }
    .bsx-menu .bsx-row.is-active,
    button.bsx-row.is-active {
        background: #f3f4f6 !important;
        background-color: #f3f4f6 !important;
    }
    .bsx-menu .bsx-row:focus-visible,
    button.bsx-row:focus-visible {
        outline: none !important;
        background: #f3f4f6 !important;
        box-shadow: inset 0 0 0 2px rgba(var(--accent-rgb), .35) !important;
    }

    .bsx-row__body {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .bsx-row__name {
        font-size: .88rem;
        font-weight: 700;
        color: #111827;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.25;
    }
    .bsx-row.is-active .bsx-row__name {
        font-weight: 800;
    }
    .bsx-row__meta {
        font-size: .72rem;
        font-weight: 500;
        color: #6b7280;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.2;
    }

    .bsx-row__check {
        flex-shrink: 0;
        color: rgb(var(--accent-rgb));
    }

    /* ─── Footer ──────────────────────────────────────────────── */
    .bsx-menu__manage {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 10px 14px;
        border-top: 1px solid #f3f4f6;
        background: #fafafa;
        font-size: .82rem;
        font-weight: 600;
        color: #4b5563;
        text-decoration: none;
        transition: background-color .12s, color .12s;
    }
    .bsx-menu__manage:hover {
        background: #f3f4f6;
        color: rgb(var(--primary-rgb));
    }
    .bsx-menu__manage > i {
        color: #9ca3af;
        font-size: .9rem;
        transition: color .12s;
    }
    .bsx-menu__manage:hover > i { color: rgb(var(--primary-rgb)); }

    /* Mobile */
    @media (max-width: 768px) {
        .bsx-trigger { padding: 0 8px; max-width: 44px; justify-content: center; }
        .bsx-trigger__name, .bsx-trigger__chev { display: none; }
        .bsx-menu { min-width: 280px !important; max-width: calc(100vw - 24px); }
    }
</style>
@endonce
