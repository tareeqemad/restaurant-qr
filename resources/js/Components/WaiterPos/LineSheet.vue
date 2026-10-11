<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    item: { type: Object, required: true },
    line: { type: Object, default: null },
    currency: { type: Object, required: true },
});

const emit = defineEmits({
    close: () => true,
    save: (payload) => (
        payload
        && Number.isInteger(payload.quantity)
        && Array.isArray(payload.modifier_ids)
        && Array.isArray(payload.excluded_ingredient_ids)
        && (payload.line_notes === null || typeof payload.line_notes === 'string')
    ),
});

const quantity = ref(1);
const selectedIds = ref([]);
const selectedExcludedIds = ref([]);
const lineNotes = ref('');
const validationError = ref('');
let previousBodyOverflow = '';
const dialogPanel = ref(null);
const closeButton = ref(null);
let previousFocus = null;
let dialogMounted = false;

const isEditing = computed(() => props.line !== null);
const basePrice = computed(() => (
    isEditing.value ? Number(props.line.unit_price) || 0 : Number(props.item.price) || 0
));
const selectedModifiers = computed(() => (props.item.modifier_groups ?? []).flatMap(
    (group) => (group.modifiers ?? []).filter((modifier) => selectedIds.value.includes(Number(modifier.id))),
));
const modifiersTotal = computed(() => selectedModifiers.value.reduce(
    (amount, modifier) => amount + (Number(modifier.price_delta) || 0),
    0,
));
const lineTotal = computed(() => (
    (basePrice.value + modifiersTotal.value) * clampQuantity(quantity.value)
));

watch(
    () => [props.item.id, props.line?.id],
    resetForm,
    { immediate: true },
);

watch(selectedIds, () => {
    validationError.value = '';
}, { deep: true });

onMounted(() => {
    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    dialogMounted = true;
    nextTick(() => {
        if (dialogMounted) (closeButton.value || dialogPanel.value)?.focus({ preventScroll: true });
    });
});

onBeforeUnmount(() => {
    dialogMounted = false;
    document.body.style.overflow = previousBodyOverflow;
    const target = previousFocus;
    previousFocus = null;
    nextTick(() => {
        if (target?.isConnected) target.focus({ preventScroll: true });
    });
});

function handleDialogKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        emit('close');
        return;
    }
    if (event.key !== 'Tab' || !dialogPanel.value) return;

    const focusable = [...dialogPanel.value.querySelectorAll(
        'a[href], button, input, select, textarea, summary, [tabindex]',
    )].filter((element) => element.tabIndex >= 0 && !element.disabled
        && element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden');
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    if (!first) {
        event.preventDefault();
        dialogPanel.value.focus({ preventScroll: true });
    } else if (!dialogPanel.value.contains(active) || active === dialogPanel.value
        || (event.shiftKey ? active === first : active === last)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
    }
}

function resetForm() {
    const validModifierIds = new Set((props.item.modifier_groups ?? []).flatMap(
        (group) => (group.modifiers ?? []).map((modifier) => Number(modifier.id)),
    ));
    quantity.value = clampQuantity(props.line?.quantity ?? 1);
    selectedIds.value = [...(props.line?.modifier_ids ?? [])]
        .map(Number)
        .filter((id) => validModifierIds.has(id));
    const validIngredientIds = new Set((props.item.removable_ingredients ?? []).map(
        (ingredient) => Number(ingredient.id),
    ));
    selectedExcludedIds.value = [...(props.line?.excluded_ingredient_ids ?? [])]
        .map(Number)
        .filter((id) => validIngredientIds.has(id));
    lineNotes.value = props.line?.line_notes ?? '';
    validationError.value = '';
}

function clampQuantity(value) {
    return Math.min(99, Math.max(1, Math.trunc(Number(value)) || 1));
}

function normalizeQuantity() {
    quantity.value = clampQuantity(quantity.value);
    validationError.value = '';
}

function changeQuantity(delta) {
    quantity.value = clampQuantity(clampQuantity(quantity.value) + delta);
    validationError.value = '';
}

