<script setup>
/**
 * شاشة المحطة (KDS) v4 — Wave 3. Chrome-free full-viewport board: wall
 * mode is now the ONLY mode. Waiting, cooking, and waiter hand-off are
 * equal filterable ticket stages so a ready order never collapses into a
 * tiny strip or disappears under pressure.
 *
 * Refresh: a 5s visible pulse check with a periodic safety reload.
 * Sounds ride the ID-set diff rules ported verbatim in Support/kdsSound.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import KdsCard from '../../../Components/Kds/KdsCard.vue';
import Toaster from '../../../Components/Ui/Toaster.vue';
import { useLiveRefresh } from '../../../Composables/useLiveRefresh';
import { useToast } from '../../../Composables/useToast';
import { checkKdsChanges, kdsSoundEnabled, setKdsSound } from '../../../Support/kdsSound';

const props = defineProps({
    station: { type: Object, required: true },
    board: { type: Object, required: true },
    filters: { type: Object, required: true },
    live: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const toast = useToast();
const stationLabel = computed(() => props.station.name || 'المحطة');

// ── Clock ────────────────────────────────────────────────────────────
const clock = ref('00:00');
let clockTimer = null;
const syncClock = () => {
    clock.value = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
};

// ── Sound ────────────────────────────────────────────────────────────
const soundOn = ref(kdsSoundEnabled());
const toggleSound = () => {
    soundOn.value = ! soundOn.value;
    setKdsSound(soundOn.value);
};

watch(() => props.board, (board) => {
    checkKdsChanges({
        orderIds: board.orderIds,
        readyIds: board.readyIds,
        changeRequestIds: board.changeRequestIds,
        redCount: board.load.redCards,
        filter: props.filters.table,
        enabled: soundOn.value,
    });
}, { immediate: true });

// ── Connection dot ───────────────────────────────────────────────────
const offline = ref(! navigator.onLine);
const markOnline = () => { offline.value = false; };
const markOffline = () => { offline.value = true; };

onMounted(() => {
    syncClock();
    clockTimer = window.setInterval(syncClock, 1000);
    window.addEventListener('online', markOnline);
    window.addEventListener('offline', markOffline);
});

onBeforeUnmount(() => {
    window.clearInterval(clockTimer);
    window.removeEventListener('online', markOnline);
    window.removeEventListener('offline', markOffline);
});

// ── One-screen focus — filtering and sorting never leave the board ───
const focus = ref('all');
const sort = ref(props.filters.sort || 'urgency');
const selectedTable = ref(props.filters.table || '');

const counts = computed(() => ({
    waiting: props.board.waiting.length,
    cooking: props.board.cooking.length,
    ready: props.board.ready.length,
    active: props.board.waiting.length + props.board.cooking.length,
    total: props.board.waiting.length + props.board.cooking.length + props.board.ready.length,
}));

const pressureMode = computed(() => counts.value.total >= 12);

const visibleCards = computed(() => {
    let cards = [
        ...props.board.waiting.map((card) => ({ ...card, _column: 'waiting' })),
        ...props.board.cooking.map((card) => ({ ...card, _column: 'cooking' })),
        ...props.board.ready.map((card) => ({ ...card, _column: 'ready' })),
    ];

    if (focus.value !== 'all') cards = cards.filter((card) => card._column === focus.value);
    if (selectedTable.value) cards = cards.filter((card) => String(card.tableNum) === String(selectedTable.value));

    return cards.sort((a, b) => {
        if (sort.value === 'table') {
            const tableA = a.tableNum || Number.MAX_SAFE_INTEGER;
            const tableB = b.tableNum || Number.MAX_SAFE_INTEGER;
            return tableA - tableB || b.ageMin - a.ageMin;
        }
        if (sort.value === 'time') return b.ageMin - a.ageMin;
        return b.urgencyRank - a.urgencyRank || b.ageMin - a.ageMin;
    });
});

const focusTable = (num) => {
    selectedTable.value = selectedTable.value === String(num) ? '' : String(num);
};

// ── Live refresh ─────────────────────────────────────────────────────
const refresh = () => router.reload({ only: ['board', 'live'], preserveScroll: true });

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
        if (! data || data.version !== lastVersion || ++idlePolls >= 4) {
            idlePolls = 0;
            refresh();
        }
    } catch { /* offline — next poll tries again */ }
};

useLiveRefresh({
    pollMs: 5000,
    onPing: (reason, signal) => (reason === 'poll' ? checkPulse(signal) : refresh()),
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
        if (data?.ok && data.message) toast.success(data.message);
        else if (data && ! data.ok) toast.warning(data.message ?? 'تعذّر التنفيذ — حاول مجدداً.');
    } catch {
        toast.error('انقطع الاتصال — ما اتنفذ الإجراء.');
    } finally {
        setBusy(key, false);
        // Refresh even after a stale tap — the board must show the real
        // state that caused the conflict.
        refresh();
    }
};

