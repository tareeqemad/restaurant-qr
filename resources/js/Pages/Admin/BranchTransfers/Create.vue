<script setup>
/**
 * تحويل بين الفروع — إنشاء.
 *
 * A multi-line picker: one row per ingredient with a quantity, an optional
 * source warehouse and a destination warehouse. Everything the row needs to
 * warn about — available stock, the reorder threshold it would breach, the
 * branch's weighted-average cost — is seeded by the controller so the row
 * reacts without a round-trip.
 *
 * The screen only ever saves a DRAFT. Stock moves later, when someone
 * presses «إرسال» on the show page.
 *
 * Two invariants worth stating:
 *  - The submit button is gated by `blockedReason`, but the SERVER is the
 *    authority: store() re-validates every field and the service re-checks
 *    stock at send time. This gate is courtesy, not security.
 *  - Only rows the summary counts (ok/warn) are posted. A half-filled row
 *    is dropped rather than sent as `ingredient_id: 0`, which the
 *    controller's `exists:` rule would bounce as a 422.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useToast } from '../../../Composables/useToast';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    branches: { type: Array, required: true },
    ingredients: { type: Array, required: true },
    locationsByBranch: { type: Object, required: true },
    hasAnyLocation: { type: Boolean, default: false },
    stockByBranch: { type: Object, required: true },
    stockByLocation: { type: Object, required: true },
    thresholdByBranchSum: { type: Object, required: true },
    thresholdByLocation: { type: Object, required: true },
    costByBranch: { type: Object, required: true },
    currency: { type: Object, required: true },
    old: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const toast = useToast();
const page = usePage();
const errorList = computed(() => Object.values(page.props.errors ?? {}));

const EPS = 0.0001;

// ── State ────────────────────────────────────────────────────────────
let keySeq = 0;
const newKey = () => ++keySeq;

const blankLine = () => ({
    _key: newKey(),
    ingredient_id: 0,
    quantity_base: 1,
    from_location_id: '',
    to_location_id: '',
    notes: '',
});

const fromBranchId = ref(Number(props.old.from_branch_id) || 0);
const toBranchId = ref(Number(props.old.to_branch_id) || 0);
const notes = ref(props.old.notes ?? '');

// Rehydrate after a failed store() — `back()->withInput()` is the error
// path and a picker that loses ten typed rows on a 422 is worse than none.
const seeded = (props.old.lines ?? []).map((l) => ({
    _key: newKey(),
    ingredient_id: Number(l.ingredient_id) || 0,
    quantity_base: Number(l.quantity_base) || 0,
    from_location_id: Number(l.from_location_id) || '',
    to_location_id: Number(l.to_location_id) || '',
    notes: l.notes ?? '',
}));
const lines = reactive(seeded.length ? seeded : [blankLine()]);

// ── Lookups ──────────────────────────────────────────────────────────
const ingredientById = computed(() => new Map(props.ingredients.map((i) => [i.id, i])));

const branchHasStock = (branchId) => {
    const map = props.stockByBranch[branchId] ?? {};
    return Object.values(map).some((q) => Number(q) > 0);
};

const locationsForBranch = (branchId) => {
    if (! branchId) return [];
    return props.locationsByBranch[branchId] ?? [];
};

/** Ingredients with stock > 0 at the source branch. */
const ingredientsAtFromBranch = computed(() => {
    if (! fromBranchId.value) return [];
    const stockMap = props.stockByBranch[fromBranchId.value] ?? {};
    return props.ingredients.filter((i) => Number(stockMap[i.id] ?? 0) > 0);
});

const ingredientUnit = (id) => ingredientById.value.get(Number(id))?.unit_code ?? '';

const stockForIngredientAtSource = (ingredientId, locationId) => {
    if (! ingredientId || ! fromBranchId.value) return 0;
    if (locationId) {
        return Number((props.stockByLocation[locationId] ?? {})[ingredientId] ?? 0);
    }
    return Number((props.stockByBranch[fromBranchId.value] ?? {})[ingredientId] ?? 0);
};

/** Available stock for a row, narrowed by from_location_id when set. */
const availableForLine = (line) => stockForIngredientAtSource(line.ingredient_id, line.from_location_id || null);

