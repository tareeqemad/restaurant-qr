<script setup>
/**
 * قائمة الطلبات — the manager's daily list (Wave 3.ج). The live triage
 * work lives on the service board; this page is for looking things up and
 * doing the two bulk-ish actions (approve / unapprove) without leaving it.
 *
 * Auto-refresh every 20s, paused while the tab is hidden or the operator
 * is typing in a filter — same rule the retired Blade page had, minus the
 * full-page reload (an Inertia partial keeps scroll and focus).
 */
import { reactive, onBeforeUnmount, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { formPost } from '../../../Support/formPost';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    orders: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    showBranchColumn: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    date: props.filters.date ?? '',
});

const search = () => {
    router.get(props.urls.list, {
        search: form.search || undefined,
        status: form.status || undefined,
        date: form.date || undefined,
    }, { preserveState: true, preserveScroll: true });
};

const clear = () => {
    form.search = '';
    form.status = '';
    form.date = '';
    search();
};

const hasFilters = () => Boolean(props.filters.search || props.filters.status || props.filters.date);

const approve = (order) => formPost(order.urls.approve);

const unapprove = async (order) => {
    const yes = await ask({
        title: `فك اعتماد الطلب ${order.number}؟`,
        message: 'بيرجع لقائمة الانتظار وبينعاد المخزون المخصوم.',
        confirmLabel: 'فك الاعتماد',
        danger: true,
    });
    if (yes) formPost(order.urls.unapprove);
};

// ── Quiet auto-refresh ───────────────────────────────────────────────
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (document.hidden) return;
        const el = document.activeElement;
        if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
        router.reload({ only: ['orders', 'stats'], preserveScroll: true });
    }, 20000);
});
onBeforeUnmount(() => clearInterval(timer));

const STATUSES = [
    { value: '', label: 'كل الحالات' },
    { value: 'pending', label: 'بانتظار' },
    { value: 'approved', label: 'معتمد' },
    { value: 'preparing', label: 'قيد التحضير' },
    { value: 'ready', label: 'جاهز' },
    { value: 'delivered', label: 'تم التسليم' },
    { value: 'completed', label: 'مكتمل' },
    { value: 'cancelled', label: 'ملغى' },
];
</script>

<template>
    <Head title="الطلبات" />

    <PageHeader title="الطلبات" icon="bi-receipt" >
        <template #actions>
            <a :href="urls.board" class="btn btn-primary">
                <i class="bi bi-person-check-fill"></i> لوحة الخدمة
            </a>
        </template>
    </PageHeader>

    <details class="ops-summary"><summary><i class="bi bi-bar-chart"></i> ملخص الطلبات اليوم<i class="bi bi-chevron-down"></i></summary><StatRail :stats="[
        { label: 'بانتظار الاعتماد', value: stats.pending, icon: 'bi-hourglass-split', color: 'accent' },
        { label: 'طلبات اليوم', value: stats.todayCount, icon: 'bi-receipt', color: 'primary' },
        { label: 'نشطة الآن', value: stats.todayActive, icon: 'bi-activity', color: 'success' },
        { label: 'إيراد اليوم', value: stats.todayRevenue, icon: 'bi-cash-coin', color: 'accent' },
    ]" /></details>

    <DataPanel title="قائمة الطلبات" :count="orders.total" icon="bi-receipt">
        <template v-if="hasFilters()" #actions>
            <button type="button" class="btn btn-light" @click="clear">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </button>
        </template>

        <template #filters>
            <form class="row g-2" @submit.prevent="search">
                <div class="col-md-3">
                    <input v-model="form.search" class="form-control" placeholder="🔍 رقم الطلب">
                </div>
                <div class="col-md-3">
                    <select v-model="form.status" class="form-select">
                        <option v-for="s in STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input v-model="form.date" type="date" class="form-control">
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary px-5"><i class="bi bi-funnel"></i> استعلام</button>
                </div>
            </form>
        </template>

        <div class="order-register">
            <article v-for="o in orders.data" :key="o.id" class="order-register-row">
                <div class="order-register-id"><small>{{ o.tableLabel }}</small><strong>{{ o.number }}</strong><span v-if="showBranchColumn">{{ o.branchName ?? '—' }}</span></div>
                <div class="order-register-state"><span class="badge" :class="`bg-${o.statusColor}`">{{ o.statusLabel }}</span><small>{{ o.createdAgo }}</small></div>
                <div class="order-register-total"><strong>{{ o.total }}</strong><small>{{ o.itemCount }} صنف</small></div>
                <div class="order-register-actions"><button v-if="o.canApprove" type="button" class="btn btn-primary" @click="approve(o)"><i class="bi bi-check2"></i> اعتماد</button><button v-else-if="o.canUnapprove" type="button" class="btn btn-outline-warning" @click="unapprove(o)"><i class="bi bi-arrow-counterclockwise"></i> فك الاعتماد</button><a :href="o.urls.show" class="btn btn-light">تفاصيل <i class="bi bi-arrow-left"></i></a></div>
            </article>
            <EmptyState v-if="!orders.data.length" icon="bi-receipt" title="لا توجد طلبات" message="ستظهر الطلبات الواردة هنا." />
        </div>

        <template #footer>
            <Pagination :links="orders.links" />
        </template>
    </DataPanel>
</template>

<style scoped>
.ops-summary{margin-bottom:16px;border:1px solid var(--line);border-radius:8px;background:var(--surface)}.ops-summary>summary{display:flex;align-items:center;gap:10px;min-height:48px;padding:12px 16px;cursor:pointer;list-style:none;font-size:14px;font-weight:700}.ops-summary>summary>i:last-child{margin-inline-start:auto}.ops-summary[open]>summary{border-bottom:1px solid var(--line)}.ops-summary>:deep(.stat-rail){margin:0;border:0;border-radius:0}.row.g-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin:0;gap:12px}.row.g-2>div{width:auto;max-width:none;padding:0;margin:0}.row.g-2 .form-control,.row.g-2 .form-select{min-height:46px;font-size:14px}.row.g-2 .btn{min-height:46px;width:100%}@media(max-width:560px){.row.g-2{grid-template-columns:1fr 1fr}.row.g-2>div:first-child{grid-column:1/-1}}

/* Operational layout and responsive hierarchy. */
.order-register-row{display:grid;grid-template-columns:minmax(140px,1fr) 150px 130px auto;gap:16px;padding:18px;align-items:center;border-bottom:1px solid var(--line)}.order-register-id,.order-register-state,.order-register-total{display:grid;gap:6px}.order-register-id strong{font-size:18px}.order-register-row small,.order-register-id>span{font-size:14px;color:var(--muted)}.order-register-total strong{font-size:19px}.order-register-state .badge{justify-self:start;border-radius:4px;font-size:14px;font-weight:600}.order-register-actions{display:flex;gap:8px}.order-register-actions .btn{min-height:42px}@media(max-width:800px){.order-register-row{grid-template-columns:1fr auto}.order-register-actions{grid-column:1/-1;border-top:1px solid var(--line);padding-top:12px;justify-content:flex-end}.order-register-total{grid-column:2;grid-row:2}.order-register-state{grid-column:1;grid-row:2}}
</style>