const followUp = (card) => card.tableNum && props.board.followUpTables.includes(card.tableNum);
</script>

<template>
    <Head :title="`${station.name} — شاشة المحطة`" />

    <div class="kds" :class="{ 'kds--pressure': pressureMode }">
        <header class="kds-command" :class="`kb-load-${board.load.level}`">
            <div class="kds-identity">
                <span class="kds-station-icon">{{ station.emoji }}</span>
                <div>
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
                <button type="button" class="kds-sound" :class="soundOn ? 'is-on' : 'is-off'" @click="toggleSound">
                    <i class="bi" :class="soundOn ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'"></i>
                    <span>{{ soundOn ? 'الصوت يعمل' : 'شغّل الصوت' }}</span>
                </button>
                <span class="kds-connection" :class="{ 'is-offline': offline }" :title="offline ? 'غير متصل بالخادم' : 'متصل بالخادم'"></span>
                <a :href="urls.home" class="kds-home" title="لوحة التحكم"><i class="bi bi-house"></i></a>
            </div>
        </header>

        <div v-if="offline" class="kds-offline"><i class="bi bi-wifi-off"></i> الاتصال مقطوع — الطلبات المعروضة محفوظة، والإجراءات متوقفة حتى عودة الاتصال.</div>

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
                <span v-for="d in board.allDay" :key="d.name" class="kb-allday-chip">
                    <strong>{{ d.qty }}×</strong> {{ d.name }}
                </span>
            </div>
        </div>

        <section class="kds-workspace">
            <header class="kds-workspace-head">
                <div>
                    <h2>{{ focus === 'waiting' ? 'طلبات جديدة — ابدأ التحضير' : (focus === 'cooking' ? 'طلبات قيد التحضير' : (focus === 'ready' ? 'جاهز — سلّمه للجرسون الظاهر على البطاقة' : `كل طلبات ${stationLabel}`)) }}</h2>
                    <p>كل طلب في بطاقة واحدة من وصوله إلى {{ stationLabel }} حتى تسليمه؛ الجاهز ينبه الجرسون المسؤول تلقائياً.</p>
                </div>
                <span class="kds-visible-count">{{ visibleCards.length }} طلب ظاهر</span>
                <button v-if="selectedTable" type="button" class="kds-clear-filter" @click="selectedTable = ''">
                    <i class="bi bi-x-lg"></i> إظهار كل الطاولات
                </button>
            </header>

            <div v-if="visibleCards.length" class="kds-ticket-grid">
                <KdsCard v-for="card in visibleCards" :key="`${card._column}-${card.orderId}`"
                         :card="card" :column="card._column" :follow-up="followUp(card)"
                         :busy="cardBusy(card) || offline" @act="act" />
            </div>

            <div v-else class="kds-empty">
                <i class="bi bi-check2-circle"></i>
                <strong>{{ counts.total ? 'لا يوجد طلب يطابق هذا التركيز' : `لا توجد طلبات في ${stationLabel} الآن` }}</strong>
                <small>{{ counts.total ? 'اختر «الكل» أو أزل فلتر الطاولة.' : 'سيظهر الطلب الجديد تلقائيًا مع صوت تنبيه.' }}</small>
                <button v-if="counts.total" type="button" @click="focus = 'all'; selectedTable = ''">عرض كل الطلبات</button>
            </div>
        </section>
    </div>

    <Toaster />
</template>

<style scoped>
.kds {
    min-height: 100dvh;
    background: #eef2f0;
    padding: .5rem .6rem 1rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    color: #17251d;
}

