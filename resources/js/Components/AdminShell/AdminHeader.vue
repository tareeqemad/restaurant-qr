<script setup>
/**
 * Dashtic .app-header rebuilt in Vue: logo, offcanvas hamburger,
 * branch switcher, attendance pill, notifications bell, fullscreen,
 * profile dropdown. Logout posts classically (server redirects to login).
 */
import { ref } from 'vue';
import AttendancePill from './AttendancePill.vue';
import BranchSwitcher from './BranchSwitcher.vue';
import NotificationsBell from './NotificationsBell.vue';
import { useDropdown } from '../../Composables/useDropdown';
import { formPost } from '../../Support/formPost';

const props = defineProps({
    shell: { type: Object, required: true },
    sidebarOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-sidebar', 'close-sidebar']);

const { open: profileOpen, root: profileRoot, toggle: profileToggle } = useDropdown();
const isFullscreen = ref(false);

const toggleFullscreen = () => {
    if (! document.fullscreenElement) {
        document.documentElement.requestFullscreen?.();
        isFullscreen.value = true;
    } else {
        document.exitFullscreen?.();
        isFullscreen.value = false;
    }
};

const logout = () => formPost(props.shell.urls.logout);
</script>

<template>
    <header class="app-header">
        <div class="main-header-container container-fluid">

            <div class="header-content-left">
                <div class="header-element">
                    <div class="horizontal-logo">
                        <a :href="shell.urls.dashboard" class="header-logo">
                            <img :src="shell.brand.logo" alt="logo" class="desktop-logo">
                            <img :src="shell.brand.logo" alt="logo" class="toggle-logo">
                            <img :src="shell.brand.logo" alt="logo" class="desktop-dark">
                            <img :src="shell.brand.logo" alt="logo" class="toggle-dark">
                            <img :src="shell.brand.logo" alt="logo" class="desktop-white">
                            <img :src="shell.brand.logo" alt="logo" class="toggle-white">
                        </a>
                    </div>
                </div>

                <div class="header-element">
                    <div class="sidemenu-toggle hor-toggle horizontal-navtoggle">
                        <button
                            id="admin-mobile-menu-toggle"
                            type="button"
                            class="open-toggle mobile-menu-trigger"
                            aria-controls="sidebar"
                            :aria-expanded="sidebarOpen"
                            :aria-label="sidebarOpen ? 'إغلاق قائمة الإدارة' : 'فتح قائمة الإدارة'"
                            @click="emit('toggle-sidebar')"
                        >
                            <svg class="header-link-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="header-content-right">
                <BranchSwitcher v-if="shell.branch" :branch="shell.branch" />
                <AttendancePill v-if="shell.attendance" :attendance="shell.attendance" />
                <NotificationsBell :urls="shell.urls.notifications" />

                <div class="header-element header-fullscreen">
                    <a aria-label="ملء الشاشة" href="#" class="header-link" @click.prevent="toggleFullscreen">
                        <svg v-if="! isFullscreen" class="header-link-icon" viewBox="0 0 24 24" height="24" width="24" preserveAspectRatio="xMidYMid meet" focusable="false"><path d="M7,14 L5,14 L5,19 L10,19 L10,17 L7,17 L7,14 Z M5,10 L7,10 L7,7 L10,7 L10,5 L5,5 L5,10 Z M17,17 L14,17 L14,19 L19,19 L19,14 L17,14 L17,17 Z M14,5 L14,7 L17,7 L17,10 L19,10 L19,5 L14,5 Z"></path></svg>
                        <svg v-else class="header-link-icon" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 0 24 24" width="24px"><path d="M0 0h24v24H0V0z" fill="none"/><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>
                    </a>
                </div>

                <div class="header-element main-profile-user profile-dd" ref="profileRoot">
                    <button
                        type="button"
                        class="header-link profile-trigger"
                        :class="{ 'is-open': profileOpen }"
                        aria-haspopup="menu"
                        aria-controls="admin-profile-menu"
                        :aria-expanded="profileOpen"
                        aria-label="فتح قائمة الحساب"
                        @click="profileToggle"
                    >
                        <span class="profile-trigger__content">
                            <img :src="shell.user.avatar" :alt="`صورة ${shell.user.name}`" class="rounded-circle header-profile-img avatar">
                            <span class="profile-trigger__identity">
                                <strong>{{ shell.user.name }}</strong>
                                <small>{{ shell.user.roleLabel }}</small>
                            </span>
                            <i class="bi bi-chevron-down profile-trigger__chevron" aria-hidden="true"></i>
                        </span>
                    </button>

                    <div
                        v-show="profileOpen"
                        id="admin-profile-menu"
                        class="main-header-dropdown dropdown-menu header-profile-dropdown dropdown-menu-end show profile-menu"
                        role="menu"
                        aria-label="قائمة حساب المستخدم"
                    >
                        <div class="profile-menu__identity">
                            <img :src="shell.user.avatar" alt="" aria-hidden="true">
                            <span>
                                <strong>{{ shell.user.name }}</strong>
                                <small>{{ shell.user.roleLabel }}</small>
                            </span>
                        </div>

                        <nav class="profile-menu__actions" aria-label="خيارات الحساب">
                            <a :href="shell.urls.profile" class="profile-menu__item" role="menuitem">
                                <i class="bi bi-person" aria-hidden="true"></i>
                                <span>
                                    <strong>الملف الشخصي</strong>
                                    <small>بياناتك وأمان حسابك</small>
                                </span>
                                <i class="bi bi-chevron-left profile-menu__arrow" aria-hidden="true"></i>
                            </a>
                            <a :href="shell.urls.usageGuide" class="profile-menu__item" role="menuitem">
                                <i class="bi bi-journal-bookmark" aria-hidden="true"></i>
                                <span>
                                    <strong>دليل الاستخدام</strong>
                                    <small>شرح النظام حسب دورك</small>
                                </span>
                                <i class="bi bi-chevron-left profile-menu__arrow" aria-hidden="true"></i>
                            </a>
                        </nav>

                        <button type="button" class="profile-menu__logout" role="menuitem" @click="logout">
                            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                            <span>تسجيل الخروج</span>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </header>

    <div
        id="responsive-overlay"
        :class="{ active: sidebarOpen }"
        :aria-hidden="! sidebarOpen"
        @click="emit('close-sidebar')"
    ></div>
</template>

<style scoped>
.profile-dd {
    position: relative;
    isolation: isolate;
}
.mobile-menu-trigger {
    display: inline-grid;
    width: 42px;
    height: 42px;
    padding: 0;
    place-items: center;
    border: 0;
    border-radius: 11px;
    color: #234536;
    background: transparent;
}
.mobile-menu-trigger:hover,
.mobile-menu-trigger:focus-visible {
    color: rgb(var(--primary-rgb));
    background: rgba(var(--primary-rgb), .08);
    outline: none;
}
.profile-trigger {
    border: 1px solid rgba(var(--primary-rgb), .1);
    background: #fff;
    cursor: pointer;
}
.profile-trigger.is-open {
    border-color: rgba(var(--primary-rgb), .25) !important;
    background: rgba(var(--primary-rgb), .06) !important;
}
.profile-trigger__content {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: .5rem;
}
.profile-trigger__identity {
    display: grid;
    min-width: 0;
    gap: .15rem;
    text-align: start;
}
.profile-trigger__identity strong,
.profile-trigger__identity small {
    max-width: 116px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.profile-trigger__identity strong {
    color: #243a30;
    font-size: .72rem;
    font-weight: 900;
    line-height: 1.15;
}
.profile-trigger__identity small {
    color: #7b8c83;
    font-size: .58rem;
    font-weight: 700;
    line-height: 1.15;
}
.profile-trigger__chevron {
    color: #85958d;
    font-size: .65rem;
    transition: transform .18s ease;
}
.profile-trigger.is-open .profile-trigger__chevron {
    transform: rotate(180deg);
}
.profile-dd .profile-menu {
    position: absolute;
    top: calc(100% + 10px) !important;
    inset-inline-end: 0 !important;
    inset-inline-start: auto !important;
    right: auto;
    left: auto;
    z-index: 1200;
    width: min(250px, calc(100vw - 1rem)) !important;
    min-width: min(250px, calc(100vw - 1rem)) !important;
    padding: .45rem !important;
    overflow: visible;
    border: 1px solid #dfe8e3;
    border-radius: 14px !important;
    background: #fff;
    box-shadow: 0 18px 45px rgba(24, 53, 37, .16) !important;
}
.profile-dd .profile-menu::before {
    inset-block-start: -6px;
    inset-inline-end: 18px;
    width: 11px;
    height: 11px;
    border-color: #dfe8e3 transparent transparent #dfe8e3;
    background: #fff;
}
.profile-menu__identity {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: .65rem;
    padding: .55rem .55rem .7rem;
    border-bottom: 1px solid #edf2ef;
}
.profile-menu__identity img {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    border: 2px solid #e0eee5;
    border-radius: 12px;
    object-fit: cover;
}
.profile-menu__identity span {
    display: grid;
    min-width: 0;
    gap: .14rem;
}
.profile-menu__identity strong,
.profile-menu__identity small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.profile-menu__identity strong {
    color: #20362b;
    font-size: .73rem;
    font-weight: 900;
}
.profile-menu__identity small {
    color: #819087;
    font-size: .59rem;
}
.profile-menu__actions {
    display: grid;
    gap: .12rem;
    padding-block: .35rem;
}
.profile-menu__item {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr) auto;
    min-height: 49px;
    align-items: center;
    gap: .55rem;
    padding: .45rem .5rem;
    border-radius: 10px;
    color: #344b40;
    text-decoration: none;
    transition: color .15s ease, background .15s ease;
}
.profile-menu__item > i:first-child {
    display: grid;
    width: 32px;
    height: 32px;
    place-items: center;
    border-radius: 9px;
    background: #edf7f1;
    color: rgb(var(--primary-rgb));
    font-size: .9rem;
}
.profile-menu__item span {
    display: grid;
    min-width: 0;
    gap: .1rem;
}
.profile-menu__item strong {
    font-size: .68rem;
    font-weight: 900;
}
.profile-menu__item small {
    overflow: hidden;
    color: #849189;
    font-size: .55rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.profile-menu__arrow {
    color: #9ba8a1;
    font-size: .62rem;
}
.profile-menu__item:hover,
.profile-menu__item:focus-visible {
    color: #126c3d;
    background: #f1f8f4;
    outline: none;
}
.profile-menu__item:focus-visible,
.profile-menu__logout:focus-visible,
.profile-trigger:focus-visible {
    outline: 3px solid rgba(var(--primary-rgb), .16);
    outline-offset: 1px;
}
.profile-menu__logout {
    display: flex;
    width: 100%;
    min-height: 40px;
    align-items: center;
    gap: .6rem;
    padding: .55rem .7rem;
    border: 0;
    border-top: 1px solid #f3e6e5;
    border-radius: 9px;
    background: transparent;
    color: #c23939;
    font-size: .67rem;
    font-weight: 850;
    text-align: start;
}
.profile-menu__logout:hover {
    background: #fff3f2;
}
@media (max-width: 1199.98px) {
    .profile-trigger__identity,
    .profile-trigger__chevron {
        display: none;
    }
}
@media (max-width: 575.98px) {
    .profile-dd .profile-menu {
        top: calc(100% + 8px) !important;
    }
}
@media (prefers-reduced-motion: reduce) {
    .profile-trigger__chevron,
    .profile-menu__item {
        transition: none;
    }
}
#responsive-overlay {
    position: fixed;
    inset: 0;
    z-index: 1180;
    background: rgba(9, 25, 18, .52);
    backdrop-filter: blur(2px);
    display: none;
}
#responsive-overlay.active { display: block; }
@media (min-width: 992px) {
    #responsive-overlay.active { display: none; }
}
</style>
