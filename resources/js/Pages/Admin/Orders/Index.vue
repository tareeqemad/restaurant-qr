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

    <PageHeader title="الطلبات" icon="bi-receipt" subtitle="إدارة ومتابعة كل الطلبات الواردة">
        <template #actions>
            <a :href="urls.board" class="btn btn-primary">
                <i class="bi bi-person-check-fill"></i> لوحة الخدمة
            </a>
        </template>
    </PageHeader>

    <StatRail :stats="[
        { label: 'بانتظار الاعتماد', value: stats.pending, icon: 'bi-hourglass-split', color: 'accent' },
        { label: 'طلبات اليوم', value: stats.todayCount, icon: 'bi-receipt', color: 'primary' },
        { label: 'نشطة الآن', value: stats.todayActive, icon: 'bi-activity', color: 'success' },
        { label: 'إيراد اليوم', value: stats.todayRevenue, icon: 'bi-cash-coin', color: 'accent' },
    ]" />

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

        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الرقم</th>
                        <th v-if="showBranchColumn">الفرع</th>
                        <th>الطاولة</th>
                        <th>الأصناف</th>
                        <th>الإجمالي</th>
                        <th>الحالة</th>
                        <th>الوقت</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id">
                        <td class="fw-bold">{{ o.number }}</td>
                        <td v-if="showBranchColumn">{{ o.branchName ?? '—' }}</td>
                        <td>{{ o.tableLabel }}</td>
                        <td>{{ o.itemCount }}</td>
                        <td>{{ o.total }}</td>
                        <td><span class="badge" :class="`bg-${o.statusColor}`">{{ o.statusLabel }}</span></td>
                        <td>{{ o.createdAgo }}</td>
                        <td class="text-nowrap">
                            <a :href="o.urls.show" class="btn btn-sm btn-primary"><i class="bi bi-eye"></i> تفاصيل</a>
                            <button v-if="o.canApprove" type="button" class="btn btn-sm btn-success" @click="approve(o)">
                                <i class="bi bi-check2"></i> اعتماد
                            </button>
                            <button v-else-if="o.canUnapprove" type="button" class="btn btn-sm btn-outline-warning" @click="unapprove(o)">
                                <i class="bi bi-arrow-counterclockwise"></i> فك الاعتماد
                            </button>
                        </td>
                    </tr>
                    <tr v-if="orders.data.length === 0">
                        <td :colspan="showBranchColumn ? 8 : 7">
                            <EmptyState icon="bi-receipt" title="ما في طلبات بعد"
                                        message="حالما يطلب الزبائن من خلال الـ QR، الطلبات ستظهر هنا للاعتماد والمتابعة." />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <Pagination :links="orders.links" />
        </template>
    </DataPanel>
</template>