/** Threshold to compare the post-transfer remainder against. */
const thresholdForLine = (line) => {
    if (! line.ingredient_id || ! fromBranchId.value) return 0;
    // Per-location threshold wins when a source location is chosen.
    if (line.from_location_id) {
        const locThr = (props.thresholdByLocation[line.from_location_id] ?? {})[line.ingredient_id];
        if (locThr) return Number(locThr);
        return Number(ingredientById.value.get(line.ingredient_id)?.global_threshold ?? 0);
    }
    // Branch level: sum of per-location thresholds, else the global one.
    const branchSum = (props.thresholdByBranchSum[fromBranchId.value] ?? {})[line.ingredient_id];
    if (branchSum && Number(branchSum) > 0) return Number(branchSum);
    return Number(ingredientById.value.get(line.ingredient_id)?.global_threshold ?? 0);
};

const remainingAfter = (line) => availableForLine(line) - (Number(line.quantity_base) || 0);
const willDeplete = (line) => remainingAfter(line) <= EPS;
const willGoBelowThreshold = (line) => {
    const thr = thresholdForLine(line);
    if (thr <= 0) return false;
    return remainingAfter(line) < thr;
};

// ── Cost ─────────────────────────────────────────────────────────────
const unitCostForIngredient = (ingredientId) => {
    if (! fromBranchId.value || ! ingredientId) return 0;
    const branchCost = (props.costByBranch[fromBranchId.value] ?? {})[ingredientId];
    if (branchCost && Number(branchCost) > 0) return Number(branchCost);
    return Number(ingredientById.value.get(ingredientId)?.global_cost ?? 0);
};

const lineCost = (line) => unitCostForIngredient(line.ingredient_id) * (Number(line.quantity_base) || 0);

// ── Row status (drives the tint + the pills) ──────────────────────────
const lineStatus = (line) => {
    if (! line.ingredient_id) return 'pending';
    const qty = Number(line.quantity_base) || 0;
    const avail = availableForLine(line);
    if (qty <= 0) return 'pending';
    if (qty > avail || avail <= 0) return 'error';
    if (willGoBelowThreshold(line)) return 'warn';
    return 'ok';
};

const counts = (line) => lineStatus(line) === 'ok' || lineStatus(line) === 'warn';

// ── Row CRUD ─────────────────────────────────────────────────────────
const addLine = () => lines.push(blankLine());
const removeLine = (idx) => {
    lines.splice(idx, 1);
    if (lines.length === 0) lines.push(blankLine());
};

const fillAll = (line) => { line.quantity_base = availableForLine(line); };

// ── Branch change side effects ───────────────────────────────────────
const onFromBranchChange = () => {
    // Drop ingredients that no longer have stock at the new source, and
    // reset source locations (they're branch-bound).
    const validIds = new Set(ingredientsAtFromBranch.value.map((i) => i.id));
    lines.forEach((l) => {
        if (! (l.ingredient_id && validIds.has(l.ingredient_id))) l.ingredient_id = 0;
        l.from_location_id = '';
    });
    if (lines.length === 0) lines.push(blankLine());
};

const onToBranchChange = () => {
    // Auto-pick the default destination location so stock has somewhere to
    // land. The list is server-sorted with is_default first.
    const defaultLoc = (locationsForBranch(toBranchId.value)[0] ?? {}).id ?? '';
    lines.forEach((l) => { l.to_location_id = defaultLoc; });
};

// ── Aggregates ───────────────────────────────────────────────────────
const validLines = computed(() => lines.filter(counts));
const validLineCount = computed(() => validLines.value.length);
const warningLineCount = computed(() => lines.filter((l) => lineStatus(l) === 'warn').length);
const errorLineCount = computed(() => lines.filter((l) => lineStatus(l) === 'error').length);
const totalQty = computed(() => validLines.value.reduce((s, l) => s + (Number(l.quantity_base) || 0), 0));
const totalCost = computed(() => validLines.value.reduce((s, l) => s + lineCost(l), 0));

