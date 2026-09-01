<script setup>
/**
 * تعديل مكوّن — three independent forms on one screen, exactly as the
 * Blade had them, because each posts to its own endpoint:
 *
 *   1. the catalogue form (shared IngredientForm)
 *   2. وحدات الصنف — pack sizes. The whole list is replaced on save, so
 *      removing a row here really removes the unit. Barcodes are globally
 *      unique; the server names the clashing ingredient when they aren't.
 *   3. الوصفة الفرعية — only for a composite. Also a full replace.
 *
 * Exactly one pack may be the default purchase unit — the radio group
 * enforces it in the UI and the controller re-checks it, so a hand-made
 * POST cannot break the invariant either.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import IngredientForm from '../../../Components/Ingredients/IngredientForm.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ingredient: { type: Object, required: true },
    units: { type: Array, required: true },
    suppliers: { type: Array, required: true },
    supplierIdsByBranch: { type: Object, default: () => ({}) },
    branches: { type: Array, default: () => [] },
    locationsByBranch: { type: Object, default: () => ({}) },
    activeBranchId: { type: [Number, String], default: null },
    canCreateBranch: { type: Boolean, default: false },
    costLocked: { type: Boolean, default: false },
    unitLocked: { type: Boolean, default: false },
    packUnits: { type: Array, default: () => [] },
    subRecipeLines: { type: Array, default: () => [] },
    subRecipeIngredients: { type: Array, default: () => [] },
    subRecipeUnits: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
    editUrls: { type: Object, required: true },
});

// ── Pack sizes ───────────────────────────────────────────────────────
const blankPack = () => ({
    name: '',
    factor_to_base: '',
    barcode: '',
    purchase_price: '',
    sale_price: '',
    is_default_purchase: false,
});

const packForm = useForm({ units: props.packUnits.map((u) => ({ ...u })) });

const addPack = () => packForm.units.push(blankPack());
const removePack = (idx) => packForm.units.splice(idx, 1);
const markDefaultPack = (idx) => packForm.units.forEach((u, i) => { u.is_default_purchase = i === idx; });

const savePacks = () => {
    if (packForm.processing) return;
    packForm.transform((data) => ({
        units: data.units.map((u) => ({
            name: u.name,
            factor_to_base: u.factor_to_base,
            barcode: u.barcode === '' ? null : u.barcode,
            purchase_price: u.purchase_price === '' ? null : u.purchase_price,
            sale_price: u.sale_price === '' ? null : u.sale_price,
            is_default_purchase: Boolean(u.is_default_purchase),
        })),
    })).post(props.editUrls.unitsUpdate, { preserveScroll: true });
};

// ── Sub-recipe (composite only) ──────────────────────────────────────
const recipeForm = useForm({
    lines: props.subRecipeLines.map((l) => ({ ...l })),
});

const addLine = () => recipeForm.lines.push({
    ingredient_id: props.subRecipeIngredients[0]?.id ?? '',
    quantity: '',
    unit_id: props.subRecipeUnits[0]?.id ?? '',
});
const removeLine = (idx) => recipeForm.lines.splice(idx, 1);

const saveRecipe = () => {
    if (recipeForm.processing) return;
    recipeForm.post(props.editUrls.subRecipeUpdate, { preserveScroll: true });
};

const baseCode = computed(() => props.ingredient.baseUnitCode ?? '');

// Server-side errors on the pack/recipe editors arrive keyed by index
// (`units.0.name`); surface them next to the row that caused them.
const packError = (idx, field) => packForm.errors[`units.${idx}.${field}`];
const lineError = (idx, field) => recipeForm.errors[`lines.${idx}.${field}`];
</script>

<template>
    <Head :title="`تعديل: ${ingredient.name}`" />

    <PageHeader :title="`تعديل: ${ingredient.name}`" icon="bi-pencil-square"
                :crumbs="[{ label: 'المكونات', url: urls.index }]" />

    <DataPanel title="النموذج" icon="bi-pencil-square">
        <template #actions>
            <a :href="urls.index" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
        </template>

        <IngredientForm mode="edit"
                        :ingredient="ingredient"
                        :units="units"
                        :suppliers="suppliers"
                        :supplier-ids-by-branch="supplierIdsByBranch"
                        :branches="branches"
                        :locations-by-branch="locationsByBranch"
                        :active-branch-id="activeBranchId"
                        :can-create-branch="canCreateBranch"
                        :cost-locked="costLocked"
                        :unit-locked="unitLocked"
                        :today="today"
                        :submit-url="editUrls.update"
                        :urls="urls" />
    </DataPanel>

    <!-- ════ Alternate units (pack sizes) ════ -->
    <DataPanel title="وحدات الصنف (أحجام العبوات)" icon="bi-boxes" class="mt-3">
        <div class="alert alert-info small">
            <i class="bi bi-info-circle"></i>
            الوحدة الأساسية: <strong>{{ ingredient.baseUnitName || '—' }} ({{ baseCode }})</strong>.
            أضف هنا أحجام عبوات أخرى تشتري/تبيع بها (مثلاً: <em>كرتون 24 علبة</em>،
            <em>بالة 144 علبة</em>). عمود <strong>"تعادل"</strong> = كم وحدة أساسية في عبوة واحدة.
            المخزون يبقى محسوب بالوحدة الأساسية دائماً — العبوات شيء مختصر للشراء فقط.
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>اسم الوحدة <span class="text-danger">*</span></th>
                        <th class="text-end" style="width:130px">تعادل ({{ baseCode }})</th>
                        <th style="width:170px">باركود</th>
                        <th class="text-end" style="width:130px">سعر الشراء</th>
                        <th class="text-end" style="width:130px">سعر البيع</th>
                        <th class="text-center" style="width:100px">افتراضي شراء</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(u, idx) in packForm.units" :key="idx">
                        <td>
                            <input v-model="u.name" type="text" required class="form-control form-control-sm"
                                   placeholder="مثلاً: كرتون 24 علبة">
                            <small v-if="packError(idx, 'name')" class="ing-err">{{ packError(idx, 'name') }}</small>
                        </td>
                        <td>
                            <input v-model="u.factor_to_base" type="number" step="0.0001" min="0.0001" required
                                   class="form-control form-control-sm text-end" placeholder="24">
                            <small v-if="packError(idx, 'factor_to_base')" class="ing-err">{{ packError(idx, 'factor_to_base') }}</small>
                        </td>
                        <td>
                            <input v-model="u.barcode" type="text"
                                   class="form-control form-control-sm" placeholder="—">
                            <small v-if="packError(idx, 'barcode')" class="ing-err">{{ packError(idx, 'barcode') }}</small>
                        </td>
                        <td>
                            <input v-model="u.purchase_price" type="number" step="0.01" min="0"
                                   class="form-control form-control-sm text-end" placeholder="—">
                        </td>
                        <td>
                            <input v-model="u.sale_price" type="number" step="0.01" min="0"
                                   class="form-control form-control-sm text-end" placeholder="—">
                        </td>
                        <td class="text-center">
                            <input type="radio" name="default_purchase_idx" class="form-check-input"
                                   :checked="u.is_default_purchase" @change="markDefaultPack(idx)">
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="removePack(idx)">
                                <i class="bi bi-x"></i>
                            </button>
                        </td>
                    </tr>
                    <tr v-if="packForm.units.length === 0">
                        <td colspan="7" class="text-center text-muted py-3">لا وحدات بديلة — الشراء بالوحدة الأساسية.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary" @click="addPack">
                <i class="bi bi-plus"></i> إضافة وحدة جديدة
            </button>
            <button type="button" class="btn btn-sm btn-success" :disabled="packForm.processing" @click="savePacks">
                <i class="bi bi-check2"></i> حفظ وحدات الصنف
            </button>
        </div>
    </DataPanel>

    <!-- ════ Sub-recipe editor — composite ingredients only ════ -->
    <DataPanel v-if="ingredient.is_composite" title="الوصفة الفرعية" icon="bi-diagram-3" class="mt-3">
        <div class="alert alert-info small">
            <i class="bi bi-info-circle"></i>
            أضف المكوّنات الخام التي تُنتج <strong>{{ ingredient.compositeYieldLabel }} {{ baseCode }}</strong>
            من <strong>{{ ingredient.name }}</strong>. عند بيع أي صنف يستخدم هذا المركّب، النظام يحلّله تلقائياً
            ويخصم المكوّنات الفرعية بالنسبة الصحيحة.
        </div>

        <div v-if="recipeForm.errors.lines" class="alert alert-danger small">{{ recipeForm.errors.lines }}</div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="bg-light">
                    <tr>
                        <th style="width: 50%">المكوّن الفرعي</th>
                        <th style="width: 20%">الكمية</th>
                        <th style="width: 20%">الوحدة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, idx) in recipeForm.lines" :key="idx">
                        <td>
                            <select v-model.number="line.ingredient_id" class="form-select form-select-sm" required>
                                <option v-for="i in subRecipeIngredients" :key="i.id" :value="i.id">{{ i.label }}</option>
                            </select>
                            <small v-if="lineError(idx, 'ingredient_id')" class="ing-err">{{ lineError(idx, 'ingredient_id') }}</small>
                        </td>
                        <td>
                            <input v-model="line.quantity" type="number" step="0.0001" min="0.0001" required
                                   class="form-control form-control-sm text-end">
                            <small v-if="lineError(idx, 'quantity')" class="ing-err">{{ lineError(idx, 'quantity') }}</small>
                        </td>
                        <td>
                            <select v-model.number="line.unit_id" class="form-select form-select-sm" required>
                                <option v-for="u in subRecipeUnits" :key="u.id" :value="u.id">{{ u.label }}</option>
                            </select>
                            <small v-if="lineError(idx, 'unit_id')" class="ing-err">{{ lineError(idx, 'unit_id') }}</small>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="removeLine(idx)">
                                <i class="bi bi-x"></i>
                            </button>
                        </td>
                    </tr>
                    <tr v-if="recipeForm.lines.length === 0">
                        <td colspan="4" class="text-center text-muted py-3">لا مكوّنات فرعية بعد.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary"
                    :disabled="subRecipeIngredients.length === 0" @click="addLine">
                <i class="bi bi-plus"></i> إضافة مكوّن فرعي
            </button>
            <button type="button" class="btn btn-sm btn-success" :disabled="recipeForm.processing" @click="saveRecipe">
                <i class="bi bi-check2"></i> حفظ الوصفة الفرعية
            </button>
        </div>
    </DataPanel>
</template>

<style scoped>
.ing-err { display: block; margin-top: .2rem; color: #dc2626; font-size: .72rem; font-weight: 700; }
.form-control-sm, .form-select-sm { min-height: 44px; }
.btn-sm { min-height: 44px; }
.form-check-input { width: 1.3rem; height: 1.3rem; }
</style>
