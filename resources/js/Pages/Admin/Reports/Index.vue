<script setup>
/**
 * لوحة التقارير — the reports hub.
 *
 * Every figure, tile href, alert body and bar height arrives precomputed
 * from ReportController@index; this file only lays them out. Nothing here
 * does arithmetic on a money value — the server is the single source of
 * truth for the numbers, and the tiles carry server-built route() URLs.
 *
 * One deliberate faithfulness note:
 *  - `stat-rail--info` and `report-tile-*` have no CSS in the design system.
 *    The Blade passed them anyway and the tiles all render identically today;
 *    reproduced as-is rather than silently restyling the screen.
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    stats: { type: Object, required: true },
    trend: { type: Array, default: () => [] },
    maxTrend: { type: Number, default: 1 },
    alerts: { type: Array, default: () => [] },
    tiles: { type: Array, default: () => [] },
    topItems: { type: Array, default: () => [] },
    currency: { type: Object, required: true },
    shortcuts: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const form = reactive({ from: props.from, to: props.to });

const visit = () => router.get(props.urls.self, { from: form.from, to: form.to }, {
    preserveState: true,
    preserveScroll: true,
});

// The three shortcut chips ship from the server so they use the app
// timezone, not the browser's.
const jump = (range) => {
    form.from = range.from;
    form.to = range.to;
    visit();
};

const money = (v) => formatMoney(v, props.currency);
// number_format($v, 1) — thousands separator included, one decimal.
const pct1 = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(v) || 0);
// number_format($v, 0) — the hub's top-items table prints quantity with NO
// decimals (the items report prints the same column with two).
const qty0 = (v) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(v) || 0);

const marginTone = (v) => (v >= 45 ? 'success' : (v >= 30 ? 'accent' : 'danger'));
</script>

<template>
    <Head title="لوحة التقارير" />

    <PageHeader title="لوحة التقارير" icon="bi-speedometer2"
                subtitle="مركز قراءة سريع يحوّل أرقام المطعم إلى قرارات تشغيلية." />

    <div class="data-panel-filters mb-3"
         style="background: white; border-radius: 14px; padding: 1rem; border: 1px solid rgba(var(--primary-rgb), .1);">
        <form class="row g-2 align-items-end" @submit.prevent="visit">
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
                    <button type="button" class="btn btn-light btn-sm" @click="jump(shortcuts.today)">اليوم</button>
                    <button type="button" class="btn btn-light btn-sm" @click="jump(shortcuts.week)">7 أيام</button>
                    <button type="button" class="btn btn-light btn-sm" @click="jump(shortcuts.month)">الشهر</button>
                </div>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5">
                    <i class="bi bi-search"></i> استعلام
                </button>
            </div>
        </form>
    </div>

    <StatRail :stats="[
        { label: 'الإيراد المحصل', value: money(stats.revenue), icon: 'bi-cash-stack', color: 'primary' },
        { label: 'الربح الإجمالي', value: money(stats.grossProfit), icon: 'bi-graph-up-arrow', color: stats.grossProfit >= 0 ? 'success' : 'danger' },
        { label: 'هامش الربح', value: pct1(stats.marginPct) + '%', icon: 'bi-percent', color: marginTone(stats.marginPct) },
        { label: 'متوسط الفاتورة', value: money(stats.averageTicket), icon: 'bi-receipt', color: 'accent' },
        { label: 'طلبات الفترة', value: stats.ordersCount, icon: 'bi-bag-check', color: 'info' },
        { label: 'الهدر', value: money(stats.wasteCost), icon: 'bi-trash3', color: stats.wasteCost > 0 ? 'warning' : 'muted' },
    ]" />

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <DataPanel title="نبض الإيراد اليومي" icon="bi-activity">
                <div class="reports-trend">
                    <div v-for="day in trend" :key="day.day" class="reports-trend-day"
                         :title="`${day.day} - ${money(day.revenue)}`">
                        <div class="reports-trend-bar-wrap">
                            <div class="reports-trend-bar" :style="{ height: day.heightPct + '%' }"></div>
                        </div>
                        <span>{{ day.label }}</span>
                    </div>
                </div>
                <div class="d-flex justify-content-between flex-wrap gap-2 mt-3 small text-muted">
                    <span>متوسط اليوم: <strong style="color: var(--primary);">{{ money(stats.dailyAverage) }}</strong></span>
                    <span>عدد الفواتير: <strong style="color: var(--primary);">{{ stats.invoiceCount }}</strong></span>
                </div>
            </DataPanel>
        </div>

        <div class="col-xl-4">
            <DataPanel title="تنبيهات تنفيذية" icon="bi-lightning-charge-fill">
                <div class="reports-alerts">
                    <component :is="alert.link ? 'a' : 'div'"
                               v-for="(alert, i) in alerts" :key="i"
                               class="reports-alert" :class="`reports-alert-${alert.tone}`"
                               :href="alert.link || null">
                        <i class="bi" :class="alert.icon"></i>
                        <span>
                            <strong>{{ alert.title }}</strong>
                            <small>{{ alert.body }}</small>
                        </span>
                    </component>
                </div>
            </DataPanel>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-7">
            <DataPanel title="خارطة التقارير" icon="bi-compass-fill">
                <div class="report-tile-grid">
                    <!-- Plain <a>, never Inertia <Link>: several targets are still Blade pages. -->
                    <a v-for="(tile, i) in tiles" :key="i"
                       class="report-tile" :class="`report-tile-${tile.tone}`" :href="tile.href">
                        <span class="report-tile-icon"><i class="bi" :class="tile.icon"></i></span>
                        <span class="report-tile-body">
                            <strong>{{ tile.title }}</strong>
                            <small>{{ tile.desc }}</small>
                        </span>
                        <i class="bi bi-arrow-left report-tile-arrow"></i>
                    </a>
                </div>
            </DataPanel>
        </div>

        <div class="col-xl-5">
            <DataPanel title="أصناف تصنع الربح" icon="bi-trophy-fill" :count="topItems.length">
                <EmptyState v-if="topItems.length === 0"
                            icon="bi-trophy"
                            title="لا توجد مبيعات أصناف"
                            message="اختر فترة فيها طلبات مكتملة لتظهر مساهمة الأصناف." />
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>الصنف</th>
                                <th class="text-end">الكمية</th>
                                <th class="text-end">الإيراد</th>
                                <th class="text-end">الربح</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, i) in topItems" :key="i">
                                <td class="fw-bold">{{ item.name }}</td>
                                <td class="text-end">{{ qty0(item.qty) }}</td>
                                <td class="text-end">{{ money(item.revenue) }}</td>
                                <td class="text-end fw-bold" style="color: var(--primary);">{{ money(item.profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </DataPanel>
        </div>
    </div>

</template>

<style scoped>
/* Tablet touch targets — the only deliberate deviation from the Blade,
   which used stock Bootstrap heights (~31px on .btn-sm). */
