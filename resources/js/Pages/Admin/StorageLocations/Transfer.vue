<script setup>
/**
 * نقل مخزون بين المواقع — move a quantity of one ingredient from one
 * storage location to another. The server writes TWO movements (out + in)
 * and drags the FIFO batch layers along with the quantity.
 *
 * Guards ported verbatim from the retired Alpine screen — none of them may
 * soften, because each one keeps the operator from asking for a move the
 * server will refuse:
 *   · the ingredient dropdown only lists what actually has qty > 0 AT THE
 *     CHOSEN SOURCE (stockByLocation is the server's own map);
 *   · quantity is capped at the source's available amount, and exceeding it
 *     both marks the input invalid and blocks submit;
 *   · source and destination can never be the same location;
 *   · changing the source drops an ingredient that isn't stocked there.
 * These are convenience only: LocationInventoryService::transfer re-reads
 * the source row under `lockForUpdate` and throws if it is short.
 *
 * NEW here (the Blade did not do it): `can.transfer`. transferForm()
 * authorizes `viewAny` while transferStore() authorizes `manage`, so a
 * chef or bartender could open this screen and press a fully-live submit
 * button that answered 403.
 */
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    locations: { type: Array, required: true },
    ingredients: { type: Array, required: true },
    stockByLocation: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const form = useForm({
    from_id: 0,
    to_id: 0,
    ingredient_id: 0,
    quantity: '',
    reason: '',
});

// ── Lookups ──────────────────────────────────────────────────────────
const stockAt = (locId, ingId) => {
    if (! locId || ! ingId) return 0;
    return Number(props.stockByLocation[locId]?.[ingId] ?? 0);
};

const qtyNum = computed(() => Number(form.quantity) || 0);

const availableAtSource = computed(() => stockAt(form.from_id, form.ingredient_id));

const ingredientsAtSource = computed(() => {
    if (! form.from_id) return [];
    const stockMap = props.stockByLocation[form.from_id] ?? {};
    return props.ingredients.filter((i) => Number(stockMap[i.id] ?? 0) > 0);
});

const selectedIngredient = computed(
    () => props.ingredients.find((i) => i.id === form.ingredient_id) ?? null,
);

const locationName = (id) => props.locations.find((l) => l.id === id)?.name ?? '';
const branchIdOf = (id) => props.locations.find((l) => l.id === id)?.branchId ?? 0;

const crossBranch = computed(
    () => Boolean(form.to_id && form.from_id && branchIdOf(form.from_id) !== branchIdOf(form.to_id)),
);

const overAvailable = computed(() => Boolean(form.ingredient_id) && qtyNum.value > availableAtSource.value);

const willGoBelowThreshold = computed(() => {
    const ing = selectedIngredient.value;
    if (! ing || ! ing.globalThreshold) return false;
    return availableAtSource.value - qtyNum.value < ing.globalThreshold;
});

// ── Source change side effects ───────────────────────────────────────
watch(() => form.from_id, () => {
    // Drop the chosen ingredient if it's no longer available at the new source.
    if (form.ingredient_id) {
        const validIds = new Set(ingredientsAtSource.value.map((i) => i.id));
        if (! validIds.has(form.ingredient_id)) {
            form.ingredient_id = 0;
            form.quantity = '';
        }
    }
    // Clear destination if it's the same as the new source.
    if (form.to_id && form.to_id === form.from_id) {
        form.to_id = 0;
    }
});

// ── Submit gating ────────────────────────────────────────────────────
const blockedReason = computed(() => {
    if (! props.can.transfer) return 'ما عندك صلاحية نقل المخزون.';
    if (! form.from_id) return 'اختر موقع المصدر.';
    if (! form.to_id) return 'اختر موقع الوجهة.';
    if (form.from_id === form.to_id) return 'المصدر والوجهة لا يمكن أن يكونا نفس الموقع.';
    if (! form.ingredient_id) return 'اختر المكوّن.';
    if (qtyNum.value <= 0) return 'أدخل كمية أكبر من صفر.';
    if (qtyNum.value > availableAtSource.value) return 'الكمية تتجاوز المتاح في المصدر.';
    return '';
});

