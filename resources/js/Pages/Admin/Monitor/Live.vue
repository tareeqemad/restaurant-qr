<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useLiveRefresh } from '../../../Composables/useLiveRefresh';

const props = defineProps({
    branches: { type: Array, required: true },
    live: { type: Object, required: true },
    currency: { type: String, default: '₪' },
    generatedAt: { type: String, default: null },
    urls: { type: Object, required: true },
});

const clock = ref('');
const dateLabel = ref('');
const offline = ref(false);
const paused = ref(false);
const refreshing = ref(false);
const fullscreen = ref(false);
const density = ref(props.branches.length >= 5 ? 'compact' : 'comfortable');
const focusBranch = ref('all');
const lastUpdated = ref(props.generatedAt ? new Date(props.generatedAt) : new Date());
let clockTimer = null;

const updateClock = () => {
    const now = new Date();
    clock.value = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    dateLabel.value = now.toLocaleDateString('ar-PS', { weekday: 'long', day: 'numeric', month: 'long' });
};
const setOnline = () => { offline.value = false; };
const setOffline = () => { offline.value = true; };
const syncFullscreen = () => { fullscreen.value = Boolean(document.fullscreenElement); };

onMounted(() => {
    offline.value = ! navigator.onLine;
    updateClock();
    clockTimer = window.setInterval(updateClock, 1000);
    window.addEventListener('online', setOnline);
    window.addEventListener('offline', setOffline);
    document.addEventListener('fullscreenchange', syncFullscreen);
});
onBeforeUnmount(() => {
    window.clearInterval(clockTimer);
    window.removeEventListener('online', setOnline);
    window.removeEventListener('offline', setOffline);
    document.removeEventListener('fullscreenchange', syncFullscreen);
});

const shownBranches = computed(() => focusBranch.value === 'all'
    ? props.branches
    : props.branches.filter((branch) => String(branch.id) === String(focusBranch.value)));

const totals = computed(() => {
    const base = {
        sales: 0, invoices: 0, activeOrders: 0, todayOrders: 0, occupied: 0, tables: 0,
        delayed: 0, pending: 0, approved: 0, preparing: 0, ready: 0, delivered: 0, oldest: 0,
    };
    const total = props.branches.reduce((acc, branch) => {
        acc.sales += Number(branch.sales || 0);
        acc.invoices += Number(branch.invoices || 0);
        acc.activeOrders += Number(branch.activeOrders || 0);
        acc.todayOrders += Number(branch.todayOrders || 0);
        acc.occupied += Number(branch.occupied || 0);
        acc.tables += Number(branch.totalTables || 0);
        acc.delayed += Number(branch.delayedOrders || 0);
        acc.oldest = Math.max(acc.oldest, Number(branch.oldestActiveMinutes || 0));
        ['pending', 'approved', 'preparing', 'ready', 'delivered'].forEach((key) => {
            acc[key] += Number(branch.statusCounts?.[key] || 0);
        });
        return acc;
    }, base);
    return {
        ...total,
        avgTicket: total.invoices ? total.sales / total.invoices : 0,
        occupancy: total.tables ? Math.round((total.occupied / total.tables) * 100) : 0,
    };
});

const statusStages = computed(() => [
    { key: 'pending', label: 'بانتظار الاعتماد', value: totals.value.pending, icon: 'bi-hourglass-split' },
    { key: 'approved', label: 'وصل للتحضير', value: totals.value.approved, icon: 'bi-send-check' },
    { key: 'preparing', label: 'قيد التحضير', value: totals.value.preparing, icon: 'bi-fire' },
    { key: 'ready', label: 'جاهز للتسليم', value: totals.value.ready, icon: 'bi-bell' },
]);

const money = (value, decimals = 0) => Number(value || 0).toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
});
const updatedLabel = computed(() => lastUpdated.value.toLocaleTimeString('ar-PS', { hour: '2-digit', minute: '2-digit' }));
const recentRows = (branch) => branch.recent.slice(0, density.value === 'compact' ? 4 : 6);
const waitLabel = (minutes) => {
    const value = Number(minutes || 0);
    if (value < 60) return `${value} د`;
    return `${Math.floor(value / 60)} س ${value % 60} د`;
};