.data-panel-filters .form-control,
.data-panel-filters .btn { min-height: 44px; }

.reports-trend {
    min-height: 260px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(34px, 1fr));
    gap: 8px;
    align-items: end;
}
.reports-trend-day {
    min-width: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}
.reports-trend-bar-wrap {
    width: 100%;
    height: 220px;
    border-radius: 8px;
    background: linear-gradient(180deg, rgba(var(--primary-rgb), .05), rgba(var(--accent-rgb), .08));
    display: flex;
    align-items: end;
    overflow: hidden;
}
.reports-trend-bar {
    width: 100%;
    min-height: 6px;
    background: linear-gradient(180deg, var(--primary), var(--accent));
    border-radius: 8px 8px 0 0;
}
.reports-trend-day span {
    font-size: .72rem;
    font-weight: 700;
    color: #78716c;
}
.reports-alerts,
.report-tile-grid {
    display: grid;
    gap: .75rem;
}
.reports-alert,
.report-tile {
    text-decoration: none;
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 8px;
    padding: .85rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    background: #fff;
    transition: transform .15s ease, border-color .15s ease;
}
.reports-alert:hover,
.report-tile:hover {
    transform: translateY(-1px);
    border-color: rgba(var(--accent-rgb), .45);
}
.reports-alert > i,
.report-tile-icon {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 38px;
    background: rgba(var(--primary-rgb), .08);
    color: var(--primary);
    font-size: 1.05rem;
}
.reports-alert span,
.report-tile-body {
    min-width: 0;
    display: grid;
    gap: 2px;
}
.reports-alert strong,
.report-tile strong {
    color: var(--primary);
}
.reports-alert small,
.report-tile small {
    color: #6b7280;
    line-height: 1.45;
}
.reports-alert-danger > i { color: #b91c1c; background: rgba(185, 28, 28, .08); }
.reports-alert-warning > i { color: #8a6920; background: rgba(var(--accent-rgb), .12); }
.reports-alert-info > i { color: #0369a1; background: rgba(3, 105, 161, .08); }
.reports-alert-success > i { color: #15803d; background: rgba(21, 128, 61, .08); }
.report-tile-grid {
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
}
.report-tile-arrow {
    margin-inline-start: auto;
    color: #94a3b8;
}
</style>
