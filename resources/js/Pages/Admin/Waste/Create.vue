<script setup>
/**
 * تسجيل هدر — Wave 4. Everything the form needs was seeded once by the
 * server; the only round-trip before submit is the FIFO batch list of the
 * picked ingredient.
 *
 * Guardrails that must not soften: the unit dropdown only offers units of
 * the ingredient's own type (picking ml for a gram ingredient would blow
 * up the conversion server-side), and the live "available" figure follows
 * the chosen location so the quantity warning matches what the server
 * will actually validate against.
 */
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useToast } from '../../../Composables/useToast';
import { formPost } from '../../../Support/formPost';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ingredients: { type: Array, required: true },
    units: { type: Array, required: true },
    reasons: { type: Array, required: true },
    locations: { type: Array, required: true },
    preselectedIngredientId: { type: Number, default: 0 },
    submitToken: { type: String, required: true },
    canManage: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const toast = useToast();

// ── Form state ───────────────────────────────────────────────────────
const locationId = ref(props.locations.find((l) => l.isDefault)?.id ?? props.locations[0]?.id ?? 0);
const ingredientId = ref(props.preselectedIngredientId || 0);
const search = ref('');
const quantity = ref('');
const unitId = ref(0);
const reasonId = ref(0);
const batchId = ref(0);
const notes = ref('');
const submitting = ref(false);

const batches = ref([]);
const loadingBatches = ref(false);

// ── Derived ──────────────────────────────────────────────────────────
const ingredient = computed(() => props.ingredients.find((i) => i.id === ingredientId.value) ?? null);
const reason = computed(() => props.reasons.find((r) => r.id === reasonId.value) ?? null);

// Location filter: only ingredients that actually have stock there.
const visibleIngredients = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.ingredients.filter((i) => {
        const inLocation = locationId.value > 0 && Number(i.stocks?.[locationId.value] ?? 0) > 0;
        const matches = ! q || `${i.name} ${i.sku ?? ''}`.toLowerCase().includes(q);
        return inLocation && matches;
    });
});

// Same type only — the server's UnitConverter would throw otherwise.
const usableUnits = computed(() => {
    if (! ingredient.value) return [];
    return props.units.filter((u) => u.unitType === ingredient.value.baseUnitType);
});

const available = computed(() => {
    if (! ingredient.value) return 0;
    if (batchId.value) {
        return Number(batches.value.find((b) => b.id === batchId.value)?.remaining_qty ?? 0);
    }
    return Number(ingredient.value.stocks?.[locationId.value] ?? 0);
});

// Cost preview uses the batch's own unit cost when a batch is picked —
// exactly what the server will charge the waste at.
const unitCost = computed(() => {
    if (! ingredient.value) return 0;
    if (batchId.value) {
        return Number(batches.value.find((b) => b.id === batchId.value)?.unit_cost ?? ingredient.value.costPerUnit);
    }
    return Number(ingredient.value.costs?.[locationId.value] ?? ingredient.value.costPerUnit);
});

// Entered quantity converted to the ingredient's BASE unit — mirrors
// UnitConverter::convert so the preview and the availability check speak
// the same language the server validates in. (The retired screen skipped
// this conversion entirely: typing "2 kg" against a gram ingredient
// previewed 2× the per-gram cost and compared 2 against a 5000 g cap.)
const qtyBase = computed(() => {
    const entered = Number(quantity.value || 0);
    if (! entered || ! ingredient.value) return 0;

    const from = props.units.find((u) => u.id === unitId.value);
    const base = props.units.find((u) => u.id === ingredient.value.baseUnitId);
    const fromFactor = Number(from?.factorToBase || 0);
    const baseFactor = Number(base?.factorToBase || 0);
    if (! fromFactor || ! baseFactor) return entered;   // unknown → don't lie

    return (entered * fromFactor) / baseFactor;
});

const costPreview = computed(() => qtyBase.value * unitCost.value);

const overAvailable = computed(() => qtyBase.value > available.value + 0.0001);

const problem = computed(() => {
    if (! locationId.value) return 'اختر موقع المخزون الذي حصل فيه الهدر.';
    if (! ingredientId.value) return 'اختر المكوّن.';
    if (! reasonId.value) return 'اختر سبب الهدر.';
    if (! (Number(quantity.value) > 0)) return 'اكتب الكمية.';
    if (! unitId.value) return 'اختر الوحدة.';
    if (overAvailable.value) return 'الكمية أكبر من المتوفّر.';
    return null;
});

