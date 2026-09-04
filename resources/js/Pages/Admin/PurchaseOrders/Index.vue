<script setup>
/**
 * أوامر الشراء — القائمة. The row answers the one question a purchasing
 * manager opens this for: how much of what I ordered actually arrived, and
 * how much money is still outstanding on the supplier.
 *
 * Every figure on the row (received value, outstanding value, base-currency
 * equivalent, badge colours) is computed server-side — the retired Blade
 * ran `receivedValue()`/`outstandingValue()` inside the loop and called the
 * exchange-rate service once per row.
 *
 * Filters live in the query string so a filtered list is a shareable URL;
 * the search box is debounced, the selects and dates commit immediately.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    pos: { type: Object, required: true },
    stats: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    hasFilters: { type: Boolean, default: false },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    supplier_id: props.filters.supplier_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const loading = ref(false);

const visit = (patch = {}) => {
    Object.assign(form, patch);
    router.get(props.urls.index, {
        search: form.search || undefined,
        status: form.status || undefined,
        supplier_id: form.supplier_id || undefined,
        from: form.from || undefined,
        to: form.to || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
};

// Typing shouldn't fire a request per keystroke.
let searchTimer = null;
watch(() => form.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit(), 400);
});

const clear = () => {
    clearTimeout(searchTimer);
    visit({ search: '', status: '', supplier_id: '', from: '', to: '' });
};

const anyFilter = computed(() =>
    Boolean(form.search || form.status || form.supplier_id || form.from || form.to));

// Supplier picker: a restaurant can carry a long vendor list, so the select
// gets a local type-ahead instead of a CDN choices.js.
const supplierSearch = ref('');
const visibleSuppliers = computed(() => {
    const q = supplierSearch.value.trim().toLowerCase();
    if (! q) return props.suppliers;
    return props.suppliers.filter((s) => String(s.name).toLowerCase().includes(q));
});
</script>

<template>
    <Head title="أوامر الشراء" />

    <PageHeader title="أوامر الشراء" icon="bi-truck"
                subtitle="إدارة طلبات الشراء من الموردين واستلام البضاعة" />

    <StatRail :stats="[
        { label: 'مسودات', value: stats.draft, icon: 'bi-file-earmark', color: 'muted' },
        { label: 'مُرسلة', value: stats.sent, icon: 'bi-send-check-fill', color: 'primary' },
        { label: 'مستلمة جزئياً', value: stats.partial, icon: 'bi-inboxes-fill', color: 'accent' },
        { label: 'مشتريات الشهر', value: stats.thisMonthLabel, icon: 'bi-cash-coin', color: 'success' },
    ]" />

    <DataPanel title="قائمة الأوامر" :count="pos.total" icon="bi-truck">
        <template #actions>
            <Link v-if="can.create" :href="urls.create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> أمر شراء جديد
            </Link>
            <button v-if="hasFilters || anyFilter" type="button" class="btn btn-light" @click="clear">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </button>
        </template>

        <template #filters>
            <form class="po-filters" @submit.prevent="visit()">
                <div class="po-filter">
                    <input v-model="form.search" class="form-control po-control" placeholder="🔍 رقم PO">
                </div>
                <div class="po-filter">
                    <select v-model="form.status" class="form-select po-control" @change="visit()">
                        <option value="">كل الحالات</option>
                        <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>
                <div class="po-filter po-filter--supplier">
                    <input v-model="supplierSearch" class="form-control po-control po-sup-search"
                           placeholder="ابحث عن مورد...">
                    <select v-model="form.supplier_id" class="form-select po-control" @change="visit()">
                        <option value="">كل الموردين</option>
                        <option v-for="s in visibleSuppliers" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                    </select>
                </div>
                <div class="po-filter">
                    <input v-model="form.from" type="date" class="form-control po-control"
                           title="من تاريخ" @change="visit()">
                </div>
                <div class="po-filter">
                    <input v-model="form.to" type="date" class="form-control po-control"
                           title="إلى تاريخ" @change="visit()">
                </div>
                <div class="po-filter po-filter--submit">
                    <button type="submit" class="btn btn-primary" :disabled="loading"><i class="bi" :class="loading ? 'bi-arrow-repeat po-spin' : 'bi-search'"></i> {{ loading ? 'جارٍ التحديث…' : 'استعلام' }}</button>
                </div>
            </form>
        </template>

        <div v-if="loading" class="po-loading" role="status" aria-live="polite"><i class="bi bi-arrow-repeat po-spin"></i> جارٍ تحميل النتائج…</div>
        <div v-if="pos.data.length" class="table-responsive" :class="{ 'is-loading': loading }" :aria-busy="loading">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>رقم PO</th>
                        <th>المورد</th>
                        <th>البنود</th>
                        <th>الإجمالي</th>
                        <th>المستلم فعلياً</th>
                        <th>المتبقي</th>
                        <th>الحالة</th>
                        <th>تاريخ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="po in pos.data" :key="po.id">
                        <td>
                            <Link :href="po.urls.show" class="fw-bold po-num">{{ po.number }}</Link>
                        </td>
                        <td>{{ po.supplierName }}</td>
                        <td><span class="badge bg-secondary">{{ po.itemsCount }} بند</span></td>
                        <td class="fw-bold po-total">
                            {{ po.totalLabel }}
                            <small v-if="po.baseTotalLabel" class="d-block text-muted">≈ {{ po.baseTotalLabel }}</small>
                        </td>
                        <td class="fw-bold" :class="po.receivedAny ? 'text-success' : 'text-muted'">
                            {{ po.receivedLabel }}
                        </td>
                        <td class="fw-bold" :class="po.outstandingOpen ? 'text-warning' : 'text-success'">
                            {{ po.outstandingLabel }}
                        </td>
                        <td><span class="badge" :class="`bg-${po.statusColor}`">{{ po.statusLabel }}</span></td>
                        <td class="text-muted small">
                            {{ po.createdAt }}
                            <div v-if="po.expectedAt" class="small">
                                <i class="bi bi-calendar-check"></i> {{ po.expectedAt }}
                            </div>
                        </td>
                        <td class="text-nowrap">
                            <Link :href="po.urls.show" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i> تفاصيل
                            </Link>
                            <Link v-if="po.canReceive" :href="po.urls.receive" class="btn btn-sm btn-success"
                               title="استلام البضاعة">
                                <i class="bi bi-box-arrow-in-down"></i>
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmptyState v-else-if="!loading" :icon="anyFilter ? 'bi-funnel' : 'bi-truck'"
                    :title="anyFilter ? 'لا توجد نتائج مطابقة' : 'ما في أوامر شراء بعد'"
                    :message="anyFilter ? 'غيّر الفلاتر أو امسحها لعرض بقية أوامر الشراء.' : 'أنشئ أول أمر شراء من مورد لاستلام بضاعة وتحديث المخزون تلقائياً.'">
            <template v-if="can.create || anyFilter" #cta>
                <Link v-if="!anyFilter" :href="urls.create" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> أمر شراء جديد
                </Link>
                <button v-else type="button" class="btn btn-light" @click="clear">مسح الفلاتر</button>
            </template>
        </EmptyState>

        <template #footer>
            <Pagination :links="pos.links" />
        </template>
    </DataPanel>
</template>

<style scoped>
/* 44px minimum on every control — these lists get driven from a tablet
   on the receiving dock, not from a mouse. */
