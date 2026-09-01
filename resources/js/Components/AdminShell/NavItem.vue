<script setup>
/**
 * One nav entry — recursive: leaf link, section header, or a parent whose
 * children render in a dropdown (desktop hover / touch tap). Depth 1 sits
 * on the horizontal bar; deeper levels cascade sideways.
 *
 * Internal leaves use Inertia links so the admin shell does not flash or
 * reload while staff move between workspaces. Explicit new-tab destinations
 * remain regular anchors.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    item: { type: Object, required: true },
    depth: { type: Number, default: 1 },
});

const emit = defineEmits(['navigate']);
const page = usePage();

// Active identifies the current section; it must not leave its dropdown open
// after every page navigation. Opening is an explicit hover/tap interaction.
const open = ref(false);
const root = ref(null);
const desktopMenuStyle = ref({});
const hasChildren = computed(() => (props.item.children?.length ?? 0) > 0);
const isWideMenu = computed(() => props.depth === 1 && (props.item.children?.length ?? 0) > 7);
const canHover = window.matchMedia?.('(hover: hover) and (pointer: fine)')?.matches ?? false;

const isDesktop = () => window.matchMedia?.('(min-width: 992px)')?.matches ?? window.innerWidth >= 992;

/**
 * Dashtic's old horizontal-menu rules mix physical and logical RTL insets.
 * Anchoring the first dropdown from the button's real viewport rectangle keeps
 * it directly below its parent and inside the screen at every desktop width.
 */
const positionDesktopMenu = (force = false) => {
    if (props.depth !== 1 || (! open.value && ! force) || ! isDesktop() || ! root.value) return;

    const trigger = root.value.querySelector(':scope > .side-menu__toggle');
    if (! trigger) return;

    const rect = trigger.getBoundingClientRect();
    const gutter = 12;
    const menuWidth = Math.min(isWideMenu.value ? 500 : 260, window.innerWidth - (gutter * 2));
    const preferredLeft = document.documentElement.dir === 'rtl'
        ? rect.right - menuWidth
        : rect.left;
    const left = Math.min(
        Math.max(preferredLeft, gutter),
        Math.max(gutter, window.innerWidth - menuWidth - gutter),
    );

    desktopMenuStyle.value = {
        '--admin-nav-menu-left': `${Math.round(left)}px`,
        '--admin-nav-menu-top': `${Math.round(rect.bottom + 8)}px`,
        '--admin-nav-menu-width': `${Math.round(menuWidth)}px`,
    };
};

const enter = () => {
    if (! canHover) return;
    open.value = true;
    nextTick(positionDesktopMenu);
};
const leave = () => { if (canHover) open.value = false; };
const toggle = () => {
    // A pointer click is preceded by mouseenter on desktop. Keep the menu
    // that mouseenter just opened instead of toggling it closed again.
    if (canHover && open.value) {
        nextTick(positionDesktopMenu);
        return;
    }

    open.value = ! open.value;
    if (open.value) nextTick(positionDesktopMenu);
};

const closeMenu = () => {
    open.value = false;
    desktopMenuStyle.value = {};
};

// Close every ancestor in the recursive tree before Inertia swaps the page.
// The route watcher is a second guard for browser history and programmatic
// navigation where no menu link emitted the event directly.
const handleNavigate = () => {
    if (document.activeElement instanceof HTMLElement) {
        document.activeElement.blur();
    }
    closeMenu();
    emit('navigate');
};

const closeFromOutside = (event) => {
    if (props.depth === 1 && open.value && ! root.value?.contains(event.target)) {
        open.value = false;
    }
};

const handleKeydown = (event) => {
    if (props.depth === 1 && event.key === 'Escape' && open.value) {
        open.value = false;
        root.value?.querySelector(':scope > .side-menu__toggle')?.focus({ preventScroll: true });
    }
};

const handleViewportChange = () => {
    if (! isDesktop()) {
        desktopMenuStyle.value = {};
        return;
    }

    positionDesktopMenu();
};

