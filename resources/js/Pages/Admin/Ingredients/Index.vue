<script setup>
/**
 * المكونات والمخزون — the inventory catalogue.
 *
 * Every figure on this page (stock, usable-vs-book split, reorder
 * threshold, blended cost, value, status) is computed server-side with the
 * per-branch formulas; the client only paints them. That is deliberate:
 * the four stat cards and the table used to disagree whenever the "low"
 * predicate drifted between the KPI query and the row badge.
 *
 * Filters live in the query string so a filtered view is linkable and the
 * Excel export can carry exactly the same predicate.
 */
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import AdjustStockSheet from '../../../Components/Ingredients/AdjustStockSheet.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ingredients: { type: Object, required: true },
    stats: { type: Object, required: true },
    statCards: { type: Array, required: true },
    statusFilter: { type: String, default: null },
    statusFilterLabel: { type: String, default: null },
    filters: { type: Object, required: true },
    hasFilters: { type: Boolean, default: false },
    hasActionFilters: { type: Boolean, default: false },
    filterLocations: { type: Array, default: () => [] },
    totalInventoryValue: { type: Number, default: 0 },
    branchName: { type: String, default: null },
    unitsByType: { type: Object, default: () => ({}) },
    today: { type: String, default: '' },
    currency: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const money = (v) => formatMoney(v, props.currency);

// ── Filters ──────────────────────────────────────────────────────────
const form = reactive({
    search: props.filters.search ?? '',
    storage_location_id: props.filters.storage_location_id ?? '',
    status: props.filters.status ?? '',
});

