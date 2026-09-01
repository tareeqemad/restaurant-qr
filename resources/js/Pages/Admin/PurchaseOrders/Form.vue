<script setup>
/**
 * أمر شراء — إنشاء/تعديل (Wave 4). ONE page for both: `po.id` decides
 * the title, the submit verb, and the endpoint.
 *
 * Money rule worth stating once: when a PACK is chosen the quantity is a
 * count of packs and the price is per pack, so the line total is still
 * simply qty × price. The pack's factor is a RECEIVING-time concept (how
 * many base units land in stock) and must never enter the PO total.
 *
 * Submit posts classically to the controller, which validates every field
 * (`exists:` checks, min/max, line rules).
 */
import { computed, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useToast } from '../../../Composables/useToast';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    po: { type: Object, required: true },
    suppliers: { type: Array, required: true },
    ingredients: { type: Array, required: true },
    units: { type: Array, required: true },
    priceInsights: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    baseCurrencyCode: { type: String, default: '' },
    urls: { type: Object, required: true },
});

const toast = useToast();
const isEdit = computed(() => Boolean(props.po.id));

// ── Header ───────────────────────────────────────────────────────────
const header = reactive({
    supplier_id: props.po.supplierId ?? '',
    currency_code: props.po.currencyCode ?? props.baseCurrencyCode,
    exchange_rate: props.po.exchangeRate ?? 1,
    expected_at: props.po.expectedAt ?? '',
    notes: props.po.notes ?? '',
});

const foreignCurrency = computed(() => header.currency_code && header.currency_code !== props.baseCurrencyCode);

// ── Lines ────────────────────────────────────────────────────────────
const blankLine = () => ({
    ingredient_id: '',
    unit_id: '',
    ingredient_unit_id: '',
    quantity_ordered: 1,
    unit_price: 0,
    notes: '',
    _search: '',
});

const lines = reactive(
    props.po.lines.length
        ? props.po.lines.map((l) => ({ ...l, ingredient_unit_id: l.ingredient_unit_id ?? '', _search: '' }))
        : [blankLine()],
);

const ingredientById = computed(() => new Map(props.ingredients.map((i) => [i.id, i])));
const ingredientOf = (line) => ingredientById.value.get(Number(line.ingredient_id)) ?? null;

const addLine = () => lines.push(blankLine());
const removeLine = (idx) => {
    lines.splice(idx, 1);
    if (lines.length === 0) lines.push(blankLine());   // always ≥1 row
};

// Last price this supplier charged for this ingredient — the seeded
// insights make it instant, with no round-trip while editing.
const lastPriceFor = (ingredientId, supplierId) => props.priceInsights.find(
    (p) => p.ingredient_id === Number(ingredientId) && p.supplier_id === Number(supplierId),
) ?? null;

const onIngredientPicked = (line) => {
    const ing = ingredientOf(line);
    if (! ing) return;

    line.unit_id = ing.baseUnitId;
    line.ingredient_unit_id = '';

    // A default purchase pack wins: it sets both the pack and its price.
    const pack = ing.packs.find((p) => p.isDefaultPurchase);
    if (pack) {
        line.ingredient_unit_id = pack.id;
        if (pack.purchasePrice !== null) line.unit_price = pack.purchasePrice;
        return;
    }

    // Otherwise: this supplier's last price, else the ingredient's cost.
    const last = lastPriceFor(line.ingredient_id, header.supplier_id);
    if (last) {
        line.unit_id = last.unit_id || ing.baseUnitId;
        line.unit_price = last.unit_price;
    } else if (! Number(line.unit_price)) {
        line.unit_price = ing.fallbackCost;
    }
};

const onPackPicked = (line) => {
    const ing = ingredientOf(line);
    if (! ing || ! line.ingredient_unit_id) return;   // "base unit" → leave price alone
    const pack = ing.packs.find((p) => p.id === Number(line.ingredient_unit_id));
    if (pack?.purchasePrice !== null && pack?.purchasePrice !== undefined) {
        line.unit_price = pack.purchasePrice;
    }
};

const lineTotal = (line) => Number(line.quantity_ordered || 0) * Number(line.unit_price || 0);
const grandTotal = computed(() => lines.reduce((s, l) => s + lineTotal(l), 0));