function groupSelectionCount(group) {
    const groupIds = new Set((group.modifiers ?? []).map((modifier) => Number(modifier.id)));
    return selectedIds.value.filter((id) => groupIds.has(Number(id))).length;
}

function modifierDisabled(group, modifierId) {
    if (selectedIds.value.includes(Number(modifierId))) return false;
    const maximum = Number(group.max_select) || 0;
    return maximum > 0 && groupSelectionCount(group) >= maximum;
}

function groupRule(group) {
    const minimum = Number(group.min_select) || 0;
    const maximum = Number(group.max_select) || 0;

    if (minimum > 0 && maximum > 0) return `اختر ${minimum}–${maximum}`;
    if (minimum > 0) return `اختر ${minimum} على الأقل`;
    if (maximum > 0) return `حتى ${maximum}`;
    return group.required ? 'مطلوب' : 'اختياري';
}

function validateGroups() {
    for (const group of props.item.modifier_groups ?? []) {
        const count = groupSelectionCount(group);
        const minimum = Number(group.min_select) || 0;
        const maximum = Number(group.max_select) || 0;

        if (group.required && count < minimum) {
            return `اختر ${minimum} من «${group.name}».`;
        }
        if (maximum > 0 && count > maximum) {
            return `الحد الأعلى في «${group.name}» هو ${maximum}.`;
        }
    }

    return '';
}

function saveLine() {
    normalizeQuantity();

    // Local checks keep the sheet responsive; the submit endpoint remains authoritative.
    validationError.value = validateGroups();
    if (validationError.value) return;

    const cleanedNote = String(lineNotes.value ?? '').trim().slice(0, 500);
    emit('save', {
        quantity: quantity.value,
        modifier_ids: [...selectedIds.value].map(Number),
        excluded_ingredient_ids: [...selectedExcludedIds.value].map(Number),
        line_notes: cleanedNote || null,
    });
}
</script>

