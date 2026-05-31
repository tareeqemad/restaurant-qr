@php
    $theme = \App\Support\ThemePalette::current();
    $brandName = \App\Helpers\Brand::name();
    $market = \App\Support\MarketProfile::class;
@endphp
<!DOCTYPE html>
<html lang="{{ $market::lang() }}" dir="{{ $market::direction() }}" data-market="{{ $market::current() }}">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="{{ $theme['primary'] }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', __('ui.customer_menu.default_title')) · {{ $brandName }}</title>
<link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">
<link href="{{ asset($market::bootstrapCssPath()) }}" rel="stylesheet">
<link href="{{ asset('assets/dashtic/icon-fonts/feather/feather.css') }}" rel="stylesheet">
<link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="{{ $market::fontUrl() }}" rel="stylesheet">
@vite(['resources/js/app.js'])
{{-- Livewire bundles its own Alpine — don't load a second copy from CDN
     or Alpine will double-init and break reactivity. @livewireStyles goes in
     <head>, @livewireScripts at end of body. --}}
@livewireStyles
<style>
:root {
    /* Premium forest green + olive gold palette */
    --brand: {{ $theme['primary'] }};
    --brand-dark: {{ $theme['dark'] }};
    --brand-light: {{ $theme['primary_light'] }};
    --brand-soft: {{ $theme['primary_soft'] }};
    --accent: {{ $theme['accent'] }};
    --accent-dark: {{ $theme['accent_dark'] }};
    --accent-soft: {{ $theme['accent_soft'] }};
    --gold: #c19845;            /* warm gold */
    --gold-soft: #efe4c6;       /* soft cream gold */
    --ink: #1c1917;             /* warm dark ink */
    --muted: #78716c;           /* warm muted */
    --bg: #faf5eb;              /* warm cream background */
    --card: #ffffff;
    --border: #e7dfd0;          /* warm border */
    --success: {{ $theme['primary'] }};
    --warning: {{ $theme['accent'] }};
    --danger: #b91c1c;
    --info: {{ $theme['primary'] }};
    --radius: 16px;
    --brand-gradient: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    --brand-gradient-y: linear-gradient(135deg, var(--brand) 0%, var(--accent) 100%);
    --gold-gradient: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
}
@include('partials.theme-vars', ['theme' => $theme])
@include('partials.market-vars')
* { -webkit-tap-highlight-color: transparent; }
html, body { background: var(--bg); color: var(--ink); font-family: var(--market-font-family); }
body { padding-bottom: env(safe-area-inset-bottom); min-height: 100vh; }
/* ── Topbar — restaurant brand strip on top of the QR menu ─────
   Refined dark-forest gradient + hand-painted gold accents. The bar
   carries: brand mark + name on the start side, and a glossy gold
   "table N" pill plus order-tracking chip on the end. Gives the
   diner a fast brand impression without competing with the menu. */
.app-topbar {
    position: sticky;
    top: 0;
    z-index: 50;
    color: #fff;
    padding: .85rem clamp(.85rem, 2vw, 1.4rem);
    padding-top: max(.85rem, env(safe-area-inset-top));
    background:
        linear-gradient(135deg, var(--dark) 0%, var(--brand) 60%, var(--brand-light) 100%);
    box-shadow: 0 8px 26px rgba(15, 45, 34, .28);
    border-bottom: 1px solid rgba(255, 255, 255, .07);
    overflow: hidden;
}
.app-topbar::before {
    /* Decorative radial glows — soft gold on the inline-end (where the
       reading flow starts in RTL = right side) and a subtle white shine
       on the bottom-start corner. Adds depth without busy textures. */
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 60% 80% at 90% -10%, rgba(var(--accent-rgb), .26), transparent 55%),
        radial-gradient(ellipse 40% 60% at 5% 110%, rgba(255, 255, 255, .08), transparent 60%);
    pointer-events: none;
}
.app-topbar::after {
    /* Hairline gold stripe along the bottom edge — the kind of subtle
       finish you'd find on a luxury menu cover. */
    content: '';
    position: absolute;
    inset-inline: clamp(1.5rem, 8vw, 4rem);
    bottom: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, rgba(var(--accent-rgb), .55), transparent);
    pointer-events: none;
}
.app-topbar > * { position: relative; }

/* Brand cluster: logo + restaurant name */
.app-topbar h4 {
    margin: 0;
    font-weight: 900;
    font-size: clamp(1.05rem, 2.6vw, 1.25rem);
    line-height: 1.15;
    display: inline-flex;
    align-items: center;
    gap: .7rem;
    letter-spacing: -.015em;
    color: #fff;
}
.logo-icon {
    width: 46px;
    height: 46px;
    flex: 0 0 46px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px;
    border-radius: 14px;
    background: #fff;
    box-shadow:
        0 6px 16px rgba(0, 0, 0, .25),
        inset 0 0 0 1px rgba(var(--accent-rgb), .35),
        inset 0 0 0 4px #fff;
    position: relative;
    transition: transform .15s ease;
}
.logo-icon::before {
    /* Faint gold corner accent that hints at gilded artwork */
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 14px;
    background: linear-gradient(135deg, transparent 60%, rgba(var(--accent-rgb), .15) 100%);
    pointer-events: none;
}
.logo-icon:hover { transform: scale(1.05) rotate(-2deg); }
.logo-icon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
    border-radius: 9px;
}

/* Brand subtitle = "table X" + (optional) name. Sub-line under h4. */
.app-topbar .sub {
    margin-top: .55rem;
    margin-inline-start: 56px;     /* indent so it lines up under name, not logo */
    font-size: .78rem;
    opacity: .98;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .45rem;
}
/* Topbar chips — they sit on the dark forest-green gradient, so we need
   them to POP. The earlier dark-on-dark and dark-gold-on-dark combos
   blended into the bar. Now using a vivid amber #fbbf24 with very dark
   ink #1c1917 — contrast measures ~9:1 (well above WCAG AAA) and the
   bright amber catches the eye from across the room, which is the whole
   point of these chips for diners and waiters glancing at the QR session. */
/* Table pill — premium gold gloss. Brightest element on the bar so a
   waiter at any distance instantly knows which table this QR belongs to. */
.table-big {
    background: linear-gradient(180deg, #fde68a 0%, #f5b224 50%, #d97706 100%);
    color: #1c1917;
    padding: .45rem .9rem;
    border-radius: 12px;
    font-weight: 900;
    font-size: .85rem;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    box-shadow:
        0 6px 16px rgba(217, 119, 6, .35),
        inset 0 1px 0 rgba(255, 255, 255, .55),
        inset 0 -2px 0 rgba(0, 0, 0, .12);
    border: 1px solid rgba(217, 119, 6, .35);
    letter-spacing: 0;
    text-shadow: 0 1px 0 rgba(255, 255, 255, .35);
}
.table-big i { font-size: .9rem; }

/* Generic chip — frosted glass on the dark gradient. */
.chip {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .3rem .7rem;
    border-radius: 99px;
    background: rgba(255, 255, 255, .14);
    backdrop-filter: blur(6px);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, .18);
    font-size: .76rem;
    font-weight: 700;
    text-decoration: none;
    transition: all .15s ease;
}
.chip:hover { background: rgba(255, 255, 255, .22); color: #fff; transform: translateY(-1px); }

/* Order-tracking chip — gold accent, pulses to invite the tap when
   the diner has live orders in flight. */
.chip-orders {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #1c1917;
    padding: .42rem .85rem;
    font-weight: 800;
    border: 1px solid rgba(217, 119, 6, .35);
    box-shadow:
        0 6px 16px rgba(217, 119, 6, .3),
        inset 0 1px 0 rgba(255, 255, 255, .35);
    animation: pulse-accent 2.2s infinite;
}
.chip-orders:hover {
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    color: #1c1917;
    transform: translateY(-2px);
    box-shadow: 0 10px 22px rgba(217, 119, 6, .4);
}
.chip-badge {
    background: #fff;
    color: var(--brand-light);
    border-radius: 99px;
    min-width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .72rem;
    font-weight: 900;
    padding: 0 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, .15);
}
@keyframes pulse-accent {
    0%, 100% { box-shadow: 0 4px 12px rgba(0,0,0,.15), 0 0 0 0 rgba(217, 119, 6, .55); }
    50%      { box-shadow: 0 4px 12px rgba(0,0,0,.15), 0 0 0 10px rgba(217, 119, 6, 0); }
}

