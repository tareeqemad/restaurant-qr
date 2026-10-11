<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AttendancePill from './AttendancePill.vue';
import BranchSwitcher from './BranchSwitcher.vue';
import NotificationsBell from './NotificationsBell.vue';
import { useDropdown } from '../../Composables/useDropdown';
import { formPost } from '../../Support/formPost';

const props = defineProps({ shell: { type: Object, required: true }, sidebarOpen: { type: Boolean, default: false } });
const emit = defineEmits(['toggle-sidebar', 'close-sidebar']);
const { open: profileOpen, root: profileRoot, toggle: profileToggle, close: closeProfile } = useDropdown();
const isFullscreen = ref(false);
const syncFullscreen = () => { isFullscreen.value = Boolean(document.fullscreenElement); };
const toggleFullscreen = async () => {
    try {
        if (document.fullscreenElement) await document.exitFullscreen?.();
        else await document.documentElement.requestFullscreen?.();
    } catch { /* The browser may disallow fullscreen; the current view remains usable. */ }
    syncFullscreen();
};
onMounted(() => document.addEventListener('fullscreenchange', syncFullscreen));
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', syncFullscreen));
const logout = () => formPost(props.shell.urls.logout);
</script>

<template>
    <header class="sarw-topbar" :inert="sidebarOpen || undefined">
        <div class="sarw-topbar__context">
            <button id="admin-mobile-menu-toggle" type="button" class="sarw-icon-button sarw-menu-toggle"
                    aria-controls="sidebar" :aria-expanded="sidebarOpen" aria-label="فتح قائمة الإدارة" @click="emit('toggle-sidebar')">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <Link :href="shell.urls.dashboard" class="sarw-mobile-brand"><img :src="shell.brand.logo" alt="" width="34" height="34"><span>{{ shell.brand.name }}</span></Link>
            <BranchSwitcher v-if="shell.branch" :branch="shell.branch" />
        </div>
        <div class="sarw-topbar__tools">
            <AttendancePill v-if="shell.attendance" :attendance="shell.attendance" />
            <NotificationsBell :urls="shell.urls.notifications" />
            <button type="button" class="sarw-icon-button sarw-fullscreen" :aria-label="isFullscreen ? 'الخروج من ملء الشاشة' : 'ملء الشاشة'" @click="toggleFullscreen">
                <i class="bi" :class="isFullscreen ? 'bi-fullscreen-exit' : 'bi-fullscreen'" aria-hidden="true"></i>
            </button>
            <div class="sarw-profile" ref="profileRoot">
                <button type="button" class="sarw-profile-trigger" :class="{ 'is-open': profileOpen }" aria-haspopup="menu"
                        aria-controls="admin-profile-menu" :aria-expanded="profileOpen" aria-label="فتح قائمة الحساب" @click="profileToggle">
                    <span class="sarw-profile-initial" aria-hidden="true">{{ shell.user.name?.trim().charAt(0) }}</span>
                    <span class="sarw-profile-copy"><strong>{{ shell.user.name }}</strong><small>{{ shell.user.roleLabel }}</small></span>
                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                </button>
                <div v-show="profileOpen" id="admin-profile-menu" class="sarw-profile-menu" role="menu" aria-label="قائمة حساب المستخدم">
                    <div class="sarw-profile-menu__identity"><strong>{{ shell.user.name }}</strong><small>{{ shell.user.roleLabel }}</small></div>
                    <Link :href="shell.urls.profile" role="menuitem" @click="closeProfile"><i class="bi bi-person" aria-hidden="true"></i>الملف الشخصي</Link>
                    <Link :href="shell.urls.usageGuide" role="menuitem" @click="closeProfile"><i class="bi bi-journal-bookmark" aria-hidden="true"></i>دليل الاستخدام</Link>
                    <button type="button" role="menuitem" class="sarw-profile-logout" @click="logout"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>تسجيل الخروج</button>
                </div>
            </div>
        </div>
    </header>
    <div v-if="sidebarOpen" class="sarw-sidebar-backdrop" aria-hidden="true" @click="emit('close-sidebar')"></div>
</template>
