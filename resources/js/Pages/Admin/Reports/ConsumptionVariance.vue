<script setup>
/**
 * مقارنة الاستهلاك النظري والفعلي — the theft/waste detector. It compares
 * what the recipes SHOULD have consumed against what was actually deducted
 * from stock, biggest money gap first.
 *
 * Every threshold that decides a colour lives in PHP: the 0.5-currency
 * dead-band on the row highlight and the summary card, and the 5% / 15%
 * bands on the percentage badge. The page just paints the class it is
 * handed. Rows arrive sorted by abs(varianceCost) DESC — never re-sort.
 *
 * `varianceDisplay` is pre-rendered with the sign OUTSIDE an abs() ladder,
 * so a negative variance shows no minus, only the amber colour. That is
 * the shipped behaviour and an existing test asserts on the string.
 *
 * Known copy mismatch, reproduced not fixed: the legend calls a negative
 * variance green while the table paints it amber.
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    from: { type: String, default: '' },
    to: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    rowCount: { type: Number, default: 0 },
    summary: { type: Object, required: true },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const money = (v) => formatMoney(v, props.currency);

// snake_case on purpose — these keys ARE the query string.
const form = reactive({ from: props.from ?? '', to: props.to ?? '' });

const submit = () => {
    router.get(props.urls.self, {
        from: form.from || undefined,
        to: form.to || undefined,
    }, { preserveState: true, preserveScroll: true });
};
</script>

<template>
    <Head title="مقارنة الاستهلاك النظري والفعلي" />

    <PageHeader title="مقارنة الاستهلاك النظري والفعلي" icon="bi-bar-chart-line"
                subtitle="يكشف الفروقات بين ما تستهلكه الوصفات نظرياً وما تم خصمه فعلياً من المخزن — أداة كشف الهدر أو السرقة"
                :crumbs="[{ label: 'التقارير', url: urls.reportsIndex }]" />

    <div class="alert alert-info d-flex align-items-start gap-3 mb-3">
        <i class="bi bi-info-circle-fill fs-4 mt-1"></i>
        <div>
            <strong class="d-block mb-1">كيف نقرأ هذا التقرير؟</strong>
            <div class="small">
                <strong>النظري</strong> = الكمية اللي لازم تنخصم حسب وصفات الأصناف المباعة.<br>
                <strong>الفعلي</strong> = الكمية اللي انخصمت فعلاً من المخزن (حركات الطلبات).<br>
                <strong>الفرق</strong> = الفعلي − النظري:
                <span class="text-danger fw-bold">موجب</span> = استهلاك زائد (هدر/سرقة/تقدير وصفة قاصر) ·
                <span class="text-success fw-bold">سالب</span> = استهلاك أقل (وصفة مبالغ فيها أو نقص بالخدمة) ·
                <strong>صفر</strong> = صحي.
            </div>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3" @submit.prevent="submit">
        <div class="col-md-3">
            <label class="form-label small text-muted fw-bold">من تاريخ</label>
            <input v-model="form.from" type="date" class="form-control cv-input">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
            <input v-model="form.to" type="date" class="form-control cv-input">
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary w-100 cv-btn"><i class="bi bi-search"></i> استعلام</button>
        </div>
    </form>

    <!-- Four hand-rolled KPI cards: the third changes its BORDER colour as
         well as its text, which StatRail has no concept of. -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">تكلفة الاستهلاك النظري</small>
                    <h4 class="text-primary mb-0">{{ money(summary.totalTheoreticalCost) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">تكلفة الاستهلاك الفعلي</small>
                    <h4 class="text-info mb-0">{{ money(summary.totalActualCost) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center" :class="`border-${summary.varianceTone}`">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">فرق التكلفة</small>
                    <h4 class="mb-0" :class="`text-${summary.varianceTone}`">
                        {{ summary.totalVarianceCostText }}
                    </h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <small class="text-muted d-block mb-1">قيمة الهدر الموثّق</small>
                    <h4 class="text-warning mb-0">{{ money(summary.totalWasteCost) }}</h4>
                </div>
            </div>
        </div>
    </div>

    <DataPanel title="التفاصيل لكل مكوّن" icon="bi-list-columns" :count="rowCount">
        <template v-if="rows.length">
            <div class="alert alert-light border mb-2 small">
                <i class="bi bi-info-circle"></i>
                في الفترة المختارة: <strong>{{ summary.itemsInRange }}</strong> صنف مباع
                ضمن <strong>{{ summary.ordersInRange }}</strong> طلب.
                القائمة مرتّبة حسب أكبر فرق تكلفة.
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>المكوّن</th>
                            <th class="text-end">النظري</th>
                            <th class="text-end">الفعلي</th>
                            <th class="text-end">الهدر</th>
                            <th class="text-end">الفرق</th>
                            <th class="text-end">% الفرق</th>
                            <th class="text-end">تكلفة الفرق</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.ingredientId" :class="r.rowTone">
                            <td>
                                <strong>{{ r.name }}</strong>
                                <span v-if="r.isComposite" class="badge bg-info ms-1" title="مكوّن مركّب — يُحلَّل تلقائياً">مركّب</span>
                                <small class="text-muted d-block">{{ r.sku }}</small>
                            </td>
                            <td class="text-end" :title="r.theoreticalTitle">{{ r.theoreticalDisplay }}</td>
                            <td class="text-end" :title="r.actualTitle">{{ r.actualDisplay }}</td>
                            <td class="text-end text-muted" :title="r.wasteTitle">{{ r.wasteDisplay }}</td>
                            <td class="text-end fw-bold" :class="r.varianceTone" :title="r.varianceTitle">
                                {{ r.varianceDisplay }}
                            </td>
                            <td class="text-end">
                                <span v-if="r.variancePct !== null" class="badge" :class="r.pctTone">
                                    {{ r.variancePctText }}
                                </span>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="text-end fw-bold" :class="r.costTone">
                                {{ r.varianceCostText }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <EmptyState v-else icon="bi-inbox" message="لا حركات استهلاك أو مبيعات في هذه الفترة." />
    </DataPanel>
</template>

<style scoped>
/* Restaurant tablets: every tap target stays at 44px. */
.cv-btn { min-height: 44px; }
.cv-input { min-height: 44px; }
</style>
