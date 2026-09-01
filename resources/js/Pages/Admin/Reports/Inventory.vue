<script setup>
/**
 * استهلاك المخزن — per ingredient over a date range: what came in, what
 * was consumed, what was wasted, and what the consumption and waste cost.
 *
 * The controller flattens the old group-of-groups and pre-renders every
 * quantity string (QuantityFormatter::smart has no JS twin). The five
 * stat-rail totals used to be summed inside the template; they are props
 * now, so the headline numbers can never drift from the table.
 *
 * ⚠ The three quantity totals add base units of DIFFERENT ingredients
 * together (grams + millilitres + pieces in one dimensionless scalar).
 * That is the shipped behaviour, reproduced deliberately — see the report.
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    from: { type: String, default: '' },
    to: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    rowCount: { type: Number, default: 0 },
    totals: { type: Object, required: true },
    currency: { type: Object, required: true },
    shortcuts: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const money = (v) => formatMoney(v, props.currency);
// number_format($x, 2) — comma thousands, exactly two decimals.
const qty2 = (v) => new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
}).format(Number(v) || 0);

// snake_case on purpose — these keys ARE the query string.
const form = reactive({ from: props.from ?? '', to: props.to ?? '' });

const visit = (params) => {
    router.get(props.urls.self, params, { preserveState: true, preserveScroll: true });
};

const submit = () => visit({ from: form.from || undefined, to: form.to || undefined });

// Server-clock dates, not the browser's.
const applyShortcut = (key) => {
    const s = props.shortcuts[key];
    if (! s) return;
    form.from = s.from;
    form.to = s.to;
    visit({ from: s.from, to: s.to });
};
</script>

<template>
    <Head title="استهلاك المخزن" />

    <PageHeader title="استهلاك المخزن" icon="bi-boxes"
                subtitle="حركة المكونات المستهلكة في فترة محددة"
                :crumbs="[{ label: 'التقارير', url: urls.reportsIndex }]" />

    <StatRail :stats="[
        { label: 'إضافات', value: qty2(totals.in), icon: 'bi-plus-circle', color: 'success' },
        { label: 'استهلاك', value: qty2(totals.out), icon: 'bi-arrow-up-right-circle', color: 'accent' },
        { label: 'هدر', value: qty2(totals.waste), icon: 'bi-trash3', color: totals.waste > 0 ? 'danger' : 'muted' },
        { label: 'تكلفة الاستهلاك', value: money(totals.outCost), icon: 'bi-cash-stack', color: 'primary' },
        { label: 'تكلفة الهدر', value: money(totals.wasteCost), icon: 'bi-exclamation-triangle', color: totals.wasteCost > 0 ? 'warning' : 'muted' },
    ]" />

    <DataPanel title="حركة المكونات" icon="bi-boxes" :count="rowCount">
        <template #filters>
            <form class="row g-2 align-items-end" @submit.prevent="submit">
                <div class="col-md-3">
                    <label class="form-label small text-muted fw-bold">من تاريخ</label>
                    <input v-model="form.from" type="date" class="form-control inv-input">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
                    <input v-model="form.to" type="date" class="form-control inv-input">
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted fw-bold">اختصارات</label>
                    <div class="btn-group w-100">
                        <button type="button" class="btn btn-light btn-sm inv-btn" @click="applyShortcut('today')">اليوم</button>
                        <button type="button" class="btn btn-light btn-sm inv-btn" @click="applyShortcut('week')">7 أيام</button>
                        <button type="button" class="btn btn-light btn-sm inv-btn" @click="applyShortcut('month')">الشهر</button>
                    </div>
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary px-5 inv-btn"><i class="bi bi-search"></i> استعلام</button>
                </div>
            </form>
        </template>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>المكون</th>
                        <th>إضافات</th>
                        <th>استهلاك</th>
                        <th>هدر</th>
                        <th>تكلفة الاستهلاك</th>
                        <th>تكلفة الهدر</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.ingredientId">
                        <td class="fw-bold">{{ r.name }}</td>
                        <td class="text-success" :title="r.inTitle">{{ r.inDisplay }}</td>
                        <td class="text-warning" :title="r.outTitle">{{ r.outDisplay }}</td>
                        <td class="text-danger" :title="r.wasteTitle">{{ r.wasteDisplay }}</td>
                        <td>{{ money(r.outCost) }}</td>
                        <td :class="r.wasteCost > 0 ? 'text-danger fw-bold' : 'text-muted'">
                            {{ money(r.wasteCost) }}
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="6">
                            <EmptyState icon="bi-boxes"
                                        title="لا توجد حركة مخزون"
                                        message="لا توجد إضافات أو استهلاك ضمن الفترة المختارة." />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </DataPanel>
</template>

<style scoped>
/* Restaurant tablets: every tap target stays at 44px. */
.inv-btn { min-height: 44px; }
.inv-input { min-height: 44px; }
</style>
