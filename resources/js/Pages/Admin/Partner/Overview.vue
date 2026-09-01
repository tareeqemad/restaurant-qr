<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useLiveRefresh } from '../../../Composables/useLiveRefresh';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    summaries: { type: Array, required: true },
    totals: { type: Object, required: true },
    trend: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    live: { type: Object, required: true },
    currency: { type: String, default: '₪' },
    generatedAt: { type: String, default: null },
    urls: { type: Object, required: true },
});

const query = ref('');
const filter = ref('all');
const sort = ref('attention');
const layout = ref('grid');
const refreshing = ref(false);
const lastUpdated = ref(props.generatedAt ? new Date(props.generatedAt) : new Date());

const normalize = (value) => String(value || '').toLocaleLowerCase('ar').trim();
const filterCounts = computed(() => ({
    all: props.summaries.length,
    attention: props.summaries.filter((branch) => branch.needsAttention).length,
    danger: props.summaries.filter((branch) => branch.health?.tone === 'danger').length,
    calm: props.summaries.filter((branch) => ! branch.needsAttention).length,
}));

const visibleBranches = computed(() => {
    const needle = normalize(query.value);
    const rows = props.summaries.filter((branch) => {
        if (needle && ! normalize(`${branch.name} ${branch.city || ''}`).includes(needle)) return false;
        if (filter.value === 'attention') return branch.needsAttention;
        if (filter.value === 'danger') return branch.health?.tone === 'danger';
        if (filter.value === 'calm') return ! branch.needsAttention;
        return true;
    });

    return [...rows].sort((a, b) => {
        if (sort.value === 'sales') return b.sales - a.sales;
        if (sort.value === 'occupancy') return b.occupancy - a.occupancy;
        if (sort.value === 'orders') return b.activeOrders - a.activeOrders;
        return (b.attention - a.attention) || (b.pressure - a.pressure) || a.name.localeCompare(b.name, 'ar');
    });
});