// ── Reactions ────────────────────────────────────────────────────────
const fetchBatches = async () => {
    batches.value = [];
    batchId.value = 0;
    if (! ingredientId.value) return;

    loadingBatches.value = true;
    try {
        const url = props.urls.batches.replace(/0(\?|$)/, `${ingredientId.value}$1`)
            + (locationId.value ? `?storage_location_id=${locationId.value}` : '');
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        batches.value = res.ok ? await res.json() : [];

        // «انتهت الصلاحية» → pre-pick the oldest expired lot so the write-off
        // lands on the batch that actually caused it.
        if (reason.value?.code === 'expired') {
            const expired = batches.value.find((b) => b.is_expired);
            if (expired) batchId.value = expired.id;
        }
    } catch {
        batches.value = [];
    } finally {
        loadingBatches.value = false;
    }
};

watch(ingredientId, () => {
    // Default the unit to the ingredient's own base unit.
    unitId.value = ingredient.value?.baseUnitId ?? 0;
    fetchBatches();
}, { immediate: true });

watch(locationId, () => {
    // The picked ingredient may not exist in the new location.
    if (ingredientId.value
        && ! (Number(ingredient.value?.stocks?.[locationId.value] ?? 0) > 0)) {
        ingredientId.value = 0;
    }
    fetchBatches();
});

watch(reasonId, () => {
    if (reason.value?.code === 'expired') {
        const expired = batches.value.find((b) => b.is_expired);
        if (expired) batchId.value = expired.id;
    }
});

const submit = () => {
    if (problem.value || submitting.value || ! props.canManage) {
        if (problem.value) toast.warning(problem.value);
        return;
    }
    submitting.value = true;

    // Classic POST — the server owns the transaction, the three validation
    // paths (batch / location / global), the movement, and the redirect.
    formPost(props.urls.store, {
        request_token: props.submitToken,
        ingredient_id: ingredientId.value,
        batch_id: batchId.value || '',
        quantity: quantity.value,
        unit_id: unitId.value,
        storage_location_id: locationId.value || '',
        reason_lookup_id: reasonId.value,
        notes: notes.value.trim(),
    });
};

const fmt = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
</script>

<template>
    <Head title="تسجيل هدر" />

    <PageHeader title="تسجيل هدر" icon="bi-trash3"
                subtitle="سجّل أي كمية خرجت من المخزون بدون بيع — النظام يخصم المخزون فوراً ويصنّف الهدر في التقارير">
        <template #actions>
            <a :href="urls.index" class="btn btn-light"><i class="bi bi-list-ul"></i> سجل الهدر</a>
        </template>
    </PageHeader>

    <div v-if="! canManage" class="wl-denied">
        <i class="bi bi-lock-fill"></i>
        <div>
            <strong>ما عندك صلاحية تسجيل هدر</strong>
            <span>تسجيل الهدر بيخصم مخزون فعلي — محصور بالإدارة. اطلب من المدير.</span>
        </div>
    </div>

    <DataPanel title="تفاصيل الحدث" icon="bi-pencil-square">
        <div class="wl-grid">
            <!-- 1. Location — drives which ingredients are even offered -->
            <label class="wl-field">
                <span><i class="bi bi-1-circle-fill"></i> موقع الهدر</span>
                <select v-model.number="locationId" class="form-select">
                    <option :value="0" disabled>— اختر موقع المخزون —</option>
                    <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.label }}</option>
                </select>
                <small>كل هدر يُخصم من موقع فعلي محدد حتى يبقى مخزون كل فرع وموقع دقيقاً.</small>
            </label>

            <!-- 2. Ingredient -->
            <label class="wl-field">
                <span><i class="bi bi-2-circle-fill"></i> المكوّن</span>
                <input v-model="search" class="form-control wl-search" placeholder="🔍 ابحث بالاسم أو SKU…">
                <select v-model.number="ingredientId" class="form-select" size="6">
                    <option :value="0">— اختر مكوّن —</option>
                    <option v-for="i in visibleIngredients" :key="i.id" :value="i.id">
                        {{ i.name }} — متوفّر {{ fmt(i.stocks?.[locationId] ?? 0) }} {{ i.unitCode }}
                    </option>
                </select>
                <small v-if="visibleIngredients.length === 0" class="wl-warn">
                    ما في مكوّنات فيها رصيد بهذا الموقع.
                </small>
            </label>

            <!-- 3. Reason -->
            <div class="wl-field wl-field--wide">
                <span><i class="bi bi-3-circle-fill"></i> سبب الهدر</span>
                <div class="wl-reasons">
                    <button v-for="r in reasons" :key="r.id" type="button"
                            class="wl-reason" :class="{ 'is-on': reasonId === r.id }"
                            :style="r.color ? { '--r-color': r.color } : null"
                            @click="reasonId = r.id">
                        <i v-if="r.icon" class="bi" :class="r.icon"></i>
                        {{ r.label }}
                    </button>
                </div>
                <small>السبب بيغذّي تقارير الفقد — اختياره الصح بيوفّر مصاري.</small>
            </div>

            <!-- 4. Quantity + unit -->
            <label class="wl-field">
                <span><i class="bi bi-4-circle-fill"></i> الكمية</span>
                <div class="wl-qty">
                    <input v-model="quantity" type="number" step="0.0001" min="0.0001"
                           class="form-control" :class="{ 'is-invalid': overAvailable }" placeholder="0">
                    <select v-model.number="unitId" class="form-select" :disabled="! ingredient">
                        <option :value="0">الوحدة</option>
                        <option v-for="u in usableUnits" :key="u.id" :value="u.id">{{ u.label }}</option>
                    </select>
                </div>
                <small v-if="ingredient" :class="overAvailable ? 'wl-warn' : ''">
                    المتوفّر: {{ fmt(available) }} {{ ingredient.unitCode }}
                    <template v-if="qtyBase > 0 && unitId !== ingredient.baseUnitId">
                        · يعادل {{ fmt(qtyBase) }} {{ ingredient.unitCode }}
                    </template>
                    <template v-if="overAvailable"> — الكمية أكبر من المتوفّر</template>
                </small>
            </label>

            <!-- 5. Batch (optional) -->
            <label class="wl-field">
                <span><i class="bi bi-5-circle-fill"></i> الدفعة <small>(اختياري)</small></span>
                <select v-model.number="batchId" class="form-select" :disabled="! ingredient || loadingBatches">
                    <option :value="0">بدون تحديد — FIFO تلقائي</option>
                    <option v-for="b in batches" :key="b.id" :value="b.id">
                        {{ b.batch_number ? `#${b.batch_number} · ` : '' }}متبقي {{ fmt(b.remaining_qty) }}{{ b.expiry_date ? ` · ينتهي ${b.expiry_date}` : '' }}{{ b.is_expired ? ' ⚠️ منتهية' : '' }}
                    </option>
                </select>
                <small v-if="loadingBatches">جارٍ تحميل الدفعات…</small>
                <small v-else-if="batches.length === 0 && ingredient">ما في دفعات مسجّلة — الخصم رح يمشي FIFO.</small>
            </label>

            <label class="wl-field wl-field--wide">
                <span>ملاحظات <small>(اختياري)</small></span>
                <textarea v-model="notes" rows="2" maxlength="500" class="form-control"
                          placeholder="تفاصيل تساعد بالمراجعة لاحقاً…"></textarea>
            </label>
        </div>

        <template #footer>
            <div class="wl-foot">
                <div v-if="costPreview > 0" class="wl-preview">
                    <span>القيمة المقدّرة للهدر</span>
                    <strong>{{ fmt(costPreview) }}</strong>
                    <small v-if="batchId">بسعر الدفعة</small>
                    <small v-else>بمتوسط تكلفة المكوّن</small>
                </div>
                <div v-else></div>
                <button type="button" class="btn btn-danger btn-lg wl-submit"
                        :disabled="Boolean(problem) || submitting || ! canManage" @click="submit">
                    <i class="bi" :class="submitting ? 'bi-arrow-repeat' : 'bi-trash3-fill'"></i>
                    {{ submitting ? 'جارٍ التسجيل…' : 'سجّل الهدر واخصم المخزون' }}
                </button>
            </div>
        </template>
    </DataPanel>
