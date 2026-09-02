<script setup>
/**
 * لوحة الطاولات v5 — the waiter's triage board on Vue/Inertia (Wave 1).
 *
 * Behavioral contract:
 * every actionable table is presented in one server-ordered priority grid,
 * with one clear action per card. KDS-graded load chip, stale lens and
 * roster-first scoping remain intact.
 * Refresh = 15s visible poll → useLiveRefresh throttle →
 * ONE Inertia partial reload per window; the poll checks the cheap pulse
 * endpoint first and forces a full refresh every 4th idle poll so
 * time-based triage transitions (a table crossing a threshold with no
 * event at all) still advance on a quiet floor.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import TbPriorityCard from '../../../Components/TablesBoard/TbPriorityCard.vue';
import TbQuickEditSheet from '../../../Components/TablesBoard/TbQuickEditSheet.vue';
import TbSectionTabs from '../../../Components/TablesBoard/TbSectionTabs.vue';
import TbTile from '../../../Components/TablesBoard/TbTile.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useLiveRefresh } from '../../../Composables/useLiveRefresh';
import { useToast } from '../../../Composables/useToast';
import { formPost } from '../../../Support/formPost';
import { playNotify } from '../../../Support/audio';
import '../../../../css/tables-board.css';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    board: { type: Object, required: true },
    filters: { type: Object, required: true },
    tabs: { type: Object, required: true },
    transferTables: { type: Array, required: true },
    live: { type: Object, required: true },
    meta: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const toast = useToast();
const { ask } = useConfirm();

// ── Client-only UI state ─────────────────────────────────────────────
const search = ref(props.filters.q);
const floorFilter = ref('all');
const openMenuId = ref(null);
const quickEditRow = ref(null);
const busyId = ref(null);
const controlSentinel = ref(null);
const controlsShell = ref(null);
const controlsDocked = ref(false);
const controlsHeight = ref(0);
const controlsDockStyle = ref({});
let dockObserver = null;
let dockResizeObserver = null;

const captureDockMetrics = () => {
    const rect = controlsShell.value?.getBoundingClientRect();
    if (! rect) return;
    controlsHeight.value = rect.height;
    controlsDockStyle.value = {
        '--tb-dock-left': `${rect.left}px`,
        '--tb-dock-width': `${rect.width}px`,
    };
};

const stopDockingOnMobile = () => {
    if (window.innerWidth <= 600) controlsDocked.value = false;
};

onMounted(() => {
    document.addEventListener('click', closeOpenMenu);
    captureDockMetrics();
    dockObserver = new IntersectionObserver(([entry]) => {
        const shouldDock = window.innerWidth > 600
            && ! entry.isIntersecting
            && entry.boundingClientRect.top < 8;
        if (shouldDock) captureDockMetrics();
        controlsDocked.value = shouldDock;
    }, { threshold: 0 });
    if (controlSentinel.value) dockObserver.observe(controlSentinel.value);

    dockResizeObserver = new ResizeObserver(captureDockMetrics);
    if (controlsShell.value) dockResizeObserver.observe(controlsShell.value);
    window.addEventListener('resize', stopDockingOnMobile, { passive: true });
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeOpenMenu);
    dockObserver?.disconnect();
    dockResizeObserver?.disconnect();
    window.removeEventListener('resize', stopDockingOnMobile);
});

const rowsById = computed(() => new Map(props.board.rows.map((r) => [r.id, r])));
const priorityRows = computed(() => {
    return (props.board.priorityIds ?? [])
        .map((id) => rowsById.value.get(id))
        .filter(Boolean);
});
const hasFeed = computed(() => priorityRows.value.length > 0);
const floorFilters = computed(() => {
    const rows = props.board.rows;
    const count = (test) => rows.filter(test).length;

    return [
        { key: 'all', label: 'كل الطاولات', shortLabel: 'الكل', icon: 'bi-grid-3x3-gap-fill', count: rows.length },
        { key: 'action', label: 'تحتاج إجراء', shortLabel: 'إجراء', icon: 'bi-lightning-charge-fill', count: count((row) => row.triage && row.triage.urgency !== 'grey') },
        { key: 'occupied', label: 'مشغولة', shortLabel: 'مشغولة', icon: 'bi-people-fill', count: count((row) => Boolean(row.sessionId)) },
        { key: 'available', label: 'متاحة', shortLabel: 'متاحة', icon: 'bi-check2-circle', count: count((row) => row.tileState === 'available') },
        { key: 'cleaning', label: 'تنظيف', shortLabel: 'تنظيف', icon: 'bi-stars', count: count((row) => row.tileState === 'cleaning') },
    ];
});
const visibleRowIds = computed(() => new Set(props.board.rows
    .filter((row) => {
        if (floorFilter.value === 'action') return row.triage && row.triage.urgency !== 'grey';
        if (floorFilter.value === 'occupied') return Boolean(row.sessionId);
        if (floorFilter.value === 'available') return row.tileState === 'available';
        if (floorFilter.value === 'cleaning') return row.tileState === 'cleaning';
        return true;
    })
    .map((row) => row.id)));
const visibleSections = computed(() => (props.board.sections ?? [])
    .map((section) => ({
        ...section,
        ids: section.ids.filter((id) => visibleRowIds.value.has(id)),
    }))
    .filter((section) => section.ids.length > 0));
const visibleCount = computed(() => visibleRowIds.value.size);
const showPriority = computed(() => hasFeed.value && ['all', 'action'].includes(floorFilter.value));

// One open ⋯ menu at a time; any outside tap closes it.
const toggleMenu = (id) => { openMenuId.value = openMenuId.value === id ? null : id; };
const closeOpenMenu = () => { openMenuId.value = null; };

// ── Navigation (view / lens / search → server round-trip) ───────────
const visit = (params, { replace = false } = {}) => {
    router.get(props.urls.board, {
        view: params.view ?? props.filters.view,
        lens: (params.lens ?? props.filters.lens) || undefined,
        q: (params.q ?? search.value) || undefined,
    }, { preserveState: true, preserveScroll: true, replace });
};

const setView = (v) => {
    visit({ view: v });
};

const toggleLens = () => {
    visit({ lens: props.filters.lens === 'stale' ? '' : 'stale' });
};

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit({}, { replace: true }), 400);
});
watch(() => props.filters.q, (q) => { if (q !== search.value) search.value = q; });

// ── Live refresh: events + pulse-checked poll ────────────────────────
const refresh = () => {
    router.reload({
        only: ['board', 'tabs', 'transferTables', 'live'],
        preserveScroll: true,
    });
};

let lastVersion = props.live.version;
let idlePolls = 0;
watch(() => props.live.version, (v) => { lastVersion = v; });

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const checkPulse = async (signal) => {
    try {
        const res = await fetch(props.urls.pulse, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        });
        const data = res.ok ? await res.json() : null;
        // Every 4th idle poll forces a refresh anyway — the safety net that
        // advances time-based triage on a floor where nothing broadcasts.
        if (! data || data.version !== lastVersion || ++idlePolls >= 4) {
            idlePolls = 0;
            refresh();
        }
    } catch {
        // Offline: skip — the next poll tries again.
    }
};

useLiveRefresh({
    pollMs: 3000,
    onPing: (reason, signal) => (reason === 'poll' ? checkPulse(signal) : refresh()),
});

// Every new service task that needs the waiter is audible. The initial set is
// baselined so opening the board never replays old alerts.
let knownSoundIds = new Set(props.board.soundIds ?? props.board.redIds);
watch(() => props.board.soundIds ?? props.board.redIds, (ids) => {
    const current = new Set(ids);
    for (const id of current) {
        if (! knownSoundIds.has(id)) {
            playNotify();
            break;
        }
    }
    knownSoundIds = current;
});

// ── Actions ──────────────────────────────────────────────────────────
const postJson = async (url, body = {}) => {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    return res.json().catch(() => null);
};

const serve = async (row) => {
    if (busyId.value) return;
    busyId.value = row.id;
    try {
        const data = await postJson(row.urls.serve);
        if (data?.ok) toast.success(data.message);
        else toast.warning(data?.message ?? 'تعذّر التسليم — حاول مجدداً.');
    } catch {
        toast.error('انقطع الاتصال — ما تأكد التسليم.');
    } finally {
        busyId.value = null;
        // Parity with v4's finally{}: refresh even after a stale tap, or
        // the board keeps showing the card that already moved on.
        refresh();
    }
};

const ackHelp = async (row) => {
    if (busyId.value) return;
    busyId.value = row.id;
    try {
        const data = await postJson(row.urls.ackHelp);
        if (data?.ok) toast.success(data.message);
    } catch {
        toast.error('انقطع الاتصال — حاول مجدداً.');
    } finally {
        busyId.value = null;
        refresh();
    }
};

// Classic form POSTs (redirect-back routes) — flash surfaces as a toast
// on the reloaded page, exactly like the Blade board's plain forms.
const markClean = (row) => formPost(row.urls.markClean);

const closeSession = async (row) => {
    const yes = await ask({
        title: `تحرير طاولة ${row.number} الآن؟`,
        message: 'لا مستحقات عليها — ستُغلق الجلسة الراكدة وتُعتبر الطاولة منظّفة وجاهزة للاستقبال.',
        confirmLabel: 'حرّر الطاولة',
        danger: true,
    });
    if (yes) formPost(row.urls.closeSession, { mark_ready: true });
};

const closeAllStale = async () => {
    const yes = await ask({
        title: `إغلاق كل الجلسات الراكدة بلا مستحقات (${props.board.staleClosableCount})؟`,
        confirmLabel: 'أغلق الكل',
        danger: true,
    });
    if (! yes) return;
    try {
        const data = await postJson(props.urls.closeStale, { view: props.filters.view });
        if (data) toast[data.closed > 0 ? 'success' : 'info'](data.message);
    } catch {
        toast.error('انقطع الاتصال — ما انفذ الإغلاق.');
    } finally {
        refresh();
    }
};

const transfer = async ({ row, targetId }) => {
    const target = props.transferTables.find((t) => t.id === targetId);
    const yes = await ask({
        title: `نقل جلسة طاولة ${row.number}؟`,
        message: target ? `إلى طاولة ${target.number}${target.name ? ' - ' + target.name : ''}` : '',
        confirmLabel: 'نقل',
    });
    if (yes) formPost(row.urls.transfer, { target_table_id: targetId });
};

const destroy = async (row) => {
    const yes = await ask({
        title: `تأكيد حذف الطاولة ${row.number}؟`,
        message: 'السجلات التاريخية بتحتفظ بالرقم الأصلي.',
        confirmLabel: 'حذف',
        danger: true,
    });
    if (yes) formPost(row.urls.destroy, { _method: 'DELETE' });
};

const onQuickEditSaved = (data) => {
    quickEditRow.value = null;
    toast.success(data.message ?? 'تم التحديث');
    if (data.info) toast.info(data.info);
    refresh();
};

const loadChip = computed(() => {
    const level = props.board.loadLevel;
    if (props.board.actionCount === 0) return { cls: 'tb-load--idle', icon: 'bi-check2-circle', text: 'الصالة تحت السيطرة' };
    if (level === 'slammed') return { cls: 'tb-load--slammed', icon: 'bi-exclamation-triangle-fill', text: `ذروة — ${props.board.actionCount} يحتاجون إجراء` };
    return { cls: `tb-load--${level}`, icon: 'bi-activity', text: `${props.board.actionCount} يحتاجون إجراء الآن` };
});
</script>

<template>
    <Head title="لوحة الطاولات" />

    <header class="tables-quick-head">
        <div class="tables-quick-title">
            <span class="tables-quick-icon" aria-hidden="true"><i class="bi bi-grid-3x3-gap-fill"></i></span>
            <div>
                <h1>لوحة الصالة</h1>
                <p>ما يحتاج إجراء أولاً، ثم كل الطاولات في نفس الشاشة</p>
            </div>
        </div>
        <Link v-if="meta.canCreate" :href="urls.create" class="tables-quick-add">
            <i class="bi bi-plus-lg"></i><span>طاولة جديدة</span>
        </Link>
    </header>

    <div class="tables-board" :data-red-ids="board.redIds.join(',')">
        <span ref="controlSentinel" class="tb-control-sentinel" aria-hidden="true"></span>
        <div v-if="controlsDocked" class="tb-control-placeholder" aria-hidden="true"
             :style="{ height: `${controlsHeight}px` }"></div>
        <section ref="controlsShell" class="tb-ops-shell" :class="{ 'is-docked': controlsDocked }"
                 :style="controlsDocked ? controlsDockStyle : null" aria-label="البحث وتصفية الطاولات">
            <TbSectionTabs :tabs="tabs" :view="filters.view" :roster-url="urls.roster" @set-view="setView" />

            <div class="tb-command">
                <div class="tb-load" :class="loadChip.cls">
                    <i class="bi" :class="loadChip.icon"></i>
                    <span>{{ loadChip.text }}</span>
                </div>

                <template v-if="board.staleCount > 0 || filters.lens === 'stale'">
                    <button type="button" class="tb-lens" :class="{ 'is-active': filters.lens === 'stale' }"
                            :aria-pressed="filters.lens === 'stale'" @click="toggleLens">
                        <i class="bi" :class="filters.lens === 'stale' ? 'bi-arrow-right' : 'bi-hourglass-bottom'"></i>
                        {{ filters.lens === 'stale' ? 'رجوع للأهم' : 'راكدة' }}
                        <span v-if="filters.lens !== 'stale'" class="tb-lens-count">{{ board.staleCount }}</span>
                    </button>

                    <button v-if="filters.lens === 'stale' && board.staleClosableCount > 0 && meta.canSweepStale"
                            type="button" class="tb-lens tb-lens--sweep" @click="closeAllStale">
                        <i class="bi bi-x-circle"></i> أغلق الكل
                        <span class="tb-lens-count">{{ board.staleClosableCount }}</span>
                    </button>
                </template>

                <div class="tb-search-wrap">
                    <i class="bi bi-search"></i>
                    <input v-model="search" type="text" placeholder="ابحث برقم الطاولة أو القسم" class="tb-search">
                    <button v-if="search" type="button" class="tb-search-clear" title="مسح" @click="search = ''">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>

            <div class="tb-state-filters" role="group" aria-label="تصفية الطاولات حسب الحالة">
                <button v-for="item in floorFilters" :key="item.key" type="button"
                        class="tb-state-filter" :class="[`tb-state-filter--${item.key}`, { 'is-active': floorFilter === item.key }]"
                        :aria-pressed="floorFilter === item.key" @click="floorFilter = item.key">
                    <i class="bi" :class="item.icon"></i>
                    <span class="tb-state-filter-label">{{ item.label }}</span>
                    <span class="tb-state-filter-short">{{ item.shortLabel }}</span>
                    <strong>{{ item.count }}</strong>
                </button>
            </div>
        </section>

        <div v-if="tabs.needsRosterNudge" class="tb-nudge">
            <i class="bi bi-person-x-fill"></i>
            <span>لم يتم توزيعك على قسم اليوم، لذلك تظهر لك كل الصالة.</span>
        </div>

        <section v-if="showPriority" class="tb-feed" aria-label="الأهم الآن">
            <header class="tb-feed-head">
                <div class="tb-feed-title">
                    <span class="tb-feed-icon"><i class="bi" :class="filters.lens === 'stale' ? 'bi-hourglass-bottom' : 'bi-lightning-charge-fill'"></i></span>
                    <div>
                        <h3>{{ filters.lens === 'stale' ? 'جلسات قديمة' : 'يحتاج إجراء الآن' }}</h3>
                        <span class="tb-feed-sub">مرتبة حسب الأولوية — إجراء واحد واضح لكل طاولة</span>
                    </div>
                </div>
                <span class="tb-feed-total">{{ priorityRows.length }}</span>
            </header>

            <div class="tb-priority-grid" role="list">
                <TbPriorityCard v-for="row in priorityRows" :key="row.id"
                                :row="row" :busy="busyId === row.id"
                                @serve="serve" @ack="ackHelp" @clean="markClean" @close="closeSession" />
            </div>
        </section>

        <section class="tb-map" aria-label="خريطة الصالة">
            <header class="tb-map-head">
                <div class="tb-map-title"><i class="bi bi-layout-grid"></i><h3>الطاولات</h3></div>
                <span class="tb-map-count">{{ visibleCount }} من {{ board.rows.length }} طاولة</span>
            </header>

            <EmptyState v-if="visibleSections.length === 0" icon="bi-grid-3x3-gap"
                        title="لا توجد طاولات في هذه الحالة" message="غيّر الحالة أو امسح البحث للعودة لكل الصالة.">
                <template #cta>
                    <button v-if="floorFilter !== 'all'" type="button" class="btn btn-primary me-2" @click="floorFilter = 'all'">
                        <i class="bi bi-grid-3x3-gap-fill"></i> كل الحالات
                    </button>
                    <button v-if="filters.view !== 'all'" type="button" class="btn btn-primary me-2" @click="setView('all')">
                        <i class="bi bi-grid-3x3-gap-fill"></i> عرض كل الصالة
                    </button>
                    <button v-if="search" type="button" class="btn btn-light me-2" @click="search = ''">
                        <i class="bi bi-x-circle"></i> مسح البحث
                    </button>
                    <Link v-if="meta.canCreate" :href="urls.create" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> طاولة جديدة
                    </Link>
                </template>
            </EmptyState>

            <div v-for="sec in visibleSections" :key="sec.label" class="tb-map-section">
                <div class="tb-map-section-label">
                    <span class="tb-map-section-dot" :style="{ '--sec-color': rowsById.get(sec.ids[0])?.zoneColor }"></span>
                    <strong>{{ sec.label }}</strong><span>{{ sec.ids.length }} طاولة</span>
                </div>
                <div class="tb-map-grid">
                    <TbTile v-for="id in sec.ids" :key="id" :row="rowsById.get(id)"
                            :transfer-tables="transferTables" :menu-open="openMenuId === id"
                            @menu-toggle="toggleMenu"
                            @quick-edit="quickEditRow = $event"
                            @transfer="transfer" @destroy="destroy" @clean="markClean"
                            @serve="serve" @ack="ackHelp" @close="closeSession" />
                </div>
            </div>
        </section>
    </div>

    <TbQuickEditSheet :row="quickEditRow" :zones="tabs.sections"
                      @close="quickEditRow = null" @saved="onQuickEditSaved" />
</template>