/* Hero — warm cream with olive-gold accents */
.hero { background: linear-gradient(180deg, #faf5eb 0%, #fffdf7 100%); padding: 1.5rem 1rem .75rem; text-align: center; border-bottom: 1px solid var(--border); position: relative; overflow: hidden; }
.hero::before { content: ''; position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(var(--accent-rgb), .18), transparent 70%); border-radius: 50%; }
.hero::after { content: ''; position: absolute; bottom: -50px; left: -50px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(var(--primary-rgb), .12), transparent 70%); border-radius: 50%; }
.hero h2 { font-weight: 900; margin: .5rem 0 .25rem; font-size: 1.35rem; color: var(--brand-dark); position: relative; z-index: 1; letter-spacing: -.02em; }
.hero .welcome-emoji { font-size: 2.4rem; position: relative; z-index: 1; display: inline-block; filter: drop-shadow(0 4px 8px rgba(var(--accent-rgb), .3)); }
.hero .subtitle { color: var(--muted); font-size: .85rem; margin: 0; position: relative; z-index: 1; font-weight: 500; }
/* Side toasts: pin to the bottom-end corner so they don't blanket the
   whole page width, stack vertically when multiple events fire close
   together, and slide in from the side instead of dropping in. */
.toast-stack {
    position: fixed;
    bottom: max(1rem, env(safe-area-inset-bottom));
    inset-inline-end: 1rem;
    z-index: 2000;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    pointer-events: none;
    max-width: min(360px, calc(100vw - 2rem));
}
.flash {
    pointer-events: auto;
    display: inline-flex;
    align-items: center;
    gap: .55rem;
    margin: 0;
    padding: .65rem .85rem;
    border-radius: 14px;
    color: #fff;
    font-weight: 700;
    font-size: .88rem;
    line-height: 1.35;
    background: var(--brand-dark);
    border-inline-start: 4px solid var(--accent);
    box-shadow: 0 14px 32px rgba(15, 45, 34, .22);
    animation: toast-slide-in .22s ease-out;
}
.flash .bi:first-child { font-size: 1rem; opacity: .9; }
.flash.is-leaving { animation: toast-slide-out .18s ease-in forwards; }
@keyframes toast-slide-in {
    from { opacity: 0; transform: translateX(20%); }
    to   { opacity: 1; transform: translateX(0); }
}
[dir="rtl"] @keyframes toast-slide-in {
    from { opacity: 0; transform: translateX(-20%); }
    to   { opacity: 1; transform: translateX(0); }
}
@keyframes toast-slide-out {
    to { opacity: 0; transform: translateY(8px); }
}
.cat-tabs { background: white; position: sticky; top: var(--topbar-h, 72px); z-index: 40; border-bottom: 1px solid var(--border); padding: .6rem 0; box-shadow: 0 2px 10px rgba(0,0,0,.04); }
/* Hide horizontal tabs on desktop — vertical sidebar takes over */
@media (min-width: 992px) {
    .cat-tabs { display: none; }
}

/* Menu layout: main + side (desktop) */
.menu-layout { max-width: 1400px; margin: 0 auto; }
@media (min-width: 992px) {
    .menu-layout { display: flex; gap: 1.5rem; padding: 0 1rem; align-items: flex-start; }
    .menu-main { flex: 1; min-width: 0; }
    .menu-aside { flex: 0 0 240px; position: sticky; top: calc(var(--topbar-h, 80px) + 12px); align-self: flex-start; max-height: calc(100vh - var(--topbar-h, 80px) - 24px); overflow-y: auto; }
}
.menu-aside { display: none; }
@media (min-width: 992px) { .menu-aside { display: block; } }

/* Side tabs — artistic touch */
/* Compact, quiet sidebar — was too busy (big icon boxes, big paddings,
   animated tip card). Stripped down to a clean vertical list. */
.side-tabs {
    background: #ffffff;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(var(--primary-rgb), .05);
    border: 1px solid rgba(var(--accent-rgb), .12);
    padding: .5rem 0;
    overflow: hidden;
}
.side-tabs::before { display: none; }           /* drop the 4-px gradient stripe */

.side-tabs-title {
    font-weight: 800;
    color: var(--brand-dark);
    padding: .75rem 1rem .5rem;
    font-size: .82rem;
    display: flex; align-items: center; gap: 6px;
    letter-spacing: 0;
}
.side-tabs-title::before { display: none; }
.side-tabs-title .title-sub {
    font-size: .68rem;
    font-weight: 600;
    color: #a8a29e;
    margin-inline-start: auto;
    padding: 2px 7px;
    background: rgba(var(--accent-rgb), .1);
    border-radius: 99px;
}

.side-tabs-divider {
    height: 1px;
    margin: 0 1rem 4px;
    background: rgba(var(--accent-rgb), .2);
    opacity: 1;
}

.side-tab-v {
    display: flex; align-items: center; gap: 10px;
    margin: 2px 6px;
    padding: 7px 10px;
    color: var(--brand-dark);
    text-decoration: none;
    font-weight: 700;
    font-size: .82rem;
    border-radius: 9px;
    transition: all .12s ease;
    position: relative;
}
.side-tab-v .tab-icon {
    width: 28px; height: 28px;
    border-radius: 7px;
    background: rgba(var(--accent-rgb), .1);
    color: var(--accent);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: .82rem;
    transition: all .15s;
}
.side-tab-v .tab-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.side-tab-v .count-bubble {
    background: rgba(var(--primary-rgb), .06);
    color: var(--brand-dark);
    border-radius: 99px;
    padding: 1px 7px;
    font-size: .65rem;
    font-weight: 700;
    min-width: 20px;
    text-align: center;
    transition: all .12s;
}
.side-tab-v:hover {
    background: rgba(var(--accent-rgb), .08);
    color: var(--brand-dark);
}
.side-tab-v:hover .tab-icon {
    background: var(--accent);
    color: white;
}
.side-tab-v.active {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    color: white;
    box-shadow: 0 3px 10px rgba(var(--primary-rgb), .2);
}
.side-tab-v.active::before { display: none; }   /* drop the decorative pip */
.side-tab-v.active .tab-icon {
    background: var(--accent);
    color: var(--brand-dark);
}
.side-tab-v.active .count-bubble {
    background: rgba(255, 255, 255, .2);
    color: white;
}