</template>

<style scoped>
.wl-denied {
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
.wl-denied i { font-size: 1.2rem; }
.wl-denied strong { display: block; }

.wl-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
    gap: 1rem;
}
.wl-field { display: flex; flex-direction: column; gap: .4rem; margin: 0; }
.wl-field--wide { grid-column: 1 / -1; }
.wl-field > span {
    font-size: .86rem;
    font-weight: 800;
    color: #334155;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.wl-field > span i { color: rgb(var(--primary-rgb)); }
.wl-field > span small { font-weight: 600; color: #94a3b8; }
.wl-field small { font-size: .74rem; color: #94a3b8; }
.wl-field small.wl-warn { color: #b91c1c; font-weight: 700; }
.wl-search { margin-bottom: .2rem; }

.wl-qty { display: flex; gap: .5rem; }
.wl-qty input { flex: 1 1 60%; }
.wl-qty select { flex: 1 1 40%; }

.wl-reasons { display: flex; flex-wrap: wrap; gap: .45rem; }
.wl-reason {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    min-height: 44px;
    padding: 0 .9rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .84rem;
    font-weight: 700;
    cursor: pointer;
}
.wl-reason.is-on {
    border-color: var(--r-color, rgb(var(--primary-rgb)));
    background: color-mix(in srgb, var(--r-color, rgb(var(--primary-rgb))) 10%, #fff);
    color: var(--r-color, rgb(var(--primary-rgb)));
}

.wl-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.wl-preview { display: flex; align-items: baseline; gap: .5rem; }
.wl-preview span { font-size: .82rem; color: #64748b; font-weight: 700; }
.wl-preview strong { font-size: 1.3rem; font-weight: 900; color: #b91c1c; }
.wl-preview small { font-size: .74rem; color: #94a3b8; }
.wl-submit { min-height: 52px; font-weight: 800; }
</style>
