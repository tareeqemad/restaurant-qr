<script setup>
/**
 * هندسة المنيو — the 2×2 popularity × margin matrix.
 *
 * Every number on this page is computed server-side (ReportController@menuEngineering):
 * the median split on both axes, the four bucket counts, the profit-DESC row order and
 * the four "top 4 of this quadrant" name lists. This file only formats and paints.
 *
 * Two controller paths render this same component. The empty path ships
 * `rows: []` and `thresholds: null` — that is the ONLY way the empty state is
 * reachable, and the only time `thresholds` is null, so it must stay guarded.
 */
import { reactive, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { formatMoney } from '../../../Composables/useMoney';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    buckets: { type: Object, required: true },
    // null IF AND ONLY IF the empty state is showing — never dereference unguarded.
    thresholds: { type: Object, default: null },
    topByClass: { type: Object, required: true },
    currency: { type: Object, required: true },
    shortcuts: { type: Object, required: true },
    urls: { type: Object, required: true },
});

/**
 * Pure presentation — this was a @php block inside the old template
 * (menu-engineering.blade.php:52-79). Labels, copy and colours verbatim.
 */
const CLASSES = Object.freeze({
    star: {
        label: 'نجوم',
        desc: 'ربحية عالية + مبيعات عالية — أبقها بارزة في المنيو',
        color: '#166534',
        bg: 'rgba(22,101,52,.08)',
    },
    puzzle: {
        label: 'مربحة قليلة الطلب',
        desc: 'ربحية عالية + مبيعات منخفضة — روّج لها، حسّن الوصف، ضعها في أماكن بارزة',
        color: '#3d6b47',
        bg: 'rgba(61,107,71,.08)',
    },
    plowhorse: {
        label: 'رائجة بهامش ضعيف',
        desc: 'مبيعات عالية + ربحية منخفضة — ارفع السعر تدريجياً أو قلّل التكلفة',
        color: '#b8872a',
        bg: 'rgba(184,135,42,.08)',
    },
    dog: {
        label: 'ضعيفة الأداء',
        desc: 'مبيعات وربحية منخفضة — فكّر بحذفها أو إعادة تصميمها',
        color: '#b91c1c',
        bg: 'rgba(185,28,28,.08)',
    },
});

// The Blade did an unguarded $classes[$it->class] lookup. A value the map does
// not know renders blank instead of throwing.
const FALLBACK = Object.freeze({ label: '', desc: '', color: '#57534e', bg: 'transparent' });
const cls = (k) => CLASSES[k] ?? FALLBACK;

/**
 * DOM order is load-bearing. The app is RTL, so grid cell 1 lands top-RIGHT:
 *
 *              High margin │ Low margin
 *    High pop │ ⭐ Star     │ 🐎 Plowhorse
 *    Low pop  │ 🧩 Puzzle   │ 🐕 Dog
 */
const QUADRANTS = Object.freeze(['star', 'plowhorse', 'puzzle', 'dog']);

const form = reactive({ from: props.from, to: props.to });

const visit = (from, to) => router.get(
    props.urls.self,
    { from, to },
    { preserveState: true, preserveScroll: true },
);

// The date inputs only take effect on submit — the old form had no @change hook.
const submit = () => visit(form.from, form.to);

// Server-supplied presets, so the range follows the app timezone, not the browser's.
const applyShortcut = (s) => {
    form.from = s.from;
    form.to = s.to;
    visit(s.from, s.to);
};