// What a pack line actually lands in stock — shown so the buyer can sanity
// check "3 cartons = 36 kg" before ordering.
const baseEquivalent = (line) => {
    const ing = ingredientOf(line);
    if (! ing || ! line.ingredient_unit_id) return null;
    const pack = ing.packs.find((p) => p.id === Number(line.ingredient_unit_id));
    if (! pack) return null;
    return `${(Number(line.quantity_ordered || 0) * pack.factorToBase).toLocaleString('en-US', { maximumFractionDigits: 4 })} ${ing.baseUnitCode}`;
};

const validLines = computed(() => lines.filter((l) => l.ingredient_id
    && l.unit_id
    && Number(l.quantity_ordered) > 0
    && Number(l.unit_price) >= 0));

const problem = computed(() => {
    if (! header.supplier_id) return 'اختر المورّد.';
    if (validLines.value.length === 0) return 'ضيف سطر واحد على الأقل بمكوّن وكمية.';
    // Every FILLED row must be complete — the old screen let one good row
    // unlock submit while the rest were junk the server silently dropped.
    const halfDone = lines.find((l) => (l.ingredient_id || Number(l.quantity_ordered) > 1)
        && ! (l.ingredient_id && l.unit_id && Number(l.quantity_ordered) > 0));
    if (halfDone) return 'في سطر ناقص — كمّله أو احذفه.';
    return null;
});

// ── Submit (classic POST → controller validation → redirect) ─────────
const saving = ref(false);
const submit = () => {
    if (problem.value) { toast.warning(problem.value); return; }
    if (saving.value) return;
    saving.value = true;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = props.po.submitUrl;
    form.style.display = 'none';

    const add = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value ?? '';
        form.appendChild(input);
    };

    add('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
    if (isEdit.value) add('_method', 'PUT');
    add('supplier_id', header.supplier_id);
    add('currency_code', header.currency_code ?? '');
    if (foreignCurrency.value) add('exchange_rate', header.exchange_rate);
    add('expected_at', header.expected_at);
    add('notes', header.notes);

    validLines.value.forEach((l, i) => {
        add(`lines[${i}][ingredient_id]`, l.ingredient_id);
        add(`lines[${i}][unit_id]`, l.unit_id);
        if (l.ingredient_unit_id) add(`lines[${i}][ingredient_unit_id]`, l.ingredient_unit_id);
        add(`lines[${i}][quantity_ordered]`, l.quantity_ordered);
        add(`lines[${i}][unit_price]`, l.unit_price);
        add(`lines[${i}][notes]`, l.notes ?? '');
    });

    document.body.appendChild(form);
    form.submit();
};

const fmt = (n) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Ingredient picker filtering (per row).
const matchesFor = (line) => {
    const q = (line._search ?? '').trim().toLowerCase();
    if (! q) return props.ingredients.slice(0, 30);
    return props.ingredients
        .filter((i) => `${i.name} ${i.sku ?? ''}`.toLowerCase().includes(q))
        .slice(0, 30);
};
</script>