const spark = computed(() => {
    const values = props.trend.map((day) => Number(day.value || 0));
    if (! values.length) return { path: '', area: '', points: [], max: 0 };
    const max = Math.max(...values, 1);
    const step = values.length > 1 ? 100 / (values.length - 1) : 100;
    const points = values.map((value, index) => ({
        x: index * step,
        y: 34 - (value / max) * 28,
        value,
    }));
    const path = points.map((point, index) => `${index ? 'L' : 'M'}${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(' ');
    return { path, area: `${path} L100,36 L0,36 Z`, points, max };
});

const trendTotal = computed(() => props.trend.reduce((sum, day) => sum + Number(day.value || 0), 0));
const money = (value, decimals = 0) => `${Number(value || 0).toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
})} ${props.currency}`;
const updatedLabel = computed(() => lastUpdated.value.toLocaleTimeString('ar-PS', { hour: '2-digit', minute: '2-digit' }));
const pressureLabel = (value) => value >= 70 ? 'ضغط مرتفع' : value >= 40 ? 'نشاط واضح' : 'هادئ';

const refresh = () => {
    if (refreshing.value) return;
    refreshing.value = true;
    router.reload({
        only: ['summaries', 'totals', 'trend', 'actions', 'live', 'generatedAt'],
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
    } catch { /* The next visible poll retries. */ }
};

useLiveRefresh({
    pollMs: 20000,
    onPing: (reason, signal) => (reason === 'poll' ? checkPulse(signal) : refresh()),
});
</script>

<template>
    <Head title="متابعة المالك" />

    <PageHeader title="متابعة المالك" icon="bi-binoculars-fill"
                subtitle="الاستثناءات أولاً، ثم مقارنة الفروع من شاشة واحدة">
        <template #actions>
            <button type="button" class="btn btn-light owner-refresh" :disabled="refreshing" @click="refresh">
                <i class="bi bi-arrow-repeat" :class="{ spin: refreshing }"></i>
                تحديث
            </button>
            <a :href="urls.liveMonitor" target="_blank" rel="noopener" class="btn btn-dark">
                <i class="bi bi-broadcast-pin"></i> المراقبة الحية
            </a>
        </template>
    </PageHeader>

    <section class="owner-statusbar" aria-label="حالة التحديث">
        <div class="owner-live"><span></span><strong>متصل ومحدّث تلقائياً</strong></div>
        <small>آخر تحديث {{ updatedLabel }}</small>
        <span class="owner-statusbar-sep"></span>
        <small>{{ totals.branches }} {{ totals.branches === 1 ? 'فرع' : 'فروع' }} ضمن صلاحيتك</small>
    </section>

    <section class="owner-overview">
        <div class="owner-kpis">
            <article class="owner-kpi owner-kpi--sales">
                <span class="owner-kpi-icon"><i class="bi bi-cash-stack"></i></span>
                <div><small>مبيعات اليوم</small><strong>{{ money(totals.sales) }}</strong><p :class="totals.net >= 0 ? 'positive' : 'negative'">الصافي {{ money(totals.net) }}</p></div>
            </article>
            <article class="owner-kpi">
                <span class="owner-kpi-icon"><i class="bi bi-receipt-cutoff"></i></span>
                <div><small>طلبات اليوم</small><strong>{{ totals.orders }}</strong><p>{{ totals.active }} قيد التنفيذ</p></div>
            </article>
            <article class="owner-kpi">
                <span class="owner-kpi-icon"><i class="bi bi-grid-3x3-gap-fill"></i></span>
                <div><small>إشغال الصالة</small><strong>{{ totals.occupancy }}%</strong><p>{{ totals.occupied }} من {{ totals.totalTables }} طاولة</p></div>
            </article>
            <article class="owner-kpi" :class="{ 'owner-kpi--alert': totals.delayed > 0 }">
                <span class="owner-kpi-icon"><i class="bi bi-stopwatch-fill"></i></span>
                <div><small>طلبات متأخرة</small><strong>{{ totals.delayed }}</strong><p>{{ totals.delayed ? 'تحتاج متابعة الآن' : 'لا يوجد تأخير' }}</p></div>
            </article>
            <article class="owner-kpi" :class="{ 'owner-kpi--alert': totals.attention > 0 }">
                <span class="owner-kpi-icon"><i class="bi bi-exclamation-diamond-fill"></i></span>
                <div><small>نقاط تحتاج انتباه</small><strong>{{ totals.attention }}</strong><p>{{ totals.criticalBranches }} فرع متأثر</p></div>
            </article>
        </div>

        <article class="owner-trend">
            <header>
                <div><small>اتجاه المبيعات</small><strong>آخر ٧ أيام</strong></div>
                <div class="owner-trend-total"><small>الإجمالي</small><strong>{{ money(trendTotal) }}</strong></div>
            </header>
            <svg v-if="trend.length" viewBox="0 0 100 38" preserveAspectRatio="none" aria-label="رسم اتجاه المبيعات">
                <path :d="spark.area" class="trend-area" />
                <path :d="spark.path" class="trend-line" />
                <circle v-for="point in spark.points" :key="point.x" :cx="point.x" :cy="point.y" r="1.25" />
            </svg>
            <div class="owner-trend-days"><span v-for="day in trend" :key="day.label">{{ day.label }}</span></div>
        </article>
    </section>

    <section class="owner-focus" :class="{ 'is-clear': ! actions.length }">
        <header>
            <div>
                <span class="section-eyebrow">ما يحتاج قراراً</span>
                <h2>{{ actions.length ? 'ابدأ من هنا' : 'الوضع مستقر' }}</h2>
            </div>
            <span v-if="actions.length" class="focus-count">{{ actions.length }} ملفات مفتوحة</span>
        </header>
        <div v-if="actions.length" class="owner-actions">
            <a v-for="action in actions" :key="action.label" :href="action.url" class="owner-action" :class="`is-${action.tone}`">
                <span class="owner-action-icon"><i class="bi" :class="action.icon"></i></span>
                <span><strong>{{ action.display }}</strong><small>{{ action.label }}</small></span>
                <i class="bi bi-arrow-left-short"></i>
            </a>
        </div>
        <div v-else class="owner-clear">
            <i class="bi bi-check2-circle"></i>
            <span><strong>لا يوجد شيء عاجل الآن</strong><small>كل الفروع تعمل ضمن المؤشرات الطبيعية.</small></span>
        </div>
    </section>

    <section class="owner-branches">
        <header class="branches-head">
            <div><span class="section-eyebrow">مقارنة الفروع</span><h2>أين تركز اليوم؟</h2></div>
            <div class="branches-tools">
                <label class="branch-search"><i class="bi bi-search"></i><input v-model="query" type="search" placeholder="ابحث عن فرع أو مدينة"></label>
                <select v-model="sort" aria-label="ترتيب الفروع">
                    <option value="attention">الأكثر حاجة للمتابعة</option>
                    <option value="orders">الأعلى ضغطاً بالطلبات</option>
                    <option value="sales">الأعلى مبيعات</option>
                    <option value="occupancy">الأعلى إشغالاً</option>
                </select>
                <div class="layout-toggle" aria-label="طريقة العرض">
                    <button type="button" :class="{ active: layout === 'grid' }" title="بطاقات" @click="layout = 'grid'"><i class="bi bi-grid"></i></button>
                    <button type="button" :class="{ active: layout === 'list' }" title="قائمة" @click="layout = 'list'"><i class="bi bi-list"></i></button>
                </div>
            </div>
        </header>

        <div class="branch-filters">
            <button v-for="item in [
                { key: 'all', label: 'الكل' },
                { key: 'danger', label: 'تدخل الآن' },
                { key: 'attention', label: 'تحتاج متابعة' },
                { key: 'calm', label: 'مستقرة' },
            ]" :key="item.key" type="button" :class="{ active: filter === item.key }" @click="filter = item.key">
                {{ item.label }} <span>{{ filterCounts[item.key] }}</span>
            </button>
        </div>

        <div class="branch-grid" :class="{ 'is-list': layout === 'list' }">
            <article v-for="branch in visibleBranches" :key="branch.id" class="branch-card" :class="`tone-${branch.health?.tone || 'calm'}`">
                <header class="branch-card-head">
                    <div class="branch-identity">
                        <span class="branch-mark">{{ branch.name.slice(0, 1) }}</span>
                        <span><strong>{{ branch.name }}</strong><small>{{ branch.city || 'الفرع' }}</small></span>
                    </div>
                    <span class="branch-health"><i class="bi bi-circle-fill"></i>{{ branch.health?.label || 'مستقر' }}</span>
                </header>

                <div class="branch-pressure">
                    <span><b>{{ pressureLabel(branch.pressure) }}</b><small>{{ branch.pressure }}%</small></span>
                    <div><i :style="{ width: `${branch.pressure}%` }"></i></div>
                </div>

                <div class="branch-primary">
                    <div><small>المبيعات</small><strong>{{ money(branch.sales) }}</strong></div>
                    <div><small>الصافي</small><strong :class="branch.net >= 0 ? 'positive' : 'negative'">{{ money(branch.net) }}</strong></div>
                    <div><small>متوسط الفاتورة</small><strong>{{ money(branch.avgTicket, 2) }}</strong></div>
                </div>

                <div class="branch-operations">
                    <span><i class="bi bi-receipt"></i><b>{{ branch.todayOrders }}</b><small>طلبات</small></span>
                    <span><i class="bi bi-lightning-charge"></i><b>{{ branch.activeOrders }}</b><small>تحت التنفيذ</small></span>
                    <span><i class="bi bi-grid-3x3-gap"></i><b>{{ branch.occupancy }}%</b><small>إشغال</small></span>
                    <span><i class="bi bi-star"></i><b>{{ branch.avgRating || '—' }}</b><small>التقييم</small></span>
                </div>

                <div v-if="branch.needsAttention" class="branch-alerts">
                    <span v-if="branch.delayedOrders" class="danger"><i class="bi bi-stopwatch"></i>{{ branch.delayedOrders }} متأخر</span>
                    <span v-if="branch.pendingOrders"><i class="bi bi-hourglass-split"></i>{{ branch.pendingOrders }} بانتظار الاعتماد</span>
                    <span v-if="branch.outStock" class="danger"><i class="bi bi-box-seam"></i>{{ branch.outStock }} نافد</span>
                    <span v-else-if="branch.lowStock"><i class="bi bi-box-seam"></i>{{ branch.lowStock }} منخفض</span>
                    <span v-if="branch.expiringBatches"><i class="bi bi-calendar-x"></i>{{ branch.expiringBatches }} صلاحية قريبة</span>
                    <span v-if="branch.overdueApCount" class="danger"><i class="bi bi-cash-coin"></i>{{ branch.overdueApCount }} مورد متأخر</span>
                    <span v-if="branch.pendingReservations"><i class="bi bi-calendar-event"></i>{{ branch.pendingReservations }} حجز معلق</span>
                    <span v-if="branch.lowReviews" class="danger"><i class="bi bi-star-half"></i>{{ branch.lowReviews }} تقييم منخفض</span>
                    <span v-if="branch.poNeedsApproval"><i class="bi bi-cart-check"></i>{{ branch.poNeedsApproval }} أمر شراء</span>
                    <span v-if="branch.invoiceVariances"><i class="bi bi-slash-circle"></i>{{ branch.invoiceVariances }} فرق فاتورة</span>
                </div>
                <div v-else class="branch-clear"><i class="bi bi-check2"></i> لا توجد استثناءات مفتوحة</div>

                <footer v-if="branch.apDue > 0 || branch.waste7d > 0">
                    <span v-if="branch.apDue > 0">مستحق للموردين <strong>{{ money(branch.apDue, 2) }}</strong></span>
                    <span v-if="branch.waste7d > 0">هدر ٧ أيام <strong>{{ money(branch.waste7d, 2) }}</strong></span>
                </footer>
            </article>

            <div v-if="! visibleBranches.length" class="branch-empty">
                <i class="bi bi-search"></i><strong>لا توجد فروع مطابقة</strong><small>غيّر البحث أو مرشح الحالة.</small>
            </div>
        </div>
    </section>
</template>

<style scoped>
.section-eyebrow { display: block; color: #7c8b82; font-size: .72rem; font-weight: 800; margin-bottom: .12rem; }
h2 { margin: 0; color: #13251b; font-size: 1.1rem; font-weight: 900; }
.positive { color: #087a43 !important; }
.negative { color: #be2934 !important; }
.spin { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.owner-statusbar { display: flex; align-items: center; gap: .7rem; min-height: 38px; margin: -.25rem 0 .75rem; padding: .45rem .75rem; border: 1px solid #e4ebe7; border-radius: 12px; background: #fbfdfc; color: #6a7a71; }
.owner-statusbar small { font-size: .72rem; }
.owner-live { display: inline-flex; align-items: center; gap: .4rem; color: #147346; font-size: .74rem; }
.owner-live span { width: 8px; height: 8px; border-radius: 50%; background: #1fa865; box-shadow: 0 0 0 4px rgba(31,168,101,.11); }
.owner-statusbar-sep { width: 1px; height: 16px; background: #dfe7e2; }
.owner-refresh { border-color: #dfe7e2; }

.owner-overview { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(260px, .7fr); gap: .8rem; margin-bottom: .8rem; }
.owner-kpis { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .55rem; }
.owner-kpi { display: flex; align-items: center; gap: .65rem; min-width: 0; padding: .85rem; background: #fff; border: 1px solid #e8eeea; border-radius: 15px; box-shadow: 0 4px 16px rgba(27,50,38,.035); }
.owner-kpi-icon { width: 36px; height: 36px; flex: 0 0 36px; display: grid; place-items: center; border-radius: 11px; background: #eff6f1; color: #157246; }
.owner-kpi > div { min-width: 0; }
.owner-kpi small, .owner-kpi p { display: block; margin: 0; font-size: .67rem; color: #87958d; white-space: nowrap; }
.owner-kpi strong { display: block; color: #14251b; font-size: 1.05rem; line-height: 1.45; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.owner-kpi--sales { border-color: #cfe6d8; background: #fbfefc; }
.owner-kpi--sales .owner-kpi-icon { background: #147346; color: #fff; }
.owner-kpi--alert { border-color: #f2d4bd; background: #fffaf5; }
.owner-kpi--alert .owner-kpi-icon { background: #fff0e4; color: #bf5d12; }

.owner-trend { padding: .8rem .9rem .55rem; background: #153a29; border-radius: 15px; color: #fff; overflow: hidden; }
.owner-trend header { display: flex; justify-content: space-between; gap: .7rem; }
.owner-trend header small { display: block; color: #9bc0aa; font-size: .66rem; }
.owner-trend header strong { font-size: .84rem; font-weight: 900; }
.owner-trend-total { text-align: left; }
.owner-trend svg { width: 100%; height: 62px; margin-top: .25rem; overflow: visible; }
.trend-area { fill: rgba(92,211,148,.12); }
.trend-line { fill: none; stroke: #5cd394; stroke-width: 1.6; vector-effect: non-scaling-stroke; }
.owner-trend circle { fill: #d8ffe9; vector-effect: non-scaling-stroke; }
.owner-trend-days { display: flex; justify-content: space-between; color: #789c88; font-size: .6rem; }

.owner-focus, .owner-branches { padding: .9rem; margin-bottom: .8rem; background: #fff; border: 1px solid #e7ede9; border-radius: 16px; }
.owner-focus > header, .branches-head { display: flex; align-items: center; justify-content: space-between; gap: .8rem; margin-bottom: .7rem; }
.focus-count { padding: .23rem .6rem; border-radius: 999px; background: #fff4e8; color: #a65416; font-size: .7rem; font-weight: 800; }
.owner-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr)); gap: .5rem; }
.owner-action { display: flex; align-items: center; gap: .55rem; min-height: 58px; padding: .6rem .7rem; color: #22362b; text-decoration: none; border: 1px solid #e8eeea; border-radius: 13px; transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease; }
.owner-action:hover { transform: translateY(-1px); border-color: #bdcfc4; box-shadow: 0 7px 18px rgba(26,55,39,.07); }
.owner-action-icon { width: 34px; height: 34px; flex: 0 0 34px; display: grid; place-items: center; border-radius: 10px; background: #fff5e8; color: #a75718; }
.owner-action.is-danger .owner-action-icon { background: #fff0f1; color: #c42b36; }
.owner-action > span:nth-child(2) { flex: 1; min-width: 0; }
.owner-action strong, .owner-action small { display: block; }
.owner-action strong { font-size: .92rem; font-weight: 900; }
.owner-action small { color: #7d8c84; font-size: .68rem; }
.owner-action > i { color: #9caaa2; }
.owner-clear { display: flex; align-items: center; gap: .65rem; padding: .75rem; background: #effaf4; color: #137247; border-radius: 12px; }
.owner-clear > i { font-size: 1.4rem; }
.owner-clear strong, .owner-clear small { display: block; }
.owner-clear small { color: #66907a; font-size: .7rem; }

.branches-tools { display: flex; align-items: center; flex-wrap: wrap; gap: .45rem; }
.branch-search { display: flex; align-items: center; gap: .4rem; min-width: 230px; height: 40px; padding-inline: .65rem; border: 1px solid #dfe7e2; border-radius: 11px; color: #849289; background: #fff; }
.branch-search input { width: 100%; border: 0; outline: 0; color: #263a2f; background: transparent; font-size: .78rem; }
.branches-tools select { height: 40px; padding: 0 .65rem; border: 1px solid #dfe7e2; border-radius: 11px; color: #415349; background: #fff; font-size: .75rem; }
.layout-toggle { display: flex; padding: 3px; border-radius: 10px; background: #eff3f0; }
.layout-toggle button { width: 33px; height: 32px; border: 0; border-radius: 8px; background: transparent; color: #7d8c84; }
.layout-toggle button.active { background: #fff; color: #126d43; box-shadow: 0 2px 8px rgba(20,55,36,.1); }
.branch-filters { display: flex; gap: .35rem; overflow-x: auto; padding-bottom: .65rem; scrollbar-width: none; }
.branch-filters button { min-height: 35px; padding: .3rem .65rem; border: 1px solid #e1e8e4; border-radius: 999px; background: #fff; color: #607168; white-space: nowrap; font-size: .72rem; font-weight: 750; }
.branch-filters button span { display: inline-grid; min-width: 20px; height: 20px; place-items: center; margin-inline-start: .2rem; border-radius: 999px; background: #edf2ef; font-size: .64rem; }
.branch-filters button.active { border-color: #187549; background: #187549; color: #fff; }
.branch-filters button.active span { background: rgba(255,255,255,.18); }

.branch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(360px, 100%), 1fr)); gap: .65rem; }
.branch-grid.is-list { grid-template-columns: 1fr; }
.branch-card { display: flex; flex-direction: column; gap: .7rem; min-width: 0; padding: .8rem; border: 1px solid #e5ece8; border-top: 3px solid #5eaa80; border-radius: 14px; background: #fff; }
.branch-card.tone-warning { border-top-color: #e1a23a; }
.branch-card.tone-danger { border-top-color: #d44b55; }
.branch-card-head, .branch-identity, .branch-pressure > span { display: flex; align-items: center; justify-content: space-between; gap: .55rem; }
.branch-identity { justify-content: flex-start; min-width: 0; }
.branch-mark { width: 36px; height: 36px; flex: 0 0 36px; display: grid; place-items: center; border-radius: 11px; background: #eaf5ee; color: #147346; font-weight: 900; }
.branch-identity strong, .branch-identity small { display: block; }
.branch-identity strong { color: #182b20; font-size: .9rem; font-weight: 900; }
.branch-identity small { color: #89968f; font-size: .65rem; }
.branch-health { display: inline-flex; align-items: center; gap: .3rem; padding: .22rem .5rem; border-radius: 999px; background: #edf8f2; color: #187347; font-size: .66rem; font-weight: 800; white-space: nowrap; }
.tone-warning .branch-health { background: #fff5e5; color: #a25b19; }
.tone-danger .branch-health { background: #fff0f1; color: #b92531; }
.branch-health i { font-size: .45rem; }
.branch-pressure > span b { color: #44574c; font-size: .68rem; }
.branch-pressure > span small { color: #819087; font-size: .65rem; }
.branch-pressure > div { height: 5px; overflow: hidden; border-radius: 999px; background: #edf2ef; }
.branch-pressure > div i { display: block; height: 100%; max-width: 100%; border-radius: inherit; background: #52a778; }
.tone-warning .branch-pressure > div i { background: #dfa13e; }
.tone-danger .branch-pressure > div i { background: #d44a55; }
.branch-primary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; }
.branch-primary > div { padding: .5rem .55rem; border-radius: 10px; background: #f7faf8; }
.branch-primary small, .branch-primary strong { display: block; }
.branch-primary small { color: #88958e; font-size: .62rem; }
.branch-primary strong { color: #1c3025; font-size: .82rem; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.branch-operations { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .35rem; }
.branch-operations span { display: grid; grid-template-columns: auto auto; justify-content: start; align-items: center; gap: .15rem .3rem; color: #607168; }
.branch-operations i { color: #7e9086; font-size: .72rem; }
.branch-operations b { color: #22372b; font-size: .8rem; }
.branch-operations small { grid-column: 1 / -1; font-size: .58rem; color: #94a098; }
.branch-alerts { display: flex; flex-wrap: wrap; gap: .3rem; }
.branch-alerts span { display: inline-flex; align-items: center; gap: .25rem; padding: .2rem .45rem; border-radius: 7px; background: #fff6e8; color: #9b5a1e; font-size: .64rem; font-weight: 800; }
.branch-alerts span.danger { background: #fff0f1; color: #b82732; }
.branch-clear { color: #4c8a68; font-size: .68rem; }
.branch-card footer { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: auto; padding-top: .55rem; border-top: 1px solid #edf1ef; color: #7a8981; font-size: .64rem; }
.branch-card footer strong { color: #415248; }
.branch-empty { grid-column: 1 / -1; display: grid; place-items: center; min-height: 180px; color: #87958d; text-align: center; }
.branch-empty i { font-size: 1.8rem; }
.branch-empty strong, .branch-empty small { display: block; }

.branch-grid.is-list .branch-card { display: grid; grid-template-columns: minmax(190px, .9fr) minmax(160px, .75fr) minmax(230px, 1fr); align-items: center; }
.branch-grid.is-list .branch-pressure { grid-column: 1; }
.branch-grid.is-list .branch-primary { grid-column: 2; grid-row: 1 / span 2; }
.branch-grid.is-list .branch-operations { grid-column: 3; grid-row: 1; }
.branch-grid.is-list .branch-alerts, .branch-grid.is-list .branch-clear { grid-column: 3; }
.branch-grid.is-list footer { grid-column: 1 / -1; }

@media (max-width: 1350px) {
    .owner-overview { grid-template-columns: 1fr; }
    .owner-kpis { grid-template-columns: repeat(5, minmax(150px, 1fr)); overflow-x: auto; padding-bottom: .2rem; }
    .owner-kpi { min-width: 150px; }
    .owner-trend { min-height: 145px; }
}
@media (max-width: 900px) {
    .branches-head { align-items: flex-start; flex-direction: column; }
    .branches-tools { width: 100%; }
    .branch-search { flex: 1; }
    .branch-grid.is-list .branch-card { display: flex; }
}
@media (max-width: 600px) {
    .owner-statusbar { flex-wrap: wrap; }
    .owner-statusbar-sep { display: none; }
    .owner-overview { gap: .6rem; }
    .owner-kpis { grid-template-columns: repeat(5, 145px); margin-inline: -.15rem; }
    .owner-focus, .owner-branches { padding: .75rem; }
    .owner-focus > header { align-items: flex-start; }
    .branches-tools select { flex: 1; min-width: 150px; }
    .branch-search { min-width: 100%; }
    .branch-primary { gap: .3rem; }
    .branch-primary > div { padding: .45rem; }
    .branch-operations { grid-template-columns: repeat(2, 1fr); }
}
@media (prefers-reduced-motion: reduce) { .owner-action { transition: none; } .spin { animation: none; } }
</style>
