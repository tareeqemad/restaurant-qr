<script setup>
/**
 * The horizontal admin nav — Dashtic's .app-sidebar skeleton fed entirely
 * by the server-built `shell.nav` array (AdminNav.php). Zero gating here.
 */
import NavItem from './NavItem.vue';

defineProps({
    nav: { type: Array, required: true },
    brand: { type: Object, required: true },
    dashboardUrl: { type: String, required: true },
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
</script>

<template>
    <aside
        id="sidebar"
        class="app-sidebar sticky mobile-admin-drawer"
        :class="{ 'is-mobile-open': open }"
        :role="open ? 'dialog' : undefined"
        :aria-modal="open ? 'true' : undefined"
        aria-label="قائمة الإدارة"
    >
        <header class="mobile-drawer__header">
            <a :href="dashboardUrl" class="mobile-drawer__brand" @click="emit('close')">
                <span class="mobile-drawer__logo">
                    <img :src="brand.logo" alt="">
                </span>
                <span>
                    <small>لوحة الإدارة</small>
                    <strong>{{ brand.name }}</strong>
                </span>
            </a>

            <button type="button" class="mobile-drawer__close" aria-label="إغلاق القائمة" @click="emit('close')">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="mobile-drawer__intro">
            <span>التنقّل</span>
            <strong>اختر مساحة العمل</strong>
        </div>

        <div class="main-sidebar-header">
            <a :href="dashboardUrl" class="header-logo">
                <img :src="brand.logo" alt="logo" class="desktop-logo">
                <img :src="brand.logo" alt="logo" class="toggle-logo">
                <img :src="brand.logo" alt="logo" class="desktop-dark">
                <img :src="brand.logo" alt="logo" class="toggle-dark">
                <img :src="brand.logo" alt="logo" class="desktop-white">
                <img :src="brand.logo" alt="logo" class="toggle-white">
            </a>
        </div>

        <div class="main-sidebar" id="sidebar-scroll">
            <nav class="main-menu-container nav nav-pills flex-column sub-open">
                <ul class="main-menu">
                    <NavItem
                        v-for="(item, i) in nav"
                        :key="item.href || item.label || i"
                        :item="item"
                        :depth="1"
                        @navigate="emit('close')"
                    />
                </ul>
            </nav>
        </div>
    </aside>
</template>

<style>
.mobile-drawer__header,
.mobile-drawer__intro {
    display: none;
}

@media (max-width: 991.98px) {
    html[data-nav-layout="horizontal"] #sidebar.mobile-admin-drawer {
        position: fixed !important;
        inset-block: 0 !important;
        inset-inline-start: 0 !important;
        inset-inline-end: auto !important;
        display: flex !important;
        width: min(360px, calc(100vw - 28px)) !important;
        height: 100vh !important;
        height: 100dvh !important;
        max-height: 100dvh !important;
        flex-direction: column;
        contain: layout paint;
        overflow: hidden !important;
        visibility: hidden;
        pointer-events: none;
        transform: translateX(105%) !important;
        z-index: 1190 !important;
        border: 0 !important;
        border-start-end-radius: 22px;
        border-end-end-radius: 22px;
        background: #fff !important;
        box-shadow: -18px 0 60px rgba(7, 31, 20, .22) !important;
        transition: transform .24s ease, visibility .24s ease !important;
    }

    html[data-nav-layout="horizontal"] #sidebar.mobile-admin-drawer.is-mobile-open {
        visibility: visible;
        pointer-events: auto;
        transform: translateX(0) !important;
    }

    .mobile-drawer__header {
        display: flex;
        min-height: 72px;
        flex: 0 0 72px;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: max(.75rem, env(safe-area-inset-top)) .9rem .75rem;
        border-block-end: 1px solid #e6ece8;
        background: linear-gradient(135deg, #f7fbf8, #fff);
    }

    .mobile-drawer__brand {
        display: flex;
        min-width: 0;
        align-items: center;
        gap: .7rem;
        color: #173c2c;
        text-decoration: none;
    }

    .mobile-drawer__logo {
        display: grid;
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        place-items: center;
        border: 1px solid #dce8e0;
        border-radius: 13px;
        background: #fff;
    }

    .mobile-drawer__logo img {
        width: 32px;
        height: 32px;
        object-fit: contain;
    }

    .mobile-drawer__brand > span:last-child {
        display: grid;
        min-width: 0;
        gap: .12rem;
    }

    .mobile-drawer__brand small {
        color: #839188;
        font-size: .65rem;
        font-weight: 750;
    }

    .mobile-drawer__brand strong {
        overflow: hidden;
        font-size: .92rem;
        font-weight: 900;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .mobile-drawer__close {
        display: grid;
        width: 44px;
        height: 44px;
        flex: 0 0 44px;
        place-items: center;
        border: 1px solid #dce6e0;
        border-radius: 13px;
        color: #53665b;
        background: #fff;
        font-size: 1.05rem;
    }

    .mobile-drawer__close:focus-visible {
        outline: 3px solid rgba(var(--primary-rgb), .2);
        outline-offset: 2px;
    }

    .mobile-drawer__intro {
        display: flex;
        flex: 0 0 auto;
        align-items: baseline;
        justify-content: space-between;
        gap: .5rem;
        padding: .8rem 1rem .35rem;
    }

    .mobile-drawer__intro span {
        color: rgb(var(--primary-rgb));
        font-size: .66rem;
        font-weight: 900;
    }

    .mobile-drawer__intro strong {
        color: #6f7f76;
        font-size: .7rem;
        font-weight: 750;
    }

    .mobile-admin-drawer .main-sidebar {
        width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        flex: 1 1 auto;
        margin: 0 !important;
        padding: .35rem .7rem calc(1rem + env(safe-area-inset-bottom)) !important;
        overflow-x: hidden !important;
        overflow-y: auto !important;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: #cddbd2 transparent;
    }

    .mobile-admin-drawer .main-menu-container {
        display: block !important;
        width: 100% !important;
        min-height: 0 !important;
    }

    .mobile-admin-drawer .main-menu {
        display: flex !important;
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: .24rem !important;
        margin: 0 !important;
        padding: 0 0 2rem !important;
    }
}
</style>
