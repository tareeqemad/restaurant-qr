<script setup>
/**
 * «تسجيل حركة مخزون» — the modal the index rendered once per row and the
 * stock card promised but never shipped (its button pointed at #cardAdjust,
 * a modal that does not exist anywhere in the Blade tree).
 *
 * Posts to ingredients.adjust, which validates type/quantity/unit/reason,
 * refuses a unit whose measurement type differs from the ingredient's, and
 * demands an expiry date when receiving an expiry-tracked item. useForm
 * keeps every field on a 422 so a rejected movement is one fix away from
 * being re-submitted, not a re-typed form.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    open: { type: Boolean, default: false },
    // { name, currentStockLabel, currentStockExact, baseUnitId, costPerUnit,
    //   tracksExpiry, baseCode, actionUrl }
    ingredient: { type: Object, default: null },
    units: { type: Array, default: () => [] },
    today: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const form = useForm({
    type: 'in',
    quantity: '',
    unit_id: '',
    unit_cost: '',
    reason: '',
    expiry_date: '',
    storage_location_id: '',
});

// Re-seed whenever a different ingredient opens the sheet: the unit falls
// back to the ingredient's own base unit and the cost to its current
// average — exactly the defaults the Blade modal carried.
watch(() => [props.open, props.ingredient?.id], () => {
    if (! props.open || ! props.ingredient) return;
    form.clearErrors();
    form.type = 'in';
    form.quantity = '';
    form.unit_id = props.ingredient.baseUnitId ?? '';
    form.unit_cost = props.ingredient.costPerUnit ?? '';
    form.reason = '';
    form.expiry_date = '';
    const locations = props.ingredient.locations ?? [];
    const preferred = locations.find((location) => Number(location.id) === Number(props.ingredient.preferredLocationId));
    form.storage_location_id = preferred?.id
        ?? locations.find((location) => location.isDefault)?.id
        ?? locations[0]?.id
        ?? '';
});

const selectedLocation = computed(() => (props.ingredient?.locations ?? [])
    .find((location) => Number(location.id) === Number(form.storage_location_id)) ?? null);
const needsExpiry = computed(() => Boolean(props.ingredient?.tracksExpiry)
    && ['in', 'adjustment_in'].includes(form.type));

watch(selectedLocation, (location) => {
    if (! location) return;
    form.unit_cost = location.unitCost ?? props.ingredient?.costPerUnit ?? '';
});

const submit = () => {
    if (form.processing) return;
    form.post(props.ingredient.actionUrl, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Teleport to="body">
        <Transition name="adj">
            <div v-if="open && ingredient" class="adj-backdrop" @click.self="emit('close')">
                <div class="adj-card" role="dialog" aria-modal="true">
                    <header class="adj-head">
                        <h5>تعديل مخزون: {{ ingredient.name }}</h5>
                        <button type="button" class="adj-x" aria-label="إغلاق" @click="emit('close')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>

                    <form class="adj-body" @submit.prevent="submit">
                        <p class="adj-current">
                            رصيد الموقع المحدد:
                            <strong :title="`القيمة الأساسية: ${selectedLocation?.stockExact ?? ingredient.currentStockExact} ${ingredient.baseCode ?? ''}`">
                                {{ selectedLocation?.stockLabel ?? ingredient.currentStockLabel }}
                            </strong>
                        </p>

                        <div class="adj-field">
                            <label class="form-label">الفرع وموقع التخزين *</label>
                            <select v-model="form.storage_location_id" class="form-select" required>
                                <option value="" disabled>اختر موقع التخزين</option>
                                <option v-for="location in ingredient.locations ?? []" :key="location.id" :value="location.id">
                                    {{ location.label }} — {{ location.stockLabel }}
                                </option>
                            </select>
                            <small class="text-muted">كل حركة تُسجل على مخزن فرع واحد، ولا تغيّر رصيد أي فرع آخر.</small>
                            <small v-if="form.errors.storage_location_id" class="adj-err">{{ form.errors.storage_location_id }}</small>
                        </div>

                        <div class="adj-field">
                            <label class="form-label">نوع الحركة</label>
                            <select v-model="form.type" class="form-select" required>
                                <option value="in">إضافة (شراء)</option>
                                <option value="out">خصم (استهلاك)</option>
                                <option value="waste">هدر</option>
                                <option value="adjustment_in">تسوية زيادة جردية</option>
                                <option value="adjustment_out">تسوية نقص جردي</option>
                            </select>
                            <small v-if="form.errors.type" class="adj-err">{{ form.errors.type }}</small>
                        </div>

                        <div class="adj-row">
                            <div class="adj-field">
                                <label class="form-label">الكمية</label>
                                <input v-model="form.quantity" type="number" step="0.0001" class="form-control" required>
                                <small v-if="form.errors.quantity" class="adj-err">{{ form.errors.quantity }}</small>
                            </div>
                            <div class="adj-field">
                                <label class="form-label">الوحدة</label>
                                <select v-model="form.unit_id" class="form-select" required>
                                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.label }}</option>
                                </select>
                                <small v-if="form.errors.unit_id" class="adj-err">{{ form.errors.unit_id }}</small>
                            </div>
                        </div>

                        <div class="adj-field">
                            <label class="form-label">التكلفة للوحدة (اختياري)</label>
                            <input v-model="form.unit_cost" type="number" step="0.0001" class="form-control">
                            <small v-if="form.errors.unit_cost" class="adj-err">{{ form.errors.unit_cost }}</small>
                        </div>

                        <div v-if="ingredient.tracksExpiry" class="adj-field">
                            <label class="form-label">تاريخ الصلاحية</label>
                            <input v-model="form.expiry_date" type="date" :min="today" class="form-control"
                                   :required="needsExpiry">
                            <small class="text-muted">مطلوب عند اختيار «إضافة» فقط.</small>
                            <small v-if="form.errors.expiry_date" class="adj-err">{{ form.errors.expiry_date }}</small>
                        </div>

                        <div class="adj-field">
                            <label class="form-label">السبب *</label>
                            <input v-model="form.reason" class="form-control" maxlength="255" required>
                            <small v-if="form.errors.reason" class="adj-err">{{ form.errors.reason }}</small>
                        </div>

                        <footer class="adj-foot">
                            <button type="button" class="btn btn-light" @click="emit('close')">إلغاء</button>
                            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                {{ form.processing ? 'جارٍ التسجيل…' : 'تسجيل' }}
                            </button>
                        </footer>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.adj-backdrop {
    position: fixed;
    inset: 0;
    z-index: 18500;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, .45);
    overflow-y: auto;
}
.adj-card {
    width: 100%;
    max-width: 480px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 60px -18px rgba(15, 23, 42, .4);
    max-height: 92vh;
    display: flex;
    flex-direction: column;
}
.adj-head {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: 1rem 1.1rem .6rem;
    border-block-end: 1px solid #eef0f3;
}
.adj-head h5 { margin: 0; font-size: 1rem; font-weight: 800; color: #0f172a; flex: 1; }
.adj-x {
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 10px;
    background: #f3f4f6;
    color: #475569;
    cursor: pointer;
}
.adj-body { padding: 1rem 1.1rem 1.1rem; overflow-y: auto; }
.adj-current { font-size: .86rem; color: #475569; margin-bottom: .8rem; }
.adj-field { display: flex; flex-direction: column; margin-bottom: .7rem; }
.adj-field .form-label { font-size: .82rem; font-weight: 700; color: #334155; margin-bottom: .25rem; }
.adj-field .form-control,
.adj-field .form-select { min-height: 44px; }
.adj-row { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
.adj-err { color: #dc2626; font-size: .76rem; font-weight: 700; margin-top: .2rem; }
.adj-foot { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
.adj-foot .btn { min-height: 44px; min-width: 96px; }

.adj-enter-active, .adj-leave-active { transition: opacity .15s; }
.adj-enter-from, .adj-leave-to { opacity: 0; }
</style>
