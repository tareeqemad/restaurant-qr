<script setup>
/**
 * مركز خدمة الجرسون — Wave 3.ب. ONE prioritized list: the server merged
 * pending / production / ready / billing into tasks and sorted them, so
 * the waiter reads top-to-bottom and never picks a column.
 *
 * Design brief (owner: «خليها مرنة وتصميم سهل»): one card shape for every
 * kind, five filter chips, and everything else expands in place. Refresh
 * rides a 3s visible pulse check; the full task payload is only reloaded
 * when the branch changes (plus one 30s safety refresh).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ChangeRequestCard from '../../../Components/Service/ChangeRequestCard.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import TaskCard from '../../../Components/Service/TaskCard.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useLiveRefresh } from '../../../Composables/useLiveRefresh';
import { useToast } from '../../../Composables/useToast';
import { beep } from '../../../Support/audio';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    tasks: { type: Array, required: true },
    stats: { type: Object, required: true },
    changeRequests: { type: Array, required: true },
    stockReports: { type: Object, required: true },
    filters: { type: Object, required: true },
    live: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const toast = useToast();
const { ask } = useConfirm();

const expanded = ref(new Set());
const toggle = (key) => {
    const next = new Set(expanded.value);
    next.has(key) ? next.delete(key) : next.add(key);
    expanded.value = next;
};

// ── Focus chips ──────────────────────────────────────────────────────
const chips = computed(() => [
    { key: 'all', label: 'الكل', count: null },
    { key: 'help', label: 'نداء نادل', count: props.stats.help, tone: 'hot' },
    { key: 'urgent', label: 'المتأخر', count: props.stats.urgent, tone: 'hot' },
    { key: 'pending', label: 'بانتظار الاعتماد', count: props.stats.pending },
    { key: 'ready', label: 'جاهز للتقديم', count: props.stats.readyItems, tone: 'ready' },
    { key: 'billing', label: 'فواتير', count: props.stats.billing },
    { key: 'production', label: 'بالمطبخ', count: props.stats.production },
]);

const setFocus = (focus) => {
    router.get(props.urls.self, {
        focus: focus === 'all' ? undefined : focus,
        table_id: props.filters.tableId || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const clearTable = () => {
    router.get(props.urls.self, {
        focus: props.filters.focus === 'all' ? undefined : props.filters.focus,
    }, { preserveState: true, preserveScroll: true });
};

// ── Live refresh ─────────────────────────────────────────────────────
const refresh = () => router.reload({
    only: ['tasks', 'stats', 'changeRequests', 'stockReports', 'live'],
    preserveScroll: true,
});

let lastVersion = props.live.version;
let idlePolls = 0;
watch(() => props.live.version, (v) => { lastVersion = v; });

const checkPulse = async (signal) => {
    try {
        const res = await fetch(props.urls.pulse, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        });
        const data = res.ok ? await res.json() : null;
        if (! data || data.version !== lastVersion || ++idlePolls >= 10) {
            idlePolls = 0;
            refresh();
        }
    } catch { /* offline — next poll retries */ }
};

useLiveRefresh({
    pollMs: 3000,
    onPing: (reason, signal) => (reason === 'poll' ? checkPulse(signal) : refresh()),
});

// ── Sound: one chime per change, strict priority order (ported) ──────
const soundOn = ref((() => {
    try { return localStorage.getItem('wbSoundMuted') !== '1'; } catch { return true; }
})());
const toggleSound = () => {
    soundOn.value = ! soundOn.value;
    try { localStorage.setItem('wbSoundMuted', soundOn.value ? '0' : '1'); } catch { /* private mode */ }
};