const handleFocusIn = () => {
    if (props.depth === 1 && isDesktop()) nextTick(() => positionDesktopMenu(true));
};

onMounted(() => {
    // Reveal the current workspace immediately on phones. Desktop keeps the
    // horizontal dropdown closed until hover or tap.
    if (props.item.active && window.matchMedia?.('(max-width: 991.98px)')?.matches) {
        open.value = true;
    }

    if (props.depth === 1) {
        document.addEventListener('pointerdown', closeFromOutside);
        document.addEventListener('keydown', handleKeydown);
        window.addEventListener('resize', handleViewportChange);
        window.addEventListener('scroll', handleViewportChange, { passive: true });
    }
});

onBeforeUnmount(() => {
    if (props.depth !== 1) return;

    document.removeEventListener('pointerdown', closeFromOutside);
    document.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('resize', handleViewportChange);
    window.removeEventListener('scroll', handleViewportChange);
});

watch(open, (value) => {
    if (value) nextTick(positionDesktopMenu);
});

watch(() => page.url, closeMenu);
</script>

<template>
    <li v-if="item.section" class="slide-section nav-section">{{ item.section }}</li>

    <li v-else-if="hasChildren"
        ref="root"
        class="slide has-sub" :class="{ open }"
        @mouseenter="enter" @mouseleave="leave" @focusin="handleFocusIn">
        <button
            type="button"
            class="side-menu__item side-menu__toggle"
            :class="{ active: item.active }"
            :aria-expanded="open"
            @click="toggle"
        >
            <span v-if="item.icon" class="nav-icon" :class="depth === 1 ? 'side-menu__icon' : 'submenu-icon'" aria-hidden="true">
                <i :class="item.icon"></i>
            </span>
            <span class="side-menu__label">{{ item.label }}</span>
            <span v-if="item.tag" class="badge bg-success-transparent ms-1 nav-tag">{{ item.tag }}</span>
            <span v-if="item.badge" class="badge ms-auto" :class="`bg-${item.badge.tone}`">{{ item.badge.value }}</span>
            <i class="side-menu__angle bi" :class="depth === 1 ? 'bi-chevron-down' : 'bi-chevron-left'"></i>
        </button>
        <ul class="slide-menu"
            :class="[depth === 1 ? 'child1' : 'child2', { 'admin-nav-menu--wide': isWideMenu }]"
            :style="{ display: open ? (isWideMenu ? 'grid' : 'block') : 'none', ...desktopMenuStyle }">
            <NavItem
                v-for="(child, i) in item.children"
                :key="child.href || child.label || i"
                :item="child"
                :depth="depth + 1"
                @navigate="handleNavigate"
            />
        </ul>
    </li>

    <li v-else-if="item.newTab" class="slide" :class="{ active: item.active }">
        <a :href="item.href" class="side-menu__item" :class="{ active: item.active }"
           target="_blank" rel="noopener" @click="handleNavigate">
            <span v-if="item.icon" class="nav-icon" :class="depth === 1 ? 'side-menu__icon' : 'submenu-icon'" aria-hidden="true">
                <i :class="item.icon"></i>
            </span>
            <span class="side-menu__label">{{ item.label }}</span>
            <span v-if="item.tag" class="badge bg-success-transparent ms-1 nav-tag">{{ item.tag }}</span>
            <span v-if="item.badge" class="badge ms-auto" :class="`bg-${item.badge.tone}`">{{ item.badge.value }}</span>
        </a>
    </li>

    <li v-else class="slide" :class="{ active: item.active }">
        <Link
            :href="item.href"
            class="side-menu__item"
            :class="{ active: item.active }"
            :prefetch="item.active ? false : 'hover'"
            :cache-for="30000"
            @click="handleNavigate"
        >
            <span v-if="item.icon" class="nav-icon" :class="depth === 1 ? 'side-menu__icon' : 'submenu-icon'" aria-hidden="true">
                <i :class="item.icon"></i>
            </span>
            <span class="side-menu__label">{{ item.label }}</span>
            <span v-if="item.tag" class="badge bg-success-transparent ms-1 nav-tag">{{ item.tag }}</span>
            <span v-if="item.badge" class="badge ms-auto" :class="`bg-${item.badge.tone}`">{{ item.badge.value }}</span>
        </Link>
    </li>
