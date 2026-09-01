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
                    <a href="#" class="header-link" :aria-expanded="profileOpen" @click.prevent="profileToggle">
                        <div class="d-flex align-items-center">
                            <img :src="shell.user.avatar" alt="" class="rounded-circle header-profile-img avatar me-sm-2 me-0">
                            <div class="d-xl-block d-none align-items-center my-auto text-start">
                                <h6 class="fw-medium mb-0 lh-1 fs-13">{{ shell.user.name }}</h6>
                                <span class="op-5 fw-normal d-block fs-11 lh-1">{{ shell.user.roleLabel }}</span>
                            </div>
                        </div>
                    </a>
                    <div v-show="profileOpen" class="main-header-dropdown dropdown-menu header-profile-dropdown dropdown-menu-end show profile-menu">
                        <ul class="list-unstyled mb-0">
                            <li class="dropdown-item">
                                <a class="d-flex align-items-center w-100" :href="shell.urls.profile">
                                    <i class="bi bi-person me-3 fs-16"></i> الملف الشخصي
                                </a>
                            </li>
                            <li class="dropdown-item">
                                <button type="button" class="d-flex align-items-center w-100 border-0 bg-transparent p-0 text-danger" @click="logout">
                                    <i class="bi bi-box-arrow-right me-3 fs-16"></i> تسجيل خروج
                                </button>
                            </li>
                        </ul>
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
.profile-dd { position: relative; }
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
.profile-menu {
    position: absolute;
    top: calc(100% + 8px);
    inset-inline-end: 0;
    min-width: 200px;
    z-index: 1040;
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
