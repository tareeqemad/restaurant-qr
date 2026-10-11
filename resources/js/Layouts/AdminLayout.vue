<script setup>
/**
 * Dar Al Sarw administration shell with a persistent workspace sidebar.
 * Renders navigation and utilities from the server-built `shell` prop.
 * The layout decides nothing about authorization — it draws exactly what
 * AdminNav emitted. Session flash messages surface as toasts.
 *
 * Usage in a page:  defineOptions({ layout: AdminLayout })
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AdminHeader from '../Components/AdminShell/AdminHeader.vue';
import AdminSidebar from '../Components/AdminShell/AdminSidebar.vue';
import ConfirmHost from '../Components/Ui/ConfirmHost.vue';
import Toaster from '../Components/Ui/Toaster.vue';
import FormErrorSummary from '../Components/Ui/FormErrorSummary.vue';
import InventoryWorkspaceNav from '../Components/Inventory/InventoryWorkspaceNav.vue';
import { useToast } from '../Composables/useToast';

const page = usePage();
const shell = computed(() => page.props.shell);
const toast = useToast();
const sidebarOpen = ref(false);

const setSidebarOpen = (open) => {
    if (sidebarOpen.value === open) return;
    sidebarOpen.value = open;
    document.documentElement.setAttribute('data-toggled', open ? 'open' : 'close');
    document.body.classList.toggle('mobile-admin-nav-open', open);

    // Keep focus inside the active surface so keyboard users do not get lost.
    nextTick(() => {
        const target = open
            ? document.querySelector('.mobile-drawer__close')
            : document.getElementById('admin-mobile-menu-toggle');

        target?.focus({ preventScroll: true });
    });
};

const closeSidebar = () => setSidebarOpen(false);
const toggleSidebar = () => setSidebarOpen(! sidebarOpen.value);
const handleKeydown = (event) => {
    if (! sidebarOpen.value) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        closeSidebar();
        return;
    }

    if (event.key !== 'Tab') return;

    const drawer = document.getElementById('sidebar');
    const focusable = [...(drawer?.querySelectorAll([
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',')) || [])].filter((element) => element.getClientRects().length > 0);

    if (! focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (!drawer.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
    } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (! event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
};
const handleResize = () => {
    if (window.innerWidth >= 1100 && sidebarOpen.value) closeSidebar();
};

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
    window.addEventListener('resize', handleResize);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('resize', handleResize);
    document.body.classList.remove('mobile-admin-nav-open');
    document.documentElement.setAttribute('data-toggled', 'close');
});

watch(() => page.url, closeSidebar);

watch(
    () => page.props.flash,
    (flash) => {
        if (! flash) return;
        if (flash.success) toast.success(flash.success);
        if (flash.error) toast.error(flash.error);
        if (flash.warning) toast.warning(flash.warning);
        if (flash.info) toast.info(flash.info);
    },
    { immediate: true },
);
</script>

<template>
    <div class="sarw-admin" :data-page="page.component">
        <a class="sarw-skip-link" href="#admin-main" :inert="sidebarOpen || undefined">انتقل إلى محتوى الصفحة</a>
        <AdminHeader
            :shell="shell"
            :sidebar-open="sidebarOpen"
            @toggle-sidebar="toggleSidebar"
            @close-sidebar="closeSidebar"
        />
        <AdminSidebar
            :nav="shell.nav"
            :brand="shell.brand"
            :dashboard-url="shell.urls.dashboard"
            :open="sidebarOpen"
            @close="closeSidebar"
        />

        <main id="admin-main" class="sarw-main app-content" tabindex="-1" :inert="sidebarOpen || undefined">
            <div class="sarw-page-content">
                <InventoryWorkspaceNav v-if="shell.workspace?.type === 'inventory'" :workspace="shell.workspace" />
                <FormErrorSummary class="admin-global-errors" :errors="page.props.errors" />
                <slot />
            </div>
        </main>

        <footer class="sarw-footer" :inert="sidebarOpen || undefined">
            <span>{{ shell.brand.name }}</span><span>© {{ new Date().getFullYear() }}</span>
        </footer>
    </div>

    <Toaster />
    <ConfirmHost />
</template>

<style>
.admin-global-errors { margin-block: .75rem; }
@media (max-width: 1099.98px) {
    body.mobile-admin-nav-open {
        overflow: hidden;
        overscroll-behavior: none;
    }
}
</style>