.kds-command {
    position: sticky;
    top: .35rem;
    z-index: 40;
    display: grid;
    grid-template-columns: minmax(210px, auto) minmax(360px, 1fr) auto;
    align-items: center;
    gap: .65rem;
    min-height: 64px;
    padding: .5rem .65rem;
    border: 1px solid rgba(255,255,255,.12);
    border-inline-start: 5px solid #22c55e;
    border-radius: 14px;
    background: linear-gradient(135deg, #176b4a, #123323);
    color: #fff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .18);
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
.kds-identity h1 { margin: 0; font-size: 1.08rem; font-weight: 950; }
.kds-identity p { margin: .12rem 0 0; display: flex; align-items: center; gap: .4rem; color: rgba(255,255,255,.78); font-size: .73rem; }
.kds-oldest { color: #fde68a; font-weight: 850; }
.kds-pressure-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 7px;
    border-radius: 999px;
    background: rgba(245, 158, 11, .16);
    color: #fde68a;
    font-weight: 850;
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
    min-height: 42px;
    padding: .25rem .65rem;
    display: flex; align-items: center; justify-content: center; gap: .45rem;
    border: 0; border-radius: 9px;
    background: transparent; color: rgba(255,255,255,.76);
    font: inherit; font-size: .8rem; font-weight: 850; cursor: pointer;
}
.kds-stage-switch button strong {
    min-width: 25px; height: 25px; padding: 0 5px;
    display: inline-grid; place-items: center;
    border-radius: 999px; background: rgba(255,255,255,.13);
    font-variant-numeric: tabular-nums;
}
.kds-stage-switch button.is-active { background: #fff; color: #163b29; box-shadow: 0 2px 7px rgba(0,0,0,.18); }
.kds-stage-switch button.is-waiting.is-active strong { background: #fff7ed; color: #c2410c; }
.kds-stage-switch button.is-cooking.is-active strong { background: #eff6ff; color: #1d4ed8; }
.kds-stage-switch button.is-ready.is-active strong { background: #ecfdf5; color: #047857; }

.kds-tools { display: flex; align-items: center; justify-content: flex-end; gap: .4rem; }
.kds-sort {
    height: 40px; display: inline-flex; align-items: center; gap: .25rem;
    padding: 0 .5rem; border-radius: 9px; background: rgba(0,0,0,.2);
}
.kds-sort select { border: 0; outline: 0; background: transparent; color: #fff; font: inherit; font-size: .76rem; font-weight: 800; cursor: pointer; }
.kds-sort option { color: #17251d; }
.kds-sound, .kds-home {
    min-height: 40px; border: 0; border-radius: 9px;
    display: inline-flex; align-items: center; justify-content: center; gap: .3rem;
    padding: 0 .65rem; background: rgba(0,0,0,.2); color: #fff;
    font: inherit; font-size: .76rem; font-weight: 850; cursor: pointer;
}
.kds-sound.is-on { background: rgba(34,197,94,.25); }
.kds-sound.is-off { background: rgba(239,68,68,.28); }
.kds-connection { width: 10px; height: 10px; flex: 0 0 10px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 0 3px rgba(74,222,128,.2); }
.kds-connection.is-offline { background: #f87171; box-shadow: 0 0 0 3px rgba(248,113,113,.2); }
.kds-home {
    text-decoration: none;
    width: 40px; padding: 0;
}
.kds-offline { padding: .55rem .8rem; border: 1px solid #fecaca; border-radius: 10px; background: #fef2f2; color: #991b1b; font-size: .82rem; font-weight: 800; }
.kb-table-filter, .kb-allday { margin: 0; min-height: 42px; padding-block: .4rem; flex-wrap: nowrap; overflow-x: auto; }
.kb-table-pill { min-height: 34px; }

.kds-workspace { padding: .55rem; border: 1px solid #d9e3dd; border-radius: 14px; background: #f8faf9; box-shadow: 0 5px 18px rgba(20, 58, 38, .06); }
.kds-workspace-head { min-height: 42px; display: flex; align-items: center; gap: .6rem; padding: 0 .15rem .45rem; }
.kds-workspace-head h2 { margin: 0; font-size: .96rem; font-weight: 950; color: #173d2b; }
.kds-workspace-head p { margin: .12rem 0 0; color: #718078; font-size: .7rem; }
.kds-visible-count { margin-inline-start: auto; padding: .25rem .55rem; border-radius: 999px; background: #e8f2ec; color: #285b3d; font-size: .72rem; font-weight: 900; white-space: nowrap; }
.kds-clear-filter { min-height: 34px; padding: 0 .65rem; border: 1px solid #cfdcd4; border-radius: 8px; background: #fff; color: #285b3d; font: inherit; font-size: .72rem; font-weight: 850; cursor: pointer; }

.kds-ticket-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
    gap: .55rem;
    align-items: start;
}
.kds--pressure .kds-ticket-grid { grid-template-columns: repeat(auto-fill, minmax(218px, 1fr)); gap: .4rem; }
.kds-empty { min-height: 210px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem; color: #6f7e75; text-align: center; }
.kds-empty i { font-size: 2.3rem; color: #3b8c5a; }
.kds-empty strong { color: #244534; }
.kds-empty small { font-size: .78rem; }
.kds-empty button { margin-top: .4rem; min-height: 40px; padding: 0 .85rem; border: 0; border-radius: 9px; background: #176b3a; color: #fff; font: inherit; font-weight: 850; cursor: pointer; }

@media (min-width: 1800px) {
    .kds--pressure .kds-ticket-grid { grid-template-columns: repeat(auto-fill, minmax(225px, 1fr)); }
}
@media (max-width: 1050px) {
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
    .kds-ticket-grid, .kds--pressure .kds-ticket-grid { grid-template-columns: 1fr; }
    .kds-workspace-head p { display: none; }
}

@media (prefers-reduced-motion: reduce) {
    .kds *, .kds *::before, .kds *::after { animation: none !important; transition: none !important; }
}
</style>
