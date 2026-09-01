<script setup>
/**
 * أكثر الأصناف مبيعاً — what was sold in the period, ranked by quantity.
 *
 * Grouped by `order_items.name_snapshot`, so a menu item renamed mid-period
 * legitimately appears as two rows. `rank` is absolute across pages and
 * `avgUnit` is a weighted average price — both computed server-side.
 *
 * The subtitle promises "حسب الكمية أو الإيراد" but there is no sort control
 * on this screen and never was; the order is always qty DESC. Copy kept
 * verbatim rather than inventing a control the server does not support.
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    rows: { type: Object, required: true },
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
// number_format($v, 2) — this screen prints quantity with TWO decimals,
// unlike the hub's top-items table which prints the same column with none.
const qty2 = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v) || 0);
</script>

<template>
    <Head title="أكثر الأصناف مبيعاً" />

    <PageHeader title="أكثر الأصناف مبيعاً" icon="bi-trophy"
                subtitle="ترتيب الأصناف حسب الكمية أو الإيراد"
                :crumbs="[{ label: 'التقارير', url: urls.reportsIndex }]" />

    <DataPanel title="الأصناف الأكثر حركة" icon="bi-trophy-fill" :count="rows.total">
        <template #filters>
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
                    <button class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
                </div>
            </form>
        </template>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>#</th>
                        <th>الصنف</th>
                        <th>الكمية</th>
                        <th>الإجمالي</th>
                        <th>متوسط القطعة</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows.data" :key="r.rank">
                        <td>{{ r.rank }}</td>
                        <td class="fw-bold">{{ r.name }}</td>
                        <td>{{ qty2(r.qty) }}</td>
                        <td class="fw-bold" style="color: var(--primary);">{{ money(r.total) }}</td>
                        <td>{{ money(r.avgUnit) }}</td>
                    </tr>
                    <tr v-if="rows.data.length === 0">
                        <td colspan="5">
                            <EmptyState icon="bi-trophy"
                                        title="ما في بيانات"
                                        message="لم تُباع أي أصناف ضمن الفترة المختارة." />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <Pagination :links="rows.links" />
        </template>
    </DataPanel>
</template>

<style scoped>
/* Tablet touch targets — the only deliberate deviation from the Blade. */
.data-panel-filters .form-control,
.data-panel-filters .btn { min-height: 44px; }
</style>
