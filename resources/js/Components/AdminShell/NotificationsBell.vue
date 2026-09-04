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

const { open, root, toggle, close } = useDropdown();
const unread = ref(0);
const items = ref([]);
const loading = ref(true);
const markingAll = ref(false);
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
    if (document.hidden || polling || navigationBusy) return;
    if (! navigator.onLine) {
        loading.value = false;
        return;
    }
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
        loading.value = false;
    }
};

const toggleMenu = () => {
    toggle();
    if (open.value) refresh();
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
    close();
    dismissAlert(notification);
    markRead(notification);
    if (notification.action_url) router.visit(notification.action_url);
};

const markAllRead = async () => {
    if (markingAll.value) return;
    markingAll.value = true;
    try {
        const res = await call(props.urls.readAll, 'POST');
        if (res?.ok) {
            unread.value = res.unread;
            items.value.forEach((i) => { i.read = true; });
        }
    } finally {
        markingAll.value = false;
    }
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
        <button
            type="button"
            class="header-link bell-trigger"
            :class="{ 'is-open': open }"
            aria-haspopup="dialog"
            aria-controls="notifications-popover"
            :aria-expanded="open"
            aria-label="فتح الإشعارات"
            title="الإشعارات"
            @click="toggleMenu"
        >
            <svg class="header-link-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><path opacity=".3" d="M12 6.5c-2.49 0-4 2.02-4 4.5v6h8v-6c0-2.48-1.51-4.5-4-4.5z"></path><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-11c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2v-5zm-2 6H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"></path></svg>
            <span v-if="unread > 0" class="bell-count" :aria-label="`${unread} إشعار غير مقروء`">{{ unread > 99 ? '99+' : unread }}</span>
        </button>

        <section
            v-show="open"
            id="notifications-popover"
            class="main-header-dropdown dropdown-menu dropdown-menu-end notifications-menu show bell-menu"
            role="dialog"
            aria-modal="false"
            aria-labelledby="notifications-title"
        >
            <header class="bell-menu__header">
                <span class="bell-menu__heading-icon" aria-hidden="true"><i class="bi bi-bell-fill"></i></span>
                <span class="bell-menu__heading">
                    <strong id="notifications-title">الإشعارات</strong>
                    <small>{{ unread > 0 ? `${unread} غير مقروء` : 'لا يوجد جديد' }}</small>
                </span>
                <button
                    v-if="unread > 0"
                    type="button"
                    class="mark-all"
                    :disabled="markingAll"
                    @click="markAllRead"
                >
                    <i class="bi bi-check2-all" aria-hidden="true"></i>
                    {{ markingAll ? 'جارٍ التحديث…' : 'قراءة الكل' }}
                </button>
            </header>

            <div class="notifications-scroll" aria-live="polite">
                <div v-if="loading" class="notifications-loading" role="status">
                    <span></span><span></span><span></span>
                    <small>جارٍ التحقق من الجديد…</small>
                </div>

                <div v-else-if="items.length === 0" class="notifications-empty">
                    <span aria-hidden="true"><i class="bi bi-check2-circle"></i></span>
                    <strong>كل شيء تحت السيطرة</strong>
                    <small>لا توجد إشعارات تحتاج انتباهك الآن.</small>
                </div>

                <ul v-else class="notifications-list">
                    <li v-for="n in items" :key="n.id">
                        <article class="notification-row" :class="{ 'is-read': n.read }">
                            <a :href="n.action_url || '#'" class="notification-link" @click.prevent="openNotification(n)">
                            <span class="notify-avatar" :class="sevClass(n.severity)">
                                <i class="bi" :class="n.icon || 'bi-bell'"></i>
                            </span>
                                <span class="notification-copy">
                                    <span class="notification-title">
                                        <strong>{{ n.title }}</strong>
                                        <small>{{ n.created_at }}</small>
                                    </span>
                                    <span v-if="n.body" class="notification-body">{{ n.body }}</span>
                                </span>
                                <span v-if="! n.read" class="unread-dot" aria-label="غير مقروء"></span>
                            </a>
                            <button
                                v-if="n.quick_action"
                                type="button"
                                class="quick-action"
                                :disabled="busyQuick.has(n.id)"
                                @click="quickAct(n)"
                            >
                                <i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>
                                {{ busyQuick.has(n.id) ? 'جارٍ التنفيذ…' : n.quick_action.label }}
                            </button>
                        </article>
                    </li>
                </ul>
            </div>

            <a :href="urls.index" class="bell-menu__footer" @click="close">
                <span>
                    <i class="bi bi-inbox" aria-hidden="true"></i>
                    عرض سجل الإشعارات
                </span>
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </a>
        </section>

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
.bell {
    position: relative;
    isolation: isolate;
}
.bell-trigger {
    position: relative;
    border: 1px solid rgba(var(--primary-rgb), .11);
    background: #fff;
    cursor: pointer;
}
.bell-trigger:hover,
.bell-trigger:focus-visible,
.bell-trigger.is-open {
    color: rgb(var(--primary-rgb));
    border-color: rgba(var(--primary-rgb), .28);
    background: rgba(var(--primary-rgb), .07);
    outline: none;
}
.bell-trigger:focus-visible {
    box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .13);
}
.bell-count {
    position: absolute;
    inset-block-start: 3px;
    inset-inline-end: 3px;
    display: grid;
    min-width: 17px;
    height: 17px;
    padding-inline: 4px;
    place-items: center;
    border: 2px solid #fff;
    border-radius: 999px;
    color: #fff;
    background: #d33e45;
    font-size: .5rem;
    font-weight: 900;
    line-height: 1;
}
.bell-menu {
    position: absolute !important;
    top: calc(100% + 10px) !important;
    right: auto !important;
    left: auto !important;
    inset-inline-end: 0 !important;
    inset-inline-start: auto !important;
    z-index: 1200;
    width: min(390px, calc(100vw - 1rem)) !important;
    min-width: 0 !important;
    max-width: none !important;
    padding: 0 !important;
    overflow: hidden;
    border: 1px solid #dce7e1;
    border-radius: 16px !important;
    background: #fff;
    box-shadow: 0 22px 55px -24px rgba(18, 53, 35, .42) !important;
    transform: none !important;
}
.bell-menu::before { display: none !important; }
.bell-menu__header {
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr) auto;
    align-items: center;
    gap: .6rem;
    min-height: 66px;
    padding: .7rem .8rem;
    border-bottom: 1px solid #e8efeb;
    background: linear-gradient(135deg, #fff 10%, #f3f9f5 100%);
}
.bell-menu__heading-icon {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 11px;
    color: rgb(var(--primary-rgb));
    background: rgba(var(--primary-rgb), .09);
}
.bell-menu__heading {
    display: grid;
    min-width: 0;
    gap: .1rem;
}
.bell-menu__heading strong {
    color: #1f382c;
    font-size: .78rem;
    font-weight: 900;
}
.bell-menu__heading small {
    color: #7c8c84;
    font-size: .59rem;
    font-weight: 700;
}
.mark-all {
    display: inline-flex;
    min-height: 34px;
    align-items: center;
    gap: .3rem;
    padding-inline: .6rem;
    border: 1px solid rgba(var(--primary-rgb), .2);
    border-radius: 9px;
    color: rgb(var(--primary-rgb));
    background: #fff;
    font: inherit;
    font-size: .6rem;
    font-weight: 850;
    white-space: nowrap;
}
.mark-all:disabled { opacity: .55; }
.notifications-scroll {
    max-height: min(58vh, 430px);
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #c8d8cf transparent;
}
.notifications-list {
    margin: 0;
    padding: 0;
    list-style: none;
}
.notification-row {
    position: relative;
    padding: .35rem .45rem .5rem;
    border-bottom: 1px solid #edf2ef;
}
.notification-row.is-read { background: #fbfcfb; }
.notification-link {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr) 8px;
    min-height: 58px;
    align-items: center;
    gap: .6rem;
    padding: .5rem .55rem;
    border-radius: 11px;
    color: inherit;
    text-decoration: none;
}
.notification-link:hover,
.notification-link:focus-visible {
    background: #f3f8f5;
    outline: none;
}
.notify-avatar {
    display: grid;
    width: 40px;
    height: 40px;
    place-items: center;
    border-radius: 12px;
    font-size: .95rem;
}
.notification-copy {
    display: grid;
    min-width: 0;
    gap: .18rem;
}
.notification-title {
    display: flex;
    min-width: 0;
    align-items: baseline;
    justify-content: space-between;
    gap: .55rem;
}
.notification-title strong {
    overflow: hidden;
    color: #253a30;
    font-size: .7rem;
    font-weight: 900;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.notification-title small {
    flex: 0 0 auto;
    color: #8b9991;
    font-size: .54rem;
    white-space: nowrap;
}
.notification-body {
    display: -webkit-box;
    overflow: hidden;
    color: #697a71;
    font-size: .61rem;
    line-height: 1.55;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}
.unread-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: rgb(var(--primary-rgb));
    box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .11);
}
.is-read .unread-dot { visibility: hidden; }
.quick-action {
    display: inline-flex;
    min-height: 34px;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    margin-inline: 3.65rem .55rem;
    padding: 0 .75rem;
    border: 1px solid rgba(var(--primary-rgb), .22);
    border-radius: 9px;
    color: rgb(var(--primary-rgb));
    background: rgba(var(--primary-rgb), .065);
    font: inherit;
    font-size: .62rem;
    font-weight: 850;
}
.quick-action:hover { background: rgba(var(--primary-rgb), .11); }
.quick-action:disabled { opacity: .55; }
.notifications-empty,
.notifications-loading {
    display: grid;
    min-height: 142px;
    align-content: center;
    justify-items: center;
    gap: .3rem;
    padding: 1.1rem;
    text-align: center;
}
.notifications-empty > span {
    display: grid;
    width: 46px;
    height: 46px;
    margin-bottom: .15rem;
    place-items: center;
    border-radius: 14px;
    color: #16814c;
    background: #eaf6ee;
    font-size: 1.2rem;
}
.notifications-empty strong { color: #294137; font-size: .72rem; font-weight: 900; }
.notifications-empty small,
.notifications-loading small { color: #819087; font-size: .59rem; }
.notifications-loading {
    grid-template-columns: repeat(3, 8px);
    column-gap: .3rem;
}
.notifications-loading span {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgb(var(--primary-rgb));
    animation: bell-loading .8s ease-in-out infinite alternate;
}
.notifications-loading span:nth-child(2) { animation-delay: .16s; }
.notifications-loading span:nth-child(3) { animation-delay: .32s; }
.notifications-loading small { grid-column: 1 / -1; margin-top: .3rem; }
.bell-menu__footer {
    display: flex;
    min-height: 48px;
    align-items: center;
    justify-content: space-between;
    gap: .7rem;
    padding: .65rem .85rem;
    border-top: 1px solid #e7eee9;
    color: #285c43;
    background: #fbfdfc;
    font-size: .65rem;
    font-weight: 850;
    text-decoration: none;
}
.bell-menu__footer span { display: inline-flex; align-items: center; gap: .4rem; }
.bell-menu__footer:hover,
.bell-menu__footer:focus-visible { color: rgb(var(--primary-rgb)); background: #f1f8f4; outline: none; }
@keyframes bell-loading { to { opacity: .28; transform: translateY(-3px); } }
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
    .bell-menu {
        position: fixed !important;
        top: 68px !important;
        right: 8px !important;
        left: 8px !important;
        width: auto !important;
    }
    .bell-menu__header { grid-template-columns: 36px minmax(0, 1fr) auto; }
    .bell-menu__heading-icon { width: 36px; height: 36px; }
    .mark-all { padding-inline: .5rem; }
    .notification-title { display: grid; gap: .08rem; }
    .notification-title small { grid-row: 1; }
    .operational-float { grid-template-columns: 38px minmax(0, 1fr) 34px; }
    .operational-icon { width: 38px; height: 38px; }
    .operational-action { grid-column: 1 / -1; width: 100%; }
    .operational-dismiss { grid-column: 3; grid-row: 1; }
}
</style>