</template>

<style scoped>
/* Dropdown behavior is OURS (no Dashtic menu JS): explicit positioning +
   inline display toggling, so the menu can't depend on template scripts. */
.slide.has-sub { position: relative; }
.side-menu__toggle {
    border: 0;
    background: transparent;
    text-align: start;
    font: inherit;
}

.slide-menu {
    position: absolute;
    top: 100%;
    inset-inline-start: 0;
    min-width: 240px;
    margin: 0;
    padding: .4rem;
    list-style: none;
    background: #fff;
    border: 1px solid rgba(15, 23, 42, .08);
    border-radius: 10px;
    box-shadow: 0 14px 30px -10px rgba(15, 23, 42, .18);
    z-index: 1030;
}

/* Dashtic still ships physical left/right rules for its vertical menu. On
   Inertia pages those rules can outrank the logical inset above and move a
   first-level dropdown beside its trigger. Pin level one to the trigger;
   only deeper levels are allowed to cascade sideways. */
/* Nested levels cascade sideways instead of stacking downward. */
.slide-menu .slide-menu {
    top: 0;
    inset-inline-start: 100%;
}

.slide-menu .slide.has-sub > .slide-menu {
    right: calc(100% + .35rem) !important;
    left: auto !important;
}

.nav-section {
    padding: .5rem .9rem .25rem;
    font-size: .68rem;
    font-weight: 700;
    color: #9ca3af;
    list-style: none;
}

.nav-tag { font-size: .58rem; }

.side-menu__angle { font-size: .68rem; margin-inline-start: auto; }
.side-menu__item .badge + .side-menu__angle { margin-inline-start: .35rem; }

/* The fixed frame owns layout; the icon-font glyph can no longer shift the
   navbar item through its own baseline or Bootstrap's vertical-align. */
.nav-icon {
    display: inline-grid;
    place-items: center;
    line-height: 0;
}

.nav-icon > i {
    display: inline-grid;
    width: 1em;
    height: 1em;
    place-items: center;
    margin: 0;
    padding: 0;
    font-size: inherit;
    line-height: 1;
    vertical-align: 0;
}

.nav-icon > i::before {
    display: block;
    width: 1em;
    height: 1em;
    margin: 0;
    line-height: 1;
    vertical-align: 0;
}

/* Offcanvas (mobile) — the menu is a vertical list, dropdowns expand
   in place instead of floating. */
