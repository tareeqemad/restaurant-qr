<script setup>
/**
 * Header notifications bell — the Vue rebirth of window.notifBell.
 * Operational alerts poll every 3s on shared hosting. New order/help/ready
 * events chime once and carry their safe primary action inside the dropdown.
 */
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useDropdown } from '../../Composables/useDropdown';
import { useToast } from '../../Composables/useToast';
import { playNotify } from '../../Support/audio';

const props = defineProps({
    urls: { type: Object, required: true }, // { recent, readAll, base, index }
    floating: { type: Boolean, default: true },
});

const { open, root, toggle } = useDropdown();
const unread = ref(0);
const items = ref([]);
const busyQuick = ref(new Set());
const dismissedAlerts = ref(new Set());
const toast = useToast();
let knownIds = new Set();
let initialized = false;
let timer = null;
let polling = false;
let navigationBusy = false;
let refreshController = null;
let stopStartListener = null;
let stopFinishListener = null;

const OPERATIONAL = new Set(['order.new', 'order.change', 'order.ready', 'table.help', 'bill.requested']);
const PRIORITY = { 'table.help': 50, 'order.change': 40, 'order.ready': 30, 'order.new': 20, 'bill.requested': 10 };

const activeAlert = computed(() => items.value
    .filter((item) => ! item.read && OPERATIONAL.has(item.type_key) && ! dismissedAlerts.value.has(item.id))
    .sort((a, b) => (PRIORITY[b.type_key] ?? 0) - (PRIORITY[a.type_key] ?? 0))[0] ?? null);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const call = (url, method = 'GET', payload = null, signal = undefined) => fetch(url, {
    method,
    headers: {
        'X-CSRF-TOKEN': csrf(),
        Accept: 'application/json',
        ...(payload ? { 'Content-Type': 'application/json' } : {}),
    },
    credentials: 'same-origin',
    body: payload ? JSON.stringify(payload) : undefined,
    signal,
}).then((r) => (r.ok ? r.json() : null)).catch(() => null);

const refresh = async () => {
    if (document.hidden || polling || navigationBusy || ! navigator.onLine) return;
    polling = true;
    refreshController = new AbortController();
    try {
        const data = await call(props.urls.recent, 'GET', null, refreshController.signal);
        if (! data) return;
        const next = data.items ?? [];
        if (initialized) {
            const fresh = next
                .filter((item) => ! item.read && OPERATIONAL.has(item.type_key) && ! knownIds.has(item.id))
                .sort((a, b) => (PRIORITY[b.type_key] ?? 0) - (PRIORITY[a.type_key] ?? 0));
            if (fresh.length) {
                playNotify();
                const first = fresh[0];
                toast.info(`${first.title}${first.body ? ` — ${first.body}` : ''}${fresh.length > 1 ? ` · و${fresh.length - 1} تنبيه آخر` : ''}`);
            }
        }
        knownIds = new Set([...knownIds, ...next.map((item) => item.id)].slice(-64));
        initialized = true;
        unread.value = data.unread ?? 0;
        items.value = next;
    } finally {
        polling = false;
        refreshController = null;
    }
};

const markRead = (n) => {
    if (n.read) return;
    n.read = true;
    call(`${props.urls.base}/${n.id}/read`, 'POST').then((res) => {
        if (res?.ok) unread.value = res.unread;
    });
};

const dismissAlert = (notification) => {
    const next = new Set(dismissedAlerts.value);
    next.add(notification.id);
    dismissedAlerts.value = next;
};

const openNotification = (notification) => {
    dismissAlert(notification);
    markRead(notification);
    if (notification.action_url) router.visit(notification.action_url);
};

const markAllRead = () => {
    call(props.urls.readAll, 'POST').then((res) => {
        if (res?.ok) {
            unread.value = res.unread;
            items.value.forEach((i) => { i.read = true; });
        }
    });
};

const quickAct = async (notification) => {
    const action = notification.quick_action;
    if (! action || busyQuick.value.has(notification.id)) return;

    const next = new Set(busyQuick.value);
    next.add(notification.id);
    busyQuick.value = next;
    const result = await call(action.url, 'POST', action.payload ?? {});
    if (result?.ok) {
        toast.success(result.message || 'تم التنفيذ.');
        markRead(notification);
    } else {
        toast.warning(result?.message || 'تعذّر التنفيذ؛ حدّث الحالة وحاول مجددًا.');
    }
    const done = new Set(busyQuick.value);
    done.delete(notification.id);
    busyQuick.value = done;
    refresh();
};