<template>
    <div class="line-backdrop" role="presentation" @click.self="emit('close')">
        <section
            ref="dialogPanel"
            class="line-sheet"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="`line-sheet-title-${item.id}`"
            tabindex="-1"
            @keydown="handleDialogKeydown"
        >
            <header class="sheet-header">
                <div>
                    <span v-if="isEditing" class="edit-mode"><i class="bi bi-pencil-fill"></i> تعديل سطر</span>
                    <strong :id="`line-sheet-title-${item.id}`">{{ item.name }}</strong>
                    <span class="item-price">
                        <span v-if="item.has_promo && !isEditing" class="old-price">
                            {{ formatMoney(item.original_price, currency) }}
                        </span>
                        {{ formatMoney(basePrice, currency) }}
                    </span>
                </div>
                <button ref="closeButton" type="button" class="close-sheet" aria-label="إغلاق" @click="emit('close')">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <div class="sheet-body">
                <div class="quantity-row">
                    <label for="line-sheet-quantity">الكمية</label>
                    <div class="quantity-stepper">
                        <button
                            type="button"
                            :disabled="quantity <= 1"
                            aria-label="تقليل الكمية"
                            @click="changeQuantity(-1)"
                        >
                            <i class="bi bi-dash"></i>
                        </button>
                        <input
                            id="line-sheet-quantity"
                            v-model.number="quantity"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="99"
                            aria-label="كمية الصنف"
                            @focus="$event.target.select()"
                            @change="normalizeQuantity"
                        >
                        <button
                            type="button"
                            :disabled="quantity >= 99"
                            aria-label="زيادة الكمية"
                            @click="changeQuantity(1)"
                        >
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                </div>

                <div v-for="group in item.modifier_groups" :key="group.id" class="modifier-group">
                    <div class="group-title">
                        <strong>{{ group.name }}</strong>
                        <span :class="group.required ? 'is-required' : 'is-optional'">{{ groupRule(group) }}</span>
                        <small>{{ groupSelectionCount(group) }} محدد</small>
                    </div>
                    <div class="modifier-options">
                        <label
                            v-for="modifier in group.modifiers"
                            :key="modifier.id"
                            class="modifier-option"
                            :class="{ 'is-disabled': modifierDisabled(group, modifier.id) }"
                        >
                            <input
                                v-model="selectedIds"
                                type="checkbox"
                                :value="Number(modifier.id)"
                                :disabled="modifierDisabled(group, modifier.id)"
                            >
                            <span>{{ modifier.name }}</span>
                            <small v-if="Number(modifier.price_delta) !== 0">
                                {{ Number(modifier.price_delta) > 0 ? '+' : '' }}{{ formatMoney(modifier.price_delta, currency) }}
                            </small>
                        </label>
                    </div>
                </div>

                <details v-if="item.removable_ingredients?.length" class="ingredient-exclusions">
                    <summary>
                        تعديل المكونات (اختياري)
                        <span v-if="selectedExcludedIds.length">{{ selectedExcludedIds.length }} مستبعد</span>
                    </summary>
                    <div class="exclusion-options">
                        <label v-for="ingredient in item.removable_ingredients" :key="ingredient.id"
                               class="exclusion-option" :class="{ 'is-on': selectedExcludedIds.includes(Number(ingredient.id)) }">
                            <input v-model="selectedExcludedIds" type="checkbox" :value="Number(ingredient.id)">
                            <i class="bi bi-x-circle-fill"></i>
                            <span>بدون {{ ingredient.name }}</span>
                            <small v-if="ingredient.requires_confirmation">أكّدها مع الزبون</small>
                        </label>
                    </div>
                </details>

                <label class="line-note">
                    <span>ملاحظات (اختياري)</span>
                    <input
                        v-model="lineNotes"
                        type="text"
                        maxlength="500"
                        placeholder="مثلاً: بدون بصل"
                    >
                </label>

                <p v-if="validationError" class="validation-error" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    {{ validationError }}
                </p>
            </div>

            <footer class="sheet-footer">
                <button type="button" class="cancel-action" @click="emit('close')">إلغاء</button>
                <button type="button" class="save-action" @click="saveLine">
                    <i :class="isEditing ? 'bi bi-check-lg' : 'bi bi-plus-lg'"></i>
                    {{ isEditing ? 'حفظ التعديل' : 'إضافة للطلب' }}
                    — {{ formatMoney(lineTotal, currency) }}
                </button>
            </footer>
        </section>
    </div>
</template>