const canSubmit = computed(() => blockedReason.value === '');

const submit = () => {
    if (! canSubmit.value || form.processing) return;

    form.post(props.urls.store, {
        preserveScroll: true,
        // transferStore always answers with back() — a refused move flashes
        // `error`, a completed one flashes `success`. Only clear the entry
        // fields on a real move so a rejected attempt keeps what was typed.
        onSuccess: (page) => {
            if (page?.props?.flash?.success) {
                form.ingredient_id = 0;
                form.quantity = '';
                form.reason = '';
            }
        },
    });
};

const fillAll = () => { form.quantity = availableAtSource.value; };

// Same rounding the retired screen used: snap to a whole number when the
// value is within the 0.0001 storage epsilon, else at most 2 decimals.
const fmtQty = (v) => {
    const n = Number(v) || 0;
    if (Math.abs(n - Math.round(n)) < 0.0001) return String(Math.round(n));
    return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
};
</script>

<template>
    <Head title="نقل مخزون بين المواقع" />

    <PageHeader title="نقل بين المواقع" icon="bi-arrow-left-right"
                subtitle="انقل كمية من مكوّن من موقع تخزين إلى آخر — تُسجَّل حركتا «خصم» و«إدخال» في السجل"
                :crumbs="[{ label: 'مواقع التخزين', url: urls.index }]" />

    <div v-if="! can.transfer" class="lt-denied">
        <i class="bi bi-lock-fill"></i>
        <div>
            <strong>ما عندك صلاحية نقل المخزون</strong>
            <span>عرض المخزون مسموح، لكن تحريكه بين المواقع محصور بالإدارة. اطلب من المدير.</span>
        </div>
    </div>

    <div v-if="Object.keys(form.errors).length" class="alert alert-danger lt-alert">
        <ul class="mb-0">
            <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
        </ul>
    </div>

    <form @submit.prevent="submit">
        <!-- Source / Destination -->
        <div class="card custom-card mb-3">
            <div class="card-header">
                <h3 class="card-title mb-0">
                    <i class="bi bi-geo-alt-fill text-accent me-1"></i> المواقع
                </h3>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-stretch">
                    <div class="col-md-5">
                        <div class="lt-loc-card h-100">
                            <label class="label" for="lt-from">
                                <i class="bi bi-box-arrow-up"></i> من موقع <span class="text-danger">*</span>
                            </label>
                            <select id="lt-from" v-model.number="form.from_id"
                                    class="form-select form-select-lg" :disabled="! can.transfer" required>
                                <option :value="0">— اختر —</option>
                                <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                    {{ loc.name }} — {{ loc.branchName }}
                                </option>
                            </select>
                            <div v-if="form.from_id" class="mt-2 small text-muted">
                                <i class="bi bi-box-seam"></i>
                                {{ ingredientsAtSource.length }} مكوّن متاح في هذا الموقع
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2 d-flex align-items-center justify-content-center">
                        <i class="bi bi-arrow-left-circle-fill lt-arrow"></i>
                    </div>

                    <div class="col-md-5">
                        <div class="lt-loc-card h-100">
                            <label class="label" for="lt-to">
                                <i class="bi bi-box-arrow-in-down"></i> إلى موقع <span class="text-danger">*</span>
                            </label>
                            <select id="lt-to" v-model.number="form.to_id"
                                    class="form-select form-select-lg" :disabled="! can.transfer" required>
                                <option :value="0">— اختر —</option>
                                <option v-for="loc in locations" :key="loc.id" :value="loc.id"
                                        :disabled="loc.id === form.from_id">
                                    {{ loc.name }} — {{ loc.branchName }}{{ loc.id === form.from_id ? ' (المصدر)' : '' }}
                                </option>
                            </select>
                            <div v-if="crossBranch" class="mt-2 small text-warning">
                                <i class="bi bi-info-circle-fill"></i>
                                هذا نقل عبر فرعين. الأنسب استخدام
                                <a :href="urls.branchTransferCreate" class="alert-link">«تحويل بين الفروع»</a>
                                للحصول على دورة كاملة (إرسال → استلام).
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ingredient + Quantity -->
        <div class="card custom-card mb-3">
            <div class="card-header">
                <h3 class="card-title mb-0">
                    <i class="bi bi-basket2-fill text-accent me-1"></i> المكوّن والكمية
                </h3>
            </div>

            <div v-if="! form.from_id" class="card-body text-center py-4 text-muted">
                <i class="bi bi-arrow-up-circle lt-hint-icon"></i>
                <div class="mt-2">اختر موقع المصدر أولاً لعرض المكونات المتاحة.</div>
            </div>

            <div v-else-if="ingredientsAtSource.length === 0" class="card-body text-center py-4 text-muted">
                <i class="bi bi-inbox lt-hint-icon"></i>
                <div class="mt-2">لا توجد مكونات بكميات قابلة للنقل في هذا الموقع.</div>
            </div>

            <div v-else class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="lt-ing">المكوّن <span class="text-danger">*</span></label>
                        <select id="lt-ing" v-model.number="form.ingredient_id" class="form-select"
                                :disabled="! can.transfer" required>
                            <option :value="0">— اختر —</option>
                            <option v-for="ing in ingredientsAtSource" :key="ing.id" :value="ing.id">
                                {{ ing.name }} — متاح: {{ fmtQty(stockAt(form.from_id, ing.id)) }} {{ ing.unitCode }}
                            </option>
                        </select>
                        <div v-if="form.ingredient_id" class="mt-2">
                            <span class="lt-pill ok">
                                <i class="bi bi-box"></i>
                                متاح في المصدر:
                                <strong>{{ fmtQty(availableAtSource) }}</strong>
                                <span>{{ selectedIngredient?.unitCode }}</span>
                            </span>
                            <span v-if="form.to_id && stockAt(form.to_id, form.ingredient_id) > 0" class="lt-pill ok ms-1">
                                <i class="bi bi-arrow-down-right"></i>
                                موجود في الوجهة:
                                <strong>{{ fmtQty(stockAt(form.to_id, form.ingredient_id)) }}</strong>
                                <span>{{ selectedIngredient?.unitCode }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="lt-qty">الكمية <span class="text-danger">*</span></label>
                        <input id="lt-qty" v-model="form.quantity" type="number" step="0.0001" min="0.0001"
                               :max="availableAtSource || ''"
                               class="form-control text-end"
                               :class="{ 'is-invalid': overAvailable }"
                               :disabled="! can.transfer" required>
                        <button v-if="form.ingredient_id && availableAtSource > 0" type="button"
                                class="btn btn-link btn-sm p-0 mt-1 small text-decoration-none"
                                :disabled="! can.transfer" @click="fillAll">
                            <i class="bi bi-magic"></i> نقل الكل ({{ fmtQty(availableAtSource) }})
                        </button>
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <div class="w-100">
                            <div class="small text-muted">الوحدة</div>
                            <div class="fs-5 fw-semibold">{{ selectedIngredient?.unitCode || '—' }}</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div v-if="overAvailable" class="lt-msg error">
                            <i class="bi bi-exclamation-octagon-fill mt-1"></i>
                            <span>الكمية المطلوبة تتجاوز المتاح. الحد الأقصى:
                                <strong>{{ fmtQty(availableAtSource) }}</strong>
                                <span>{{ selectedIngredient?.unitCode }}</span>.</span>
                        </div>
                        <div v-else-if="form.ingredient_id && qtyNum > 0 && willGoBelowThreshold" class="lt-msg warn">
                            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                            <span>المتبقّي بعد النقل سيكون تحت حد إعادة الطلب
                                (<span>{{ fmtQty(selectedIngredient?.globalThreshold || 0) }}</span>
                                 <span>{{ selectedIngredient?.unitCode }}</span>).</span>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="lt-reason">السبب <small class="text-muted">(اختياري)</small></label>
                        <input id="lt-reason" v-model="form.reason" type="text" maxlength="500"
                               class="form-control" :disabled="! can.transfer"
                               placeholder="مثلاً: نفاد البار من الزيت، إعادة توزيع، إلخ...">
                    </div>
                </div>
            </div>
        </div>

        <!-- Live preview + Submit -->
        <div class="row g-3 align-items-stretch">
            <div class="col-md-7">
                <div v-if="form.from_id && form.to_id && form.ingredient_id && qtyNum > 0" class="lt-loc-card h-100">
                    <div class="text-muted small">معاينة الحركة</div>
                    <div class="fs-6">
                        <strong>{{ fmtQty(qtyNum) }}</strong>
                        <span>{{ selectedIngredient?.unitCode }}</span>
                        من
                        <strong>{{ locationName(form.from_id) }}</strong>
                        →
                        <strong>{{ locationName(form.to_id) }}</strong>
                    </div>
                    <div class="small text-muted mt-1">
                        الرصيد بعد النقل في المصدر:
                        <strong>{{ fmtQty(availableAtSource - qtyNum) }}</strong>
                        /
                        في الوجهة:
                        <strong>{{ fmtQty(stockAt(form.to_id, form.ingredient_id) + qtyNum) }}</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="lt-actions">
                    <a :href="urls.index" class="btn btn-light">
                        <i class="bi bi-arrow-right"></i> إلغاء
                    </a>
                    <button type="submit" class="btn btn-primary"
                            :disabled="! canSubmit || form.processing" :title="blockedReason">
                        <i class="bi" :class="form.processing ? 'bi-arrow-repeat' : 'bi-arrow-left-right'"></i>
                        {{ form.processing ? 'جارٍ النقل…' : 'نقل المخزون' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="blockedReason" class="text-end mt-2 small text-muted">
            <i class="bi bi-info-circle"></i>
            <span>{{ blockedReason }}</span>
        </div>
    </form>
</template>

<style scoped>
.lt-denied {
    display: flex;
    gap: .7rem;
    align-items: flex-start;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    border-radius: 14px;
    padding: .85rem 1rem;
    margin-bottom: .9rem;
}
.lt-denied i { font-size: 1.2rem; }
.lt-denied strong { display: block; }

.lt-alert { border-radius: .5rem; margin-bottom: .9rem; }

.lt-pill {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    padding: .15rem .55rem;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
}
.lt-pill.ok { background: rgba(25, 135, 84, .12); color: #157347; }

.lt-arrow { font-size: 2.2rem; color: var(--accent); }

.lt-loc-card {
    background: rgba(15, 71, 49, .03);
    border: 1px solid rgba(15, 71, 49, .08);
    border-radius: 10px;
    padding: 1rem;
}
.lt-loc-card .label {
    display: block;
    font-size: 12px;
    color: #6c757d;
    font-weight: 600;
    margin-bottom: .35rem;
}

.lt-msg {
    font-size: 12.5px;
    line-height: 1.45;
    margin-top: .35rem;
    display: flex;
    align-items: flex-start;
    gap: .35rem;
}
.lt-msg.error { color: #b02a37; }
.lt-msg.warn { color: #997404; }

.lt-hint-icon { font-size: 2rem; opacity: .35; }

.lt-actions {
    display: flex;
    gap: .5rem;
    justify-content: flex-end;
    align-items: flex-end;
    height: 100%;
}
.lt-actions .btn { min-height: 48px; }

.form-select-lg,
.form-select,
.form-control { min-height: 44px; }
</style>