const sevClass = (s) => ({
    danger: 'bg-danger-transparent text-danger',
    warning: 'bg-warning-transparent text-warning',
    success: 'bg-success-transparent text-success',
}[s] ?? 'bg-info-transparent text-info');

onMounted(() => {
    stopStartListener = router.on('start', () => {
        navigationBusy = true;
        refreshController?.abort();
    });
    stopFinishListener = router.on('finish', () => {
        navigationBusy = false;
    });
    refresh();
    timer = setInterval(refresh, 3000);
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('online', refresh);
});
onBeforeUnmount(() => {
    refreshController?.abort();
    clearInterval(timer);
    stopStartListener?.();
    stopFinishListener?.();
    document.removeEventListener('visibilitychange', refresh);
    window.removeEventListener('online', refresh);
});
</script>

<template>
    <div class="header-element notifications-dropdown bell" ref="root">
        <a href="#" class="header-link" :aria-expanded="open" title="الإشعارات" @click.prevent="toggle">
            <svg class="header-link-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><path opacity=".3" d="M12 6.5c-2.49 0-4 2.02-4 4.5v6h8v-6c0-2.48-1.51-4.5-4-4.5z"></path><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-11c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2v-5zm-2 6H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"></path></svg>
            <span v-if="unread > 0" class="badge bg-danger rounded-pill header-icon-badge pulse pulse-danger">{{ unread }}</span>
        </a>

        <div v-show="open" class="main-header-dropdown dropdown-menu dropdown-menu-end notifications-menu p-0 show bell-menu">
            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                <h6 class="fw-semibold mb-0 fs-14 text-dark">
                    <i class="bi bi-bell-fill text-accent me-1"></i> الإشعارات
                </h6>
                <button v-if="unread > 0" type="button"
                        class="btn btn-sm btn-link text-decoration-none p-0 fs-12 fw-semibold"
                        @click="markAllRead">
                    <i class="bi bi-check2-all me-1"></i> تحديد الكل كمقروء
                </button>
            </div>

            <ul class="list-unstyled mb-0 notifications-list">
                <li v-for="n in items" :key="n.id">
                    <div class="notification-row" :class="{ 'opacity-75': n.read }">
                        <a :href="n.action_url || '#'" class="notification-link" @click.prevent="openNotification(n)">
                            <span class="notify-avatar" :class="sevClass(n.severity)">
                                <i class="bi" :class="n.icon || 'bi-bell'"></i>
                            </span>
                            <div class="flex-fill min-w-0">
                                <div class="d-flex justify-content-between align-items-baseline gap-2">
                                    <span class="fw-semibold fs-13 text-dark">{{ n.title }}</span>
                                    <small class="text-muted fs-11">{{ n.created_at }}</small>
                                </div>
                                <div class="text-muted fs-12 text-truncate">{{ n.body }}</div>
                            </div>
                        </a>
                        <button v-if="n.quick_action" type="button" class="quick-action"
                                :disabled="busyQuick.has(n.id)" @click="quickAct(n)">
                            <i class="bi bi-check2-circle"></i>
                            {{ busyQuick.has(n.id) ? 'جارٍ التنفيذ…' : n.quick_action.label }}
                        </button>
                    </div>
                </li>
                <li v-if="items.length === 0" class="text-center text-muted py-4 px-3">
                    <i class="bi bi-bell-slash fs-3 d-block mb-2 op-5"></i>
                    <span class="fs-13">لا إشعارات</span>
                </li>
            </ul>

            <div class="border-top text-center py-2">
                <a :href="urls.index" class="text-decoration-none fw-semibold fs-13 text-primary">
                    فتح كل الإشعارات <i class="bi bi-arrow-left ms-1"></i>
                </a>
            </div>
        </div>

        <aside v-if="floating && activeAlert" class="operational-float" :class="`is-${activeAlert.severity}`" role="alert">
            <span class="operational-icon"><i class="bi" :class="activeAlert.icon || 'bi-bell-fill'"></i></span>
            <span class="operational-copy">
                <strong>{{ activeAlert.title }}</strong>
                <small>{{ activeAlert.body }}</small>
            </span>
            <button
                v-if="activeAlert.type_key === 'table.help' && activeAlert.quick_action"
                type="button"
                class="operational-action"
                :disabled="busyQuick.has(activeAlert.id)"
                @click="quickAct(activeAlert)"
            >{{ busyQuick.has(activeAlert.id) ? 'جارٍ التأكيد…' : activeAlert.quick_action.label }}</button>
            <button v-else type="button" class="operational-action" @click="openNotification(activeAlert)">
                {{ activeAlert.action_label || 'افتح المهمة' }}
            </button>
            <button type="button" class="operational-dismiss" aria-label="إخفاء التنبيه" @click="dismissAlert(activeAlert)">
                <i class="bi bi-x-lg"></i>
            </button>
        </aside>
    </div>