// The house helper, so the shipped currency.decimals is actually honoured
// instead of being accepted and then hardcoded back to 2.
const money = (v) => formatMoney(v, props.currency);
// number_format(x, n) — grouped, half-up, fixed decimals.
const num = (v, d) => new Intl.NumberFormat('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }).format(Number(v || 0));

// Colour ladders compare the RAW figure while the cell displays a rounded one,
// so 49.96% prints "50.0%" but is still painted with the sub-50 amber.
const profitColor = (p) => (Number(p) > 0 ? 'var(--primary)' : '#b91c1c');
const marginColor = (m) => (Number(m) >= 50 ? 'var(--primary)' : (Number(m) >= 30 ? '#8a6920' : '#b91c1c'));

// The print rules below have to reach outside this component (the sidebar,
// the header), so they cannot be `scoped`. An unscoped block in an SPA is
// injected once and never removed, which would rewrite the printout of every
// page visited afterwards — so gate it on a class this page owns only while
// it is mounted.
onMounted(() => document.body.classList.add('printing-menu-engineering'));
onBeforeUnmount(() => document.body.classList.remove('printing-menu-engineering'));
</script>

<template>
    <Head title="هندسة المنيو" />

    <PageHeader title="هندسة المنيو" icon="bi-bezier2"
                subtitle="تصنيف الأصناف حسب الربحية × الشعبية لاتخاذ قرارات التسعير"
                :crumbs="[{ label: 'التقارير', url: urls.reportsIndex }]" />

    <div class="data-panel-filters mb-3 me-filters">
        <form class="row g-2 align-items-end" @submit.prevent="submit">
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">من تاريخ</label>
                <input v-model="form.from" type="date" name="from" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
                <input v-model="form.to" type="date" name="to" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted fw-bold">اختصارات</label>
                <div class="btn-group w-100">
                    <button type="button" class="btn btn-light btn-sm me-shortcut" @click="applyShortcut(shortcuts.d30)">30 يوم</button>
                    <button type="button" class="btn btn-light btn-sm me-shortcut" @click="applyShortcut(shortcuts.month)">الشهر</button>
                    <button type="button" class="btn btn-light btn-sm me-shortcut" @click="applyShortcut(shortcuts.d7)">7 أيام</button>
                </div>
            </div>
            <div class="col-12 text-center mt-2">
                <button type="submit" class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
            </div>
        </form>
    </div>

    <EmptyState v-if="rows.length === 0"
                icon="bi-bezier2"
                title="ما في بيانات كافية"
                message="لا يوجد مبيعات في الفترة المحددة لتحليل المنيو. جرّب تمديد الفترة أو تأكد من وجود طلبات معتمدة." />

    <template v-else>
        <StatRail :stats="[
            { label: 'نجوم', value: buckets.star, icon: 'bi-star-fill', color: 'success' },
            { label: 'رائجة بهامش ضعيف', value: buckets.plowhorse, icon: 'bi-speedometer2', color: 'accent' },
            { label: 'مربحة قليلة الطلب', value: buckets.puzzle, icon: 'bi-puzzle-fill', color: 'primary' },
            { label: 'ضعيفة الأداء', value: buckets.dog, icon: 'bi-x-octagon-fill', color: 'danger' },
        ]" />

        <!-- Matrix visualization — plain CSS grid, no chart library. -->
        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <DataPanel title="مصفوفة التصنيف" icon="bi-grid-3x3-gap-fill">
                    <div class="p-3">
                        <div class="me-matrix">
                            <div class="me-axis-y">الشعبية ↑</div>
                            <div class="me-axis-x">الربحية →</div>

                            <div v-for="k in QUADRANTS" :key="k" class="me-quadrant"
                                 :style="{ background: cls(k).bg, borderColor: cls(k).color }">
                                <div class="me-quadrant-head" :style="{ color: cls(k).color }">
                                    {{ cls(k).label }}
                                    <span class="badge" :style="{ background: cls(k).color, color: 'white' }">{{ buckets[k] }}</span>
                                </div>
                                <div class="me-quadrant-desc">{{ cls(k).desc }}</div>
                                <div class="me-quadrant-items">
                                    <div v-for="(name, i) in topByClass[k]" :key="i" class="me-item-chip"
                                         :style="{ background: cls(k).color, color: 'white' }">
                                        {{ name }}
                                    </div>
                                    <small v-if="buckets[k] > 4" class="text-muted">+ {{ buckets[k] - 4 }} أخرى</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </DataPanel>
            </div>

            <!-- Thresholds + recommendations -->
            <div class="col-xl-5">
                <DataPanel title="العتبات المستخدمة" icon="bi-rulers">
                    <div v-if="thresholds" class="p-3">
                        <div class="small text-muted mb-3">
                            <i class="bi bi-info-circle"></i>
                            نستخدم الوسيط (median) للتقسيم، وهو أكثر متانة من المتوسط الحسابي.
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="fw-bold">وسيط الكمية المباعة</span>
                            <span class="fw-bold" style="color:var(--primary);">{{ num(thresholds.medianQty, 0) }} قطعة</span>
                        </div>
                        <div class="d-flex justify-content-between py-2">
                            <span class="fw-bold">وسيط هامش الربح</span>
                            <span class="fw-bold" style="color:var(--primary);">{{ num(thresholds.medianMargin, 1) }}%</span>
                        </div>
                        <div class="mt-3 p-3 rounded me-explain">
                            <strong style="color: var(--accent-dark);">💡 تفسير</strong>
                            <ul class="mb-0 mt-2 small" style="padding-inline-start: 1.25rem;">
                                <li>الأصناف فوق الوسيطين = نجوم</li>
                                <li>الأصناف تحت الوسيطين = ضعيفة الأداء</li>
                                <li>بين الاثنين = أَحد طرفي المصفوفة</li>
                            </ul>
                        </div>
                    </div>
                </DataPanel>
            </div>
        </div>

        <!-- Full classified table — profit DESC, server-ordered, unpaginated. -->
        <DataPanel title="كل الأصناف مصنّفة" :count="rows.length" icon="bi-table">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>التصنيف</th>
                            <th>الصنف</th>
                            <th>كمية مباعة</th>
                            <th>الإيرادات</th>
                            <th>التكلفة</th>
                            <th>الربح</th>
                            <th>هامش %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>
                                <span class="badge" :style="{ background: cls(row.klass).color, color: 'white' }">
                                    {{ cls(row.klass).label }}
                                </span>
                            </td>
                            <td class="fw-bold">{{ row.name }}</td>
                            <td>{{ num(row.qtySold, 0) }}</td>
                            <td class="fw-bold" style="color: var(--primary);">{{ money(row.revenue) }}</td>
                            <td class="text-muted">{{ money(row.cogs) }}</td>
                            <td class="fw-bold" :style="{ color: profitColor(row.profit) }">
                                {{ money(row.profit) }}
                            </td>
                            <td>
                                <span class="fw-bold" :style="{ color: marginColor(row.marginPct) }">
                                    {{ num(row.marginPct, 1) }}%
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </DataPanel>
    </template>