.po-control { min-height: 44px; }
.po-sup-search { margin-bottom: .35rem; }
.po-filters { display: grid; grid-template-columns: minmax(130px,.75fr) minmax(145px,.85fr) minmax(220px,1.35fr) minmax(145px,.8fr) minmax(145px,.8fr) auto; align-items: end; gap: .6rem; }
.po-filter { min-width: 0; }
.po-filter--submit .btn { min-height: 44px; min-width: 112px; }
.table-responsive { overscroll-behavior-inline: contain; scrollbar-width: thin; }
.table { min-width: 1020px; }
.table-responsive.is-loading { opacity: .52; pointer-events: none; }
.po-loading { display: flex; align-items: center; justify-content: center; gap: .45rem; min-height: 42px; color: var(--primary); background: rgba(var(--primary-rgb),.055); font-size: .72rem; font-weight: 800; }
.po-spin { display: inline-block; animation: po-rotate 1s linear infinite; }
@keyframes po-rotate { to { transform: rotate(360deg); } }

.po-num {
    color: var(--primary);
    text-decoration: none;
    font-family: 'Courier New', monospace;
}
.po-total { color: var(--primary); }

.table td .btn-sm { min-height: 38px; }
@media (max-width: 1180px) {
    .po-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .po-filter--supplier { grid-row: span 2; }
    .po-filter--submit .btn { width: 100%; }
}
@media (max-width: 620px) {
    .po-filters { grid-template-columns: 1fr; }
    .po-filter--supplier { grid-row: auto; }
}
</style>