</template>

<style scoped>
.bell { position: relative; }
.bell-menu {
    position: absolute;
    top: calc(100% + 8px);
    inset-inline-end: 0;
    min-width: 330px;
    max-width: min(92vw, 380px);
    z-index: 1040;
}
.notification-row { border-bottom: 1px solid #eef2f0; padding: .2rem .35rem .55rem; }
.notification-link { min-height: 56px; padding: .55rem .65rem; display: flex; align-items: flex-start; gap: .55rem; color: inherit; text-decoration: none; border-radius: 10px; }
.notification-link:hover { background: #f7faf8; }
.quick-action { min-height: 40px; margin-inline: .65rem; padding: 0 .8rem; border: 1px solid rgba(var(--primary-rgb), .25); border-radius: 11px; background: rgba(var(--primary-rgb), .07); color: rgb(var(--primary-rgb)); font: inherit; font-size: .75rem; font-weight: 800; }
.quick-action:disabled { opacity: .55; }
.operational-float { display: none; }

@media (max-width: 1199.98px) {
    .operational-float {
        position: fixed;
        inset-inline: max(12px, env(safe-area-inset-left)) max(12px, env(safe-area-inset-right));
        bottom: max(14px, env(safe-area-inset-bottom));
        z-index: 1300;
        display: grid;
        grid-template-columns: 42px minmax(0, 1fr) auto 38px;
        align-items: center;
        gap: .65rem;
        min-height: 72px;
        padding: .65rem;
        border: 1px solid #d9e5de;
        border-inline-start: 5px solid rgb(var(--primary-rgb));
        border-radius: 18px;
        color: #163126;
        background: rgba(255, 255, 255, .98);
        box-shadow: 0 20px 50px -24px rgba(15, 45, 31, .55);
        backdrop-filter: blur(12px);
    }
    .operational-float.is-danger { border-inline-start-color: #dc2626; }
    .operational-float.is-warning { border-inline-start-color: #d97706; }
    .operational-float.is-success { border-inline-start-color: #059669; }
    .operational-icon {
        display: grid;
        width: 42px;
        height: 42px;
        place-items: center;
        border-radius: 13px;
        color: rgb(var(--primary-rgb));
        background: rgba(var(--primary-rgb), .09);
        font-size: 1.05rem;
    }
    .operational-copy { min-width: 0; }
    .operational-copy strong,
    .operational-copy small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .operational-copy strong { font-size: .82rem; font-weight: 900; }
    .operational-copy small { margin-top: .15rem; color: #64756c; font-size: .68rem; }
    .operational-action {
        min-height: 42px;
        padding: 0 .85rem;
        border: 0;
        border-radius: 12px;
        color: #fff;
        background: rgb(var(--primary-rgb));
        font: inherit;
        font-size: .72rem;
        font-weight: 850;
        white-space: nowrap;
    }
    .operational-action:disabled { opacity: .55; }
    .operational-dismiss {
        display: grid;
        width: 38px;
        height: 38px;
        place-items: center;
        border: 0;
        border-radius: 11px;
        color: #789086;
        background: #f1f5f3;
    }
}

@media (max-width: 560px) {
    .operational-float { grid-template-columns: 38px minmax(0, 1fr) 34px; }
    .operational-icon { width: 38px; height: 38px; }
    .operational-action { grid-column: 1 / -1; width: 100%; }
    .operational-dismiss { grid-column: 3; grid-row: 1; }
}
</style>