let timer = null;
const go = () => router.get(props.urls.index, {
    search: form.search || undefined,
    storage_location_id: form.storage_location_id || undefined,
    status: form.status || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

const visit = () => { clearTimeout(timer); go(); };
const onType = () => { clearTimeout(timer); timer = setTimeout(go, 350); };
onBeforeUnmount(() => clearTimeout(timer));

const clearAll = () => { clearTimeout(timer); router.get(props.urls.index); };

// ── Row actions ──────────────────────────────────────────────────────
const adjusting = ref(null);

const openAdjust = (row) => {
    adjusting.value = {
        id: row.id,
        name: row.name,
        baseCode: row.baseCode,
        tracksExpiry: row.tracksExpiry,
        currentStockLabel: row.adjust.currentStockLabel,
        currentStockExact: row.adjust.currentStockExact,
        baseUnitId: row.adjust.baseUnitId,
        costPerUnit: row.adjust.costPerUnit,
        locations: row.adjust.locations ?? [],
        preferredLocationId: form.storage_location_id || null,
        actionUrl: row.urls.adjust,
        measurementType: row.measurementType,
    };
};

const adjustUnits = computed(() => {
    const type = adjusting.value?.measurementType;
    return type ? (props.unitsByType[type] ?? []) : [];
});

const destroy = async (row) => {
    const yes = await ask({
        title: `حذف «${row.name}»؟`,
        message: 'المكوّن بينحذف من الكتالوج. الحركات والدفعات المسجّلة عليه بتضل بالسجل.',
        confirmLabel: 'احذف',
        danger: true,
    });
    if (yes) router.delete(row.urls.destroy, { preserveScroll: true });
};

const rowTone = (key) => (key === 'out' ? 'table-danger' : (key === 'low' ? 'table-warning' : ''));
</script>

<template>
    <Head title="المكونات" />

    <PageHeader title="المكونات والمخزون" icon="bi-basket2-fill"
                :subtitle="branchName ? `مخزون فرع «${branchName}»` : 'مخزون شامل لكل الفروع'" />

    <StatRail :stats="statCards" />

    <DataPanel title="قائمة المكونات" :count="ingredients.total" icon="bi-basket2-fill">
        <template #actions>
            <a v-if="can.manage" :href="urls.create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> مكون جديد
            </a>
            <a :href="urls.export" class="btn btn-success" title="تنزيل القائمة الحالية كملف Excel">
                <i class="bi bi-file-earmark-excel"></i> تصدير Excel
            </a>
            <button v-if="hasActionFilters" type="button" class="btn btn-light" @click="clearAll">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </button>
        </template>

        <template #filters>
            <form class="row g-2" @submit.prevent="visit">
                <div class="col-md-4">
                    <input v-model="form.search" class="form-control"
                           placeholder="🔍 ابحث باسم المكون" @input="onType">
                </div>
                <div class="col-md-4">
                    <select v-model="form.storage_location_id" class="form-select"
                            title="فلترة المكونات حسب موقع التخزين" @change="visit">
                        <option value="">📍 كل المواقع</option>
                        <option v-for="loc in filterLocations" :key="loc.id" :value="String(loc.id)">
                            {{ loc.label }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <select v-model="form.status" class="form-select" @change="visit">
                        <option value="">— كل الحالات —</option>
                        <option value="healthy">مخزون صحي فقط</option>
                        <option value="low">مخزون منخفض فقط</option>
                        <option value="out">المخزون النافد فقط</option>
                    </select>
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
                    <button v-if="hasFilters" type="button" class="ing-clear" @click="clearAll">
                        مسح الفلاتر
                    </button>
                </div>
            </form>
        </template>

        <!-- Total capital tied up in inventory for the current view. -->
        <div class="ing-capital">
            <div>
                <i class="bi bi-coin text-accent"></i>
                <strong>قيمة المخزون الإجمالية</strong>
                <small>
                    مجموع (المخزون × التكلفة) لكل المكونات
                    <template v-if="branchName">في فرع «{{ branchName }}»</template>
                    <template v-else>عبر كل الفروع</template>
                    <template v-if="statusFilterLabel"> — مفلترة حسب «{{ statusFilterLabel }}»</template>.
                    هذا هو رأس المال المحبوس في المخزون.
                </small>
            </div>
            <div class="ing-capital__value">{{ money(totalInventoryValue) }}</div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الاسم</th>
                        <th>SKU</th>
                        <th>المخزون</th>
                        <th>
                            المواقع
                            <i class="bi bi-info-circle text-muted small ing-help"
                               title="مواقع التخزين التي تحتوي هذا المكوّن مع كميته في كل موقع. عند اختيار فرع معيّن تظهر مواقع هذا الفرع فقط."></i>
                        </th>
                        <th>حد الطلب</th>
                        <th>الوحدة</th>
                        <th>
                            التكلفة/وحدة
                            <i class="bi bi-info-circle text-muted small ing-help"
                               title="تكلفة شراء الوحدة الواحدة. متوسط مرجَّح من دفعات الاستلام النشطة في الفرع المعروض."></i>
                        </th>
                        <th>
                            قيمة المخزون
                            <i class="bi bi-info-circle text-muted small ing-help"
                               title="المخزون × التكلفة. المال المحبوس في هذا المكوّن داخل المخازن — مفيد لجرد التقييم وحساب رأس المال الدوّار."></i>
                        </th>
                        <th>تتبع</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in ingredients.data" :key="row.id" :class="rowTone(row.statusKey)">
                        <td class="fw-bold">
                            {{ row.name }}
                            <span v-if="row.statusKey === 'out'" class="badge bg-danger ms-1">نفد</span>
                            <span v-else-if="row.statusKey === 'low'" class="badge bg-warning text-dark ms-1">منخفض</span>
                        </td>
                        <td>{{ row.sku }}</td>
                        <td :title="`الكمية الصالحة: ${row.stockExact} ${row.baseCode ?? ''}`">
                            {{ row.stockLabel }}
                            <small v-if="row.unusableLabel" class="text-danger d-block">
                                {{ row.unusableLabel }} منتهٍ/غير متاح
                            </small>
                        </td>
                        <td>
                            <span v-if="row.locations.length === 0" class="text-muted small">—</span>
                            <div v-else class="loc-chips">
                                <span v-for="(loc, i) in row.locations" :key="i" class="loc-chip" :title="loc.tooltip">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <span class="loc-chip__name">{{ loc.name }}</span>
                                    <span class="loc-chip__qty">{{ loc.qty }}</span>
                                </span>
                            </div>
                        </td>
                        <td :title="`القيمة الأساسية: ${row.thresholdExact} ${row.baseCode ?? ''}`">
                            {{ row.thresholdLabel }}
                        </td>
                        <td>{{ row.baseCode ?? '' }}</td>
                        <td>
                            {{ row.costLabel }} <small class="text-muted">{{ currency.symbol }}</small>
                            <small v-if="row.globalCostLabel" class="text-muted d-block fs-11" title="السعر المتوسط العام">
                                عام: {{ row.globalCostLabel }}
                            </small>
                        </td>
                        <td class="fw-semibold" :title="row.valueTooltip">{{ money(row.value) }}</td>
                        <td>{{ row.trackStock ? 'نعم' : 'لا' }}</td>
                        <td class="ing-actions-cell">
                            <div class="ing-actions">
                                <a :href="row.urls.show" class="btn btn-sm btn-primary ing-act-btn" title="فتح كرت الصنف">
                                    <i class="bi bi-card-text"></i>
                                </a>
                                <button v-if="row.can.manage" type="button" class="btn btn-sm btn-success ing-act-btn"
                                        title="تسجيل حركة مخزون" @click="openAdjust(row)">
                                    <i class="bi bi-plus-slash-minus"></i>
                                </button>
                                <a :href="row.urls.vendorPrices" class="btn btn-sm btn-info ing-act-btn"
                                   title="تاريخ الأسعار من المورّدين">
                                    <i class="bi bi-graph-up-arrow"></i>
                                </a>
                                <a v-if="row.can.manage" :href="row.urls.edit" class="btn btn-sm btn-light ing-act-btn" title="تعديل">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button v-if="row.can.manage" type="button"
                                        class="btn btn-sm btn-outline-danger ing-act-btn" title="حذف"
                                        @click="destroy(row)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <EmptyState v-if="ingredients.data.length === 0" icon="bi-basket2-fill"
                        title="ما في مكونات بعد"
                        message="أضف المكونات لتفعيل خصم المخزون التلقائي من الوصفات.">
                <template v-if="can.manage" #cta>
                    <a :href="urls.create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> أضف مكوناً</a>
                </template>
            </EmptyState>
        </div>

        <template #footer>
            <Pagination :links="ingredients.links" />
        </template>
    </DataPanel>

    <AdjustStockSheet :open="Boolean(adjusting)" :ingredient="adjusting" :units="adjustUnits"
                      :today="today" @close="adjusting = null" />
</template>

<style scoped>
.ing-clear {
    display: block;
    margin: .35rem auto 0;
    border: 0;
    background: none;
    color: #94a3b8;
    font: inherit;
    font-size: .8rem;
    cursor: pointer;
}
.ing-help { cursor: help; }

.ing-capital {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .5rem;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    background: linear-gradient(135deg, rgba(15, 71, 49, .06), rgba(15, 71, 49, .02));
    border: 1px solid rgba(15, 71, 49, .12);
    border-radius: .5rem;
}
.ing-capital small {
    display: block;
    margin-top: .25rem;
    font-size: 12px;
    line-height: 1.4;
    color: #6b7280;
}
.ing-capital__value {
    font-size: 1.5rem;
    font-weight: 800;
    color: rgb(var(--primary-rgb));
    font-variant-numeric: tabular-nums;
}

/* ─── Per-location stock chips (column "المواقع") ──────────────── */
.loc-chips { display: flex; flex-wrap: wrap; gap: 4px; max-width: 220px; }
.loc-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #14532d;
    border-radius: 99px;
    font-size: .72rem;
    font-weight: 600;
    line-height: 1.5;
    white-space: nowrap;
}
.loc-chip i { font-size: .7rem; color: #16a34a; }
.loc-chip__name { font-weight: 700; }
.loc-chip__qty {
    background: #fff;
    border: 1px solid #bbf7d0;
    padding: 0 6px;
    border-radius: 99px;
    font-variant-numeric: tabular-nums;
}

/* ─── Row action buttons — single horizontal line, never wrap ─── */
.ing-actions-cell { white-space: nowrap; min-width: 200px; text-align: end; }
.ing-actions { display: inline-flex; flex-wrap: nowrap; gap: 4px; align-items: center; justify-content: flex-end; }
.ing-actions .ing-act-btn {
    width: 44px;
    height: 44px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    line-height: 1;
}
.ing-actions .ing-act-btn i { font-size: .95rem; }
.fs-11 { font-size: 11px; }
</style>