const refresh = (force = false) => {
    if ((paused.value && ! force) || refreshing.value) return;
    refreshing.value = true;
    router.reload({
        only: ['branches', 'live', 'generatedAt'],
        preserveScroll: true,
        onFinish: () => {
            refreshing.value = false;
            lastUpdated.value = new Date();
        },
    });
};

let lastVersion = props.live.version;
let idlePolls = 0;
watch(() => props.live.version, (version) => {
    lastVersion = version;
    lastUpdated.value = props.generatedAt ? new Date(props.generatedAt) : new Date();
});

const checkPulse = async (signal) => {
    if (paused.value) return;
    try {
        const response = await fetch(props.urls.pulse, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        });
        const data = response.ok ? await response.json() : null;
        if (! data || data.version !== lastVersion || ++idlePolls >= 6) {
            idlePolls = 0;
            refresh();
        }
    } catch { /* Offline indicator explains the stale state. */ }
};

useLiveRefresh({
    pollMs: 10000,
    onPing: (reason, signal) => {
        if (! paused.value) reason === 'poll' ? checkPulse(signal) : refresh();
    },
});

const togglePause = () => {
    paused.value = ! paused.value;
    if (! paused.value) refresh(true);
};
const toggleFullscreen = async () => {
    if (! document.fullscreenElement) await document.documentElement.requestFullscreen?.();
    else await document.exitFullscreen?.();
};
</script>

