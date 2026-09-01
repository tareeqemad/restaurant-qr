<script setup>
/**
 * The ingredient catalogue form — one component for create and edit, the
 * same way `_form.blade.php` was shared by both.
 *
 * What only CREATE shows: the branch / storage-location / opening-quantity
 * block. Those three declare where the ingredient *lives* before any stock
 * arrives, and the server cross-checks that the location belongs to the
 * branch and that the user may write to that branch.
 *
 * Two locks come from the server as booleans and are never re-derived here:
 *   • costLocked — cost_per_unit becomes a weighted average the moment any
 *     stock history exists; editing it afterwards would revalue stock with
 *     no matching journal entry.
 *   • unitLocked — the base unit freezes once stock/movements/recipes exist,
 *     because changing it would silently reinterpret every past quantity.
 * The server enforces both again on update; these only keep the UI honest.
 *
 * useForm keeps every typed value on a 422 — a rejected ingredient is one
 * corrected field away from saving, not a whole re-typed form.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    mode: { type: String, required: true },          // 'create' | 'edit'
    ingredient: { type: Object, default: null },
    units: { type: Array, required: true },
    suppliers: { type: Array, required: true },
    supplierIdsByBranch: { type: Object, default: () => ({}) },
    branches: { type: Array, default: () => [] },
    locationsByBranch: { type: Object, default: () => ({}) },
    activeBranchId: { type: [Number, String], default: null },
    canCreateBranch: { type: Boolean, default: false },
    costLocked: { type: Boolean, default: false },
    unitLocked: { type: Boolean, default: false },
    today: { type: String, default: '' },
    submitUrl: { type: String, required: true },
    urls: { type: Object, required: true },
});

const isCreate = computed(() => props.mode === 'create');
const ing = computed(() => props.ingredient);

const MEASUREMENT_LABELS = [
    { value: 'weight', label: 'وزن' },
    { value: 'volume', label: 'حجم/سوائل' },
    { value: 'count', label: 'عدد/قطعة' },
    { value: 'length', label: 'طول' },
];

// Pre-select the active branch context when the user actually has it —
// branch + location are both required, so a sensible default saves a click.
const firstBranchId = () => {
    const seeded = Number(props.activeBranchId) || 0;
    return props.branches.some((b) => b.id === seeded) ? seeded : '';
};

const form = useForm({
    name: ing.value?.name ?? '',
    measurement_type: ing.value?.measurement_type ?? 'weight',
    base_unit_id: ing.value?.base_unit_id ?? '',
    supplier_id: ing.value?.supplier_id ?? '',
    cost_per_unit: ing.value?.cost_per_unit ?? 0,
    reorder_threshold: ing.value?.reorder_threshold ?? 0,
    tracks_expiry: ing.value ? Boolean(ing.value.tracks_expiry) : true,
    default_shelf_life_days: ing.value?.default_shelf_life_days ?? '',
    yield_pct: ing.value?.yield_pct ?? '',
    track_stock: ing.value ? Boolean(ing.value.track_stock) : true,
    active: ing.value ? Boolean(ing.value.active) : true,
    is_composite: ing.value ? Boolean(ing.value.is_composite) : false,
    composite_yield: ing.value?.composite_yield ?? '',
    notes: ing.value?.notes ?? '',
    // create-only
    branch_id: isCreate.value ? firstBranchId() : '',
    storage_location_id: '',
    initial_quantity: 0,
    initial_expiry_date: '',
});

// ── Units narrowed to the chosen measurement type ────────────────────
const availableUnits = computed(() => props.units.filter((u) => u.unitType === form.measurement_type));

watch(() => form.measurement_type, () => {
    if (props.unitLocked) return;
    const stillValid = availableUnits.value.some((u) => Number(u.id) === Number(form.base_unit_id));
    if (! stillValid) form.base_unit_id = availableUnits.value[0]?.id ?? '';
}, { immediate: true });

// ── Branch → locations / suppliers (create only) ─────────────────────
const locationsForBranch = computed(() => {
    if (! form.branch_id) return [];
    return props.locationsByBranch[form.branch_id] ?? [];
});

// With no branch picked, every supplier shows (catalogue mode for
// owner-level users seeding global ingredients).
const suppliersForBranch = computed(() => {
    if (! form.branch_id) return props.suppliers;
    const allowed = new Set((props.supplierIdsByBranch[form.branch_id] ?? []).map(Number));
    return props.suppliers.filter((s) => allowed.has(Number(s.id)));
});

const applyDefaultLocation = () => {
    form.storage_location_id = locationsForBranch.value[0]?.id ?? '';
};

const onBranchChange = () => {
    // Location: keep it only if it still belongs to this branch.
    const stillValid = locationsForBranch.value.some((l) => Number(l.id) === Number(form.storage_location_id));
    if (! stillValid) applyDefaultLocation();

    // Supplier: blank it rather than silently mismatching the branch.
    if (form.supplier_id) {
        const ok = suppliersForBranch.value.some((s) => Number(s.id) === Number(form.supplier_id));
        if (! ok) form.supplier_id = '';
    }
};

if (isCreate.value && form.branch_id) applyDefaultLocation();

const branchName = computed(() => props.branches.find((b) => b.id === Number(form.branch_id))?.name ?? '');
const locationName = computed(() => locationsForBranch.value.find((l) => Number(l.id) === Number(form.storage_location_id))?.name ?? '');

const needsInitialExpiry = computed(() => form.tracks_expiry && Number(form.initial_quantity || 0) > 0);

// ── Submit ───────────────────────────────────────────────────────────
const blankToNull = (v) => (v === '' || v === null || v === undefined ? null : v);

const submit = () => {
    if (form.processing) return;

    form.transform((data) => {
        const payload = {
            name: data.name,
            measurement_type: data.measurement_type,
            base_unit_id: data.base_unit_id,
            supplier_id: blankToNull(data.supplier_id),
            cost_per_unit: data.cost_per_unit === '' ? 0 : data.cost_per_unit,
            reorder_threshold: data.reorder_threshold === '' ? 0 : data.reorder_threshold,
            track_stock: data.track_stock,
            tracks_expiry: data.tracks_expiry,
            default_shelf_life_days: data.tracks_expiry ? blankToNull(data.default_shelf_life_days) : null,
            active: data.active,
            notes: blankToNull(data.notes),
            yield_pct: blankToNull(data.yield_pct),
            is_composite: data.is_composite,
            composite_yield: blankToNull(data.composite_yield),
        };

        if (isCreate.value) {
            payload.branch_id = data.branch_id;
            payload.storage_location_id = data.storage_location_id;
            payload.initial_quantity = data.initial_quantity === '' ? 0 : data.initial_quantity;
            payload.initial_expiry_date = data.tracks_expiry ? blankToNull(data.initial_expiry_date) : null;
        }

        return payload;
    });

    if (isCreate.value) form.post(props.submitUrl);
    else form.put(props.submitUrl);
};
</script>

<template>
    <form class="row g-3" @submit.prevent="submit">
        <!-- Identity ──────────────────────────────────────────────── -->
        <template v-if="ing">
            <div class="col-md-4">
                <label class="form-label">SKU</label>
                <input :value="ing.sku" class="form-control bg-light" readonly tabindex="-1"
                       title="رمز داخلي يُولَّد تلقائياً عند الإنشاء — للعرض فقط">
            </div>
            <div class="col-md-4">
                <label class="form-label">الاسم *</label>
                <input v-model="form.name" class="form-control" required>
                <small v-if="form.errors.name" class="ing-err">{{ form.errors.name }}</small>
            </div>
        </template>
        <template v-else>
            <div class="col-md-6">
                <label class="form-label">الاسم *</label>
                <input v-model="form.name" class="form-control" required autofocus>
                <small v-if="form.errors.name" class="ing-err">{{ form.errors.name }}</small>
            </div>
        </template>

        <!-- Branch context — CREATE only ──────────────────────────── -->
        <div v-if="isCreate" class="col-12">
            <div class="ing-home">
                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-building text-accent me-1"></i> فرع المكوّن وموقع تخزينه</h5>
                    <small class="text-muted">يُحدِّد البيت الافتراضي للمكوّن في هذا الفرع. الكمية الأولية اختيارية.</small>
                </div>

                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">الفرع <span class="text-danger">*</span></label>
                        <select v-model.number="form.branch_id" class="form-select" required
                                :disabled="branches.length === 0" @change="onBranchChange">
                            <option value="">— اختر —</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                        <template v-if="branches.length === 0">
                            <small v-if="canCreateBranch" class="text-warning d-block mt-1">
                                <i class="bi bi-signpost-2-fill"></i>
                                لا توجد فروع بعد — أنشئ أول فرع للبدء:
                                <a :href="urls.branchCreate" class="alert-link">إنشاء فرع جديد</a>.
                            </small>
                            <small v-else class="text-danger d-block mt-1">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                لا تملك صلاحية على أي فرع — اطلب من مدير النظام إضافتك لفرع.
                            </small>
                        </template>
                        <small v-if="form.errors.branch_id" class="ing-err">{{ form.errors.branch_id }}</small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">موقع التخزين <span class="text-danger">*</span></label>
                        <select v-model.number="form.storage_location_id" class="form-select" required
                                :disabled="! form.branch_id || locationsForBranch.length === 0">
                            <option value="">— اختر —</option>
                            <option v-for="l in locationsForBranch" :key="l.id" :value="l.id">
                                {{ l.name }}{{ l.is_default ? ' (افتراضي)' : '' }}
                            </option>
                        </select>
                        <small v-if="! form.branch_id" class="text-muted d-block mt-1">اختر الفرع أولاً لعرض المواقع.</small>
                        <small v-else-if="locationsForBranch.length === 0" class="text-danger d-block mt-1">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            هذا الفرع بلا مواقع تخزين.
                            <a :href="urls.locationCreate" target="_blank" class="alert-link">أنشئ موقعاً</a>.
                        </small>
                        <small v-else class="text-muted d-block mt-1">
                            <i class="bi bi-cursor"></i>
                            {{ locationsForBranch.length }} موقع متاح — يمكنك التغيير.
                        </small>
                        <small v-if="form.errors.storage_location_id" class="ing-err">{{ form.errors.storage_location_id }}</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">الكمية الأولية <small class="text-muted">(اختياري)</small></label>
                        <input v-model.number="form.initial_quantity" type="number" step="0.0001" min="0"
                               class="form-control text-end" placeholder="0">
                        <small class="text-muted d-block mt-1">اتركها 0 لو ستُضاف الكمية لاحقاً عبر أمر شراء.</small>
                        <small v-if="form.errors.initial_quantity" class="ing-err">{{ form.errors.initial_quantity }}</small>
                    </div>

                    <div v-if="form.tracks_expiry" class="col-md-3">
                        <label class="form-label">تاريخ صلاحية الكمية الافتتاحية</label>
                        <input v-model="form.initial_expiry_date" type="date" :min="today" class="form-control"
                               :required="needsInitialExpiry">
                        <small class="text-muted d-block mt-1">إلزامي عند تفعيل الصلاحية وإدخال كمية.</small>
                        <small v-if="form.errors.initial_expiry_date" class="ing-err">{{ form.errors.initial_expiry_date }}</small>
                    </div>
                </div>

                <div v-if="form.branch_id && form.storage_location_id && form.initial_quantity > 0" class="mt-2 small text-muted">
                    <i class="bi bi-check-circle-fill text-success"></i>
                    ستُضاف <strong>{{ form.initial_quantity }}</strong> وحدة إلى
                    <strong>{{ branchName }}</strong> — موقع <strong>{{ locationName }}</strong>،
                    وتُسجَّل كحركة «إدخال» في سجل المخزون.
                </div>
                <div v-else-if="form.branch_id && form.storage_location_id" class="mt-2 small text-muted">
                    <i class="bi bi-bookmark-check-fill text-accent"></i>
                    سيُسجَّل المكوّن في <strong>{{ branchName }}</strong> — موقع <strong>{{ locationName }}</strong>
                    كمكان افتراضي. عند استلام أمر شراء، يُقترح هذا الموقع تلقائياً.
                </div>
            </div>
        </div>

        <!-- Catalog details ───────────────────────────────────────── -->
        <div class="col-md-4">
            <label class="form-label">نوع قياس الصنف *</label>
            <select v-model="form.measurement_type" class="form-select" required :disabled="unitLocked">
                <option v-for="m in MEASUREMENT_LABELS" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
            <small class="text-muted d-block mt-1">
                {{ unitLocked ? 'مقفول بعد بدء استخدام الصنف لحماية الكميات السابقة.' : 'يمنع خلط الوزن مع السوائل أو القطع.' }}
            </small>
            <small v-if="form.errors.measurement_type" class="ing-err">{{ form.errors.measurement_type }}</small>
        </div>

        <div class="col-md-4">
            <label class="form-label">الوحدة الأساسية *</label>
            <select v-model.number="form.base_unit_id" class="form-select" required :disabled="unitLocked">
                <option v-for="u in availableUnits" :key="u.id" :value="u.id">{{ u.label }}</option>
            </select>
            <small v-if="form.errors.base_unit_id" class="ing-err">{{ form.errors.base_unit_id }}</small>
        </div>

        <div class="col-md-4">
            <label class="form-label">المورد</label>
            <select v-model="form.supplier_id" class="form-select">
                <option value="">—</option>
                <option v-for="s in (isCreate ? suppliersForBranch : suppliers)" :key="s.id" :value="s.id">
                    {{ s.name }}
                </option>
            </select>
            <template v-if="isCreate">
                <small v-if="form.branch_id && suppliersForBranch.length === 0" class="text-warning d-block mt-1">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    لا يوجد موردون مرتبطون بهذا الفرع. اربط أحد الموردين به من
                    <a :href="urls.suppliersIndex" target="_blank" class="alert-link">شاشة الموردين</a>.
                </small>
                <small v-else-if="! form.branch_id" class="text-muted d-block mt-1">اختر فرعاً لتضييق قائمة الموردين.</small>
            </template>
            <small v-if="form.errors.supplier_id" class="ing-err">{{ form.errors.supplier_id }}</small>
        </div>

        <div class="col-md-4">
            <label class="form-label">التكلفة/وحدة *</label>
            <input v-model="form.cost_per_unit" type="number" step="0.0001"
                   class="form-control" :class="{ 'bg-light': costLocked }" required
                   :readonly="costLocked" :tabindex="costLocked ? -1 : undefined"
                   :title="costLocked ? 'تُدار آلياً كمتوسط مرجَّح من الاستلامات — للعرض فقط' : undefined">
            <small v-if="costLocked" class="text-muted d-block mt-1">
                متوسط مرجَّح يُحدَّث تلقائياً عند استلام المشتريات — لا يُعدَّل يدوياً.
            </small>
            <small v-else-if="ing" class="text-muted d-block mt-1">
                <i class="bi bi-unlock"></i>
                قابلة للتعديل الآن لتصحيح تكلفة البداية — تُقفل تلقائياً بعد أول حركة مخزون (كمية افتتاحية، تسوية، أو استلام) وتصبح متوسطاً مرجَّحاً يُحسب آلياً.
            </small>
            <small v-else class="text-warning d-block mt-1">
                <i class="bi bi-exclamation-triangle-fill"></i>
                أدخل التكلفة الحقيقية — القيمة 0 تفسد تقارير التكلفة.
            </small>
            <small v-if="form.errors.cost_per_unit" class="ing-err">{{ form.errors.cost_per_unit }}</small>
        </div>

        <div class="col-md-4">
            <label class="form-label">حد الطلب *</label>
            <input v-model="form.reorder_threshold" type="number" step="0.0001" class="form-control" required>
            <small v-if="form.errors.reorder_threshold" class="ing-err">{{ form.errors.reorder_threshold }}</small>
        </div>

        <div class="col-md-4">
            <div class="form-check mt-4">
                <input id="tracks-expiry" v-model="form.tracks_expiry" type="checkbox" class="form-check-input">
                <label class="form-check-label fw-semibold" for="tracks-expiry">تتبع تاريخ الصلاحية</label>
            </div>
            <small class="text-muted">الاستلام يحتاج تاريخًا، والدفعة المنتهية لا تدخل في المتاح.</small>
        </div>

        <div v-if="form.tracks_expiry" class="col-md-4">
            <label class="form-label">مدة الصلاحية المعتادة (يوم)</label>
            <input v-model="form.default_shelf_life_days" type="number" min="1" max="3650"
                   class="form-control" placeholder="مثلاً 30">
            <small class="text-muted d-block mt-1">اقتراح عند الاستلام، ويمكن تعديل التاريخ الفعلي.</small>
            <small v-if="form.errors.default_shelf_life_days" class="ing-err">{{ form.errors.default_shelf_life_days }}</small>
        </div>

        <div class="col-md-4">
            <label class="form-label">نسبة الاستفادة % <small class="text-muted">(اختياري)</small></label>
            <div class="input-group">
                <input v-model="form.yield_pct" type="number" step="0.01" min="0.01" max="100"
                       placeholder="100" class="form-control">
                <span class="input-group-text">%</span>
            </div>
            <small class="text-muted d-block mt-1">
                لو من كل 1kg تجلب 700g صالح بعد التنظيف، اكتب 70. اتركه فاضي = استفادة 100%.
            </small>
            <small v-if="form.errors.yield_pct" class="ing-err">{{ form.errors.yield_pct }}</small>
        </div>

        <div class="col-md-8 d-flex align-items-end gap-3 flex-wrap">
            <div class="form-check">
                <input id="track-stock" v-model="form.track_stock" type="checkbox" class="form-check-input">
                <label class="form-check-label" for="track-stock">تتبع المخزون</label>
            </div>
            <div class="form-check">
                <input id="ing-active" v-model="form.active" type="checkbox" class="form-check-input">
                <label class="form-check-label" for="ing-active">فعال</label>
            </div>
            <div class="form-check"
                 title="مكوّن مركّب: مكوّن له وصفة فرعية يُحلَّل تلقائياً عند الخصم. مثال: صلصة طحينة = 200g طحينة + 100ml ماء + 1 ليمون → 280g.">
                <input id="is_composite_check" v-model="form.is_composite" type="checkbox" class="form-check-input">
                <label class="form-check-label" for="is_composite_check">
                    <i class="bi bi-diagram-3"></i> مكوّن مركّب (له وصفة فرعية)
                </label>
            </div>
        </div>

        <div class="col-md-4">
            <label class="form-label">ناتج الوصفة الفرعية <small class="text-muted">(للمركّب فقط)</small></label>
            <input v-model="form.composite_yield" type="number" step="0.0001" min="0.0001"
                   placeholder="مثلاً 280" class="form-control">
            <small class="text-muted d-block mt-1">الكمية الناتجة من تنفيذ الوصفة كاملة، بالوحدة الأساسية للمكوّن.</small>
            <small v-if="form.errors.composite_yield" class="ing-err">{{ form.errors.composite_yield }}</small>
        </div>

        <div v-if="ing" class="col-md-4">
            <label class="form-label">المخزون الحالي</label>
            <input :value="ing.trackedStockLabel" class="form-control bg-light" readonly tabindex="-1">
            <small class="text-muted d-block mt-1">
                لتعديل الكمية: استخدم زر «تسجيل حركة» على شاشة المكونات (إدخال/خصم/هدر/تعديل) — يحافظ على سجل التتبّع.
            </small>
        </div>

        <div class="col-12">
            <label class="form-label">ملاحظات</label>
            <textarea v-model="form.notes" class="form-control" rows="2"></textarea>
        </div>

        <div class="col-12 form-actions">
            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                {{ form.processing ? 'جارٍ الحفظ…' : 'حفظ' }}
            </button>
            <a :href="urls.index" class="btn btn-light">إلغاء</a>
        </div>
    </form>
</template>

<style scoped>
.ing-home {
    background: linear-gradient(135deg, rgba(15, 71, 49, .04), rgba(15, 71, 49, .01));
    border: 1px solid rgba(15, 71, 49, .12);
    border-radius: .6rem;
    padding: 1rem 1.1rem;
}
.ing-err { display: block; margin-top: .2rem; color: #dc2626; font-size: .76rem; font-weight: 700; }
.form-control, .form-select { min-height: 44px; }
.form-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.form-actions .btn { min-height: 44px; min-width: 110px; }
.form-check-input { width: 1.2rem; height: 1.2rem; }
</style>