let prev = null;
watch(() => props.stats, (s) => {
    const snap = {
        help: s.help,
        changes: props.changeRequests.length,
        ready: s.readyItems,
        pending: s.pending,
        billing: s.billing,
        hot: s.readyHot,
        cold: s.readyCold,
    };
    // First render records the baseline WITHOUT beeping.
    if (! prev) { prev = snap; return; }

    if (soundOn.value) {
        // Strict else-if: exactly one sound per change, most urgent wins.
        if (snap.help > prev.help) { beep(1046, .14, 'square', .3); setTimeout(() => beep(1046, .14, 'square', .3), 190); setTimeout(() => beep(1046, .18, 'square', .3), 380); }
        else if (snap.changes > prev.changes) { beep(988, .12, 'triangle', .3); setTimeout(() => beep(740, .12, 'triangle', .3), 130); setTimeout(() => beep(988, .14, 'triangle', .3), 260); }
        else if (snap.ready > prev.ready) { beep(1175, .12, 'sine', .32); setTimeout(() => beep(1568, .12, 'sine', .32), 120); setTimeout(() => beep(1976, .16, 'sine', .32), 240); }
        else if (snap.pending > prev.pending) { beep(880, .16, 'sine', .32); setTimeout(() => beep(1175, .2, 'sine', .32), 170); }
        else if (snap.billing > prev.billing) { beep(523, .14, 'sine', .3); setTimeout(() => beep(784, .18, 'sine', .3), 150); }
        else if (snap.hot > prev.hot) { beep(440, .16, 'square', .24); setTimeout(() => beep(440, .16, 'square', .24), 240); setTimeout(() => beep(440, .16, 'square', .24), 480); }
        else if (snap.cold > prev.cold) { beep(660, .16, 'sine', .26); }
    }

    prev = snap;
}, { immediate: true, deep: true });

// ── Actions ──────────────────────────────────────────────────────────
const busyKeys = ref(new Set());
const offline = ref(typeof navigator !== 'undefined' ? ! navigator.onLine : false);
const markOnline = () => { offline.value = false; };
const markOffline = () => { offline.value = true; };

onMounted(() => {
    window.addEventListener('online', markOnline);
    window.addEventListener('offline', markOffline);
});
onBeforeUnmount(() => {
    window.removeEventListener('online', markOnline);
    window.removeEventListener('offline', markOffline);
});

const actionKey = (payload) => {
    if (payload.request_id) return `request:${payload.request_id}`;
    if (payload.item_id) return `item:${payload.item_id}`;
    if (payload.order_id) return `order:${payload.order_id}`;
    if (payload.session_id) return `session:${payload.session_id}`;
    return `verb:${payload.verb}`;
};
const setBusy = (key, value) => {
    const next = new Set(busyKeys.value);
    value ? next.add(key) : next.delete(key);
    busyKeys.value = next;
};
const requestBusy = (request) => busyKeys.value.has(`request:${request.id}`);
const taskBusy = (task) => busyKeys.value.has(`order:${task.orderId}`)
    || busyKeys.value.has(`session:${task.sessionId}`)
    || task.items.some((item) => busyKeys.value.has(`item:${item.id}`));

const act = async (payload) => {
    const key = actionKey(payload);
    if (busyKeys.value.has(key)) return;
    if (offline.value) {
        toast.warning('الاتصال مقطوع — لم ننفّذ الإجراء. حاول بعد عودة الاتصال.');
        return;
    }

    if (payload.verb === 'cancel-item') {
        const yes = await ask({
            title: `إلغاء الصنف «${payload.name}»؟`,
            message: 'بينلغي من الطلب وبترجع مكوناته للمخزون.',
            confirmLabel: 'ألغِ الصنف',
            danger: true,
        });
        if (! yes) return;
    }
    if (payload.verb === 'resolve-change') {
        const yes = await ask({
            title: payload.decision === 'approve'
                ? (payload.disposition === 'waste' ? 'تنفيذ التعديل وتسجيل هدر؟' : 'تنفيذ تعديل الزبون؟')
                : 'رفض طلب التعديل؟',
            confirmLabel: payload.decision === 'approve' ? 'نفّذ' : 'ارفض',
            danger: payload.decision === 'reject' || payload.disposition === 'waste',
        });
        if (! yes) return;
    }

    setBusy(key, true);
    try {
        const res = await fetch(props.urls.action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => null);
        if (data?.ok) toast.success(data.message);
        else toast.warning(data?.message ?? 'تعذّر التنفيذ — حاول مجدداً.');
    } catch {
        toast.error('انقطع الاتصال — ما اتنفذ الإجراء.');
    } finally {
        setBusy(key, false);
        refresh();
    }
};