<style scoped>
.line-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1080;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    background: rgba(15, 23, 42, .5);
}
.line-sheet {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 560px;
    max-height: 92vh;
    max-height: 92dvh;
    overflow: hidden;
    border-radius: 12px 12px 0 0;
    background: var(--surface);
    box-shadow: none;
}
.sheet-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .75rem;
    padding: .85rem 1rem;
    border-bottom: 1px solid var(--line);
}
.sheet-header > div { min-width: 0; }
.sheet-header strong { display: block; color: var(--ink); font-size: 15px; }
.edit-mode {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    margin-bottom: .2rem;
    padding: .1rem .4rem;
    border-radius: 6px;
    color: #b45309;
    background: #F8F0E2;
    font-size: 14px;
    font-weight: 800;
}
.item-price { color: var(--wp-primary); font-size: 14px; font-weight: 800; }
.old-price {
    margin-inline-end: .3rem;
    color: var(--muted);
    font-size: 14px;
    text-decoration: line-through;
}
.close-sheet {
    display: inline-flex;
    flex: 0 0 44px;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 10px;
    color: var(--muted);
    background: transparent;
    font-size: 16px;
    cursor: pointer;
}
.close-sheet:hover { color: var(--ink); background: var(--canvas); }
.sheet-body { padding: .85rem 1rem; overflow-y: auto; overscroll-behavior: contain; }
.quantity-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    margin-bottom: .8rem;
}
.quantity-row label { color: var(--ink); font-size: 14px; font-weight: 700; }
.quantity-stepper {
    display: inline-flex;
    align-items: center;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 8px;
}
.quantity-stepper button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    color: var(--ink);
    background: var(--surface);
    font-size: 15px;
    cursor: pointer;
}
.quantity-stepper button:hover:not(:disabled) { background: var(--canvas); }
.quantity-stepper button:disabled { opacity: .4; cursor: default; }
.quantity-stepper input {
    width: 56px;
    height: 44px;
    border: 0;
    color: var(--ink);
    background: var(--surface);
    text-align: center;
    appearance: textfield;
    font-size: 15px;
    font-weight: 800;
}
.quantity-stepper input::-webkit-outer-spin-button,
.quantity-stepper input::-webkit-inner-spin-button { margin: 0; appearance: none; }
.quantity-stepper input:focus { outline: 2px solid var(--wp-primary); outline-offset: -2px; }
.modifier-group { margin-bottom: .9rem; }
.group-title {
    display: flex;
    align-items: center;
    gap: .4rem;
    margin-bottom: .35rem;
    color: var(--ink);
    font-size: 14px;
}
.group-title strong { font-weight: 800; }
.group-title > span {
    padding: .05rem .35rem;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 700;
}
.group-title small { margin-inline-start: auto; color: #315B6B; font-size: 14px; }
.is-required { color: #92400e; background: #F8F0E2; }
.is-optional { color: var(--muted); background: var(--canvas); }
.modifier-options { display: flex; flex-direction: column; gap: .3rem; }
.modifier-option {
    display: flex;
    align-items: center;
    gap: .5rem;
    min-height: 44px;
    box-sizing: border-box;
    padding: .45rem .6rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    cursor: pointer;
}
.modifier-option:hover { background: var(--surface); }
.modifier-option.is-disabled { opacity: .48; cursor: not-allowed; }
.modifier-option input { flex: 0 0 auto; width: 18px; height: 18px; accent-color: var(--wp-primary); }
.modifier-option > span { flex-grow: 1; font-size: 14px; }
.modifier-option small { color: var(--muted); font-size: 14px; }
.ingredient-exclusions { margin-bottom: .9rem; padding: .65rem; border: 1px solid #fecaca; border-radius: 10px; background: var(--surface); }
.exclusions-title { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; margin-bottom: .45rem; }
.exclusions-title > div { display: grid; gap: .1rem; }
.exclusions-title strong { display: flex; align-items: center; gap: .32rem; color: #991b1b; font-size: 14px; }
.exclusions-title small { color: #7f1d1d; font-size: 14px; }
.exclusions-title > span { padding: .1rem .38rem; border-radius: 12px; color: #991b1b; background: #fee2e2; font-size: 14px; font-weight: 800; }
.exclusion-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .35rem; }
.exclusion-option { min-height: 44px; display: flex; align-items: center; gap: .35rem; padding: .4rem .5rem; border: 1px solid #fecaca; border-radius: 8px; color: #7f1d1d; background: var(--surface); cursor: pointer; }
.exclusion-option input { position: absolute; opacity: 0; pointer-events: none; }
.exclusion-option > i { color: #fca5a5; }
.exclusion-option > span { flex: 1; min-width: 0; font-size: 14px; font-weight: 750; }
.exclusion-option > small { color: #a16207; font-size: 14px; }
.exclusion-option.is-on { border-color: #ef4444; background: #fee2e2; color: #991b1b; }
.exclusion-option.is-on > i { color: #dc2626; }
.line-note { display: block; margin-top: .5rem; }
.line-note span { display: block; margin-bottom: .25rem; color: var(--muted); font-size: 14px; }
.line-note input {
    width: 100%;
    min-height: 44px;
    box-sizing: border-box;
    padding: .45rem .6rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 14px;
}
.line-note input:focus { outline: 2px solid color-mix(in srgb, var(--wp-primary) 30%, transparent); border-color: var(--wp-primary); }
.validation-error {
    display: flex;
    align-items: center;
    gap: .4rem;
    margin: .65rem 0 0;
    padding: .55rem .65rem;
    border-radius: 8px;
    color: #b91c1c;
    background: var(--canvas);
    font-size: 14px;
    font-weight: 700;
}
.sheet-footer { display: flex; gap: .5rem; padding: .8rem 1rem; border-top: 1px solid var(--line); }
.cancel-action,
.save-action {
    min-height: 46px;
    border-radius: 8px;
    font-weight: 800;
    cursor: pointer;
}
.cancel-action { flex: 0 0 auto; padding-inline: .9rem; border: 1px solid var(--line); color: var(--ink); background: var(--surface); }
.save-action {
    display: inline-flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    padding: .55rem;
    border: 0;
    color: #FFFDFA;
    background: var(--wp-primary);
    box-shadow: none;
    font-size: 14px;
}
.save-action:hover { background: var(--wp-primary-dark); }
@media (max-width: 380px) {
    .sheet-footer { flex-wrap: wrap; }
    .save-action { flex-basis: 100%; order: -1; }
    .exclusion-options { grid-template-columns: 1fr; }
}

/* Task-specific component layout. */
.line-sheet{border-radius:10px;background:var(--surface);box-shadow:none;max-height:90dvh;overflow-y:auto}.sheet-header{padding:20px 24px;background:var(--surface);color:var(--ink);border-bottom:1px solid var(--line);gap:14px}.sheet-header h2,.sheet-header strong{font-size:22px;color:var(--ink)}.sheet-header button{width:44px;height:44px;min-width:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.sheet-body{padding:20px 24px}.sheet-footer{padding:16px 24px;border-top:1px solid var(--line);background:var(--surface);position:sticky;bottom:0;z-index:2}.line-sheet input:not([type=checkbox]):not([type=radio]),.line-sheet select{min-height:48px;border-radius:6px;font-size:16px}.line-sheet textarea{min-height:90px;border-radius:6px;font-size:15px}.modifier-options,.exclusion-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}.modifier-option,.exclusion-option{min-height:52px;padding:12px;border-radius:6px;font-size:15px}.group-title{padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px}.quantity-row{padding:16px 0}.quantity-stepper button{width:44px;height:44px}.save-action,.customer-sheet__button,.go{min-height:50px;border-radius:8px;font-size:16px}.person{border:0;border-bottom:1px solid var(--line);border-radius:0;padding:16px 0;min-height:70px}.person .name{font-size:16px}.bill-summary{border-radius:8px;padding:18px;background:var(--canvas)}.bill-summary__primary strong{font-size:27px}.session-round{border-radius:6px;margin-bottom:14px}.session-item{padding:14px 12px;gap:12px}.session-item__name-row strong{font-size:16px}.session-item__detail{font-size:14px;line-height:1.6}.session-item__cancel{min-height:42px}.customer-card{border-radius:8px;padding:18px;background:var(--canvas)}@media(max-width:600px){.sheet-header,.sheet-body{padding:18px}.sheet-footer{padding:14px 18px}.modifier-options,.exclusion-options{grid-template-columns:1fr}}
.ingredient-exclusions { padding: 4px 0; border: 0; border-block: 1px solid var(--line); border-radius: 0; background: transparent; }
.ingredient-exclusions > summary { min-height: 44px; padding: 10px 0; cursor: pointer; color: var(--ink); font-size: 15px; font-weight: 750; }
.ingredient-exclusions > summary::marker { color: var(--brand); }
.ingredient-exclusions > summary:focus-visible { outline: 2px solid var(--brand); outline-offset: 3px; border-radius: 4px; }
.ingredient-exclusions > summary > span { display: inline-block; margin-inline-start: 8px; padding: 2px 6px; border-radius: 4px; background: var(--brand-soft); color: var(--brand); font-size: 14px; }
.ingredient-exclusions[open] { padding-bottom: 14px; }
.ingredient-exclusions[open] > summary { margin-bottom: 8px; }
.modifier-option:focus-within, .exclusion-option:focus-within { outline: 2px solid var(--brand); outline-offset: 2px; }
</style>