.side-tabs-footer {
    margin: .75rem 10px 0;
    padding: 1rem;
    background: linear-gradient(135deg, var(--accent-soft) 0%, #f9efd4 100%);
    border-radius: 14px;
    border: 1.5px dashed var(--accent);
    text-align: center;
    font-size: .78rem;
    color: var(--accent-dark);
    line-height: 1.6;
    position: relative;
    overflow: hidden;
}
.side-tabs-footer::before {
    content: '';
    position: absolute;
    top: -20px; left: -20px;
    width: 60px; height: 60px;
    background: radial-gradient(circle, rgba(var(--accent-rgb), .25), transparent 70%);
    border-radius: 50%;
}
.side-tabs-footer .tip-icon {
    font-size: 1.6rem;
    display: block;
    margin-bottom: 4px;
    animation: wiggle 3s infinite ease-in-out;
}
@keyframes wiggle {
    0%, 100% { transform: rotate(0); }
    25% { transform: rotate(-8deg); }
    75% { transform: rotate(8deg); }
}
.side-tabs-footer strong { color: var(--brand-dark); }
.cat-tabs::-webkit-scrollbar { display: none; }
.cat-tabs-scroll { display: flex; gap: 8px; overflow-x: auto; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; padding: 0 1rem; }
.cat-tabs-scroll::-webkit-scrollbar { display: none; }
.cat-tab { flex: 0 0 auto; padding: 10px 18px; border-radius: 99px; background: transparent; border: 1.5px solid var(--border); color: var(--muted); font-weight: 700; text-decoration: none; white-space: nowrap; font-size: .85rem; transition: all .2s; }
.cat-tab:hover { background: var(--brand-soft); color: var(--brand-dark); border-color: var(--brand-light); }
.cat-tab.active { background: var(--brand); color: white; box-shadow: 0 4px 12px rgba(var(--primary-rgb), .35); border-color: var(--brand); transform: scale(1.02); }

/* Menu grid (fallback for "view all" mode) */
.menu-grid { display: grid; gap: .6rem; grid-template-columns: 1fr 1fr; padding: .6rem; }
@media (min-width: 576px) { .menu-grid { grid-template-columns: 1fr 1fr 1fr; gap: .75rem; padding: .75rem; } }
@media (min-width: 992px) { .menu-grid { grid-template-columns: repeat(3, 1fr); } }

/* Horizontal slider per category. Arrows sit in reserved left/right "lanes"
   so they NEVER visually or click-wise overlap a card's + button. Before
   this, the left arrow was covering the + button of whichever card was
   scrolled to the visual-left edge — clicks went to the arrow, not the
   button, which felt like "the + button doesn't work sometimes". */
.menu-slider { position: relative; padding: .3rem 3rem .5rem; }
@media (max-width: 575.98px) {
    .menu-slider { padding: .3rem 2.25rem .5rem; }
}

.slider-track {
    display: flex;
    gap: .7rem;
    overflow-x: auto;
    /* `proximity` is gentler than `mandatory` — browser only snaps if the
       user is already close to an edge. */
    scroll-snap-type: x proximity;
    scroll-padding-inline: .25rem;
    -webkit-overflow-scrolling: touch;
    scroll-behavior: smooth;
    padding: 8px 2px 20px;
    scrollbar-width: none;
}
.slider-track::-webkit-scrollbar { display: none; }
.slider-track > .dish {
    flex: 0 0 calc(50% - .35rem);
    min-width: 0;
    scroll-snap-align: start;
}
@media (min-width: 576px) { .slider-track > .dish { flex: 0 0 calc(40% - .35rem); } }
@media (min-width: 992px) { .slider-track > .dish { flex: 0 0 260px; } }

/* Arrow nav — visible on ALL viewports (previously hidden on phones, so
   users without a mouse had no signal there was more to scroll). On phones
   they become smaller but still present for discoverability. */
.slider-arrow {
    position: absolute; top: 50%;
    transform: translateY(-50%);
    width: 42px; height: 42px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 6px 20px rgba(var(--primary-rgb), .22), 0 0 0 1px rgba(var(--primary-rgb), .08);
    border: 0;
    color: var(--brand-dark);
    font-size: 1.5rem;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 5;
    transition: background .18s ease, color .18s ease, box-shadow .18s ease, opacity .18s ease;
    line-height: 1;
    padding: 0;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
}
.slider-arrow:hover {
    background: var(--brand);
    color: #fff;
    box-shadow: 0 8px 26px rgba(var(--primary-rgb), .4);
}
.slider-arrow:active { background: var(--brand-dark); color: #fff; }
.slider-arrow:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
.slider-arrow:disabled { opacity: 0; pointer-events: none; }

/* Arrow positions — live in the reserved side padding on .menu-slider.
   In RTL, inline-start = visual RIGHT: PREV sits on visual RIGHT,
   NEXT on visual LEFT. */
.slider-arrow-prev { inset-inline-start: 4px; }
.slider-arrow-next { inset-inline-end: 4px; }

/* Small screens: smaller footprint but still present for discoverability */
@media (max-width: 575.98px) {
    .slider-arrow {
        width: 32px; height: 32px; font-size: 1.1rem;
        box-shadow: 0 3px 10px rgba(var(--primary-rgb), .18), 0 0 0 1px rgba(var(--primary-rgb), .06);
    }
    .slider-arrow-prev { inset-inline-start: 2px; }
    .slider-arrow-next { inset-inline-end: 2px; }
}

/* View mode toggle button */
.view-toggle {
    background: transparent;
    border: 1.5px solid var(--border);
    color: var(--brand-dark);
    padding: 4px 12px;
    border-radius: 99px;
    font-weight: 700;
    font-size: .75rem;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-right: auto;
    transition: all .15s;
}
.view-toggle:hover { background: var(--brand); color: white; border-color: var(--brand); }

/* Grid mode: reuse the slider DOM but render it as a wrapping grid.
   Saves rendering 75 menu items twice — items render once in .slider-track
   and CSS swaps the layout when .grid-mode is toggled on the section. */
.menu-section.grid-mode .menu-slider { padding-inline: .6rem; }
.menu-section.grid-mode .slider-arrow { display: none; }
.menu-section.grid-mode .slider-track {
    display: grid;
    grid-template-columns: 1fr 1fr;
    overflow: visible;
    scroll-snap-type: none;
    padding: .25rem 0;
}
.menu-section.grid-mode .slider-track > .dish {
    flex: initial;
    min-width: 0;
}
@media (min-width: 576px) {
    .menu-section.grid-mode .slider-track { grid-template-columns: 1fr 1fr 1fr; }
}
@media (min-width: 1280px) {
    .menu-section.grid-mode .slider-track { grid-template-columns: repeat(4, 1fr); }
}

/* Dish card — premium warm tone.
   NOTE: We deliberately DO NOT translate/scale the whole card on hover/active.
   Previously `.dish:hover { transform: translateY(-4px) }` shifted the card
   (and its + button) away from the cursor, causing clicks to miss the button
   and land on empty space. We now animate only the shadow + image, so the
   click target never moves. */
.dish { background: var(--card); border-radius: var(--radius); overflow: hidden; box-shadow: 0 2px 10px rgba(var(--primary-rgb), .06), 0 0 0 1px rgba(var(--primary-rgb), .04); transition: box-shadow .22s ease, border-color .22s ease; display: flex; flex-direction: column; cursor: pointer; position: relative; }
.dish:hover { box-shadow: 0 10px 28px rgba(var(--primary-rgb), .13), 0 0 0 1px rgba(var(--accent-rgb), .28); }
.dish.tap-pulse { animation: tap-pulse .4s ease; }
@keyframes tap-pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.03); } }
.dish-img { position: relative; width: 100%; aspect-ratio: 1/1; background: linear-gradient(135deg, var(--accent-soft), #f8f3e8); overflow: hidden; }
.dish-img img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .4s ease; }
.dish:hover .dish-img img { transform: scale(1.07); }
.badge-today { position: absolute; top: 8px; right: 8px; background: var(--gold-gradient); color: #3d2a0f; padding: 4px 12px; border-radius: 99px; font-size: .65rem; font-weight: 800; box-shadow: 0 3px 10px rgba(var(--accent-rgb), .45); display: flex; align-items: center; gap: 3px; letter-spacing: .3px; }
.badge-today::before { content: '⭐'; }
.badge-prep { position: absolute; bottom: 6px; right: 6px; background: rgba(0,0,0,.65); color: white; padding: 3px 8px; border-radius: 99px; font-size: .65rem; font-weight: 700; backdrop-filter: blur(6px); }
/* Promotion badge — eye-catching red so the discount stands out
   among the gold "featured" + black "prep time" pills. Sits on the
   top-left so it doesn't overlap with the today/featured pill on
   the right. */
.badge-promo { position: absolute; top: 8px; inset-inline-start: 8px; background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: #fff; padding: 4px 10px; border-radius: 99px; font-size: .68rem; font-weight: 800; box-shadow: 0 3px 10px rgba(239,68,68,.4); display: inline-flex; align-items: center; gap: 4px; letter-spacing: .3px; z-index: 2; }
/* Stacked old/new price layout — old price greyed out + struck-through,
   new price wears the discount-red so it visually echoes the badge. */
.dish-price-wrap { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; line-height: 1; }
.dish-price-old { font-size: .78rem; color: #9ca3af; text-decoration: line-through; font-weight: 600; }
.dish-price-new { color: #b91c1c !important; }
.badge-unavail { position: absolute; inset: 0; background: rgba(0,0,0,.65); color: white; display: flex; align-items: center; justify-content: center; font-weight: 800; text-align: center; font-size: .85rem; }

/* + button — generous tap target, stable (no transform on hover so the
   click target doesn't move under the cursor). Relative positioning +
   z-index ensures it sits above the card's click surface. */
.dish-add-fab {
    background: var(--brand); color: white; border: 0; border-radius: 50%;
    width: 40px; height: 40px;
    display: inline-flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(var(--primary-rgb), .35);
    font-size: 1.35rem; font-weight: 900; line-height: 1;
    transition: background .18s ease, box-shadow .18s ease;
    flex-shrink: 0; position: relative; z-index: 2;
    cursor: pointer;
}
.dish-add-fab:hover { background: var(--brand-dark); box-shadow: 0 6px 18px rgba(var(--primary-rgb), .5); }
.dish-add-fab:active { background: var(--brand-dark); box-shadow: 0 2px 6px rgba(var(--primary-rgb), .35); }

/* Inline stepper on card - replaces + after first add */
.dish-stepper { background: var(--brand); color: white; border: 0; border-radius: 99px; display: inline-flex; align-items: center; box-shadow: 0 4px 14px rgba(var(--primary-rgb), .4); overflow: hidden; animation: pop-in .35s cubic-bezier(.34,1.56,.64,1); flex-shrink: 0; position: relative; z-index: 2; }
.dish-stepper button { background: transparent; border: 0; color: white; width: 34px; height: 40px; font-weight: 900; font-size: 1.2rem; transition: background .15s; cursor: pointer; }
.dish-stepper button:hover { background: rgba(255,255,255,.2); }
.dish-stepper button:active { background: rgba(0,0,0,.2); transform: scale(.9); }
.dish-stepper .qty { color: white; padding: 0 8px; font-weight: 900; font-size: .95rem; min-width: 22px; text-align: center; }
@keyframes pop-in {
    0% { transform: scale(.3) rotate(-8deg); opacity: 0; }
    60% { transform: scale(1.15) rotate(3deg); opacity: 1; }
    100% { transform: scale(1) rotate(0); opacity: 1; }
}

/* Inline notes input on card */
.dish-notes { width: 100%; background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 10px; padding: 6px 10px; font-size: .75rem; color: var(--ink); margin-top: 6px; transition: all .15s; font-family: inherit; }
.dish-notes:focus { outline: none; border-color: var(--brand); border-style: solid; background: white; box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .15); }
.dish-notes.has-value { border-color: var(--brand); border-style: solid; background: var(--brand-soft); color: var(--brand-dark); font-weight: 600; }
.dish-notes::placeholder { color: #9ca3af; font-size: .7rem; }

.dish-body { padding: 1rem .75rem .75rem; flex: 1; display: flex; flex-direction: column; }
.dish-name { font-weight: 800; font-size: .95rem; line-height: 1.25; margin: 0 0 3px; color: var(--ink); }
.dish-desc { font-size: .75rem; color: var(--muted); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin: 0 0 6px; }
.dish-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; padding-top: 8px; }
.dish-price { font-weight: 900; color: var(--accent-dark); font-size: 1.1rem; letter-spacing: -.02em; }

.allergens { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin: 6px 0; padding: 5px 8px; background: rgba(249, 168, 37, .08); border: 1px solid rgba(249, 168, 37, .25); border-radius: 8px; }
.allergens-label { display: inline-flex; align-items: center; gap: 4px; font-size: .68rem; font-weight: 700; color: #92400e; width: 100%; margin-bottom: 2px; }
.allergens-label i { font-size: .75rem; color: #d97706; }
.allergen-chip { background: var(--gold-soft); color: #92400e; padding: 1px 7px; border-radius: 99px; font-size: .65rem; font-weight: 600; }

.cat-section { padding: 0 1rem; }
.cat-title { font-size: 1.1rem; font-weight: 900; margin: 1.25rem 0 .5rem; display: flex; align-items: center; gap: .5rem; color: var(--brand-dark); letter-spacing: -.01em; }
.cat-title .bar { width: 4px; height: 22px; border-radius: 2px; background: var(--accent); }
.cat-title .count { background: var(--accent-soft); color: var(--accent-dark); padding: 2px 10px; border-radius: 99px; font-size: .7rem; font-weight: 700; }

/* Fly-to-cart animation */
@keyframes fly-to-cart {
    0% { opacity: 1; transform: scale(1); }
    70% { opacity: .8; }
    100% { opacity: 0; transform: scale(.2); }
}
.fly-ghost { position: fixed; pointer-events: none; border-radius: 50%; z-index: 999; transition: all .7s cubic-bezier(.5,0,.5,1.5); background-size: cover; background-position: center; width: 60px; height: 60px; box-shadow: 0 8px 24px rgba(0,0,0,.25); border: 3px solid white; }

/* FAB Cart.
   IMPORTANT: this bar is ~60px tall and, when visible, would cover the +
   button of any dish card that happens to be in the bottom strip of the
   viewport — the click would land on the FAB instead of the button.
   We solve this by auto-hiding the FAB while the user is scrolling DOWN
   (they're browsing the menu, cart is not in the way), showing it again
   when they scroll up or stop. While hidden the FAB also sets
   pointer-events:none so clicks fall through to dish cards. Script is in
   menu.blade.php. */
.cart-fab {
    position: fixed; bottom: calc(1rem + env(safe-area-inset-bottom));
    left: 1rem; right: 1rem;
    background: var(--brand-gradient); color: white; border-radius: 16px;
    padding: 14px 18px; font-weight: 800;
    box-shadow: 0 12px 32px rgba(var(--primary-rgb), .45);
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px; border: 2px solid rgba(var(--accent-rgb), .7);
    z-index: 45; font-size: .95rem;
    transition: transform .28s cubic-bezier(.34,1.56,.64,1),
                box-shadow .28s, opacity .22s ease;
}
.cart-fab:hover { box-shadow: 0 16px 40px rgba(var(--primary-rgb), .55); border-color: var(--accent); }
.cart-fab:active { opacity: .95; }
/* Auto-hide while browsing. Critical: pointer-events:none so that while
   the FAB is sliding out, the dish + button UNDER IT is immediately
   clickable. Without this the bar would still swallow clicks during the
   200 ms transition. */
.cart-fab.is-hidden {
    transform: translateY(calc(100% + 2rem));
    opacity: 0;
    pointer-events: none;
}
.cart-fab-left { display: flex; align-items: center; gap: 10px; }
.cart-fab .count { background: var(--accent); color: var(--brand-dark); border-radius: 99px; min-width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; transition: transform .25s; }
.cart-fab-right { display: flex; align-items: center; gap: 6px; }
@media (min-width: 576px) {
    .cart-fab { left: auto; right: 1.5rem; min-width: 280px; }
}

/* Cart shake animation when item added */
@keyframes cart-bump {
    0%, 100% { transform: translateY(0) scale(1); }
    20% { transform: translateY(-8px) scale(1.06) rotate(-1.5deg); }
    45% { transform: translateY(-4px) scale(1.03) rotate(1.5deg); }
    70% { transform: translateY(-2px) scale(1.02) rotate(-.5deg); }
}
.cart-fab.bump { animation: cart-bump .55s cubic-bezier(.34,1.56,.64,1); }
@keyframes count-pop {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.4); }
}
.cart-fab.bump .count { animation: count-pop .4s; }

/* Bottom Sheet / Modal (responsive) */
.sheet-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 100; opacity: 0; pointer-events: none; transition: opacity .25s; backdrop-filter: blur(4px); }
.sheet-overlay.open { opacity: 1; pointer-events: auto; }

/* Mobile: bottom sheet.
   CRITICAL: pointer-events:none when closed. Without it, on desktop the
   invisible (opacity:0) centered sheet still intercepts every click in the
   middle 520×950 px of the viewport — which is exactly where most of the
   menu's + buttons sit. Cursor shows default, clicks vanish, user thinks
   the buttons are broken. This single rule is the real fix for "after I
   scroll a bit the + button stops working". */
.sheet {
    position: fixed; left: 0; right: 0; bottom: 0;
    background: white; border-radius: 22px 22px 0 0;
    z-index: 110; max-height: 85vh;
    transform: translateY(100%);
    transition: transform .35s cubic-bezier(.18,.85,.3,1);
    display: flex; flex-direction: column;
    padding-bottom: env(safe-area-inset-bottom);
    box-shadow: 0 -20px 60px rgba(0,0,0,.25);
    pointer-events: none;            /* ← blocks the ghost-click bug */
}
.sheet.open { transform: translateY(0); pointer-events: auto; }

/* Desktop: centered modal */
@media (min-width: 640px) {
    .sheet {
        left: 50%; right: auto; top: 50%; bottom: auto;
        max-width: 520px; width: calc(100% - 2rem);
        border-radius: 20px;
        max-height: 88vh;
        transform: translate(-50%, -50%) scale(.92);
        opacity: 0;
        transition: transform .28s cubic-bezier(.18,.85,.3,1), opacity .22s;
        box-shadow: 0 20px 60px rgba(0,0,0,.4);
        /* pointer-events:none inherited from the base .sheet rule above —
           no override needed. The .open state re-enables them. */
    }
    .sheet.open { transform: translate(-50%, -50%) scale(1); opacity: 1; pointer-events: auto; }
}

.sheet-handle { width: 48px; height: 5px; background: #d1d5db; border-radius: 99px; margin: 10px auto 4px; flex-shrink: 0; }
@media (min-width: 640px) { .sheet-handle { display: none; } }

.sheet-header { padding: .75rem 1rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; background: white; flex-shrink: 0; position: sticky; top: 0; z-index: 2; }
.sheet-header h5 { margin: 0; font-weight: 800; font-size: 1.05rem; line-height: 1.3; padding-left: 0.5rem; }
.sheet-header .btn-close { background-color: #f3f4f6; border-radius: 50%; padding: 10px; opacity: .7; transition: all .15s; flex-shrink: 0; width: 32px; height: 32px; }
.sheet-header .btn-close:hover { opacity: 1; background-color: #e5e7eb; }

.sheet-body { overflow-y: auto; padding: 1rem; flex: 1; -webkit-overflow-scrolling: touch; }
.sheet-footer { padding: 1rem; border-top: 1px solid var(--border); background: white; flex-shrink: 0; }
.sheet-addmore { background: var(--brand-soft); color: var(--brand-dark); border: 2px dashed var(--brand); border-radius: 12px; padding: 10px; font-weight: 700; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 1rem; transition: all .15s; }
.sheet-addmore:hover { background: var(--brand); color: white; }
.sheet-addmore:active { transform: scale(.98); }

/* Item detail image wrapper */
.item-img-wrap { position: relative; border-radius: 14px; overflow: hidden; margin-bottom: 1rem; background: linear-gradient(135deg, #fef3c7, #fde68a); height: 200px; }
@media (min-width: 640px) { .item-img-wrap { height: 220px; } }
.item-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.item-img-wrap .price-tag { position: absolute; bottom: 10px; left: 10px; background: var(--brand-gradient); color: white; padding: 6px 14px; border-radius: 99px; font-weight: 900; font-size: 1rem; box-shadow: 0 4px 14px rgba(var(--primary-rgb), .4); border: 2px solid var(--accent); }
.item-img-wrap .featured-tag { position: absolute; top: 10px; right: 10px; background: var(--gold-gradient); color: #78350f; padding: 4px 12px; border-radius: 99px; font-weight: 800; font-size: .75rem; box-shadow: 0 3px 10px rgba(234,179,8,.5); }

.section-label { font-weight: 800; color: var(--brand-dark); font-size: .95rem; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
.section-label .hint { color: var(--muted); font-size: .72rem; font-weight: 500; }
.btn:not(.btn-close),
.btn-brand,
.btn-send,
.btn-ghost {
    max-width: 100%;
    line-height: 1.25;
    white-space: normal;
    text-align: center;
}
.btn:not(.btn-close) i,
.btn-brand i,
.btn-send i,
.btn-ghost i {
    flex: 0 0 auto;
}
.btn-brand { background: var(--brand); color: white; border: 0; border-radius: 12px; padding: 14px; font-weight: 800; font-size: 1rem; width: 100%; transition: all .2s; }
.btn-brand:active { background: var(--brand-dark); transform: scale(.98); }
.btn-brand:hover { background: var(--brand-dark); color: white; }
.btn-send { background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 50%, var(--accent) 130%); color: white; border: 0; border-radius: 12px; padding: 16px; font-weight: 800; font-size: 1.05rem; width: 100%; box-shadow: 0 8px 24px rgba(var(--primary-rgb), .4); transition: all .2s; }
.btn-send:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(var(--primary-rgb), .5); color: white; }
.btn-send:active { transform: translateY(0); }
.btn-ghost { background: #f3f4f6; color: #374151; border: 0; border-radius: 12px; padding: 10px; font-weight: 600; }

/* Qty stepper */
.stepper { display: inline-flex; align-items: center; background: #f3f4f6; border-radius: 99px; padding: 3px; }
.stepper button {
    background: white; border: 0;
    width: 34px; height: 34px;
    border-radius: 50%;
    font-weight: 800;
    color: var(--brand);
    box-shadow: 0 1px 3px rgba(0,0,0,.1);
    transition: background .15s, transform .1s;
}
.stepper button:hover:not(:disabled) { background: var(--accent-soft); color: var(--accent-dark); }
.stepper button:active:not(:disabled) { transform: scale(.92); }
.stepper button:disabled { opacity: .35; cursor: not-allowed; }
.stepper input { background: transparent; border: 0; text-align: center; width: 40px; font-weight: 800; font-size: 1rem; color: var(--brand-dark); }
.stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

/* Compact variant — used inside cart rows so it doesn't dominate the row.
   Keeps the same tap targets (critical for mobile) but shrinks visual weight. */
.stepper.stepper-sm { padding: 2px; }
.stepper.stepper-sm button {
    width: 30px; height: 30px;
    font-size: .95rem;
    box-shadow: 0 1px 2px rgba(0,0,0,.08);
}
.stepper.stepper-sm input { width: 34px; font-size: .92rem; }

/* Modifier option */
.mod-opt { display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 2px solid var(--border); border-radius: 12px; margin-bottom: 6px; cursor: pointer; transition: all .15s; }
.mod-opt:has(input:checked) { border-color: var(--brand); background: var(--brand-soft); }
.mod-opt input { accent-color: var(--brand); width: 20px; height: 20px; }
.mod-opt .price { color: var(--success); font-weight: 700; font-size: .85rem; }

/* Cart items */
.cart-item { display: flex; gap: 12px; padding: 14px 0; border-bottom: 1px solid var(--border); }
.cart-item:last-child { border-bottom: 0; }
.cart-item img { width: 72px; height: 72px; border-radius: 12px; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,.08); flex-shrink: 0; }
.cart-item .mods { font-size: .75rem; color: var(--muted); margin-top: 2px; }
.cart-item-notes { width: 100%; background: #fffbeb; border: 1px dashed var(--warning); border-radius: 10px; padding: 6px 10px; font-size: .8rem; color: #78350f; margin-top: 6px; transition: all .15s; font-family: inherit; }
.cart-item-notes:focus { outline: none; border-style: solid; background: white; box-shadow: 0 0 0 3px rgba(234,179,8,.15); border-color: var(--accent-dark); }
.cart-item-notes.has-value { background: var(--gold-soft); border-style: solid; font-weight: 600; }
.cart-item-notes::placeholder { color: #ca8a04; font-size: .72rem; opacity: .7; }

/* Submit confirmation overlay */
.confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 130; display: flex; align-items: center; justify-content: center; padding: 1rem; opacity: 0; pointer-events: none; transition: opacity .2s; }
.confirm-overlay.open { opacity: 1; pointer-events: auto; }
.confirm-box { background: white; border-radius: 20px; padding: 1.5rem; max-width: 380px; width: 100%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.3); transform: scale(.9); transition: transform .2s; border-top: 4px solid var(--accent); }
.confirm-overlay.open .confirm-box { transform: scale(1); }
.confirm-box h4 { font-weight: 900; margin-bottom: 8px; color: var(--brand-dark); }
.confirm-box .emoji { font-size: 3rem; margin-bottom: 8px; }
.confirm-box .d-flex:has(.btn),
.modal-footer {
    flex-wrap: wrap;
    gap: .5rem;
    overflow: visible;
}
.modal-content:has(.modal-footer) {
    overflow: visible;
}
.sheet-footer,
.menu-hero-actions,
.menu-guest-promo-actions,
.cart-account-actions,
.track-signup-actions,
.track-actions {
    min-width: 0;
    overflow: visible;
}
.sheet-footer:has(.btn),
.sheet-footer:has(.btn-brand),
.sheet-footer:has(.btn-send),
.menu-hero-actions,
.menu-guest-promo-actions,
.cart-account-actions,
.track-signup-actions,
.track-actions,
.modal-footer {
    display: flex;
    flex-wrap: wrap !important;
    gap: .5rem;
}
.track-signup-actions form,
.track-actions form,
.modal-footer form {
    min-width: 0;
}
.track-signup-actions button,
.track-actions button,
.modal-footer .btn-track-cancel {
    max-width: 100%;
    white-space: normal;
    line-height: 1.25;
    text-align: center;
}
@media (max-width: 480px) {
    .confirm-box .d-flex:has(.btn) {
        flex-direction: column;
        align-items: stretch;
    }
    .confirm-box .d-flex:has(.btn) > .btn,
    .confirm-box .d-flex:has(.btn) > .btn-send,
    .modal-footer .btn,
    .modal-footer .btn-track-cancel,
    .track-signup-actions form,
    .track-signup-actions button,
    .track-actions button,
    .cart-account-actions > *,
    .menu-guest-promo-actions > * {
        width: 100%;
        flex: 1 1 100% !important;
    }
}

/* Skeleton */
.empty-state { text-align: center; padding: 3rem 1rem; color: var(--muted); }
.empty-state i { font-size: 4rem; opacity: .3; }

@keyframes pulse-brand { 0%, 100% { box-shadow: 0 0 0 0 rgba(var(--primary-rgb), .5); } 50% { box-shadow: 0 0 0 12px rgba(var(--primary-rgb), 0); } }
.pulse { animation: pulse-brand 1.8s infinite; }


/* ═══════════════════════════════════════════════════════════════════════
   RESPONSIVE OVERRIDES — mobile (<576px) + tablet (576-991px)
   ═══════════════════════════════════════════════════════════════════════ */

/* Global: always reserve bottom space for the fixed cart FAB on mobile/tablet
   (prevents it from covering the last dish row). */
body { padding-bottom: calc(96px + env(safe-area-inset-bottom)); }
@media (min-width: 992px) {
    body { padding-bottom: env(safe-area-inset-bottom); }
}

/* ── PHONES (<576px) ───────────────────────────────────────────────── */
@media (max-width: 575.98px) {
    /* Topbar — shrink padding + typography so it's ~64px not 90px */
    .app-topbar { padding: .55rem .75rem; }
    .app-topbar h4 { font-size: 1rem; gap: 6px; }
    .app-topbar .sub { font-size: .7rem; }
    .logo-icon { width: 28px; height: 28px; }
    .logo-icon svg { width: 16px; height: 16px; }
    .logo-icon img { border-radius: 7px; }
    .table-big { padding: 2px 8px; font-size: .72rem; border-radius: 8px; }
    .chip { padding: 3px 8px; font-size: .7rem; }
    .chip-orders { padding: 4px 10px; font-size: .7rem; }
    .chip-badge { min-width: 16px; height: 16px; font-size: .62rem; }

    /* Hero — cut vertical space roughly 40% */
    .hero { padding: .85rem .75rem .5rem; }
    .hero h2 { font-size: 1.1rem; margin: .25rem 0 .1rem; }
    .hero .welcome-emoji { font-size: 1.9rem; }
    .hero .subtitle { font-size: .78rem; }
    .hero::before, .hero::after { width: 100px; height: 100px; }

    /* Category tabs — compact chips */
    .cat-tabs { padding: .45rem 0; }
    .cat-tab { padding: 5px 12px; font-size: .78rem; }
    .cat-tabs-scroll { gap: 6px; padding: 0 .75rem; }

    /* Section title (per category) — smaller */
    .cat-section { padding: .6rem .75rem .2rem; }
    .cat-title { font-size: .95rem; }
    .cat-title .count { font-size: .65rem; padding: 1px 7px; }
    .view-toggle { font-size: .7rem; padding: 3px 9px; }

    /* Dish cards — tighten to maximize content visible */
    .menu-grid { gap: .5rem; padding: .5rem; }
    .dish-body { padding: .6rem .55rem .55rem; }
    .dish-name { font-size: .85rem; line-height: 1.2; margin-bottom: 2px; }
    .dish-desc { font-size: .66rem; -webkit-line-clamp: 2; }
    .dish-price { font-size: .95rem; }
    .dish-foot { padding-top: 6px; gap: 6px; }
    .dish-add-fab { width: 36px; height: 36px; font-size: 1.2rem; }
    .dish-stepper button { width: 32px; height: 36px; font-size: 1rem; }
    .dish-stepper .qty { padding: 0 6px; font-size: .85rem; min-width: 18px; }
    .badge-today, .badge-prep, .badge-options { font-size: .6rem; padding: 2px 6px; }

    /* Slider track — show ~1.8 cards so user sees there's MORE to swipe */
    .slider-track > .dish { flex: 0 0 calc(55% - .35rem); }

    /* Cart FAB — smaller + less obtrusive */
    .cart-fab {
        padding: 11px 14px;
        font-size: .88rem;
        bottom: calc(.75rem + env(safe-area-inset-bottom));
        left: .75rem; right: .75rem;
        border-radius: 14px;
    }
    .cart-fab .count { min-width: 24px; height: 24px; font-size: .8rem; }

    /* Sheet modals — take full viewport on phone (was 85vh) */
    .sheet { max-height: 92vh; border-radius: 18px 18px 0 0; }
    .sheet-body { padding: 0 .85rem 1rem; }
    .sheet-header { padding: .75rem .85rem; }
    .sheet-footer { padding: .75rem .85rem calc(.75rem + env(safe-area-inset-bottom)); }
    .sheet-header h5 { font-size: 1rem; }

    /* Modifier option rows — slightly denser */
    .mod-opt { padding: 8px 10px; font-size: .88rem; }

    /* Dish image on mobile — slightly shorter so 2 rows fit above fold */
    .dish-img { aspect-ratio: 4/3; }
}

/* ── TABLETS (576-991px) ───────────────────────────────────────────── */
@media (min-width: 576px) and (max-width: 991.98px) {
    /* 3 columns on tablet feels right for dish cards */
    .menu-grid { grid-template-columns: repeat(3, 1fr); }

    /* Slider shows 3 cards fully visible + hint of 4th */
    .slider-track > .dish { flex: 0 0 calc(33% - .5rem); }

    /* Topbar still compact — don't let the h4 wrap */
    .app-topbar h4 { font-size: 1.15rem; }

    /* Hero balanced — not as tall as desktop */
    .hero { padding: 1.1rem 1rem .6rem; }
    .hero h2 { font-size: 1.25rem; }
}

/* ── Orientation: landscape phone (very short viewport) ──────────── */
@media (max-height: 500px) and (orientation: landscape) {
    .hero { padding: .5rem .75rem .4rem; }
    .hero .welcome-emoji { font-size: 1.5rem; }
    .hero h2 { font-size: 1rem; margin: 0 0 .1rem; }
    .hero .subtitle { font-size: .7rem; }
}

/* ── Unavailable item: dim, non-interactive, clear visual cue ────────── */
.dish.is-unavailable {
    opacity: .55;
    filter: grayscale(.4);
    cursor: not-allowed;
    pointer-events: auto;                       /* keep the overlay interactive-feeling */
}
.dish.is-unavailable:hover {
    transform: none;
    box-shadow: 0 2px 10px rgba(var(--primary-rgb), .06);  /* no lift on hover */
}
.dish.is-unavailable .dish-img img { transform: none; }
.dish-unavail-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px; height: 40px;
    border-radius: 50%;
    background: #e7e5e4;
    color: #78716c;
    font-size: 1.15rem;
    cursor: not-allowed;
    flex-shrink: 0;
}

/* Optimistic-update: mildly dim stepper+qty while server is syncing (rare, <300ms usually) */
.dish-stepper.is-pending { opacity: .75; }
.dish-stepper.is-pending .qty::after {
    content: '…';
    display: inline-block;
    margin-inline-start: 2px;
    animation: dot-pulse 1s infinite;
    opacity: .6;
}
@keyframes dot-pulse { 0%,100% { opacity:.6; } 50% { opacity:.1; } }

/* Improved "unavailable" overlay (more readable) */
.badge-unavail {
    backdrop-filter: blur(2px);
    background: rgba(0,0,0,.55);
    font-size: 1rem;
    letter-spacing: .02em;
    gap: 6px;
}
.badge-unavail i { font-size: 1.3rem; }

/* ── "has options" hint badge on the image (gold) ────────────────────── */
.badge-options {
    position: absolute;
    bottom: 8px;
    inset-inline-start: 8px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
    color: #fff;
    border-radius: 99px;
    font-size: .68rem;
    font-weight: 800;
    box-shadow: 0 2px 6px rgba(var(--accent-rgb), .35);
    backdrop-filter: blur(4px);
    z-index: 2;
}
.badge-options i { font-size: .7rem; }

/* + button variant for items with modifiers — different icon + subtle glow */
.dish-add-fab.has-mods {
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
    box-shadow: 0 4px 14px rgba(var(--accent-rgb), .45);
}
.dish-add-fab.has-mods:hover {
    background: linear-gradient(135deg, #a57726 0%, var(--accent) 100%);
    box-shadow: 0 6px 18px rgba(var(--accent-rgb), .55);
}
.dish-add-fab.has-mods i { font-size: 1rem; }

/* Modifier modal polish — make "required" tag more prominent */
.sheet .section-label .badge.bg-danger-subtle {
    background: linear-gradient(135deg, #dc3545 0%, #c4322f 100%) !important;
    color: #fff !important;
    padding: 2px 8px !important;
    border-radius: 99px !important;
    font-size: .62rem !important;
    font-weight: 800 !important;
    box-shadow: 0 1px 4px rgba(220, 53, 69, .3);
}

/* Modifier option label — better hover/selected feedback */
.mod-opt {
    transition: all .12s ease;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 4px;
    background: #fafaf7;
    border: 2px solid transparent;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.mod-opt:hover {
    background: #fff;
    border-color: rgba(var(--accent-rgb), .25);
}
.mod-opt:has(input:checked) {
    background: rgba(var(--accent-rgb), .1);
    border-color: var(--accent, #b8872a);
}
.mod-opt input[type="radio"],
.mod-opt input[type="checkbox"] {
    accent-color: var(--accent, #b8872a);
    width: 18px; height: 18px;
    cursor: pointer;
}
.mod-opt .price {
    font-weight: 800;
    color: var(--brand-dark);
    font-size: .85rem;
}
</style>
@include('partials.runtime-theme', ['theme' => $theme])
@stack('styles')
</head>
<body>

@php
    $brandLogoUrl = \App\Helpers\Brand::logoUrl();
    $activeOrdersCount = 0;
    try {
        $activeOrdersCount = \App\Models\Order::where('table_session_id', $session->id)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->count();
    } catch (\Throwable $e) {}
@endphp
<div class="app-topbar" id="appTopbar">
    <div class="topbar-row">
        <div class="topbar-brand">
            <h4>
                <span class="logo-icon">
                    <img src="{{ $brandLogoUrl }}" alt="{{ $brandName }}" loading="eager">
                </span>
                <span class="brand-text">
                    <span class="brand-text__name">{{ $brandName }}</span>
                    <span class="brand-text__tag"><i class="bi bi-geo-alt-fill"></i> {{ $session->table?->branch?->localizedName() ?: __('ui.customer_menu.branch_default') }}</span>
                </span>
            </h4>
            <div class="sub">
                <span class="table-big"><i class="bi bi-grid-3x3-gap-fill"></i> {{ __('ui.customer_menu.table_short', ['number' => $session->table->number ?? '—']) }}</span>
                @if($session->customer_name)
                    <span class="chip"><i class="bi bi-person-circle"></i> {{ $session->customer_name }}</span>
                @endif
            </div>
        </div>
        <div class="topbar-actions">
            {{-- Currency switcher — hidden by default. Enable via config
                 `restaurant.customer.currency_switcher` when you need
                 multi-currency UX. --}}
            @if(\App\Models\Setting::get('customer_currency_switcher', config('restaurant.customer.currency_switcher', false)))
                @php
                    $curSvc = app(\App\Services\CurrencyService::class);
                    $activeCurrencies = $curSvc->active();
                    $currentCurrency = $curSvc->current();
                @endphp
                @if($activeCurrencies->count() > 1)
                    <div class="dropdown">
                        <button class="chip" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="background: rgba(255,255,255,.12);">
                            <i class="bi bi-currency-exchange"></i>
                            {{ $currentCurrency->code }}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="min-width: 200px;">
                            <li class="dropdown-header small">{{ __('ui.customer_menu.choose_currency') }}</li>
                            @foreach($activeCurrencies as $cur)
                                <li>
                                    <form method="POST" action="{{ route('customer.currency.switch') }}" class="m-0">
                                        @csrf
                                        <input type="hidden" name="code" value="{{ $cur->code }}">
                                        <button class="dropdown-item d-flex justify-content-between align-items-center {{ $cur->id === $currentCurrency->id ? 'active' : '' }}" type="submit">
                                            <span>{{ $cur->name }}</span>
                                            <span class="text-muted fw-bold">{{ $cur->code }} {{ $cur->symbol }}</span>
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif

            @if($activeOrdersCount > 0)
                <a href="{{ route('customer.track') }}" class="chip chip-orders" title="{{ __('ui.customer_menu.track_order') }}">
                    <i class="bi bi-receipt-cutoff"></i>
                    {{ __('ui.customer_menu.track_order') }}
                    <span class="chip-badge">{{ $activeOrdersCount }}</span>
                </a>
            @endif
        </div>
    </div>
</div>

<style>
    /* ── Topbar layout polish ──────────────────────── */
    .topbar-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
    }
    .topbar-brand { min-width: 0; flex-grow: 1; }
    .topbar-actions {
        display: flex;
        align-items: center;
        gap: .5rem;
        flex-shrink: 0;
        padding-top: 4px;
    }
    .brand-text {
        display: flex;
        flex-direction: column;
        min-width: 0;
        line-height: 1.1;
    }
    .brand-text__name {
        display: block;
        font-size: clamp(1.1rem, 2.8vw, 1.3rem);
        font-weight: 900;
        letter-spacing: -.015em;
    }
    .brand-text__tag {
        display: inline-flex;
        align-items: center;
        gap: .2rem;
        font-size: .68rem;
        font-weight: 700;
        opacity: .85;
        margin-top: 2px;
        color: rgba(255, 255, 255, .8);
    }
    .brand-text__tag i { color: rgba(var(--accent-rgb), .9); font-size: .7rem; }

    @media (max-width: 480px) {
        .app-topbar .sub { margin-inline-start: 56px; }
        .topbar-actions { flex-direction: column-reverse; gap: .35rem; }
    }
</style>

<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true">
@if(session('success'))<div class="flash" data-tone="success"><i class="bi bi-check-circle-fill"></i><span>{{ session('success') }}</span></div>@endif
@if(session('error'))<div class="flash" data-tone="danger" style="background:#7f1d1d"><i class="bi bi-exclamation-triangle-fill"></i><span>{{ session('error') }}</span></div>@endif
</div>

<main>@yield('content')</main>

<script src="{{ asset('assets/dashtic/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script>
setTimeout(() => {
    document.querySelectorAll('.flash').forEach(a => {
        a.classList.add('is-leaving');
        setTimeout(() => a.remove(), 200);
    });
}, 4000);
</script>
<script>
// Measure topbar height dynamically and expose as CSS var so cat-tabs stick right under it.
(function() {
    function updateTopbarHeight() {
        const bar = document.getElementById('appTopbar');
        if (! bar) return;
        const h = bar.getBoundingClientRect().height;
        document.documentElement.style.setProperty('--topbar-h', h + 'px');
    }
    updateTopbarHeight();
    window.addEventListener('resize', updateTopbarHeight);
    window.addEventListener('orientationchange', updateTopbarHeight);
    // Also re-measure after fonts load (may change heights)
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(updateTopbarHeight);
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const sessionToken = @json($session->token ?? null);
    const customerLayoutI18n = @json(__('ui.customer_menu'));
    const t = (key, replacements = {}) => {
        let text = customerLayoutI18n[key] || key;
        Object.entries(replacements).forEach(([name, value]) => {
            text = text.replaceAll(':' + name, value);
        });
        return text;
    };
    if (! sessionToken) return;
    const wait = setInterval(() => {
        if (window.Echo) {
            clearInterval(wait);
            window.Echo.channel('session.' + sessionToken)
                .listen('.order.status_changed', (e) => {
                    showToast(t('order_status_changed', { number: e.order_number || '', status: e.status_label }), 'info');
                    if (window.location.pathname.includes('/track')) setTimeout(()=>location.reload(), 800);
                })
                .listen('.item.status_changed', (e) => {
                    if (e.status === 'ready') showToast(t('item_ready', { name: e.name }), 'success');
                    else if (e.status === 'preparing') showToast(t('item_preparing', { name: e.name }), 'info');
                    if (window.location.pathname.includes('/track')) setTimeout(()=>location.reload(), 800);
                })
                .listen('.invoice.paid', () => {
                    showToast(t('payment_success'), 'success');
                });
        }
    }, 100);
});
function showToast(text, type = 'info') {
    const themeStyles = getComputedStyle(document.documentElement);
    const brandToast = themeStyles.getPropertyValue('--brand').trim() || '#1f4733';
    const accentToast = themeStyles.getPropertyValue('--accent-dark').trim()
        || themeStyles.getPropertyValue('--accent').trim()
        || '#92590f';
    const tones = {
        success: { bg: brandToast, icon: 'bi-check-circle-fill' },
        info:    { bg: brandToast, icon: 'bi-info-circle-fill' },
        warning: { bg: accentToast, icon: 'bi-exclamation-triangle-fill' },
        danger:  { bg: '#7f1d1d', icon: 'bi-x-octagon-fill' },
    };
    const tone = tones[type] || tones.info;
    let stack = document.getElementById('toastStack');
    if (! stack) {
        stack = document.createElement('div');
        stack.id = 'toastStack';
        stack.className = 'toast-stack';
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
    }
    const div = document.createElement('div');
    div.className = 'flash';
    div.dataset.tone = type;
    div.style.background = tone.bg;
    div.innerHTML = `<i class="bi ${tone.icon}"></i><span></span>`;
    div.lastElementChild.textContent = text;
    stack.appendChild(div);
    // Animate out then remove so the slide-out transition is visible.
    setTimeout(() => {
        div.classList.add('is-leaving');
        setTimeout(() => div.remove(), 200);
    }, 3200);
}
</script>
@livewireScripts
<script src="{{ asset('assets/dashtic/js/relax-submit-lock.js') }}?v={{ filemtime(public_path('assets/dashtic/js/relax-submit-lock.js')) }}"></script>
@stack('scripts')
</body>
</html>