<template>
    <Head :title="isEdit ? `تعديل ${po.number}` : 'أمر شراء جديد'" />

    <PageHeader :title="isEdit ? `تعديل أمر الشراء ${po.number}` : 'أمر شراء جديد'"
                :icon="isEdit ? 'bi-pencil-square' : 'bi-plus-circle-fill'"
                subtitle="اطلب من المورّد — الاستلام لاحقاً بيحدّث المخزون والأسعار"
                :crumbs="[{ label: 'أوامر الشراء', url: urls.index }]" />

    <DataPanel title="بيانات الأمر" icon="bi-truck">
        <div class="po-head">
            <label class="po-field">
                <span>المورّد *</span>
                <select v-model="header.supplier_id" class="form-select">
                    <option value="">— اختر المورّد —</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </label>

            <label class="po-field">
                <span>التاريخ المتوقع</span>
                <input v-model="header.expected_at" type="date" class="form-control">
            </label>

            <label v-if="currencies.length > 1" class="po-field">
                <span>العملة</span>
                <select v-model="header.currency_code" class="form-select">
                    <option v-for="c in currencies" :key="c.code" :value="c.code">{{ c.label }}</option>
                </select>
            </label>

            <label v-if="foreignCurrency" class="po-field">
                <span>سعر الصرف</span>
                <input v-model.number="header.exchange_rate" type="number" step="0.000001" min="0.000001" class="form-control">
                <small>مقابل {{ baseCurrencyCode }}</small>
            </label>

            <label v-if="isEdit" class="po-field">
                <span>رقم الأمر</span>
                <input :value="po.number" class="form-control" disabled>
            </label>

            <label class="po-field po-field--wide">
                <span>ملاحظات</span>
                <textarea v-model="header.notes" rows="2" maxlength="2000" class="form-control"
                          placeholder="تعليمات للمورّد أو ملاحظات داخلية…"></textarea>
            </label>
        </div>
    </DataPanel>

    <DataPanel title="سطور الأمر" :count="validLines.length" icon="bi-list-ul">
        <template #actions>
            <button type="button" class="btn btn-light" @click="addLine">
                <i class="bi bi-plus-lg"></i> أضف سطر
            </button>
        </template>

        <div class="table-responsive">
            <table class="table align-middle po-table">
                <thead class="bg-light">
                    <tr>
                        <th style="min-width: 220px">المكوّن *</th>
                        <th>العبوة</th>
                        <th>الكمية *</th>
                        <th>السعر *</th>
                        <th>المجموع</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, idx) in lines" :key="idx">
                        <td>
                            <input v-model="line._search" class="form-control form-control-sm mb-1"
                                   placeholder="🔍 ابحث…">
                            <select v-model="line.ingredient_id" class="form-select" @change="onIngredientPicked(line)">
                                <option value="">— اختر مكوّن —</option>
                                <option v-for="i in matchesFor(line)" :key="i.id" :value="i.id">
                                    {{ i.name }}<template v-if="i.sku"> · {{ i.sku }}</template>
                                </option>
                            </select>
                        </td>
                        <td>
                            <select v-model="line.ingredient_unit_id" class="form-select"
                                    :disabled="! ingredientOf(line) || ingredientOf(line).packs.length === 0"
                                    @change="onPackPicked(line)">
                                <option value="">— الوحدة الأساسية —</option>
                                <option v-for="p in (ingredientOf(line)?.packs ?? [])" :key="p.id" :value="p.id">
                                    {{ p.name }}
                                </option>
                            </select>
                            <small v-if="baseEquivalent(line)" class="po-equiv">
                                = {{ baseEquivalent(line) }} بالمخزون
                            </small>
                        </td>
                        <td>
                            <input v-model.number="line.quantity_ordered" type="number" step="0.0001" min="0.0001"
                                   class="form-control po-num">
                        </td>
                        <td>
                            <input v-model.number="line.unit_price" type="number" step="0.0001" min="0"
                                   class="form-control po-num">
                            <small v-if="line.ingredient_unit_id" class="po-hint">للعبوة</small>
                        </td>
                        <td class="po-total">{{ fmt(lineTotal(line)) }}</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="removeLine(idx)">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="4" class="text-end">الإجمالي</th>
                        <th class="po-grand">{{ fmt(grandTotal) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <template #footer>
            <div class="po-foot">
                <a :href="urls.index" class="btn btn-light">إلغاء</a>
                <span v-if="problem" class="po-problem"><i class="bi bi-info-circle"></i> {{ problem }}</span>
                <button type="button" class="btn btn-primary btn-lg po-save"
                        :disabled="Boolean(problem) || saving" @click="submit">
                    <i class="bi" :class="saving ? 'bi-arrow-repeat po-spin' : 'bi-save'"></i>
                    {{ saving ? 'جارٍ الحفظ…' : (isEdit ? 'تحديث الأمر' : 'حفظ كمسودة') }}
                </button>
            </div>
        </template>
    </DataPanel>
</template>

<style scoped>
.po-head {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr));
    gap: 1rem;
}
.po-field { display: flex; flex-direction: column; gap: .35rem; margin: 0; }
.po-field--wide { grid-column: 1 / -1; }
.po-field > span { font-size: .84rem; font-weight: 800; color: #334155; }
.po-field small { font-size: .72rem; color: #94a3b8; }

.po-table th { white-space: nowrap; }
.po-num { text-align: center; font-weight: 700; min-width: 90px; }
.po-total { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
.po-grand { font-size: 1.1rem; font-weight: 900; color: rgb(var(--primary-rgb)); }
.po-equiv { display: block; font-size: .7rem; color: #059669; font-weight: 700; margin-top: .2rem; }
.po-hint { display: block; font-size: .7rem; color: #94a3b8; }

.po-foot { display: flex; align-items: center; gap: .7rem; flex-wrap: wrap; }
.po-problem { color: #b45309; font-size: .82rem; font-weight: 700; }
.po-save { margin-inline-start: auto; font-weight: 800; }
.po-spin { animation: po-rotate 1s linear infinite; display: inline-block; }
@keyframes po-rotate { to { transform: rotate(360deg); } }
</style>