<template>
    <Head title="المراقبة الحية" />

    <main class="monitor" :class="[`density-${density}`, { 'is-paused': paused }]">
        <header class="monitor-header">
            <div class="monitor-title">
                <span class="live-beacon" :class="{ offline, paused }"><i></i></span>
                <div>
                    <span>{{ offline ? 'الاتصال مقطوع' : paused ? 'التحديث متوقف مؤقتاً' : 'مباشر الآن' }}</span>
                    <h1>مراقبة الفروع</h1>
                </div>
            </div>

            <div class="monitor-clock">
                <span>{{ dateLabel }}</span>
                <strong>{{ clock }}</strong>
            </div>

            <nav class="monitor-actions" aria-label="أدوات الشاشة">
                <a :href="urls.overview" class="monitor-btn" title="نظرة المالك"><i class="bi bi-bar-chart-line"></i><span>النظرة</span></a>
                <button type="button" class="monitor-btn" :class="{ active: density === 'compact' }" title="تغيير كثافة العرض" @click="density = density === 'compact' ? 'comfortable' : 'compact'">
                    <i class="bi" :class="density === 'compact' ? 'bi-arrows-angle-expand' : 'bi-arrows-angle-contract'"></i><span>الكثافة</span>
                </button>
                <button type="button" class="monitor-btn" :class="{ active: paused }" @click="togglePause">
                    <i class="bi" :class="paused ? 'bi-play-fill' : 'bi-pause-fill'"></i><span>{{ paused ? 'استئناف' : 'إيقاف' }}</span>
                </button>
                <button type="button" class="monitor-btn" :disabled="refreshing" @click="refresh(true)"><i class="bi bi-arrow-repeat" :class="{ spin: refreshing }"></i><span>تحديث</span></button>
                <button type="button" class="monitor-btn" @click="toggleFullscreen"><i class="bi" :class="fullscreen ? 'bi-fullscreen-exit' : 'bi-fullscreen'"></i><span>ملء الشاشة</span></button>
                <a :href="urls.home" class="monitor-btn monitor-exit" title="لوحة التحكم"><i class="bi bi-box-arrow-left"></i></a>
            </nav>
        </header>

        <section class="monitor-summary">
            <article class="summary-main">
                <small>مبيعات اليوم</small>
                <strong>{{ money(totals.sales) }} <b>{{ currency }}</b></strong>
                <span>{{ totals.invoices }} فاتورة · متوسط {{ money(totals.avgTicket, 2) }}</span>
            </article>
            <article><i class="bi bi-receipt"></i><span><small>طلبات اليوم</small><strong>{{ totals.todayOrders }}</strong></span></article>
            <article :class="{ hot: totals.activeOrders >= 15 }"><i class="bi bi-lightning-charge"></i><span><small>قيد التنفيذ</small><strong>{{ totals.activeOrders }}</strong></span></article>
            <article :class="{ danger: totals.delayed > 0 }"><i class="bi bi-stopwatch"></i><span><small>متأخرة</small><strong>{{ totals.delayed }}</strong></span></article>
            <article><i class="bi bi-grid-3x3-gap"></i><span><small>إشغال الطاولات</small><strong>{{ totals.occupancy }}%</strong></span></article>
            <article><i class="bi bi-hourglass-bottom"></i><span><small>أقدم انتظار</small><strong>{{ waitLabel(totals.oldest) }}</strong></span></article>
        </section>

        <section class="monitor-pipeline" aria-label="مراحل الطلبات">
            <div class="pipeline-title"><span>مسار الطلبات الآن</span><small>آخر تحديث {{ updatedLabel }}</small></div>
            <article v-for="stage in statusStages" :key="stage.key" :class="`stage-${stage.key}`">
                <i class="bi" :class="stage.icon"></i><span><small>{{ stage.label }}</small><strong>{{ stage.value }}</strong></span>
            </article>
            <article class="stage-delivered"><i class="bi bi-check2-circle"></i><span><small>تم التسليم</small><strong>{{ totals.delivered }}</strong></span></article>
        </section>

        <section class="monitor-toolbar" v-if="branches.length > 1">
            <div><strong>{{ shownBranches.length === branches.length ? 'كل الفروع' : 'فرع واحد' }}</strong><small>{{ shownBranches.length }} من {{ branches.length }}</small></div>
            <label><i class="bi bi-building"></i><select v-model="focusBranch"><option value="all">عرض كل الفروع</option><option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option></select></label>
        </section>

        <section class="monitor-grid" :class="{ focused: focusBranch !== 'all' }">
            <article v-for="branch in shownBranches" :key="branch.id" class="monitor-branch" :class="`tone-${branch.health?.tone || 'calm'}`">
                <header class="branch-header">
                    <div class="branch-name"><span>{{ branch.name.slice(0, 1) }}</span><div><h2>{{ branch.name }}</h2><small v-if="branch.city">{{ branch.city }}</small></div></div>
                    <div class="branch-state"><span><i></i>{{ branch.health?.label || 'مستقر' }}</span><strong>{{ branch.pressure }}%</strong></div>
                </header>

                <div class="branch-meter"><i :style="{ width: `${branch.pressure}%` }"></i></div>

                <section class="branch-kpis">
                    <div><small>المبيعات</small><strong>{{ money(branch.sales) }} <b>{{ currency }}</b></strong></div>
                    <div><small>قيد التنفيذ</small><strong>{{ branch.activeOrders }}</strong></div>
                    <div :class="{ danger: branch.delayedOrders > 0 }"><small>متأخرة</small><strong>{{ branch.delayedOrders }}</strong></div>
                    <div><small>أقدم انتظار</small><strong>{{ waitLabel(branch.oldestActiveMinutes) }}</strong></div>
                </section>

                <section class="branch-flow">
                    <div><span>اعتماد</span><strong>{{ branch.statusCounts.pending }}</strong></div>
                    <i class="bi bi-chevron-left"></i>
                    <div><span>وصل</span><strong>{{ branch.statusCounts.approved }}</strong></div>
                    <i class="bi bi-chevron-left"></i>
                    <div><span>تحضير</span><strong>{{ branch.statusCounts.preparing }}</strong></div>
                    <i class="bi bi-chevron-left"></i>
                    <div class="ready"><span>جاهز</span><strong>{{ branch.statusCounts.ready }}</strong></div>
                </section>

                <section class="branch-tables">
                    <header><span><i class="bi bi-grid-3x3-gap"></i> الصالة</span><strong>{{ branch.occupied }}/{{ branch.totalTables }} · {{ branch.occupancy }}%</strong></header>
                    <div class="occupancy-bar"><i :style="{ width: `${branch.occupancy}%` }"></i></div>
                    <div class="table-map">
                        <span v-for="table in branch.tables" :key="table.id" :class="`is-${table.status}`" :title="`طاولة ${table.number}`">{{ table.number }}</span>
                        <small v-if="! branch.tables.length">لا توجد طاولات مفعلة</small>
                    </div>
                </section>

                <section class="branch-orders">
                    <header><span><i class="bi bi-clock-history"></i> آخر الطلبات</span><small>{{ branch.todayOrders }} اليوم</small></header>
                    <div class="order-list">
                        <div v-for="order in recentRows(branch)" :key="order.id" class="order-row" :class="{ active: order.active, delayed: order.delayed }">
                            <span class="order-status-dot" :style="{ background: order.statusColor }"></span>
                            <strong>{{ order.tableNumber ? `طاولة ${order.tableNumber}` : 'طلب مباشر' }}</strong>
                            <span class="order-state">{{ order.statusLabel }}</span>
                            <span v-if="order.active" class="order-age">{{ waitLabel(order.ageMinutes) }}</span>
                            <b>{{ money(order.total, 2) }}</b>
                            <time>{{ order.at }}</time>
                        </div>
                        <div v-if="! branch.recent.length" class="orders-empty"><i class="bi bi-cup-hot"></i><span>لا توجد طلبات اليوم بعد</span></div>
                    </div>
                </section>
            </article>

            <div v-if="! shownBranches.length" class="monitor-empty"><i class="bi bi-building-slash"></i><strong>لا توجد فروع متاحة</strong><span>راجع صلاحيات المالك أو حالة الفروع.</span></div>
        </section>

        <footer class="monitor-footer">
            <span><i class="legend-dot available"></i>متاحة</span><span><i class="legend-dot occupied"></i>مشغولة</span><span><i class="legend-dot reserved"></i>محجوزة</span><span><i class="legend-dot out"></i>خارج الخدمة</span>
            <small>تتحدث الشاشة تلقائياً كل 10 ثوانٍ عند وجود تغيير.</small>
        </footer>

        <div v-if="paused || offline" class="monitor-notice" :class="{ danger: offline }">
            <i class="bi" :class="offline ? 'bi-wifi-off' : 'bi-pause-circle'"></i>
            {{ offline ? 'الاتصال مقطوع — الأرقام المعروضة هي آخر بيانات وصلت' : 'التحديث متوقف مؤقتاً' }}
        </div>
    </main>