const heroTone = computed(() => {
    if (props.stats.readyHot > 0 || props.stats.urgent >= 5) return 'hot';
    if (props.stats.urgent > 0) return 'warm';
    return 'calm';
});
</script>

<template>
    <Head title="طلبات الصالة" />

    <PageHeader title="طلبات الصالة" icon="bi-person-check-fill"
                subtitle="كل شي محتاج تدخّلك — مرتّب بالأهم أولاً">
        <template #actions>
            <button type="button" class="sv-sound" :class="{ 'is-off': ! soundOn }" @click="toggleSound">
                <i class="bi" :class="soundOn ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'"></i>
                {{ soundOn ? 'الصوت يعمل' : 'تفعيل الصوت' }}
            </button>
            <a :href="urls.list" class="sv-sound"><i class="bi bi-list-ul"></i> كل الطلبات</a>
        </template>
    </PageHeader>

    <div class="sv-board">
        <!-- ── Hero line ──────────────────────────────────────────── -->
        <div class="sv-hero" :class="`is-${heroTone}`">
            <div class="sv-hero-main">
                <i class="bi" :class="stats.urgent > 0 ? 'bi-exclamation-triangle-fill' : 'bi-check2-circle'"></i>
                <span v-if="stats.urgent > 0"><strong>{{ stats.urgent }}</strong> مهمة متأخرة</span>
                <span v-else>كل شي تحت السيطرة</span>
            </div>
            <div class="sv-hero-side">
                <span v-if="stats.readyItems > 0"><i class="bi bi-bell-fill"></i> {{ stats.readyItems }} جاهز</span>
                <span v-if="stats.pending > 0"><i class="bi bi-inbox-fill"></i> {{ stats.pending }} بانتظار</span>
                <span v-if="stats.billing > 0"><i class="bi bi-receipt-cutoff"></i> {{ stats.billing }} فاتورة</span>
            </div>
        </div>

        <div v-if="offline" class="sv-offline" role="alert">
            <i class="bi bi-wifi-off"></i>
            <span><strong>الاتصال مقطوع</strong><small>المهام ظاهرة للمتابعة، لكن الإجراءات متوقفة حتى يعود الاتصال.</small></span>
        </div>

        <!-- ── Table filter banner (deep-link from the floor) ─────── -->
        <div v-if="filters.tableId" class="sv-tablefilter">
            <i class="bi bi-filter"></i>
            <span>معروض طاولة {{ filters.tableLabel ?? filters.tableId }} فقط</span>
            <button type="button" @click="clearTable"><i class="bi bi-x-lg"></i> اعرض الكل</button>
        </div>

        <!-- ── Focus chips ────────────────────────────────────────── -->
        <div class="sv-chips">
            <button v-for="chip in chips" :key="chip.key" type="button"
                    class="sv-chip" :class="[{ 'is-active': filters.focus === chip.key }, chip.tone ? `sv-chip--${chip.tone}` : '']"
                    @click="setFocus(chip.key)">
                {{ chip.label }}
                <span v-if="chip.count" class="sv-chip-n">{{ chip.count }}</span>
            </button>
        </div>

        <!-- ── Change requests — the guest is waiting on these ────── -->
        <section v-if="changeRequests.length" class="sv-changes">
            <h3><i class="bi bi-pencil-square"></i> طلبات تعديل من الزبائن <span>{{ changeRequests.length }}</span></h3>
            <ChangeRequestCard v-for="cr in changeRequests" :key="cr.id" :request="cr" :busy="requestBusy(cr) || offline" @act="act" />
        </section>

        <!-- ── The one list ───────────────────────────────────────── -->
        <section class="sv-list">
            <TaskCard v-for="task in tasks" :key="task.key"
                      :task="task" :stock="stockReports[task.orderId] ?? null"
                      :expanded="expanded.has(task.key)" :busy="taskBusy(task) || offline"
                      @toggle="toggle" @act="act" />

            <EmptyState v-if="tasks.length === 0 && changeRequests.length === 0"
                        icon="bi-check2-circle" title="ما في شي محتاج تدخّلك"
                        message="كل الطلبات ماشية — رح تظهر المهمة الجديدة هون تلقائياً مع صوت تنبيه." />

            <EmptyState v-else-if="tasks.length === 0"
                        icon="bi-funnel" title="ما في مهام بهذا الفلتر"
                        message="جرّب «الكل» لتشوف باقي المهام." />
        </section>
    </div>
