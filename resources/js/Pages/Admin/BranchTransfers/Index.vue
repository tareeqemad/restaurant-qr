<script setup>
/**
 * التحويلات بين الفروع — القائمة.
 *
 * Read-only for branch-level staff: the "تحويل جديد" button is gated on
 * `can.create`, which the controller derives from isOwnerLevel(). The
 * client never decides who may create a transfer.
 *
 * Every figure on the row (item count, status label + colour, the two
 * timestamps) is computed server-side. The old Blade called
 * `$t->items()->count()` inside the loop — one extra query per row —
 * which is now a single `withCount('items')`.
 */
import { computed, onBeforeUnmount, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    transfers: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
});

const payload = () => ({
    search: String(form.search).trim() || undefined,
    status: form.status || undefined,
});

// No debounce helper in the repo; a local timer is the whole need.
let timer = null;
const go = () => router.get(props.urls.index, payload(), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});
const visit = (patch = {}) => {
    clearTimeout(timer);
    Object.assign(form, patch);
    go();
};
const visitSoon = () => {
    clearTimeout(timer);
    timer = setTimeout(go, 350);
};
onBeforeUnmount(() => clearTimeout(timer));

const hasFilters = computed(() => Boolean(String(form.search).trim() || form.status));
const clearAll = () => visit({ search: '', status: '' });

const STATUSES = [
    { value: '', label: 'كل الحالات' },
    { value: 'draft', label: 'مسودة' },
    { value: 'in_transit', label: 'في الطريق' },
    { value: 'received', label: 'مستلم' },
    { value: 'cancelled', label: 'ملغي' },
];
</script>

<template>
    <Head title="التحويلات بين الفروع" />

    <PageHeader title="التحويلات بين الفروع" icon="bi-arrow-left-right"
                subtitle="نقل المكونات بين فروع مختلفة — out من المصدر و in للوجهة في نفس اللحظة." />

    <StatRail :stats="[
        { label: 'في الطريق', value: stats.in_transit, icon: 'bi-truck', color: 'warning' },
        { label: 'مستلم اليوم', value: stats.received_today, icon: 'bi-check-circle-fill', color: 'success' },
        { label: 'مسودات', value: stats.draft, icon: 'bi-pencil-square', color: 'secondary' },
        { label: 'إجمالي الشهر', value: stats.this_month, icon: 'bi-calendar-month-fill', color: 'primary' },
    ]" />

    <DataPanel title="قائمة التحويلات" icon="bi-list-ul" :count="transfers.total">
        <template v-if="can.create" #actions>
            <a :href="urls.create" class="btn btn-primary bt-btn">
                <i class="bi bi-plus-lg"></i> تحويل جديد
            </a>
        </template>

        <template #filters>
            <form class="row g-2 align-items-end" @submit.prevent="visit()">
                <div class="col-md-4">
                    <label class="form-label fs-12 mb-1">رقم التحويل</label>
                    <input v-model="form.search" type="text" class="form-control bt-control"
                           placeholder="BT-..." @input="visitSoon">
                </div>
                <div class="col-md-4">
                    <label class="form-label fs-12 mb-1">الحالة</label>
                    <select v-model="form.status" class="form-select bt-control" @change="visit()">
                        <option v-for="s in STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>
                <div v-if="hasFilters" class="col-md-4">
                    <button type="button" class="btn btn-light bt-btn w-100" @click="clearAll">
                        <i class="bi bi-x-circle"></i> مسح الفلاتر
                    </button>
                </div>
            </form>
        </template>

        <EmptyState v-if="transfers.data.length === 0"
                    icon="bi-truck"
                    title="لا تحويلات"
                    :message="hasFilters
                        ? 'ما في تحويل يطابق الفلاتر — جرّب تمسحها.'
                        : 'ابدأ تحويلاً جديداً لنقل المكونات بين فروعك.'" />

        <div v-else class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الرقم</th>
                        <th>من</th>
                        <th></th>
                        <th>إلى</th>
                        <th>عدد البنود</th>
                        <th>الحالة</th>
                        <th>التاريخ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in transfers.data" :key="t.id">
                        <td><strong class="bt-number">{{ t.number }}</strong></td>
                        <td>{{ t.fromBranchName }}</td>
                        <td><i class="bi bi-arrow-left text-accent"></i></td>
                        <td>{{ t.toBranchName }}</td>
                        <td>{{ t.itemsCount }}</td>
                        <td><span class="badge" :class="`bg-${t.statusColor}`">{{ t.statusLabel }}</span></td>
                        <td class="fs-13">
                            {{ t.createdAt }}
                            <template v-if="t.sentAgo">
                                <br><small class="text-muted">أُرسل: {{ t.sentAgo }}</small>
                            </template>
                        </td>
                        <td>
                            <a :href="t.url" class="btn btn-light bt-btn bt-btn--icon" title="عرض">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <Pagination :links="transfers.links" />
        </template>
    </DataPanel>
</template>

<style scoped>
.bt-number { font-family: monospace; }

/* Restaurant tablets: every control is a finger target. */
.bt-control { min-height: 44px; }
.bt-btn {
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
}
.bt-btn--icon { min-width: 44px; }
</style>
