<script setup>
/**
 * حركات المخزون — the stock ledger: every in, out, waste, return and
 * adjustment, with the batch it hit and the document that caused it.
 *
 * Nothing is formatted here. Quantities go through QuantityFormatter's
 * unit ladder and the reference chip (label + icon + deep link) is resolved
 * from a morph relation — both PHP-only, both shipped as strings so the row
 * can never disagree with the ingredient page it links to.
 *
 * Filters live in the query string, so a shift lead can bookmark "هدر،
 * هذا الأسبوع، مخزن المطبخ" and reopen it tomorrow. Selects and dates
 * commit at once; the ingredient type-ahead is local (it narrows the list,
 * it does not query).
 *
 * Read-only screen: no writes, no per-row gates. `viewAny` on Ingredient
 * is enforced server-side and is the only gate this page has.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    movements: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    hasFilters: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

// snake_case on purpose — these keys ARE the query string.
const form = reactive({
    type: props.filters.type ?? '',
    ingredient_id: props.filters.ingredient_id != null ? String(props.filters.ingredient_id) : '',
    storage_location_id: props.filters.storage_location_id != null ? String(props.filters.storage_location_id) : '',
    reference_type: props.filters.reference_type ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const visit = (patch = {}) => {
    Object.assign(form, patch);
    router.get(props.urls.index, {
        type: form.type || undefined,
        ingredient_id: form.ingredient_id || undefined,
        storage_location_id: form.storage_location_id || undefined,
        reference_type: form.reference_type || undefined,
        from: form.from || undefined,
        to: form.to || undefined,
    }, { preserveState: true, preserveScroll: true });
};

// A bare visit so the component remounts on the server's defaults.
const clearAll = () => router.get(props.urls.index);

// Local type-ahead for the ingredient list — the Blade had a Choices.js
// search box here and the catalogue runs into the hundreds. Purely a
// client-side narrowing of the options already on the page; the current
// selection always stays listed so it can never silently reset.
const ingredientSearch = ref('');
const visibleIngredients = computed(() => {
    const q = ingredientSearch.value.trim().toLowerCase();
    if (! q) return props.options.ingredients;

    return props.options.ingredients.filter(
        (i) => i.name.toLowerCase().includes(q) || String(i.id) === form.ingredient_id,
    );
});
</script>

<template>
    <Head title="حركات المخزون" />

    <PageHeader title="حركات المخزون" icon="bi-boxes"
                subtitle="سجل الإدخالات والإخراجات والهدر والتعديلات" />

    <StatRail :stats="[
        { label: 'حركات اليوم', value: stats.today, icon: 'bi-activity', color: 'primary' },
        { label: 'إدخالات', value: stats.in, icon: 'bi-box-arrow-in-down', color: 'success' },
        { label: 'إخراجات', value: stats.out, icon: 'bi-box-arrow-up', color: 'accent' },
        { label: 'هدر', value: stats.waste, icon: 'bi-trash3-fill', color: 'danger' },
        { label: 'إرجاع', value: stats.return, icon: 'bi-arrow-counterclockwise', color: 'info' },
        { label: 'تعديل', value: stats.adjustment, icon: 'bi-sliders2', color: 'secondary' },
    ]" />

    <DataPanel title="سجل الحركات" :count="movements.total" icon="bi-boxes">
        <template v-if="hasFilters" #actions>
            <button type="button" class="btn btn-light" @click="clearAll">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </button>
        </template>

        <template #filters>
            <form class="row g-2 align-items-end" @submit.prevent="visit()">
                <div class="col-md-2">
                    <select v-model="form.type" class="form-select mv-control" @change="visit()">
                        <option value="">كل الأنواع</option>
                        <option v-for="t in options.types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input v-model="ingredientSearch" type="search" class="form-control mv-control mb-1"
                           placeholder="ابحث عن مكوّن..." autocomplete="off">
                    <select v-model="form.ingredient_id" class="form-select mv-control" @change="visit()">
                        <option value="">كل المكونات</option>
                        <option v-for="i in visibleIngredients" :key="i.id" :value="String(i.id)">{{ i.name }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select v-model="form.storage_location_id" class="form-select mv-control" @change="visit()">
                        <option value="">كل المواقع</option>
                        <option v-for="l in options.storageLocations" :key="l.id" :value="String(l.id)">{{ l.name }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select v-model="form.reference_type" class="form-select mv-control" @change="visit()">
                        <option value="">كل المصادر</option>
                        <option v-for="r in options.referenceTypes" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <input v-model="form.from" type="date" class="form-control mv-control" @change="visit()">
                </div>
                <div class="col-md-1">
                    <input v-model="form.to" type="date" class="form-control mv-control" @change="visit()">
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary px-5 mv-control">
                        <i class="bi bi-search"></i> استعلام
                    </button>
                </div>
            </form>
        </template>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>التاريخ</th>
                        <th>المكون</th>
                        <th>النوع</th>
                        <th>الموقع</th>
                        <th>الدفعة</th>
                        <th>الكمية</th>
                        <th>بعد</th>
                        <th>التكلفة</th>
                        <th>المرجع</th>
                        <th>السبب</th>
                        <th>بواسطة</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in movements.data" :key="m.id">
                        <td>{{ m.occurredAtLabel }}</td>
                        <td class="fw-bold">{{ m.ingredientName }}</td>
                        <td>
                            <span class="badge" :class="`bg-${m.typeColor}`">{{ m.typeLabel }}</span>
                            <span v-if="m.wasteReason" class="badge fs-11"
                                  :class="`bg-${m.wasteReason.color}-transparent text-${m.wasteReason.color}`">
                                {{ m.wasteReason.label }}
                            </span>
                        </td>
                        <td>
                            <span v-if="m.locationName" class="badge bg-primary-transparent text-primary fs-11">
                                <i class="bi bi-geo-alt me-1"></i>{{ m.locationName }}
                            </span>
                            <span v-else class="badge bg-light text-muted fs-11">عام</span>
                        </td>
                        <td>
                            <span v-if="m.batchLabel" class="badge bg-info-transparent text-info fs-11" title="ربط الـ FIFO">
                                <i class="bi bi-box2-fill me-1"></i>{{ m.batchLabel }}
                            </span>
                            <span v-else class="text-muted fs-12">—</span>
                        </td>
                        <td :title="m.qtyTitle">{{ m.qtyDisplay }}</td>
                        <td :title="m.afterTitle">{{ m.afterDisplay }}</td>
                        <td>{{ m.costLabel }}</td>
                        <td>
                            <a v-if="m.reference.url" :href="m.reference.url"
                               class="badge bg-success-transparent text-success fs-11">
                                <i class="bi me-1" :class="m.reference.icon"></i>{{ m.reference.label }}
                            </a>
                            <span v-else class="badge bg-light text-muted fs-11">
                                <i class="bi me-1" :class="m.reference.icon"></i>{{ m.reference.label }}
                            </span>
                        </td>
                        <td class="fs-12">{{ m.reason }}</td>
                        <td>{{ m.userName }}</td>
                    </tr>

                    <tr v-if="movements.data.length === 0">
                        <td colspan="11">
                            <EmptyState icon="bi-boxes" title="ما في حركات مخزن"
                                        message="تظهر هنا كل حركات الإدخال والإخراج والهدر بمجرد حدوثها." />
                        </td>
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
/* Restaurant tablets — every filter control takes a finger. */
.mv-control { min-height: 44px; }
</style>
