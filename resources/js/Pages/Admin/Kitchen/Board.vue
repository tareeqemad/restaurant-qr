<script setup>
/**
 * شاشة المحطة (KDS) v4 — Wave 3. Chrome-free full-viewport board: wall
 * mode is now the ONLY mode. Waiting, cooking, and waiter hand-off are
 * equal filterable ticket stages so a ready order never collapses into a
 * tiny strip or disappears under pressure.
 *
 * Refresh: a 5s visible pulse; changed data arrives in that same response.
 * Sounds ride the ID-set diff rules ported verbatim in Support/kdsSound.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import KdsCard from '../../../Components/Kds/KdsCard.vue';
import Toaster from '../../../Components/Ui/Toaster.vue';
import { useLiveRefresh } from '../../../Composables/useLiveRefresh';
import { useToast } from '../../../Composables/useToast';
import { checkKdsChanges, kdsSoundEnabled, setKdsSound } from '../../../Support/kdsSound';
import { audioReady, beep, unlockAudio } from '../../../Support/audio';
import { kdsCounts, kdsDisconnected, selectKdsCards } from '../../../Support/kdsBoard';

const props = defineProps({
    station: { type: Object, required: true },
    board: { type: Object, required: true },
    filters: { type: Object, required: true },
    live: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const toast = useToast();
const page = usePage();
const brandName = computed(() => page.props.restaurantBrand?.name || 'مطعم دار السرو');
const stationLabel = computed(() => props.station.name || 'المحطة');
const board = ref(props.board);
let disposed = false;
watch(() => props.board, (value) => { board.value = value; });

// ── Clock ────────────────────────────────────────────────────────────
const clock = ref('00:00');
const now = ref(Date.now());
let clockTimer = null;
const syncClock = () => {
    clock.value = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    now.value = Date.now();
};

// ── Sound ────────────────────────────────────────────────────────────
const soundOn = ref(kdsSoundEnabled());
let soundIntent = null;
const prepareSoundToggle = () => { soundIntent = soundOn.value && audioReady.value ? 'mute' : 'enable'; };
const toggleSound = async () => {
    const intent = soundIntent ?? (soundOn.value && audioReady.value ? 'mute' : 'enable');
    soundIntent = null;
    if (intent === 'mute') {
        soundOn.value = false;
    } else {
        soundOn.value = true;
        if (await unlockAudio()) beep(880, .15, 'sine', .25);
        else toast.warning('تعذّر تشغيل الصوت. تحقق من إعدادات الجهاز.');
    }
    setKdsSound(soundOn.value);
};

watch(board, (board) => {
    checkKdsChanges({
        orderIds: board.orderIds,
        readyIds: board.readyIds,
        changeRequestIds: board.changeRequestIds,
        redCount: board.load.redCards,
        filter: props.station.code,
        enabled: soundOn.value,
    });
}, { immediate: true });

// ── Connection dot ───────────────────────────────────────────────────
const networkOnline = ref(navigator.onLine);
const serverAvailable = ref(true);
const lastContactAt = ref(Date.now());
const offline = computed(() => kdsDisconnected({
    networkOnline: networkOnline.value, serverAvailable: serverAvailable.value,
    lastContactAt: lastContactAt.value, now: now.value,
}));
const markOnline = () => { networkOnline.value = true; };
const markOffline = () => { networkOnline.value = false; };

onMounted(() => {
    syncClock();
    clockTimer = window.setInterval(syncClock, 1000);
    window.addEventListener('online', markOnline);
    window.addEventListener('offline', markOffline);
});

onBeforeUnmount(() => {
    disposed = true;
    window.clearInterval(clockTimer);
    window.removeEventListener('online', markOnline);
    window.removeEventListener('offline', markOffline);
});

// ── One-screen focus — filtering and sorting never leave the board ───
const focus = ref('all');
const sort = ref(props.filters.sort || 'urgency');
const selectedTable = ref(props.filters.table || '');

const counts = computed(() => kdsCounts(board.value));

const pressureMode = computed(() => counts.value.total >= 12);

const visibleCards = computed(() => selectKdsCards(board.value, {
    focus: focus.value, table: selectedTable.value, sort: sort.value,
}));

const focusTable = (num) => {
    selectedTable.value = selectedTable.value === String(num) ? '' : String(num);
};

// ── Live refresh ─────────────────────────────────────────────────────
let lastVersion = props.live.version;
let idlePolls = 0;
watch(() => props.live.version, (v) => { lastVersion = v; });
let latestSnapshotRequest = 0;
let latestSuccessfulRequest = 0;
let requestSequence = 0;
let reconcileAfterActions = false;
let forcePulsePending = false;
const acceptSnapshot = (data, sequence) => {
    if (disposed || sequence < latestSnapshotRequest) return;
    if (data.board) {
        latestSnapshotRequest = sequence;
        board.value = data.board;
        lastVersion = data.version;
    }
    if (sequence >= latestSuccessfulRequest) {
        latestSuccessfulRequest = sequence;
        lastContactAt.value = Date.now();
        serverAvailable.value = true;
    }
};

const pulseBusy = ref(false);
const checkPulse = async (signal, force = false) => {
    if (disposed) return;
    if (pulseBusy.value || busyKeys.value.size > 0) {
        if (force) forcePulsePending = true;
        return;
    }
    force = force || forcePulsePending;
    forcePulsePending = false;
    pulseBusy.value = true;
    const sequence = ++requestSequence;
    const timeout = new AbortController();
    const abort = () => timeout.abort();
    signal?.addEventListener('abort', abort, { once: true });
    const timer = window.setTimeout(abort, 8000);
    try {
        const url = new URL(props.urls.pulse, window.location.origin);
        url.searchParams.set('version', lastVersion);
        if (force || ++idlePolls >= 4) url.searchParams.set('refresh', '1');
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: timeout.signal,
        });
        if (!res.ok) throw new Error('Pulse failed');
        const data = await res.json();
        if (typeof data.version !== 'string') throw new Error('Invalid pulse');
        if (data.board) idlePolls = 0;
        acceptSnapshot(data, sequence);
    } catch {
        if (!signal?.aborted && sequence >= latestSnapshotRequest && sequence >= latestSuccessfulRequest) serverAvailable.value = false;
    } finally {
        window.clearTimeout(timer);
        signal?.removeEventListener('abort', abort);
        pulseBusy.value = false;
        if (forcePulsePending && !signal?.aborted && busyKeys.value.size === 0) checkPulse(undefined, true);
    }
};

useLiveRefresh({
    pollMs: 5000,
    onPing: (_reason, signal) => checkPulse(signal),
});

// ── Actions ──────────────────────────────────────────────────────────
const busyKeys = ref(new Set());
const actionKey = (payload) => payload.item_id
    ? `item:${payload.item_id}`
    : (payload.order_id ? `order:${payload.order_id}` : `verb:${payload.verb}`);
const setBusy = (key, value) => {
    const next = new Set(busyKeys.value);
    value ? next.add(key) : next.delete(key);
    busyKeys.value = next;
};
const itemBusy = (itemId) => busyKeys.value.has(`item:${itemId}`);
const cardBusy = (card) => busyKeys.value.has(`order:${card.orderId}`)
    || card.items.some((item) => itemBusy(item.id));

const act = async (payload) => {
    const key = actionKey(payload);
    if (busyKeys.value.has(key)) return;
    if (offline.value) {
        toast.warning('الاتصال مقطوع — لم ننفّذ الإجراء. حاول بعد عودة الاتصال.');
        return;
    }
    setBusy(key, true);
    const sequence = ++requestSequence;
    // A pulse started before this mutation must not restore the old button.
    latestSnapshotRequest = sequence;
    if (busyKeys.value.size > 1) reconcileAfterActions = true;
    const timeout = new AbortController();
    const timer = window.setTimeout(() => timeout.abort(), 15000);
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
            signal: timeout.signal,
            body: JSON.stringify({ ...payload, include_board: true }),
        });
        const data = await res.json().catch(() => null);
        if (!data || typeof data.ok !== 'boolean') throw new Error('Invalid action response');
        if (res.status >= 500) throw new Error('Server error');
        acceptSnapshot(data, sequence);
        if (disposed) return;
        if (data?.ok && data.message) toast.success(data.message);
        else if (data && ! data.ok) toast.warning(data.message ?? 'تعذّر التنفيذ — حاول مجدداً.');
    } catch {
        if (disposed) return;
        serverAvailable.value = false;
        toast.error('لم نتأكد من نتيجة الإجراء. نتحقق من حالة الطلب عند عودة الاتصال.');
    } finally {
        window.clearTimeout(timer);
        setBusy(key, false);
        if (reconcileAfterActions && busyKeys.value.size === 0) {
            reconcileAfterActions = false;
            checkPulse(undefined, true);
        }
    }
};

const followUp = (card) => card.tableNum && board.value.followUpTables.map(String).includes(String(card.tableNum));
</script>

<template>
    <Head :title="`${station.name} — شاشة المحطة`" />

    <div class="kds" :class="{ 'kds--pressure': pressureMode }">
        <header class="kds-command" :class="`kb-load-${board.load.level}`">
            <div class="kds-identity">
                <span class="kds-station-icon">{{ station.emoji }}</span>
                <div>
                    <span class="kds-brand">{{ brandName }} · التشغيل</span>
                    <h1>{{ station.name }}</h1>
                    <p>
                        <span>{{ clock }}</span>
                        <span v-if="board.load.oldestAge > 0" class="kds-oldest">الأقدم {{ board.load.oldestAge }}د</span>
                        <span v-if="pressureMode" class="kds-pressure-badge"><i class="bi bi-lightning-charge-fill"></i> وضع الضغط تلقائي</span>
                    </p>
                </div>
            </div>

            <nav class="kds-stage-switch" aria-label="حالة الطلبات">
                <button type="button" :class="{ 'is-active': focus === 'all' }" @click="focus = 'all'">
                    <span>الكل</span><strong>{{ counts.total }}</strong>
                </button>
                <button type="button" class="is-waiting" :class="{ 'is-active': focus === 'waiting' }" @click="focus = 'waiting'">
                    <span>جديد</span><strong>{{ counts.waiting }}</strong>
                </button>
                <button type="button" class="is-cooking" :class="{ 'is-active': focus === 'cooking' }" @click="focus = 'cooking'">
                    <span>يُحضّر</span><strong>{{ counts.cooking }}</strong>
                </button>
                <button type="button" class="is-ready" :class="{ 'is-active': focus === 'ready' }" @click="focus = 'ready'">
                    <span>جاهز للتسليم</span><strong>{{ counts.ready }}</strong>
                </button>
            </nav>

            <div class="kds-tools">
                <label class="kds-sort">
                    <i class="bi bi-sort-down"></i>
                    <select v-model="sort" aria-label="ترتيب الطلبات">
                        <option value="urgency">الأولوية</option>
                        <option value="time">الأقدم</option>
                        <option value="table">الطاولة</option>
                    </select>
                </label>
                <button type="button" class="kds-sound" :class="soundOn && audioReady ? 'is-on' : 'is-off'"
                        :aria-label="soundOn && audioReady ? 'كتم الصوت' : 'تفعيل وتجربة الصوت'"
                        @pointerdown="prepareSoundToggle" @keydown.enter="prepareSoundToggle" @keydown.space="prepareSoundToggle" @click="toggleSound">
                    <i class="bi" :class="soundOn && audioReady ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'"></i>
                    <span>{{ soundOn && audioReady ? 'الصوت يعمل' : 'شغّل الصوت' }}</span>
                </button>
                <span class="kds-connection" :class="{ 'is-offline': offline }" :title="offline ? 'غير متصل بالخادم' : 'متصل بالخادم'"></span>
                <a :href="urls.home" class="kds-home" title="لوحة التحكم"><i class="bi bi-house"></i></a>
            </div>
        </header>

        <div v-if="offline" class="kds-offline" role="status">
            <span><i class="bi bi-wifi-off"></i> البيانات غير محدثة — نتحقق من الاتصال.</span>
            <button type="button" :disabled="pulseBusy || !networkOnline" @click="checkPulse(undefined, true)">إعادة المحاولة</button>
        </div>

        <!-- ── Active-table pills ─────────────────────────────────── -->
        <div v-if="board.activeTables.length > 1" class="kb-table-filter">
            <span class="kb-filter-label"><i class="bi bi-grid-3x3-gap-fill"></i> طاولات نشطة:</span>
            <button v-for="num in board.activeTables" :key="num" type="button"
                    class="kb-table-pill" :class="{ 'is-active': selectedTable === String(num) }"
                    @click="focusTable(num)">
                {{ num }}
            </button>
        </div>

        <!-- ── All-day batching strip ─────────────────────────────── -->
        <div v-if="board.allDay.length" class="kb-allday">
            <span class="kb-allday-label"><i class="bi bi-fire"></i> إجمالي التحضير:</span>
            <div class="kb-allday-scroll">
                <span v-for="d in board.allDay" :key="d.key" class="kb-allday-chip" :class="{ 'has-variations': d.mods.length || d.exclusions.length || d.notes || d.orderNotes }">
                    <span><strong>{{ d.qty }}×</strong> {{ d.name }}</span>
                    <small v-if="d.mods.length">{{ d.mods.map((mod) => mod.name).join(' · ') }}</small>
                    <small v-if="d.exclusions.length" class="kb-batch-exclusions">بدون {{ d.exclusions.join('، ') }}</small>
                    <small v-if="d.notes">{{ d.notes }}</small>
                    <small v-if="d.orderNotes">{{ d.orderNotes }}</small>
                </span>
            </div>
        </div>

        <section class="kds-workspace">
            <header class="kds-workspace-head">
                <div>
                    <h2>{{ focus === 'waiting' ? 'بانتظار التحضير' : (focus === 'cooking' ? 'قيد التحضير' : (focus === 'ready' ? 'جاهز للاستلام' : `طلبات ${stationLabel}`)) }}</h2>
                </div>
                <span class="kds-visible-count">{{ visibleCards.length }} طلب ظاهر</span>
                <button v-if="selectedTable" type="button" class="kds-clear-filter" @click="selectedTable = ''">
                    <i class="bi bi-x-lg"></i> إظهار كل الطاولات
                </button>
            </header>

            <div v-if="visibleCards.length" class="kds-lanes" :class="{ 'is-focused': focus !== 'all' }">
                <section v-for="lane in [{ key: 'waiting', label: 'جديد', icon: 'bi-inbox' }, { key: 'cooking', label: 'قيد التحضير', icon: 'bi-fire' }, { key: 'ready', label: 'جاهز للتسليم', icon: 'bi-bell' }].filter(lane => focus === 'all' || focus === lane.key)" :key="lane.key" class="kds-lane" :class="`kds-lane--${lane.key}`">
                    <header class="kds-lane-heading"><h3><i class="bi" :class="lane.icon"></i> {{ lane.label }}</h3><strong>{{ selectKdsCards(board, { focus: lane.key, table: selectedTable, sort }).length }}</strong></header>
                    <div class="kds-lane-tickets">
                <KdsCard v-for="card in selectKdsCards(board, { focus: lane.key, table: selectedTable, sort })" :key="`${card._column}-${card.orderId}`"
                         :card="card" :column="card._column" :follow-up="followUp(card)"
                         :busy="cardBusy(card) || offline" @act="act"
                         @focus-ready="focus = 'ready'; selectedTable = String(card.tableNum)" />
                    <p v-if="!selectKdsCards(board, { focus: lane.key, table: selectedTable, sort }).length" class="kds-lane-empty">لا طلبات هنا</p>
                    </div>
                </section>
            </div>

            <div v-else class="kds-empty">
                <i class="bi bi-check2-circle"></i>
                <strong>{{ counts.total ? 'لا يوجد طلب يطابق هذا التركيز' : `لا توجد طلبات في ${stationLabel} الآن` }}</strong>
                <small v-if="counts.total">اختر «الكل» أو أزل فلتر الطاولة.</small>
                <button v-if="counts.total" type="button" @click="focus = 'all'; selectedTable = ''">عرض كل الطلبات</button>
            </div>
        </section>
    </div>

    <Toaster />
</template>

<style scoped>
.kds {
    min-height: 100dvh;
    background: var(--canvas, #F7F3ED);
    padding: .75rem .9rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
    color: var(--ink, var(--ink));
}

.kds-command {
    position: sticky;
    top: .35rem;
    z-index: 40;
    display: grid;
    grid-template-columns: minmax(210px, auto) minmax(440px, 1fr) auto;
    align-items: center;
    gap: .65rem;
    min-height: 88px;
    padding: .85rem 1rem;
    border: 1px solid var(--ink, var(--line));
    border-inline-start: 5px solid var(--accent, #B78338);
    border-radius: 12px;
    background: var(--brand-dark, #25343A);
    color: #FFFDFA;
    box-shadow: none;
}
.kds-command.kb-load-amber { border-inline-start-color: #f59e0b; }
.kds-command.kb-load-red { border-inline-start-color: #ef4444; }
.kds-identity { display: flex; align-items: center; gap: .55rem; min-width: 0; }
.kds-station-icon {
    width: 44px; height: 44px; flex: 0 0 44px;
    display: inline-grid; place-items: center;
    border-radius: 11px;
    background: rgba(255,255,255,.13);
    font-size: 1.45rem;
}
.kds-identity h1 { margin: 0; color: #FFFDFA; font-size: 1.35rem; font-weight: 800; }
.kds-identity p { margin: .12rem 0 0; display: flex; align-items: center; gap: .4rem; color: rgba(255,255,255,.78); font-size: .875rem; }
.kds-oldest { color: #7f5525; font-weight: 800; }
.kds-pressure-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 7px;
    border-radius: 8px;
    background: #f8f0e2;
    color: #7f5525;
    font-weight: 800;
}

.kds-stage-switch {
    justify-self: center;
    display: grid;
    grid-template-columns: repeat(4, minmax(105px, 1fr));
    gap: 4px;
    padding: 4px;
    border-radius: 12px;
    background: rgba(0,0,0,.2);
}
.kds-stage-switch button {
    min-height: 46px;
    padding: .25rem .65rem;
    display: flex; align-items: center; justify-content: center; gap: .45rem;
    border: 0; border-radius: 8px;
    background: transparent; color: rgba(255,255,255,.76);
    font: inherit; font-size: .875rem; font-weight: 800; cursor: pointer;
}
.kds-stage-switch button strong {
    min-width: 25px; height: 25px; padding: 0 5px;
    display: inline-grid; place-items: center;
    border-radius: 8px; background: rgba(255,255,255,.13);
    font-variant-numeric: tabular-nums;
}
.kds-stage-switch button.is-active { background: var(--brand, #A3422B); color: #FFFDFA; box-shadow: none; }
.kds-stage-switch button.is-waiting.is-active strong { background: #fff7ed; color: #c2410c; }
.kds-stage-switch button.is-cooking.is-active strong { background: #eff6ff; color: #1d4ed8; }
.kds-stage-switch button.is-ready.is-active strong { background: #ecfdf5; color: #047857; }

.kds-tools { display: flex; align-items: center; justify-content: flex-end; gap: .4rem; }
.kds-sort {
    height: 40px; display: inline-flex; align-items: center; gap: .25rem;
    padding: 0 .5rem; border-radius: 9px; background: rgba(0,0,0,.2);
}
.kds-sort select { border: 0; outline: 0; background: transparent; color: #FFFDFA; font: inherit; font-size: .875rem; font-weight: 800; cursor: pointer; }
.kds-sort option { color: var(--ink, var(--ink)); }
.kds-sound, .kds-home {
    min-height: 40px; border: 0; border-radius: 9px;
    display: inline-flex; align-items: center; justify-content: center; gap: .3rem;
    padding: 0 .65rem; background: rgba(0,0,0,.2); color: #FFFDFA;
    font: inherit; font-size: .875rem; font-weight: 800; cursor: pointer;
}
.kds-sound.is-on { background: rgba(34,197,94,.25); }
.kds-sound.is-off { background: rgba(239,68,68,.28); }
.kds-connection { width: 10px; height: 10px; flex: 0 0 10px; border-radius: 50%; background: var(--brand); box-shadow: 0 0 0 3px rgba(74,222,128,.2); }
.kds-connection.is-offline { background: #f87171; box-shadow: 0 0 0 3px rgba(248,113,113,.2); }
.kds-home {
    text-decoration: none;
    width: 40px; padding: 0;
}
.kds-offline { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .55rem .8rem; border: 1px solid #fecaca; border-radius: 10px; background: #fef2f2; color: #991b1b; font-size: .875rem; font-weight: 800; }
.kds-offline button { min-height: 40px; border: 1px solid #fca5a5; border-radius: 8px; background: var(--surface, #FFFDFA); color: inherit; font: inherit; padding: .35rem .6rem; cursor: pointer; }
.kb-table-filter, .kb-allday { margin: 0; min-height: 42px; padding-block: .4rem; flex-wrap: nowrap; overflow-x: auto; }
.kb-table-pill { min-height: 44px; min-width: 46px; border: 1px solid var(--line, var(--line)); border-radius: 8px; color: var(--ink, var(--ink)); background: var(--surface, #FFFDFA); font-size: 1rem; }
.kb-allday-chip { flex-direction: column; align-items: flex-start; gap: .1rem; padding: .45rem .7rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; background: var(--canvas, #F7F3ED); color: var(--ink, var(--ink)); font-size: .95rem; }
.kb-allday-chip.has-variations { border-color: #eab66c; background: #fff8eb; }
.kb-allday-chip small { font-size: .875rem; white-space: normal; max-width: 240px; }
.kb-allday-chip .kb-batch-exclusions { color: #b91c1c; font-weight: 800; }

.kds-workspace { padding: 0; border: 0; border-radius: 0; background: transparent; box-shadow: none; }
.kds-workspace-head { min-height: 48px; display: flex; align-items: center; gap: .6rem; padding: 0 .15rem .65rem; }
.kds-workspace-head h2 { margin: 0; font-size: .96rem; font-weight: 800; color: var(--ink, var(--ink)); }
.kds-workspace-head p { margin: .12rem 0 0; color: var(--muted, var(--muted)); font-size: .875rem; }
.kds-visible-count { margin-inline-start: auto; padding: .25rem .55rem; border-radius: 8px; background: var(--brand-soft, #F3E3D8); color: var(--brand, #A3422B); font-size: .875rem; font-weight: 800; white-space: nowrap; }
.kds-clear-filter { min-height: 34px; padding: 0 .65rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; background: var(--surface, #FFFDFA); color: var(--ink, var(--ink)); font: inherit; font-size: .875rem; font-weight: 800; cursor: pointer; }


.kds-empty { min-height: 210px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem; color: var(--muted, var(--muted)); text-align: center; }
.kds-empty i { font-size: 2.3rem; color: var(--brand, #A3422B); }
.kds-empty strong { color: var(--ink, var(--ink)); }
.kds-empty small { font-size: .875rem; }
.kds-empty button { margin-top: .4rem; min-height: 40px; padding: 0 .85rem; border: 0; border-radius: 8px; background: var(--brand, #A3422B); color: #FFFDFA; font: inherit; font-weight: 800; cursor: pointer; }

.kds-brand {
    display: block;
    margin-bottom: .15rem;
    color: #EFE9E0;
    font-size: .875rem;
    font-weight: 650;
}

.kb-table-filter {
    gap: .5rem;
}

.kb-table-pill.is-active {
    border-color: var(--brand, #A3422B);
    color: #FFFDFA;
    background: var(--brand, #A3422B);
    box-shadow: none;
}

.kb-filter-label, .kb-allday-label {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}

.kb-allday {
    padding: .7rem .85rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 12px;
    background: var(--surface, #FFFDFA);
}

.kb-allday-chip strong {
    color: var(--brand, #A3422B);
}

@media (max-width: 1180px) {
    .kds-command { grid-template-columns: 1fr auto; }
    .kds-stage-switch { grid-column: 1 / -1; grid-row: 2; width: 100%; order: 3; }
}
@media (max-width: 680px) {
    .kds { padding: .35rem; }
    .kds-command { position: static; grid-template-columns: 1fr; }
    .kds-tools { justify-content: stretch; }
    .kds-sort { flex: 1; }
    .kds-sound span { display: none; }
    .kds-stage-switch { grid-column: auto; }
    .kds-stage-switch button { padding-inline: .25rem; }
    .kds-workspace-head p { display: none; }
}

@media (prefers-reduced-motion: reduce) {
    .kds *, .kds *::before, .kds *::after { animation: none !important; transition: none !important; }
}

/* Task-focused workspace layout. */
.kds{padding:16px;gap:12px}.kds-command{position:relative;top:auto;grid-template-columns:minmax(180px,1fr) auto;min-height:0;background:var(--surface);color:var(--ink);border:1px solid var(--line);padding:16px;border-radius:12px}.kds-identity h1{color:var(--ink);font-size:24px}.kds-brand,.kds-identity p{color:var(--muted)}.kds-station-icon{background:var(--brand-soft)}.kds-stage-switch{grid-row:2;grid-column:1/-1;background:var(--canvas);padding:4px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-radius:8px}.kds-stage-switch button{color:var(--ink);border-radius:6px;min-height:44px}.kds-stage-switch button.is-active{background:var(--ink);color:var(--surface)}.kds-tools{grid-column:2;grid-row:1}.kds-tools button,.kds-sort,.kds-home{background:var(--surface);color:var(--ink);border:1px solid var(--line)}.kds-sort select{color:var(--ink)}.kds-workspace{padding:0;background:transparent;border:0}.kds-workspace-head{padding:8px 0}.kds-lanes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;align-items:start}.kds-lane{min-width:0;background:var(--surface-muted);padding:8px;border-radius:12px}.kds-lane-heading{display:flex;align-items:center;justify-content:space-between;padding:10px 4px 14px;border-bottom:2px solid var(--brand);margin-bottom:10px}.kds-lane-heading h3{font-size:16px;margin:0;display:flex;gap:8px}.kds-lane-heading strong{font-size:18px}.kds-lane--cooking .kds-lane-heading{border-color:var(--accent)}.kds-lane--ready .kds-lane-heading{border-color:var(--line)}.kds-lane-tickets{display:grid;gap:12px}.kds-lane-empty{padding:30px 8px;text-align:center;color:var(--muted);font-size:14px}.kds-lanes.is-focused{grid-template-columns:1fr}.is-focused .kds-lane-tickets{grid-template-columns:repeat(auto-fill,minmax(min(100%,330px),1fr))}.kb-allday{padding:12px;border:1px solid var(--line);background:var(--surface);border-radius:8px}.kb-table-filter{margin:0}.kb-table-pill{border-radius:6px;min-width:44px;min-height:40px}@media(max-width:1000px){.kds-lanes{grid-template-columns:1fr}.kds-lane-tickets{grid-template-columns:repeat(auto-fill,minmax(min(100%,310px),1fr))}.kds-command{grid-template-columns:1fr}.kds-tools{grid-column:1;grid-row:auto;justify-content:flex-start;flex-wrap:wrap}.kds-stage-switch{grid-row:auto}.kds-stage-switch button{font-size:14px;padding:8px 4px}.kds{padding:10px}}
.kds--pressure .kds-lanes{gap:8px}.kds--pressure .kds-lane{padding:6px}.kds--pressure .kds-lane-tickets{gap:8px}.kds--pressure .kds-lane-heading{padding-block:6px 9px;margin-bottom:8px}.kds--pressure .kds-lanes.is-focused .kds-lane-tickets{grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr))}
/* Keep cooking and pickup visible beside the backlog on station tablets. */
@media (min-width: 760px) {
    .kds--pressure .kds-lanes:not(.is-focused) { grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: stretch; }
    .kds--pressure .kds-lanes:not(.is-focused) .kds-lane { display: flex; flex-direction: column; max-height: max(420px, calc(100dvh - 350px)); }
    .kds--pressure .kds-lanes:not(.is-focused) .kds-lane-heading { flex: 0 0 auto; }
    .kds--pressure .kds-lanes:not(.is-focused) .kds-lane-tickets { grid-template-columns: minmax(0, 1fr); overflow-y: auto; overscroll-behavior-y: contain; min-height: 0; padding: 2px; }
    /* Keep item choices inside the scrollable lane, including 760px tablets. */
    .kds--pressure .kds-lanes:not(.is-focused) :deep(.kb-item-actions.has-open-menu) { grid-column: 1 / -1; }
    .kds--pressure .kds-lanes:not(.is-focused) :deep(.kb-more.is-open) { flex: 1 0 100%; min-width: 0; }
    .kds--pressure .kds-lanes:not(.is-focused) :deep(.kb-more.is-open .kb-item-menu) { position: static; width: 100%; margin-top: 5px; }
    .kds--pressure .kds-lanes:not(.is-focused) :deep(.kb-item-menu button > i) { flex-shrink: 0; }
    .kds--pressure .kds-lanes:not(.is-focused) :deep(.kb-item-menu button span) { min-width: 0; overflow-wrap: anywhere; }
}
</style>
