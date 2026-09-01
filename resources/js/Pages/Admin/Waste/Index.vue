<script setup>
/**
 * سجل الهدر — the read side of the waste surface (the form shipped in Wave 4).
 *
 * Every figure here is computed server-side against the SAME filtered window
 * the table below uses: the four KPIs, the per-reason share (and its bar
 * width), and the top-wasted table's quantity ladder. The two PHP quantity
 * formatters (QuantityFormatter::smart / Qty::format) have no JS twin on
 * purpose — porting the unit ladder is how a number silently changes.
 *
 * Reason colours come from the lookups admin as a hex; the badge/bar styles
 * are assembled from it here, and the fallback ('#64748b' + bi-question-circle
 * when a legacy row has no lookup) is decided in PHP.
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    movements: { type: Object, required: true },
    stats: { type: Object, required: true },
    byReason: { type: Array, default: () => [] },
    topIngredients: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    reasons: { type: Array, default: () => [] },
    ingredients: { type: Array, default: () => [] },
    storageLocations: { type: Array, default: () => [] },
    currency: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const money = (v) => formatMoney(v, props.currency);

const form = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    reason: props.filters.reason ?? '',
    ingredient_id: props.filters.ingredientId ?? '',
    storage_location_id: props.filters.storageLocationId ?? '',
});

const visit = () => {
    router.get(props.urls.index, {
        from: form.from || undefined,
        to: form.to || undefined,
        reason: form.reason || undefined,
        ingredient_id: form.ingredient_id || undefined,
        storage_location_id: form.storage_location_id || undefined,
    }, { preserveState: true, preserveScroll: true });
};

// Reason hex → badge / bar styles (the Blade's $reasonStyle helpers).
const badgeStyle = (hex) => ({
    background: `${hex}1a`,
    color: hex,
    border: `1px solid ${hex}40`,
});
</script>

<template>
    <Head title="الهدر" />

    <PageHeader title="الهدر" icon="bi-trash3-fill"
                subtitle="سجل خسائر المطبخ — الانتهاء، التلف، الانسكاب، فقد التحضير. كل سطر يخصم من المخزون ومن دفعة محددة." />

    <StatRail :stats="[
        { label: 'عدد الأحداث', value: stats.count, icon: 'bi-list-ul', color: 'primary' },
        { label: 'تكلفة الفترة', value: money(stats.totalCost), icon: 'bi-cash-coin', color: 'danger' },
        { label: 'أحداث اليوم', value: stats.todayCount, icon: 'bi-calendar-day-fill', color: 'warning' },
        { label: 'تكلفة اليوم', value: money(stats.todayCost), icon: 'bi-cash', color: 'accent' },
    ]" />

    <div class="row g-3 mb-3">
        <!-- Reason breakdown -->
        <div class="col-lg-5">
            <DataPanel title="حسب السبب" icon="bi-pie-chart-fill">
                <p v-if="byReason.length === 0" class="text-muted text-center py-3 mb-0">لا أحداث في الفترة.</p>
                <div v-else class="wi-reasons">
                    <div v-for="(r, i) in byReason" :key="i" class="wi-reason">
                        <i class="bi wi-reason__icon" :class="r.icon" :style="{ color: r.color }"></i>
                        <div class="wi-reason__body">
                            <div class="wi-reason__head">
                                <span>{{ r.label }}</span>
                                <span>{{ money(r.totalCost) }}</span>
                            </div>
                            <div class="progress wi-reason__bar">
                                <div class="progress-bar"
                                     :style="{ width: r.pct + '%', background: r.color }"></div>
                            </div>
                            <small class="text-muted">{{ r.count }} حدث · {{ r.pctLabel }}%</small>
                        </div>
                    </div>
                </div>
            </DataPanel>
        </div>

        <!-- Top wasted ingredients -->
        <div class="col-lg-7">
            <DataPanel title="أكثر المكونات هدراً" icon="bi-bar-chart-fill">
                <p v-if="topIngredients.length === 0" class="text-muted text-center py-3 mb-0">لا بيانات.</p>
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>المكوّن</th>
                                <th class="text-end">عدد الأحداث</th>
                                <th class="text-end">إجمالي الكمية</th>
                                <th class="text-end">إجمالي التكلفة</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ing in topIngredients" :key="ing.id">
                                <td class="fw-semibold">{{ ing.name }}</td>
                                <td class="text-end">{{ ing.eventCount }}</td>
                                <td class="text-end" :title="ing.qtyTitle">{{ ing.qtyDisplay }}</td>
                                <td class="text-end fw-semibold text-danger">{{ money(ing.totalCost) }}</td>
                                <td class="text-end">
                                    <a :href="ing.createUrl" class="btn btn-sm btn-outline-danger wi-icon-btn"
                                       title="سجّل هدر جديد">
                                        <i class="bi bi-plus-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </DataPanel>
        </div>
    </div>

    <DataPanel title="سجل الأحداث" icon="bi-list-check" :count="movements.total">
        <template #actions>
            <a :href="urls.create" class="btn btn-sm btn-danger wi-btn">
                <i class="bi bi-plus-circle"></i> سجّل هدر جديد
            </a>
        </template>

        <template #filters>
            <form class="row g-2 align-items-end" @submit.prevent="visit">
                <div class="col-md-2">
                    <label class="form-label fs-12 mb-1">من</label>
                    <input v-model="form.from" type="date" class="form-control form-control-sm wi-input">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12 mb-1">إلى</label>
                    <input v-model="form.to" type="date" class="form-control form-control-sm wi-input">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12 mb-1 d-flex align-items-center justify-content-between">
                        <span>السبب</span>
                        <a v-if="can.manageLookups" :href="urls.lookups" target="_blank"
                           class="text-muted text-decoration-none"
                           title="إدارة أسباب الهدر من شاشة الثوابت">
                            <i class="bi bi-gear-fill"></i>
                        </a>
                    </label>
                    <select v-model="form.reason" class="form-select form-select-sm wi-input" @change="visit">
                        <option value="">كل الأسباب</option>
                        <option v-for="r in reasons" :key="r.id" :value="String(r.id)">{{ r.label }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12 mb-1">المكوّن</label>
                    <select v-model="form.ingredient_id" class="form-select form-select-sm wi-input" @change="visit">
                        <option value="">كل المكونات</option>
                        <option v-for="i in ingredients" :key="i.id" :value="String(i.id)">{{ i.name }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12 mb-1">الموقع</label>
                    <select v-model="form.storage_location_id" class="form-select form-select-sm wi-input" @change="visit">
                        <option value="">كل المواقع</option>
                        <option v-for="l in storageLocations" :key="l.id" :value="String(l.id)">{{ l.name }}</option>
                    </select>
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary btn-sm px-5 wi-btn">
                        <i class="bi bi-search"></i> استعلام
                    </button>
                </div>
            </form>
        </template>

        <EmptyState v-if="movements.data.length === 0"
                    icon="bi-check-circle"
                    title="لا أحداث هدر بهذه الفلاتر"
                    message="ربما هذا خبر سار — أو فلاتر صارمة. جرّب توسيع المدى أو إزالة بعض الفلاتر." />

        <div v-else class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>الوقت</th>
                        <th>المكوّن</th>
                        <th>السبب</th>
                        <th>الموقع</th>
                        <th>الدفعة</th>
                        <th class="text-end">الكمية</th>
                        <th class="text-end">التكلفة</th>
                        <th>المسجِّل</th>
                        <th>ملاحظة</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in movements.data" :key="m.id">
                        <td>
                            <div class="fw-semibold fs-13">{{ m.at }}</div>
                            <small class="text-muted">{{ m.ago }}</small>
                        </td>
                        <td class="fw-semibold">{{ m.ingredientName }}</td>
                        <td>
                            <span v-if="m.reason" class="badge" :style="badgeStyle(m.reason.color)">
                                <i class="bi me-1" :class="m.reason.icon"></i>{{ m.reason.label }}
                            </span>
                            <span v-else class="badge bg-secondary-transparent">{{ m.reasonFallback }}</span>
                        </td>
                        <td>
                            <span v-if="m.locationName" class="badge bg-primary-transparent text-primary fs-11">
                                <i class="bi bi-geo-alt me-1"></i>{{ m.locationName }}
                            </span>
                            <span v-else class="badge bg-light text-muted fs-11">عام</span>
                        </td>
                        <td>
                            <span v-if="m.batch" class="fs-12">
                                {{ m.batch.label }}
                                <template v-if="m.batch.expiry">
                                    · <small class="text-muted">{{ m.batch.expiry }}</small>
                                </template>
                            </span>
                            <span v-else class="text-muted fs-12">—</span>
                        </td>
                        <td class="text-end" :title="m.qtyTitle">{{ m.qtyDisplay }}</td>
                        <td class="text-end fw-semibold text-danger">{{ money(m.cost) }}</td>
                        <td class="fs-12 text-muted">{{ m.userName }}</td>
                        <td class="fs-12 text-muted wi-note">{{ m.note }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <Pagination :links="movements.links" />
        </template>
    </DataPanel>
</template>

<style scoped>
.wi-reasons { display: flex; flex-direction: column; gap: .5rem; }
.wi-reason {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .5rem;
    border-radius: .5rem;
    background: #f8f9fa;
}
.wi-reason__icon { font-size: 20px; }
.wi-reason__body { flex: 1 1 auto; min-width: 0; }
.wi-reason__head {
    display: flex;
    justify-content: space-between;
    gap: .5rem;
    font-weight: 600;
    font-size: .8125rem;
}
.wi-reason__bar { height: 4px; margin-top: .25rem; }

.wi-note { max-width: 200px; white-space: normal; }

/* Restaurant tablets: keep every tap target at 44px even on btn-sm. */
.wi-btn { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; gap: .3rem; }
.wi-input { min-height: 44px; }
.wi-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    min-height: 44px;
}
</style>
