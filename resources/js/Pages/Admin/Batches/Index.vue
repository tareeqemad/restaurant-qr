<script setup>
/**
 * دفعات المكونات — FIFO lots with their expiry dates, remaining quantity and
 * per-lot unit cost.
 *
 * The expiry thresholds are NOT decided here: `isExpired` (strictly before
 * today — a date-only expiry stays usable all day), `isNearExpiry(7)` and the
 * resulting row tint all come from the model, server-side, so this table and
 * the FIFO consumption engine can never disagree about what "expired" means.
 * Same for the two quantity formatters and the لقيمة = المتبقي × التكلفة line.
 *
 * The manual-batch form injects stock without a purchase-order trail, so it is
 * gated on `can.create` (the `manage` ability store() authorizes against) —
 * a chef who reaches this page sees the table and nothing else.
 */
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    batches: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    hasFilters: { type: Boolean, default: false },
    ingredients: { type: Array, default: () => [] },
    storageLocations: { type: Array, default: () => [] },
    currency: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const money = (v) => formatMoney(v, props.currency);

// ── Filters ──────────────────────────────────────────────────────────
const form = reactive({
    ingredient_id: props.filters.ingredientId ?? '',
    storage_location_id: props.filters.storageLocationId ?? '',
    active: Boolean(props.filters.active),
    expiring: Boolean(props.filters.expiring),
    expired: Boolean(props.filters.expired),
});

const visit = (params) => {
    router.get(props.urls.index, params ?? {
        ingredient_id: form.ingredient_id || undefined,
        storage_location_id: form.storage_location_id || undefined,
        active: form.active ? 1 : undefined,
        expiring: form.expiring ? 1 : undefined,
        expired: form.expired ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true });
};

// The Blade's «عرضها الآن» was a bare `?expired=1`, which dropped every
// other param. Same behaviour here — it is a lens, not a refinement.
const showExpired = () => visit({ expired: 1 });
const clear = () => visit({});

// ── Manual batch ─────────────────────────────────────────────────────
const adding = ref(false);
const defaultLocationId = props.storageLocations.find((l) => l.isDefault)?.id
    ?? props.storageLocations[0]?.id
    ?? '';

const batchForm = useForm({
    ingredient_id: '',
    storage_location_id: defaultLocationId,
    qty: '',
    unit_cost: '',
    expiry_date: '',
    batch_number: '',
    notes: '',
});

// Quantities are stored in the ingredient's BASE unit — show which one the
// number will be read as, so nobody types "2" meaning kilos.
const baseUnitCode = ref('');
watch(() => batchForm.ingredient_id, (id) => {
    baseUnitCode.value = props.ingredients.find((i) => String(i.id) === String(id))?.unitCode ?? '';
});

const openAdd = () => {
    batchForm.clearErrors();
    adding.value = true;
};

const submitBatch = () => {
    batchForm.post(props.urls.store, {
        preserveScroll: true,
        // reset() restores the defaults above, default location included.
        onSuccess: () => {
            adding.value = false;
            batchForm.reset();
        },
    });
};
</script>