@media (max-width: 991.98px) {
    .slide {
        width: 100%;
        padding: 0 !important;
    }

    .side-menu__item {
        display: flex !important;
        width: 100% !important;
        min-height: 50px !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: .65rem !important;
        padding: .36rem .48rem !important;
        border: 1px solid transparent !important;
        border-radius: 13px !important;
        color: #4f6258 !important;
        background: transparent !important;
        font-size: .84rem !important;
        font-weight: 850 !important;
        text-align: start !important;
        box-shadow: none !important;
    }

    .side-menu__item:hover,
    .side-menu__item:focus-visible {
        border-color: #dce8e0 !important;
        color: rgb(var(--primary-rgb)) !important;
        background: #f3f8f5 !important;
        outline: none;
    }

    .side-menu__item.active,
    .slide.open > .side-menu__item {
        border-color: #cfe1d5 !important;
        color: rgb(var(--primary-rgb)) !important;
        background: #eaf4ed !important;
        box-shadow: inset -3px 0 rgb(var(--primary-rgb)) !important;
    }

    .side-menu__item .nav-icon {
        display: inline-grid !important;
        width: 38px !important;
        height: 38px !important;
        min-width: 38px !important;
        flex: 0 0 38px !important;
        place-items: center !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 1px solid #e0e9e3;
        border-radius: 11px;
        color: #64766c !important;
        background: #f4f8f5;
        font-size: 1rem !important;
        opacity: 1 !important;
    }

    .side-menu__item.active .nav-icon,
    .slide.open > .side-menu__item .nav-icon {
        border-color: rgb(var(--primary-rgb)) !important;
        color: #fff !important;
        background: rgb(var(--primary-rgb)) !important;
    }

    .side-menu__label {
        display: block !important;
        min-width: 0;
        flex: 1 1 auto;
        overflow: hidden;
        color: inherit !important;
        font-size: inherit !important;
        text-align: start !important;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .side-menu__angle {
        position: static !important;
        inset: auto !important;
        display: inline-grid !important;
        width: 28px !important;
        height: 28px !important;
        flex: 0 0 28px !important;
        place-items: center;
        margin: 0 !important;
        border-radius: 9px;
        background: rgba(15, 71, 49, .06);
        font-size: .72rem !important;
        transform: none !important;
    }

    .slide.open > .side-menu__item .side-menu__angle {
        transform: rotate(180deg) !important;
    }

    .slide-menu,
    .slide-menu .slide-menu {
        position: static !important;
        width: 100% !important;
        min-width: 0 !important;
        margin: .25rem 0 .35rem !important;
        padding: .32rem .4rem .4rem !important;
        border: 0 !important;
        border-inline-start: 2px solid #dce9e0 !important;
        border-radius: 0 !important;
        background: #fafcfb !important;
        box-shadow: none !important;
        transform: none !important;
    }

    .slide-menu .slide {
        padding: 0 !important;
    }

    .slide-menu .side-menu__item {
        min-height: 44px !important;
        padding: .28rem .38rem !important;
        border-radius: 10px !important;
        font-size: .78rem !important;
        font-weight: 780 !important;
    }

    .slide-menu .side-menu__item .nav-icon {
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        flex-basis: 30px !important;
        border: 0 !important;
        background: transparent !important;
        font-size: .86rem !important;
    }

    .slide-menu .side-menu__item.active .nav-icon {
        color: rgb(var(--primary-rgb)) !important;
    }

    .nav-section {
        padding: .65rem .55rem .25rem;
        color: #8b9a91;
        font-size: .62rem;
        letter-spacing: 0;
    }
}
</style>

<!-- Unscoped on purpose: this selector owns the horizontal shell and must
     outrank the legacy Dashtic RTL selectors loaded globally. -->
<style>
@media (min-width: 992px) {
    /* Dashtic's base stylesheet can reveal submenus from :hover even when
       Vue has already closed them. Closed means closed; only `.open` below
       may override this rule. */
    html[data-nav-layout="horizontal"] #sidebar.app-sidebar .slide.has-sub:not(.open) > .slide-menu {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }

    html[data-nav-layout="horizontal"] #sidebar.app-sidebar .main-menu > .slide.has-sub > .slide-menu.child1 {
        position: fixed !important;
        inset-block-start: var(--admin-nav-menu-top) !important;
        inset-inline: auto !important;
        top: var(--admin-nav-menu-top) !important;
        right: auto !important;
        bottom: auto !important;
        left: var(--admin-nav-menu-left) !important;
        width: var(--admin-nav-menu-width) !important;
        min-width: var(--admin-nav-menu-width) !important;
        margin: 0 !important;
        transform: none !important;
    }

    html[data-nav-layout="horizontal"] #sidebar.app-sidebar .main-menu > .slide.has-sub.open > .slide-menu.child1.admin-nav-menu--wide {
        display: grid !important;
        visibility: visible !important;
        opacity: 1 !important;
        pointer-events: auto !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .18rem .35rem;
    }

    html[data-nav-layout="horizontal"] #sidebar.app-sidebar .slide-menu.child1.admin-nav-menu--wide > .slide,
    html[data-nav-layout="horizontal"] #sidebar.app-sidebar .slide-menu.child1.admin-nav-menu--wide > .nav-section {
        min-width: 0;
        break-inside: avoid;
    }
}
</style>