</template>

<style scoped>
.me-filters {
    background: white;
    border-radius: 14px;
    padding: 1rem;
    border: 1px solid rgba(var(--primary-rgb), .1);
}
/* Restaurant tablets: the whole filter row has to be finger-sized, not
   just the shortcut strip — the date inputs and submit are tapped just as often. */
.me-shortcut { min-height: 44px; }
.me-filters .form-control,
.me-filters .form-select,
.me-filters .btn { min-height: 44px; }

.me-matrix {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: 1fr 1fr;
    gap: .75rem;
    position: relative;
    padding: 0 1.5rem 1.5rem 1.5rem;
    min-height: 480px;
}
.me-axis-y {
    position: absolute;
    inset-inline-start: 0;
    top: 50%;
    transform: translateY(-50%) rotate(-90deg);
    transform-origin: left;
    font-size: .75rem;
    font-weight: 900;
    color: var(--primary);
    letter-spacing: .06em;
}
.me-axis-x {
    position: absolute;
    bottom: 0;
    inset-inline-end: 0;
    font-size: .75rem;
    font-weight: 900;
    color: var(--primary);
    letter-spacing: .06em;
}
.me-quadrant {
    border: 2px solid;
    border-radius: 16px;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    transition: transform .15s ease;
}
.me-quadrant:hover { transform: scale(1.01); }
.me-quadrant-head {
    font-size: 1.05rem;
    font-weight: 900;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.me-quadrant-desc {
    font-size: .8rem;
    color: #57534e;
    line-height: 1.4;
}
.me-quadrant-items {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: auto;
}
.me-item-chip {
    font-size: .72rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 99px;
}
.me-explain {
    background: rgba(var(--accent-rgb), .05);
    border: 1px dashed rgba(var(--accent-rgb), .3);
}
</style>

<!--
  Unscoped on purpose: .app-sidebar / .app-header / .app-content live in
  AdminLayout, so a scoped block's data-v attribute would match nothing and the
  print sheet would silently keep the chrome. There is no print button on this
  page — this only serves a manual browser Ctrl+P.
-->
<style>
@media print {
    body.printing-menu-engineering .app-sidebar,
    body.printing-menu-engineering .app-header,
    body.printing-menu-engineering form,
    body.printing-menu-engineering .breadcrumb-list,
    body.printing-menu-engineering .btn { display: none !important; }
    body.printing-menu-engineering .app-content { margin: 0 !important; }
}
</style>