<template>
    <Head title="دفعات المكونات" />

    <PageHeader title="دفعات المكونات" icon="bi-box2-fill"
                subtitle="تتبع الدفعات وتواريخ الصلاحية (FIFO)" />

    <!-- Plain-Arabic explainer: a manager opening this for the first time
         needs to know what a "batch" is and why it is separate from the
         supplier-invoice/payment trail. -->
    <div class="card custom-card mb-3 bx-explain">
        <div class="card-body p-3 small bx-explain__body">
            <div class="d-flex align-items-start gap-2 mb-2">
                <i class="bi bi-info-circle-fill text-primary fs-5"></i>
                <strong class="fs-13">شو هي «الدفعات» وليش بتلزم؟</strong>
            </div>
            <div class="text-muted">
                كل مرة بتستلم بضاعة من مورد، النظام بيخزّن السطر كـ <strong>دفعة</strong> منفصلة فيها:
                تاريخ استلامها، تاريخ صلاحيتها، الكمية الأصلية، الكمية المتبقية، وتكلفة الوحدة في تلك الدفعة بالذات.
                <strong>تلزم لثلاث أمور أساسية:</strong>
                <ul class="mb-0 ps-3 mt-1">
                    <li><strong>FIFO</strong> — الاستهلاك يبدأ من الدفعة الأقدم تلقائياً (الكمية المتبقية تنقص أولاً للأقدم).</li>
                    <li><strong>تتبّع الصلاحية</strong> — تشوف أي دفعة بتنتهي خلال أسبوع وأي واحدة منتهية فعلياً قبل ما تخسرها.</li>
                    <li><strong>دقة التكلفة والاسترجاع</strong> — كل دفعة عندها سعرها الفعلي ورقمها، فلو ظهرت مشكلة في شحنة معينة من المورد بتعرف بالضبط أي وصفات استخدمتها.</li>
                </ul>
                <div class="mt-2">
                    <strong>تتحدّث متى؟</strong>
                    عند <strong>استلام أمر شراء</strong> (إنشاء دفعة جديدة) و<strong>استهلاك المخزون</strong>
                    من البيع/الهدر/التحويل (تنقيص الكمية المتبقية).
                    <span class="text-warning">
                        <i class="bi bi-exclamation-circle"></i>
                        <strong>دفع فاتورة المورد لا يؤثر على الدفعات</strong> — الدفعات تتعقّب البضاعة الفعلية،
                        والفواتير تتعقّب المستحقات المالية. كلاهما مسارات منفصلة عن قصد.
                    </span>
                </div>
            </div>
        </div>
    </div>

    <StatRail :stats="[
        { label: 'دفعات نشطة', value: stats.active, icon: 'bi-box2-fill', color: 'primary' },
        { label: 'تنتهي خلال 7 أيام', value: stats.expiring, icon: 'bi-alarm-fill', color: 'accent' },
        { label: 'منتهية الصلاحية', value: stats.expired, icon: 'bi-x-octagon-fill', color: 'danger' },
        { label: 'قيمة المخزون', value: money(stats.totalValue), icon: 'bi-cash-coin', color: 'success' },
    ]" />

    <div v-if="stats.expired > 0" class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        يوجد <strong>{{ stats.expired }}</strong> دفعة منتهية الصلاحية ولها مخزون متبقٍ!
        يرجى معالجتها كـ "هدر" لإزالتها من المخزون.
        <button type="button" class="btn btn-link alert-link p-0 align-baseline" @click="showExpired">
            عرضها الآن
        </button>
    </div>

    <DataPanel title="الدفعات" :count="batches.total" icon="bi-box2">
        <template #actions>
            <button v-if="can.create" type="button" class="btn btn-primary bx-btn" @click="openAdd">
                <i class="bi bi-plus-lg"></i> إضافة دفعة يدوية
            </button>
            <button v-if="hasFilters" type="button" class="btn btn-light bx-btn" @click="clear">
                <i class="bi bi-x-circle"></i> مسح
            </button>
        </template>

        <template #filters>
            <form class="row g-2" @submit.prevent="visit()">
                <div class="col-md-3">
                    <select v-model="form.ingredient_id" class="form-select bx-input" @change="visit()">
                        <option value="">كل المكونات</option>
                        <option v-for="i in ingredients" :key="i.id" :value="String(i.id)">{{ i.name }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select v-model="form.storage_location_id" class="form-select bx-input" @change="visit()">
                        <option value="">كل مواقع التخزين</option>
                        <option v-for="l in storageLocations" :key="l.id" :value="String(l.id)">{{ l.label }}</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-3 align-items-center flex-wrap">
                    <label class="bx-check">
                        <input v-model="form.active" type="checkbox" @change="visit()"> نشطة فقط
                    </label>
                    <label class="bx-check">
                        <input v-model="form.expiring" type="checkbox" @change="visit()"> تنتهي قريباً
                    </label>
                    <label class="bx-check">
                        <input v-model="form.expired" type="checkbox" @change="visit()"> منتهية
                    </label>
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary px-5 bx-btn">
                        <i class="bi bi-funnel"></i> استعلام
                    </button>
                </div>
            </form>
        </template>

        <EmptyState v-if="batches.data.length === 0"
                    icon="bi-box2"
                    title="ما في دفعات بعد"
                    message="تُنشأ الدفعات تلقائياً عند استلام أوامر الشراء، أو يمكنك إضافتها يدوياً." />

        <div v-else class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>موقع التخزين</th>
                        <th>المكوّن</th>
                        <th>رقم الدفعة</th>
                        <th>تاريخ الاستلام</th>
                        <th>الصلاحية</th>
                        <th>الكمية الأولية</th>
                        <th>المتبقي</th>
                        <th>التكلفة/وحدة</th>
                        <th>القيمة الحالية</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in batches.data" :key="b.id"
                        :class="[b.rowTone ? `table-${b.rowTone}` : '', b.isDepleted ? 'text-muted' : '']">
                        <td>{{ b.locationName }}</td>
                        <td class="fw-bold">{{ b.ingredientName }}</td>
                        <td>{{ b.batchNumber }}</td>
                        <td>{{ b.receivedDate }}</td>
                        <td>
                            <template v-if="b.expiryDate">
                                {{ b.expiryDate }}
                                <template v-if="b.isExpired">
                                    <br><small class="text-danger fw-bold">منتهية!</small>
                                </template>
                                <template v-else-if="b.isNearExpiry">
                                    <br><small class="fw-bold bx-soon">خلال {{ b.daysUntilExpiry }} يوم</small>
                                </template>
                            </template>
                            <span v-else class="text-muted">—</span>
                        </td>
                        <td :title="b.initialQtyTitle">{{ b.initialQtyDisplay }}</td>
                        <td class="fw-bold">
                            <span v-if="b.isDepleted" class="text-muted"><i class="bi bi-check2"></i> نفذت</span>
                            <span v-else :title="b.remainingQtyTitle">{{ b.remainingQtyDisplay }}</span>
                        </td>
                        <td :title="b.unitCostTitle">{{ b.unitCostDisplay }}</td>
                        <td class="fw-bold bx-value">{{ money(b.value) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <Pagination :links="batches.links" />
        </template>
    </DataPanel>

    <!-- Manual batch sheet -->
    <Teleport to="body">
        <Transition name="bxm">
            <div v-if="adding && can.create" class="bxm-backdrop" @click.self="adding = false">
                <form class="bxm-card" role="dialog" aria-modal="true" @submit.prevent="submitBatch">
                    <header class="bxm-head">
                        <h5 class="mb-0">إضافة دفعة يدوية</h5>
                        <button type="button" class="btn-close" aria-label="إغلاق" @click="adding = false"></button>
                    </header>

                    <div class="bxm-body">
                        <div class="alert alert-info small mb-2">
                            تُستخدم عادة لتسجيل مخزون موجود سلفاً لم يدخل عبر PO. ستتم زيادة المخزون تلقائياً.
                        </div>

                        <label class="bxm-field">
                            <span>المكوّن <span class="text-danger">*</span></span>
                            <select v-model="batchForm.ingredient_id" class="form-select bx-input"
                                    :class="{ 'is-invalid': batchForm.errors.ingredient_id }" required>
                                <option value="">— اختر —</option>
                                <option v-for="i in ingredients" :key="i.id" :value="String(i.id)">
                                    {{ i.name }} ({{ i.unitCode }})
                                </option>
                            </select>
                            <small v-if="batchForm.errors.ingredient_id" class="bxm-err">{{ batchForm.errors.ingredient_id }}</small>
                        </label>

                        <label class="bxm-field">
                            <span>الفرع وموقع التخزين <span class="text-danger">*</span></span>
                            <select v-model="batchForm.storage_location_id" class="form-select bx-input"
                                    :class="{ 'is-invalid': batchForm.errors.storage_location_id }" required>
                                <option value="" disabled>— اختر الموقع —</option>
                                <option v-for="l in storageLocations" :key="l.id" :value="l.id">{{ l.label }}</option>
                            </select>
                            <small v-if="batchForm.errors.storage_location_id" class="bxm-err">{{ batchForm.errors.storage_location_id }}</small>
                        </label>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="bxm-field">
                                    <span>الكمية <span class="text-danger">*</span>
                                        <small v-if="baseUnitCode" class="text-muted">({{ baseUnitCode }})</small>
                                    </span>
                                    <input v-model="batchForm.qty" type="number" step="0.0001" min="0.0001"
                                           class="form-control bx-input"
                                           :class="{ 'is-invalid': batchForm.errors.qty }" required>
                                    <small v-if="batchForm.errors.qty" class="bxm-err">{{ batchForm.errors.qty }}</small>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="bxm-field">
                                    <span>التكلفة / وحدة</span>
                                    <input v-model="batchForm.unit_cost" type="number" step="0.0001" min="0"
                                           class="form-control bx-input"
                                           :class="{ 'is-invalid': batchForm.errors.unit_cost }">
                                    <small v-if="batchForm.errors.unit_cost" class="bxm-err">{{ batchForm.errors.unit_cost }}</small>
                                </label>
                            </div>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="bxm-field">
                                    <span>رقم الدفعة</span>
                                    <input v-model="batchForm.batch_number" type="text" maxlength="80"
                                           class="form-control bx-input" placeholder="(اختياري)"
                                           :class="{ 'is-invalid': batchForm.errors.batch_number }">
                                    <small v-if="batchForm.errors.batch_number" class="bxm-err">{{ batchForm.errors.batch_number }}</small>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="bxm-field">
                                    <span>تاريخ الصلاحية</span>
                                    <input v-model="batchForm.expiry_date" type="date" class="form-control bx-input"
                                           :class="{ 'is-invalid': batchForm.errors.expiry_date }">
                                    <small v-if="batchForm.errors.expiry_date" class="bxm-err">{{ batchForm.errors.expiry_date }}</small>
                                </label>
                            </div>
                        </div>

                        <label class="bxm-field">
                            <span>ملاحظات</span>
                            <textarea v-model="batchForm.notes" rows="2" maxlength="500" class="form-control"
                                      :class="{ 'is-invalid': batchForm.errors.notes }"></textarea>
                            <small v-if="batchForm.errors.notes" class="bxm-err">{{ batchForm.errors.notes }}</small>
                        </label>
                    </div>

                    <footer class="bxm-foot">
                        <button type="button" class="btn btn-light bx-btn" @click="adding = false">تراجع</button>
                        <button type="submit" class="btn btn-primary bx-btn" :disabled="batchForm.processing">
                            <i class="bi" :class="batchForm.processing ? 'bi-arrow-repeat' : 'bi-plus-lg'"></i>
                            {{ batchForm.processing ? 'جارٍ الإنشاء…' : 'إنشاء الدفعة' }}
                        </button>
                    </footer>
                </form>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.bx-explain { border-color: rgba(15, 71, 49, .12); }
.bx-explain__body { line-height: 1.85; }

.bx-soon { color: #8a6920; }
.bx-value { color: var(--primary); }

.bx-check { display: inline-flex; align-items: center; gap: .35rem; min-height: 44px; margin: 0; }
.bx-check input { width: 20px; height: 20px; }

/* Restaurant tablets: every tap target stays at 44px. */
.bx-btn { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; gap: .3rem; }
.bx-input { min-height: 44px; }

/* ── Manual batch sheet ──────────────────────────────────────────── */
.bxm-backdrop {
    position: fixed;
    inset: 0;
    z-index: 18000;
    background: rgba(15, 23, 42, .5);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    overflow-y: auto;
}
.bxm-card {
    background: #fff;
    border-radius: 16px;
    width: min(560px, 100%);
    max-height: 92vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.bxm-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    padding: .9rem 1.1rem;
    border-bottom: 1px solid #eef0f3;
}
.bxm-body { padding: 1rem 1.1rem; overflow-y: auto; display: flex; flex-direction: column; gap: .6rem; }
.bxm-foot {
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
    padding: .8rem 1.1rem;
    border-top: 1px solid #eef0f3;
}
.bxm-field { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
.bxm-field > span { font-size: .84rem; font-weight: 700; color: #334155; }
.bxm-err { color: #b91c1c; font-size: .76rem; font-weight: 700; }

.bxm-enter-active, .bxm-leave-active { transition: opacity .15s ease; }
.bxm-enter-from, .bxm-leave-to { opacity: 0; }
</style>