</template>

<style scoped>
.sv-board { display: flex; flex-direction: column; gap: .8rem; padding-bottom: 2rem; }
.sv-offline { display: flex; align-items: center; gap: .6rem; padding: .7rem .85rem; border: 1px solid #fecaca; border-radius: 13px; background: #fff5f5; color: #991b1b; }
.sv-offline > i { font-size: 1.1rem; }
.sv-offline span, .sv-offline strong, .sv-offline small { display: block; }
.sv-offline strong { font-size: .8rem; font-weight: 950; }
.sv-offline small { margin-top: .08rem; font-size: .68rem; line-height: 1.45; }

.sv-sound {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 40px;
    padding: 0 .9rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .8rem;
    font-weight: 700;
    cursor: pointer;
}
.sv-sound.is-off { color: #94a3b8; }

.sv-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    border-radius: 16px;
    padding: .9rem 1.1rem;
    font-weight: 800;
}
.sv-hero.is-calm { background: #ecfdf5; color: #065f46; }
.sv-hero.is-warm { background: #fffbeb; color: #92400e; }
.sv-hero.is-hot { background: #fef2f2; color: #991b1b; }
.sv-hero-main { display: flex; align-items: center; gap: .55rem; font-size: 1rem; }
.sv-hero-main strong { font-size: 1.3rem; }
.sv-hero-side { display: flex; gap: .9rem; flex-wrap: wrap; font-size: .82rem; font-weight: 700; opacity: .9; }

.sv-tablefilter {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e40af;
    border-radius: 12px;
    padding: .55rem .8rem;
    font-size: .82rem;
    font-weight: 700;
}
.sv-tablefilter button {
    margin-inline-start: auto;
    border: 0;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 8px;
    min-height: 34px;
    padding: 0 .6rem;
    font: inherit;
    font-size: .76rem;
    font-weight: 800;
    cursor: pointer;
}

.sv-chips { display: flex; gap: .4rem; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; }
.sv-chips::-webkit-scrollbar { display: none; }
.sv-chip {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    min-height: 42px;
    padding: 0 .9rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .82rem;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
}
.sv-chip.is-active { background: rgb(var(--primary-rgb)); border-color: rgb(var(--primary-rgb)); color: #fff; }
.sv-chip--hot.is-active { background: #b91c1c; border-color: #b91c1c; }
.sv-chip--ready.is-active { background: #047857; border-color: #047857; }
.sv-chip-n {
    background: rgba(15, 23, 42, .08);
    border-radius: 999px;
    padding: 0 .4rem;
    font-size: .72rem;
}
.sv-chip.is-active .sv-chip-n { background: rgba(255, 255, 255, .25); }

.sv-changes { display: flex; flex-direction: column; gap: .6rem; }
.sv-changes h3 {
    display: flex;
    align-items: center;
    gap: .45rem;
    margin: 0;
    font-size: .92rem;
    font-weight: 900;
    color: #92400e;
}
.sv-changes h3 span {
    background: #fef3c7;
    border-radius: 999px;
    padding: 0 .5rem;
    font-size: .76rem;
}

.sv-list { display: flex; flex-direction: column; gap: .6rem; }
</style>