</template>

<style scoped>
.monitor { --panel: #111c2d; --panel-2: #0c1625; --line: #20314a; --muted: #8190a6; min-height: 100dvh; padding: .75rem; display: flex; flex-direction: column; gap: .65rem; background: #07111f; color: #e8eef6; font-family: inherit; }
.monitor-header { display: grid; grid-template-columns: minmax(210px, 1fr) auto minmax(360px, 1fr); align-items: center; gap: .8rem; padding: .65rem .8rem; border: 1px solid var(--line); border-radius: 15px; background: var(--panel); box-shadow: 0 10px 30px rgba(0,0,0,.12); }
.monitor-title { display: flex; align-items: center; gap: .65rem; }
.monitor-title h1 { margin: 0; color: #fff; font-size: 1.08rem; font-weight: 900; }
.monitor-title div > span { display: block; color: #53d592; font-size: .64rem; font-weight: 800; }
.live-beacon { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 12px; background: rgba(36,196,113,.12); }
.live-beacon i { width: 9px; height: 9px; border-radius: 50%; background: #29d47d; box-shadow: 0 0 0 5px rgba(41,212,125,.12); animation: pulse 2s ease-in-out infinite; }
.live-beacon.offline, .live-beacon.paused { background: rgba(242,175,64,.12); }
.live-beacon.offline i { background: #ef5964; box-shadow: 0 0 0 5px rgba(239,89,100,.12); animation: none; }
.live-beacon.paused i { background: #f2af40; box-shadow: 0 0 0 5px rgba(242,175,64,.12); animation: none; }
.monitor-clock { text-align: center; }
.monitor-clock span { display: block; color: var(--muted); font-size: .64rem; }
.monitor-clock strong { color: #fff; font-size: 1.42rem; line-height: 1.15; letter-spacing: .05em; font-variant-numeric: tabular-nums; }
.monitor-actions { display: flex; justify-content: flex-end; gap: .3rem; }
.monitor-btn { min-width: 48px; height: 42px; padding: 0 .55rem; display: inline-flex; align-items: center; justify-content: center; gap: .3rem; border: 1px solid #283a54; border-radius: 10px; background: #16243a; color: #aebbd0; text-decoration: none; font-size: .66rem; }
.monitor-btn:hover, .monitor-btn.active { color: #fff; border-color: #3c5475; background: #1b2d48; }
.monitor-btn:disabled { opacity: .55; }
.monitor-btn i { font-size: .85rem; }
.monitor-exit { min-width: 42px; color: #f08a92; }
.spin { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes pulse { 50% { opacity: .4; transform: scale(.82); } }

.monitor-summary { display: grid; grid-template-columns: 1.45fr repeat(5, minmax(110px, .65fr)); gap: .45rem; }
.monitor-summary article { min-height: 68px; display: flex; align-items: center; gap: .55rem; padding: .65rem .7rem; border: 1px solid var(--line); border-radius: 13px; background: var(--panel); }
.monitor-summary article > i { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 9px; background: #17273e; color: #8091aa; }
.monitor-summary small, .monitor-summary span { display: block; color: var(--muted); font-size: .62rem; }
.monitor-summary strong { display: block; color: #fff; font-size: 1.18rem; font-weight: 900; font-variant-numeric: tabular-nums; }
.monitor-summary strong b { color: #6f8097; font-size: .68rem; }
.summary-main { border-color: #28533f !important; background: #10281e !important; }
.summary-main strong { color: #57dc98 !important; font-size: 1.35rem !important; }
.monitor-summary article.hot { border-color: #705329; background: #261f16; }
.monitor-summary article.hot strong { color: #f5bd59; }
.monitor-summary article.danger { border-color: #6d3039; background: #28171e; }
.monitor-summary article.danger strong { color: #ff7d87; }

.monitor-pipeline { display: grid; grid-template-columns: minmax(160px, .8fr) repeat(5, minmax(105px, 1fr)); gap: .4rem; }
.pipeline-title, .monitor-pipeline article { min-height: 52px; display: flex; align-items: center; gap: .5rem; padding: .5rem .65rem; border: 1px solid var(--line); background: var(--panel); }
.pipeline-title { flex-direction: column; align-items: flex-start; justify-content: center; border-radius: 12px 0 0 12px; }
.pipeline-title span { font-size: .72rem; font-weight: 900; color: #e9eff7; }
.pipeline-title small { color: #728198; font-size: .58rem; }
.monitor-pipeline article:last-child { border-radius: 0 12px 12px 0; }
.monitor-pipeline article > i { width: 28px; height: 28px; display: grid; place-items: center; border-radius: 8px; background: #192940; color: #94a6c0; }
.monitor-pipeline article span { flex: 1; min-width: 0; }
.monitor-pipeline small { display: block; color: #7888a0; font-size: .59rem; white-space: nowrap; }
.monitor-pipeline strong { display: block; color: #fff; font-size: 1rem; font-weight: 900; }
.stage-pending > i { color: #f2b84c !important; }
.stage-preparing > i { color: #ff925c !important; }
.stage-ready > i { color: #50d895 !important; }

.monitor-toolbar { min-height: 42px; display: flex; align-items: center; justify-content: space-between; padding: .35rem .55rem; border: 1px solid var(--line); border-radius: 11px; background: rgba(17,28,45,.8); }
.monitor-toolbar > div strong, .monitor-toolbar > div small { display: block; }
.monitor-toolbar > div strong { font-size: .72rem; color: #dbe5f2; }
.monitor-toolbar > div small { font-size: .57rem; color: #718097; }
.monitor-toolbar label { display: flex; align-items: center; gap: .35rem; color: #718198; }
.monitor-toolbar select { min-width: 180px; height: 32px; padding: 0 .5rem; border: 1px solid #2b3d57; border-radius: 8px; outline: 0; background: #111d30; color: #dfe7f2; font-size: .68rem; }

.monitor-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(380px, 100%), 1fr)); gap: .55rem; flex: 1; align-items: start; }
.monitor-grid.focused { grid-template-columns: minmax(0, 1fr); }
.monitor-branch { min-width: 0; display: flex; flex-direction: column; gap: .5rem; padding: .65rem; border: 1px solid var(--line); border-top: 3px solid #3eaa72; border-radius: 14px; background: var(--panel); box-shadow: 0 8px 24px rgba(0,0,0,.1); }
.monitor-branch.tone-warning { border-top-color: #e3a844; }
.monitor-branch.tone-danger { border-top-color: #eb5964; }
.branch-header { display: flex; align-items: center; justify-content: space-between; gap: .6rem; }
.branch-name { display: flex; align-items: center; gap: .5rem; min-width: 0; }
.branch-name > span { width: 34px; height: 34px; flex: 0 0 34px; display: grid; place-items: center; border-radius: 10px; background: #183b2a; color: #62dda0; font-weight: 900; }
.branch-name h2 { margin: 0; color: #fff; font-size: .88rem; font-weight: 900; }
.branch-name small { display: block; color: #708098; font-size: .58rem; }
.branch-state { text-align: left; }
.branch-state span { display: flex; align-items: center; gap: .25rem; color: #5fd697; font-size: .6rem; font-weight: 800; }
.branch-state span i { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.branch-state strong { color: #8494a9; font-size: .67rem; font-variant-numeric: tabular-nums; }
.tone-warning .branch-state span { color: #efb84f; }
.tone-danger .branch-state span { color: #f36e78; }
.branch-meter, .occupancy-bar { height: 4px; overflow: hidden; border-radius: 999px; background: #1c2a3e; }
.branch-meter i, .occupancy-bar i { display: block; height: 100%; max-width: 100%; border-radius: inherit; background: #46bd7e; }
.tone-warning .branch-meter i { background: #e0a340; }
.tone-danger .branch-meter i { background: #e85661; }

.branch-kpis { display: grid; grid-template-columns: 1.25fr repeat(3, 1fr); gap: .35rem; }
.branch-kpis > div { padding: .4rem .48rem; border-radius: 9px; background: var(--panel-2); }
.branch-kpis small { display: block; color: #6f7f97; font-size: .57rem; }
.branch-kpis strong { display: block; color: #e9eff7; font-size: .85rem; font-weight: 900; font-variant-numeric: tabular-nums; }
.branch-kpis strong b { color: #65748a; font-size: .56rem; }
.branch-kpis .danger { background: #28171e; }
.branch-kpis .danger strong { color: #ff7782; }

.branch-flow { display: grid; grid-template-columns: 1fr auto 1fr auto 1fr auto 1fr; align-items: center; gap: .2rem; padding: .38rem; border-radius: 10px; background: #0d1828; }
.branch-flow div { display: flex; align-items: center; justify-content: center; gap: .35rem; }
.branch-flow span { color: #73829a; font-size: .56rem; }
.branch-flow strong { color: #d9e3ef; font-size: .78rem; }
.branch-flow > i { color: #34445a; font-size: .55rem; }
.branch-flow .ready strong { color: #5bd999; }

.branch-tables, .branch-orders { min-width: 0; padding-top: .1rem; }
.branch-tables header, .branch-orders header { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .35rem; color: #8292a9; font-size: .62rem; }
.branch-tables header strong { color: #b8c5d5; font-size: .62rem; }
.occupancy-bar { margin-bottom: .4rem; }
.occupancy-bar i { background: #d89b38; }
.table-map { display: flex; flex-wrap: wrap; gap: .22rem; }
.table-map > span { min-width: 25px; height: 24px; padding: 0 .25rem; display: inline-grid; place-items: center; border: 1px solid #27364b; border-radius: 6px; background: #152237; color: #6f7e94; font-size: .6rem; font-weight: 850; }
.table-map > span.is-occupied { border-color: #755824; background: #302516; color: #f0b74d; }
.table-map > span.is-reserved { border-color: #31527d; background: #172941; color: #88b9f4; }
.table-map > span.is-out_of_service { border-color: #64323a; background: #29171e; color: #f17881; }
.table-map small { color: #65748a; font-size: .6rem; }

.order-list { display: flex; flex-direction: column; gap: .22rem; }
.order-row { min-height: 30px; display: grid; grid-template-columns: 7px minmax(78px, .8fr) minmax(60px, .65fr) auto auto auto; align-items: center; gap: .35rem; padding: .28rem .4rem; border: 1px solid transparent; border-radius: 8px; background: var(--panel-2); color: #8796aa; font-size: .6rem; }
.order-row.active { border-color: #263950; }
.order-row.delayed { border-color: #65313a; background: #24161c; }
.order-status-dot { width: 7px; height: 7px; border-radius: 50%; }
.order-row > strong { color: #dce5f0; font-size: .63rem; white-space: nowrap; }
.order-state { white-space: nowrap; }
.order-age { padding: .08rem .28rem; border-radius: 999px; background: #1d2a3d; color: #9dafc4; white-space: nowrap; }
.order-row.delayed .order-age { background: #4b232b; color: #ff929a; }
.order-row > b { color: #58d393; white-space: nowrap; font-variant-numeric: tabular-nums; }
.order-row time { color: #58677c; font-variant-numeric: tabular-nums; }
.orders-empty { min-height: 54px; display: grid; place-items: center; align-content: center; color: #58687d; font-size: .62rem; }
.orders-empty i { font-size: 1rem; }

.monitor-footer { min-height: 34px; display: flex; align-items: center; gap: .7rem; padding: 0 .45rem; color: #6f7e92; font-size: .58rem; }
.monitor-footer > span { display: inline-flex; align-items: center; gap: .25rem; }
.monitor-footer small { margin-inline-start: auto; }
.legend-dot { width: 7px; height: 7px; border-radius: 2px; background: #3a4a5f; }
.legend-dot.occupied { background: #d89b38; }.legend-dot.reserved { background: #4b87cc; }.legend-dot.out { background: #d84c58; }
.monitor-notice { position: fixed; inset-block-end: 1rem; inset-inline-start: 50%; transform: translateX(-50%); z-index: 10; padding: .55rem .8rem; border: 1px solid #765828; border-radius: 999px; background: #2b2114; color: #f3bd5c; box-shadow: 0 12px 35px rgba(0,0,0,.35); font-size: .68rem; font-weight: 800; }
.monitor-notice.danger { border-color: #70343d; background: #2b181e; color: #ff818b; }
.monitor-empty { grid-column: 1 / -1; min-height: 280px; display: grid; place-items: center; align-content: center; color: #66758a; text-align: center; }
.monitor-empty i { font-size: 2rem; }.monitor-empty strong, .monitor-empty span { display: block; }

.density-compact { gap: .45rem; padding: .55rem; }
.density-compact .monitor-header { padding-block: .45rem; }
.density-compact .monitor-summary article { min-height: 58px; }
.density-compact .monitor-grid { grid-template-columns: repeat(auto-fit, minmax(min(315px, 100%), 1fr)); gap: .4rem; }
.density-compact .monitor-branch { gap: .38rem; padding: .5rem; }
.density-compact .branch-tables { display: none; }
.density-compact .branch-kpis > div { padding-block: .3rem; }
.density-compact .order-row { min-height: 27px; }
.is-paused { filter: saturate(.82); }

@media (max-width: 1200px) {
    .monitor-header { grid-template-columns: 1fr auto; }
    .monitor-clock { grid-column: 2; grid-row: 1; }
    .monitor-actions { grid-column: 1 / -1; justify-content: stretch; }
    .monitor-btn { flex: 1; }
    .monitor-summary { grid-template-columns: repeat(3, 1fr); }
    .summary-main { grid-column: span 2; }
    .monitor-pipeline { grid-template-columns: repeat(5, 1fr); }
    .pipeline-title { grid-column: 1 / -1; flex-direction: row; align-items: center; justify-content: space-between; border-radius: 10px; }
    .monitor-pipeline article:last-child { border-radius: 10px; }
}
@media (max-width: 700px) {
    .monitor { padding: .45rem; }
    .monitor-header { display: flex; flex-wrap: wrap; }
    .monitor-title { flex: 1; }
    .monitor-clock span { display: none; }
    .monitor-actions { width: 100%; overflow-x: auto; }
    .monitor-btn { min-width: 58px; flex-direction: column; gap: 0; font-size: .55rem; }
    .monitor-summary { display: flex; overflow-x: auto; scrollbar-width: none; }
    .monitor-summary article { min-width: 126px; }
    .summary-main { min-width: 190px !important; }
    .monitor-pipeline { display: flex; overflow-x: auto; scrollbar-width: none; }
    .pipeline-title { min-width: 145px; }
    .monitor-pipeline article { min-width: 110px; }
    .monitor-toolbar { position: sticky; top: .3rem; z-index: 3; backdrop-filter: blur(12px); }
    .monitor-toolbar > div { display: none; }
    .monitor-toolbar label, .monitor-toolbar select { width: 100%; }
    .monitor-grid { grid-template-columns: 1fr; }
    .branch-kpis { grid-template-columns: repeat(2, 1fr); }
    .branch-flow { overflow-x: auto; grid-template-columns: repeat(7, auto); justify-content: space-between; }
    .branch-flow div { min-width: 52px; }
    .order-row { grid-template-columns: 7px minmax(78px, 1fr) auto auto; }
    .order-state { display: none; }
    .order-row time { display: none; }
    .monitor-footer { flex-wrap: wrap; }
    .monitor-footer small { width: 100%; margin: 0; }
}
@media (prefers-reduced-motion: reduce) { .live-beacon i, .spin { animation: none; } }
</style>
