<script setup>
/**
 * المبيعات اليومية — one row per calendar day: what was invoiced vs what
 * was actually collected, with the gap made visible.
 *
 * `rows` is DESCENDING (the table) and `chart` is ASCENDING (the bars) —
 * two separate server arrays on purpose, because the old Blade re-sorted
 * the same collection at render time. Never re-sort either one here.
 *
 * Faithfulness note: the StatRail's collection-rate tile uses `accent` at
 * the 80% threshold while the per-row badge uses `warning` at the very
 * same threshold. That inconsistency is in the Blade; it is preserved.
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
    rows: { type: Array, default: () => [] },
    chart: { type: Array, default: () => [] },
    maxPaid: { type: Number, default: 1 },
    totals: { type: Object, required: true },
    collectionRate: { type: Number, default: 0 },
    collectionGap: { type: Number, default: 0 },
    averageDaily: { type: Number, default: 0 },
    averageInvoice: { type: Number, default: 0 },
    bestDay: { type: Object, default: null },
    quietDay: { type: Object, default: null },
    currency: { type: Object, required: true },
    shortcuts: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const form = reactive({ from: props.from, to: props.to });

const visit = () => router.get(props.urls.self, { from: form.from, to: form.to }, {
    preserveState: true,
    preserveScroll: true,
});

const jump = (range) => {
    form.from = range.from;
    form.to = range.to;
    visit();
};

const money = (v) => formatMoney(v, props.currency);
const pct1 = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(v) || 0);

const rateTone = (v) => (v >= 95 ? 'success' : (v >= 80 ? 'accent' : 'danger'));
</script>

<template>
    <Head title="تقرير المبيعات" />

    <PageHeader title="المبيعات اليومية" icon="bi-cash-coin"
                subtitle="قراءة يومية للتحصيل والفواتير والفجوة بين المفوتر والمقبوض"
                :crumbs="[{ label: 'التقارير', url: urls.reportsIndex }]" />

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
        { label: 'المقبوض', value: money(totals.paid), icon: 'bi-wallet2', color: 'success' },
        { label: 'المفوتر', value: money(totals.total), icon: 'bi-receipt', color: 'primary' },
        { label: 'نسبة التحصيل', value: pct1(collectionRate) + '%', icon: 'bi-check2-circle', color: rateTone(collectionRate) },
        { label: 'متوسط اليوم', value: money(averageDaily), icon: 'bi-calendar2-week', color: 'accent' },
        { label: 'متوسط الفاتورة', value: money(averageInvoice), icon: 'bi-calculator', color: 'info' },
        { label: 'غير محصل', value: money(collectionGap), icon: 'bi-exclamation-circle', color: collectionGap > 0 ? 'warning' : 'muted' },
    ]" />

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <DataPanel title="خط الأيام" icon="bi-bar-chart-line-fill">
                <EmptyState v-if="rows.length === 0"
                            icon="bi-cash-coin"
                            title="ما في مبيعات"
                            message="لا يوجد بيانات مبيعات ضمن الفترة المختارة." />
                <div v-else class="sales-pulse">
                    <div v-for="r in chart" :key="r.day" class="sales-pulse-day"
                         :title="`${r.day} - ${money(r.paid)}`">
                        <div class="sales-pulse-bar-wrap">
                            <div class="sales-pulse-bar" :style="{ height: r.heightPct + '%' }"></div>
                        </div>
                        <span>{{ r.dayLabel }}</span>
                    </div>
                </div>
            </DataPanel>
        </div>

        <div class="col-lg-4">
            <DataPanel title="قراءة سريعة" icon="bi-lightbulb-fill">
                <div class="sales-insights">
                    <div class="sales-insight">
                        <span>أفضل يوم</span>
                        <strong>{{ bestDay ? bestDay.day : '—' }}</strong>
                        <small>{{ bestDay ? money(bestDay.paid) : 'لا يوجد بيانات' }}</small>
                    </div>
                    <div class="sales-insight">
                        <span>أهدأ يوم</span>
                        <strong>{{ quietDay ? quietDay.day : '—' }}</strong>
                        <small>{{ quietDay ? money(quietDay.paid) : 'لا يوجد بيانات' }}</small>
                    </div>
                    <div class="sales-insight">
                        <span>ضريبة وخدمة</span>
                        <strong>{{ money(totals.taxPlusService) }}</strong>
                        <small>ضمن إجمالي الفترة</small>
                    </div>
                </div>
            </DataPanel>
        </div>
    </div>

    <DataPanel title="تفاصيل المبيعات اليومية" icon="bi-table" :count="rows.length">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>اليوم</th>
                        <th>فواتير</th>
                        <th>فرعي</th>
                        <th>ضريبة</th>
                        <th>خدمة</th>
                        <th>الإجمالي</th>
                        <th>المدفوع</th>
                        <th>تحصيل</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.day">
                        <td class="fw-bold">{{ r.day }}</td>
                        <td>{{ r.invoicesCount }}</td>
                        <td>{{ money(r.subtotal) }}</td>
                        <td>{{ money(r.tax) }}</td>
                        <td>{{ money(r.service) }}</td>
                        <td class="fw-bold">{{ money(r.total) }}</td>
                        <td class="text-success fw-bold">{{ money(r.paid) }}</td>
                        <td>
                            <span class="badge" :class="`bg-${r.rateTone}-transparent text-${r.rateTone}`">
                                {{ pct1(r.rate) }}%
                            </span>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="8">
                            <EmptyState icon="bi-cash-coin"
                                        title="ما في مبيعات"
                                        message="لا يوجد بيانات مبيعات ضمن الفترة المختارة." />
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length > 0" class="bg-light fw-bold">
                    <tr>
                        <td>المجموع</td>
                        <td>{{ totals.invoicesCount }}</td>
                        <td>{{ money(totals.subtotal) }}</td>
                        <td>{{ money(totals.tax) }}</td>
                        <td>{{ money(totals.service) }}</td>
                        <td>{{ money(totals.total) }}</td>
                        <td class="text-success">{{ money(totals.paid) }}</td>
                        <td>{{ pct1(collectionRate) }}%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </DataPanel>
</template>

<style scoped>
/* Tablet touch targets — the only deliberate deviation from the Blade. */
.data-panel-filters .form-control,
.data-panel-filters .btn { min-height: 44px; }

.sales-pulse {
    min-height: 260px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(34px, 1fr));
    gap: 8px;
    align-items: end;
}
.sales-pulse-day {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    min-width: 0;
}
.sales-pulse-bar-wrap {
    width: 100%;
    height: 225px;
    border-radius: 8px;
    background: rgba(var(--primary-rgb), .06);
    display: flex;
    align-items: end;
    overflow: hidden;
}
.sales-pulse-bar {
    width: 100%;
    min-height: 8px;
    background: linear-gradient(180deg, var(--primary), var(--accent));
    border-radius: 8px 8px 0 0;
}
.sales-pulse-day span {
    font-size: .72rem;
    font-weight: 700;
    color: #78716c;
}
.sales-insights {
    display: grid;
    gap: .75rem;
}
.sales-insight {
    border: 1px solid rgba(var(--primary-rgb), .1);
    border-radius: 8px;
    padding: .85rem;
    display: grid;
    gap: 2px;
    background: #fff;
}
.sales-insight span {
    color: #78716c;
    font-size: .8rem;
    font-weight: 800;
}
.sales-insight strong {
    color: var(--primary);
    font-size: 1.05rem;
}
.sales-insight small {
    color: #64748b;
}
</style>
