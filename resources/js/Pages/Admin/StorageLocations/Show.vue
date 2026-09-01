<script setup>
/**
 * تفاصيل موقع تخزين — what is physically sitting in one location.
 *
 * The card at the top is deliberate documentation, not decoration: this
 * system has no "add a quantity by hand" button, because every stock
 * change must trace back to a document. The four legitimate inflow paths
 * are spelled out and linked so nobody goes hunting for a button that
 * will never exist.
 *
 * Every figure in the table (value, low-stock verdict, the trimmed
 * quantity labels) is computed server-side — IngredientStock::isLowStock
 * falls back from the per-location threshold to the ingredient's own, and
 * that rule must not get re-implemented here.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    location: { type: Object, required: true },
    stocks: { type: Array, required: true },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const money = (v) => formatMoney(v, props.currency);

const crumbs = computed(() => [{ label: 'مواقع التخزين', url: props.urls.index }]);

const paths = computed(() => [
    {
        url: props.urls.purchaseOrder,
        icon: 'bi-truck',
        title: '١. أمر شراء',
        body: 'عند استلام بضاعة من مورّد، حدِّد هذا الموقع كوجهة في بنود أمر الشراء — تتسجَّل الكمية والتكلفة تلقائياً.',
    },
    {
        url: props.urls.supplierInvoice,
        icon: 'bi-file-earmark-text',
        title: '٢. فاتورة مورّد مباشرة',
        body: 'لو الشراء بدون أمر شراء مسبق، ادخل الفاتورة وحدِّد الموقع — يتم تحديث المخزون فور حفظها.',
    },
    {
        url: props.urls.transfer,
        icon: 'bi-arrow-left-right',
        title: '٣. نقل بين المواقع',
        body: 'لنقل كمية من موقع آخر داخل نفس الفرع إلى هنا (مثلاً من مستودع جاف إلى ثلاجة المطبخ).',
    },
    {
        url: props.urls.branchTransfers,
        icon: 'bi-building',
        title: '٤. تحويل بين الفروع',
        body: 'لاستلام بضاعة قادمة من فرع آخر — يتم تحديد الموقع في بنود التحويل عند الاستلام.',
    },
]);
</script>

<template>
    <Head :title="location.name" />

    <PageHeader :title="location.name" icon="bi-boxes" :crumbs="crumbs" />

    <div class="card custom-card mb-3 sl-inflow">
        <div class="card-body p-3">
            <div class="d-flex align-items-start gap-3 mb-3">
                <span class="sl-inflow-icon"><i class="bi bi-info-circle-fill"></i></span>
                <div class="flex-grow-1">
                    <div class="fw-bold mb-1">كيف تدخل المكوّنات إلى هذا الموقع؟</div>
                    <div class="small text-muted sl-lead">
                        لا يوجد زر «إضافة كمية مباشرة» قصداً — كل حركة مخزون لازم تكون من <strong>مصدر موثَّق</strong>
                        ليبقى تتبّع التكلفة والكميات سليم. عندك أربع مسارات:
                    </div>
                </div>
            </div>

            <div class="sl-paths">
                <a v-for="p in paths" :key="p.title" :href="p.url" class="sl-path">
                    <div class="sl-path-head">
                        <i class="bi" :class="p.icon"></i>
                        <strong>{{ p.title }}</strong>
                    </div>
                    <div class="sl-path-body">{{ p.body }}</div>
                </a>
            </div>

            <div class="small text-muted mt-3 sl-lead">
                <i class="bi bi-lightbulb text-warning"></i>
                <strong>للتعديلات الاستثنائية فقط</strong> (تالف، تصحيح جرد، عيّنة…) استخدم
                <a :href="urls.stockCount">جرد جديد</a> أو
                <a :href="urls.waste">تسجيل هدر</a> — كل واحدة بتطلب سبب يبقى في سجل الحركات.
            </div>
        </div>
    </div>

    <DataPanel :title="`المكونات في ${location.name}`" :count="stocks.length" icon="bi-boxes">
        <div v-if="stocks.length" class="table-responsive sl-scroll">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>المكوّن</th>
                        <th>الكمية</th>
                        <th>حد الطلب (موقع)</th>
                        <th>التكلفة/وحدة</th>
                        <th>القيمة</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in stocks" :key="s.id" :class="{ 'table-warning': s.isLow }">
                        <td class="fw-bold">{{ s.name }}</td>
                        <td>{{ s.quantityLabel }} {{ s.unitCode }}</td>
                        <td>{{ s.thresholdLabel }}</td>
                        <td>{{ s.costLabel }}</td>
                        <td class="fw-bold sl-value">{{ money(s.value) }}</td>
                        <td>
                            <span v-if="s.isLow" class="badge bg-danger">
                                <i class="bi bi-exclamation-triangle"></i> منخفض
                            </span>
                            <span v-else class="badge bg-success">
                                <i class="bi bi-check-circle"></i> جيد
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmptyState v-else icon="bi-boxes" compact
                    title="هذا الموقع فاضي حالياً"
                    message="استخدم أحد المسارات بالأعلى لإدخال مكوّنات إلى هنا — أمر شراء، فاتورة مورّد، نقل بين المواقع، أو تحويل بين الفروع." />
    </DataPanel>
</template>

<style scoped>
.sl-inflow { border-color: rgba(15, 71, 49, .12); }
.sl-inflow-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    border-radius: 12px;
    background: rgba(15, 71, 49, .08);
    color: var(--primary);
    font-size: 1.2rem;
}
.sl-lead { line-height: 1.7; }

.sl-paths {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(230px, 100%), 1fr));
    gap: .5rem;
}
.sl-path {
    display: block;
    padding: .75rem;
    border-radius: .5rem;
    text-decoration: none;
    background: rgba(15, 71, 49, .04);
    border: 1px solid rgba(15, 71, 49, .12);
    color: inherit;
    min-height: 44px;
}
.sl-path:hover { background: rgba(15, 71, 49, .07); }
.sl-path-head {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .25rem;
    font-size: .82rem;
}
.sl-path-head i { color: var(--primary); }
.sl-path-body {
    font-size: .82rem;
    color: #6c757d;
    line-height: 1.5;
}

.sl-scroll { overflow-x: auto; }
.sl-value { color: var(--primary); }
</style>