// ── Submit gating ────────────────────────────────────────────────────
const blockedReason = computed(() => {
    if (! fromBranchId.value) return 'اختر فرع المصدر.';
    if (! toBranchId.value) return 'اختر فرع الوجهة.';
    if (fromBranchId.value === toBranchId.value) return 'فرع المصدر والوجهة لا يمكن أن يكونا نفس الفرع.';
    if (locationsForBranch(toBranchId.value).length === 0) {
        return 'فرع الوجهة لا يحتوي على أي موقع تخزين — أنشئ موقعاً واحداً على الأقل أولاً.';
    }
    if (ingredientsAtFromBranch.value.length === 0) return 'لا يوجد مخزون قابل للنقل في فرع المصدر.';
    if (errorLineCount.value > 0) return 'هناك بنود تتجاوز المخزون المتاح — صحّح الكميات.';
    if (validLineCount.value === 0) return 'أضف بنداً واحداً صالحاً على الأقل.';
    return '';
});

const saving = ref(false);
const submit = () => {
    if (blockedReason.value) { toast.warning(blockedReason.value); return; }
    if (saving.value) return;
    saving.value = true;

    router.post(props.urls.store, {
        from_branch_id: fromBranchId.value,
        to_branch_id: toBranchId.value,
        notes: notes.value || null,
        lines: validLines.value.map((l) => ({
            ingredient_id: l.ingredient_id,
            quantity_base: Number(l.quantity_base) || 0,
            from_location_id: l.from_location_id || null,
            to_location_id: l.to_location_id || null,
            notes: l.notes || null,
        })),
    }, {
        onFinish: () => { saving.value = false; },
    });
};

// ── Formatting ───────────────────────────────────────────────────────
const fmtQty = (v) => {
    const n = Number(v) || 0;
    if (Math.abs(n - Math.round(n)) < EPS) return String(Math.round(n));
    return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
};
const fmtMoney = (v) => Number(v || 0).toLocaleString('en-US', {
    minimumFractionDigits: props.currency.decimals ?? 2,
    maximumFractionDigits: props.currency.decimals ?? 2,
});
</script>

<template>
    <Head title="تحويل بين الفروع" />

    <PageHeader title="تحويل بين الفروع" icon="bi-arrow-left-right"
                subtitle="انقل المكونات بين فروع مختلفة. سيُحفظ كمسودة أولاً، ثم اضغط «إرسال» لخصم المخزون من المصدر."
                :crumbs="[{ label: 'التحويلات بين الفروع', url: urls.index }]" />

    <div v-if="errorList.length" class="alert alert-danger">
        <ul class="mb-0 ps-3">
            <li v-for="(msg, i) in errorList" :key="i">{{ msg }}</li>
        </ul>
    </div>

    <DataPanel title="الفروع" icon="bi-building">
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">من فرع <span class="text-danger">*</span></label>
                <select v-model="fromBranchId" class="form-select form-select-lg bt-control"
                        @change="onFromBranchChange">
                    <option :value="0">— اختر —</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">
                        {{ b.name }}{{ branchHasStock(b.id) ? '' : ' (لا مخزون)' }}
                    </option>
                </select>
                <div v-if="fromBranchId" class="form-text">
                    <i class="bi bi-box-seam text-muted me-1"></i>
                    {{ ingredientsAtFromBranch.length }} مكوّن متاح في هذا الفرع
                </div>
            </div>

            <div class="col-md-2 text-center">
                <i class="bi bi-arrow-left-circle-fill bt-arrow"></i>
            </div>

            <div class="col-md-5">
                <label class="form-label">إلى فرع <span class="text-danger">*</span></label>
                <select v-model="toBranchId" class="form-select form-select-lg bt-control"
                        @change="onToBranchChange">
                    <option :value="0">— اختر —</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id" :disabled="b.id === fromBranchId">
                        {{ b.name }}{{ b.id === fromBranchId ? ' (المصدر)' : '' }}
                    </option>
                </select>
                <div v-if="toBranchId && locationsForBranch(toBranchId).length === 0" class="form-text text-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    هذا الفرع لا يملك أي موقع تخزين. يجب
                    <a :href="urls.storageLocationsCreate" class="alert-link" target="_blank">إنشاء موقع تخزين</a>
                    قبل استلام التحويل، وإلا لن يظهر المخزون في الوجهة.
                </div>
                <div v-if="toBranchId && locationsForBranch(toBranchId).length > 0" class="form-text">
                    <i class="bi bi-box-seam text-muted me-1"></i>
                    {{ locationsForBranch(toBranchId).length }} موقع تخزين متاح
                </div>
            </div>

            <div class="col-12">
                <label class="form-label">ملاحظات <small class="text-muted">(اختياري)</small></label>
                <textarea v-model="notes" class="form-control" rows="2" maxlength="1000"
                          placeholder="سبب التحويل، تعليمات للسائق، إلخ..."></textarea>
            </div>
        </div>
    </DataPanel>

    <DataPanel title="البنود" icon="bi-list-ul" :count="lines.length">
        <template #actions>
            <button type="button" class="btn btn-light bt-btn"
                    :disabled="! fromBranchId || ingredientsAtFromBranch.length === 0"
                    @click="addLine">
                <i class="bi bi-plus-circle"></i> أضف بند
            </button>
        </template>

        <div class="bt-hint">
            <strong>«من مستودع»</strong> اختياري — لو حدّدته يُحسب المخزون المتاح من ذلك الموقع تحديداً.
            <strong>«إلى مستودع»</strong> مطلوب — اخترنا لك الموقع الافتراضي تلقائياً ويمكنك تغييره. بدون موقع وجهة، المخزون لن يظهر في الفرع المستلم.
            <span v-if="! hasAnyLocation" class="d-block mt-1">
                <i class="bi bi-info-circle"></i>
                لم يتم إنشاء أي مواقع تخزين بعد. يمكنك إدارتها من
                <a :href="urls.storageLocationsIndex" class="alert-link">شاشة مواقع التخزين</a>.
            </span>
        </div>

        <div v-if="! fromBranchId" class="bt-empty-state">
            <i class="bi bi-building-down"></i>
            اختر فرع المصدر أولاً لعرض المكونات المتاحة.
        </div>

        <div v-else-if="ingredientsAtFromBranch.length === 0" class="bt-empty-state">
            <i class="bi bi-inbox"></i>
            لا يوجد أي مكوّن بكميات قابلة للنقل في هذا الفرع.
            <div class="small mt-1">يجب أن يكون لدى الفرع مخزون فعلي قبل إنشاء التحويل.</div>
        </div>

        <div v-else class="table-responsive">
            <table class="table align-middle mb-0 bt-table">
                <thead class="bg-light">
                    <tr>
                        <th style="min-width: 260px;">المكوّن</th>
                        <th style="width: 150px;">الكمية</th>
                        <th>الوحدة</th>
                        <th style="min-width: 170px;"
                            title="موقع التخزين داخل فرع المصدر. اختياري — لو حدّدته يُحسب المتاح من ذلك الموقع تحديداً.">
                            من مستودع (اختياري)
                            <i class="bi bi-info-circle text-muted small bt-help"></i>
                        </th>
                        <th style="min-width: 170px;" title="موقع التخزين داخل فرع الوجهة. اختياري.">
                            إلى مستودع (اختياري)
                            <i class="bi bi-info-circle text-muted small bt-help"></i>
                        </th>
                        <th style="width: 130px;" class="text-end">التكلفة التقديرية</th>
                        <th style="width: 60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, i) in lines" :key="line._key" class="bt-line-row"
                        :class="{
                            'is-error': lineStatus(line) === 'error',
                            'is-warn': lineStatus(line) === 'warn',
                        }">
                        <td>
                            <select v-model="line.ingredient_id" class="form-select bt-control">
                                <option :value="0">— اختر —</option>
                                <option v-for="ing in ingredientsAtFromBranch" :key="ing.id" :value="ing.id">
                                    {{ ing.name }} — متاح: {{ fmtQty(stockForIngredientAtSource(ing.id, null)) }} {{ ing.unit_code }}
                                </option>
                            </select>

                            <div v-if="line.ingredient_id">
                                <div class="mt-1">
                                    <span class="bt-stock-pill"
                                          :class="{
                                            ok: availableForLine(line) > 0 && (Number(line.quantity_base) || 0) <= availableForLine(line) && ! willGoBelowThreshold(line),
                                            warn: willGoBelowThreshold(line) && (Number(line.quantity_base) || 0) <= availableForLine(line),
                                            error: (Number(line.quantity_base) || 0) > availableForLine(line),
                                            empty: availableForLine(line) <= 0,
                                          }">
                                        <i class="bi bi-box"></i>
                                        متاح: {{ fmtQty(availableForLine(line)) }} {{ ingredientUnit(line.ingredient_id) }}
                                    </span>
                                    <span v-if="(Number(line.quantity_base) || 0) > 0 && (Number(line.quantity_base) || 0) <= availableForLine(line)"
                                          class="bt-stock-pill ms-1"
                                          :class="willGoBelowThreshold(line) ? 'warn' : 'ok'">
                                        <i class="bi bi-arrow-down-right"></i>
                                        المتبقّي: {{ fmtQty(remainingAfter(line)) }}
                                    </span>
                                </div>

                                <div v-if="(Number(line.quantity_base) || 0) > availableForLine(line) && availableForLine(line) > 0"
                                     class="bt-line-msg error">
                                    <i class="bi bi-exclamation-octagon-fill"></i>
                                    <span>الكمية المطلوبة تتجاوز المتاح. الحد الأقصى:
                                        <strong>{{ fmtQty(availableForLine(line)) }}</strong>
                                        {{ ingredientUnit(line.ingredient_id) }}.
                                    </span>
                                </div>
                                <div v-if="availableForLine(line) <= 0" class="bt-line-msg error">
                                    <i class="bi bi-x-octagon-fill"></i>
                                    <span>لا مخزون متاح من هذا المكوّن في
                                        {{ line.from_location_id ? 'الموقع المختار' : 'فرع المصدر' }}.
                                    </span>
                                </div>

                                <div v-if="(Number(line.quantity_base) || 0) > 0 && (Number(line.quantity_base) || 0) <= availableForLine(line) && willDeplete(line)"
                                     class="bt-line-msg warn">
                                    <i class="bi bi-droplet-half"></i>
                                    <span>هذا التحويل سيُفرغ المخزون من هذا المكوّن في المصدر.</span>
                                </div>
                                <div v-if="(Number(line.quantity_base) || 0) > 0 && (Number(line.quantity_base) || 0) <= availableForLine(line) && willGoBelowThreshold(line) && ! willDeplete(line)"
                                     class="bt-line-msg warn">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <span>المتبقّي بعد التحويل سيكون تحت حد إعادة الطلب
                                        ({{ fmtQty(thresholdForLine(line)) }} {{ ingredientUnit(line.ingredient_id) }}).
                                    </span>
                                </div>
                            </div>
                        </td>

                        <td>
                            <input v-model.number="line.quantity_base" type="number" step="0.0001" min="0.0001"
                                   :max="availableForLine(line) || undefined"
                                   class="form-control text-end bt-control"
                                   :class="{ 'is-invalid': line.ingredient_id && (Number(line.quantity_base) || 0) > availableForLine(line) }">
                            <button v-if="line.ingredient_id && availableForLine(line) > 0 && (Number(line.quantity_base) || 0) !== availableForLine(line)"
                                    type="button" class="btn btn-link btn-sm p-0 mt-1 small text-decoration-none"
                                    title="انقل كل المتاح" @click="fillAll(line)">
                                <i class="bi bi-magic"></i> انقل الكل ({{ fmtQty(availableForLine(line)) }})
                            </button>
                        </td>

                        <td class="text-muted fs-13">{{ ingredientUnit(line.ingredient_id) }}</td>

                        <td>
                            <select v-model="line.from_location_id" class="form-select bt-control">
                                <option value="">— أي موقع —</option>
                                <option v-for="loc in locationsForBranch(fromBranchId)" :key="loc.id" :value="loc.id">
                                    {{ loc.name }}<template v-if="line.ingredient_id"> ({{ fmtQty(stockForIngredientAtSource(line.ingredient_id, loc.id)) }})</template>
                                </option>
                            </select>
                        </td>

                        <td>
                            <select v-model="line.to_location_id" class="form-select bt-control" :disabled="! toBranchId">
                                <option value="">— أي موقع —</option>
                                <option v-for="loc in locationsForBranch(toBranchId)" :key="loc.id" :value="loc.id">
                                    {{ loc.name }}
                                </option>
                            </select>
                        </td>

                        <td class="text-end fs-13">
                            <span v-if="line.ingredient_id && (Number(line.quantity_base) || 0) > 0">
                                {{ fmtMoney(lineCost(line)) }}
                                <small class="text-muted d-block bt-cur">{{ currency.symbol }}</small>
                            </span>
                            <span v-else class="text-muted">—</span>
                        </td>

                        <td>
                            <button type="button" class="btn btn-outline-danger bt-btn bt-btn--icon"
                                    title="حذف" @click="removeLine(i)">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <div class="row g-3 align-items-stretch">
                <div class="col-md-7">
                    <div v-if="fromBranchId && validLineCount > 0" class="bt-summary h-100">
                        <div class="row g-2 small">
                            <div class="col-6 col-md-4">
                                <div class="text-muted">عدد البنود الصالحة</div>
                                <div class="fs-5 fw-semibold">{{ validLineCount }}</div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="text-muted">إجمالي الكمية</div>
                                <div class="fs-5 fw-semibold">
                                    {{ fmtQty(totalQty) }}
                                    <small class="text-muted bt-note">(وحدات قاعدية)</small>
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="text-muted">القيمة التقديرية</div>
                                <div class="fs-5 fw-semibold">
                                    {{ fmtMoney(totalCost) }}
                                    <small class="text-muted bt-note">{{ currency.symbol }}</small>
                                </div>
                            </div>
                        </div>
                        <div v-if="warningLineCount > 0" class="mt-2 small text-muted">
                            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                            {{ warningLineCount }} بند سيُسبّب نقصاً تحت حد إعادة الطلب — راجع التنبيهات بالأعلى.
                        </div>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="bt-foot">
                        <a :href="urls.index" class="btn btn-light bt-btn">
                            <i class="bi bi-arrow-right"></i> إلغاء
                        </a>
                        <button type="button" class="btn btn-primary bt-btn bt-save"
                                :disabled="Boolean(blockedReason) || saving"
                                :title="blockedReason || ''" @click="submit">
                            <i class="bi" :class="saving ? 'bi-arrow-repeat bt-spin' : 'bi-save'"></i>
                            {{ saving ? 'جارٍ الحفظ…' : 'حفظ كمسودة' }}
                        </button>
                    </div>
                    <div v-if="blockedReason" class="text-end mt-2 small text-muted">
                        <i class="bi bi-info-circle"></i>
                        {{ blockedReason }}
                    </div>
                </div>
            </div>
        </template>
    </DataPanel>
</template>

<style scoped>
.bt-arrow { font-size: 2rem; color: var(--accent); }

.bt-hint {
    line-height: 1.55;
    font-size: .84rem;
    color: #64748b;
    background: rgba(15, 71, 49, .03);
    border: 1px solid rgba(15, 71, 49, .06);
    border-radius: .5rem;
    padding: .55rem .75rem;
    margin-bottom: .75rem;
}
.bt-help { cursor: help; }

.bt-line-row { transition: background-color .15s ease; }
.bt-line-row.is-error { background: rgba(220, 53, 69, .04); }
.bt-line-row.is-warn { background: rgba(255, 193, 7, .05); }

.bt-stock-pill {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    padding: .12rem .5rem;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
}
.bt-stock-pill.ok { background: rgba(25, 135, 84, .12); color: #157347; }
.bt-stock-pill.warn { background: rgba(255, 193, 7, .18); color: #997404; }
.bt-stock-pill.error { background: rgba(220, 53, 69, .12); color: #b02a37; }
.bt-stock-pill.empty { background: rgba(108, 117, 125, .12); color: #6c757d; }

.bt-line-msg {
    font-size: 12px;
    line-height: 1.4;
    margin-top: .25rem;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.bt-line-msg.error { color: #b02a37; }
.bt-line-msg.warn { color: #997404; }

.bt-summary {
    background: linear-gradient(135deg, rgba(15, 71, 49, .04), rgba(15, 71, 49, .01));
    border: 1px solid rgba(15, 71, 49, .08);
    border-radius: .5rem;
    padding: .85rem 1rem;
}
.bt-note { font-size: 11px; }
.bt-cur { font-size: 10.5px; }

.bt-empty-state { padding: 2rem 1rem; text-align: center; color: #6c757d; }
.bt-empty-state i { font-size: 2.25rem; opacity: .35; display: block; margin-bottom: .35rem; }

.bt-foot { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.bt-save { margin-inline-start: auto; font-weight: 800; }
.bt-spin { animation: bt-rotate 1s linear infinite; display: inline-block; }
@keyframes bt-rotate { to { transform: rotate(360deg); } }

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
.bt-table th { white-space: nowrap; }
</style>
