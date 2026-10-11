<script setup>
import { formatMoney } from '../../Composables/useMoney';
import { useConfirm } from '../../Composables/useConfirm';

const props = defineProps({
    line: { type: Object, required: true },
    index: { type: Number, required: true },
    currency: { type: Object, required: true },
});

const emit = defineEmits({
    edit: (index) => Number.isInteger(index) && index >= 0,
    'change-qty': (index, delta) => Number.isInteger(index) && Number.isInteger(delta),
    remove: (index) => Number.isInteger(index) && index >= 0,
});
const { ask } = useConfirm();

async function removeLine() {
    const approved = await ask({
        title: 'حذف الصنف من السلة؟',
        message: `سيُحذف «${props.line.name}» مع الإضافات والملاحظات المسجلة عليه.`,
        confirmLabel: 'حذف الصنف',
        danger: true,
    });
    if (approved) emit('remove', props.index);
}
</script>

<template>
    <article class="cart-line">
        <button type="button" class="line-info" title="تعديل السطر" @click="emit('edit', index)">
            <span class="line-name">{{ line.name }} <i class="bi bi-pencil-fill edit-hint"></i></span>
            <span v-if="line.modifier_labels.length" class="line-modifiers">
                {{ line.modifier_labels.join('، ') }}
            </span>
            <span v-if="line.excluded_ingredient_labels?.length" class="line-exclusions">
                <i class="bi bi-x-circle-fill"></i>
                بدون {{ line.excluded_ingredient_labels.join('، بدون ') }}
            </span>
            <span v-if="line.line_notes" class="line-note">
                <i class="bi bi-sticky"></i>
                {{ line.line_notes }}
            </span>
            <span class="unit-price">
                {{ formatMoney(Number(line.unit_price) + Number(line.modifiers_total), currency) }} / وحدة
            </span>
        </button>

        <div class="line-side">
            <div class="quantity-stepper">
                <button
                    type="button"
                    :disabled="line.quantity <= 1"
                    aria-label="تقليل الكمية"
                    @click="emit('change-qty', index, -1)"
                >
                    <i class="bi bi-dash"></i>
                </button>
                <span>{{ line.quantity }}</span>
                <button
                    type="button"
                    :disabled="line.quantity >= 99"
                    aria-label="زيادة الكمية"
                    @click="emit('change-qty', index, 1)"
                >
                    <i class="bi bi-plus"></i>
                </button>
            </div>
            <strong class="line-total">{{ formatMoney(line.subtotal, currency) }}</strong>
            <button type="button" class="remove-line" title="حذف" aria-label="حذف السطر" @click="removeLine">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </article>
</template>

<style scoped>
.cart-line { display: flex; gap: .5rem; padding: .6rem .8rem; border-bottom: 1px solid var(--line); }
.line-info {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: flex-start;
    min-width: 0;
    min-height: 44px;
    padding: .25rem;
    border: 0;
    border-radius: 8px;
    color: inherit;
    background: transparent;
    text-align: start;
    cursor: pointer;
}
.line-info:hover { background: var(--surface); }
.line-name { color: var(--ink); font-size: 14px; font-weight: 700; }
.edit-hint { color: var(--muted); font-size: 14px; }
.line-info:hover .line-name,
.line-info:hover .edit-hint { color: var(--wp-primary); }
.line-modifiers,
.line-exclusions,
.line-note,
.unit-price { display: block; overflow-wrap: anywhere; }
.line-modifiers { color: var(--muted); font-size: 14px; }
.line-exclusions { padding: .12rem .35rem; border-radius: 6px; color: #991b1b; background: #fee2e2; font-size: 14px; font-weight: 800; }
.line-note { color: #315B6B; font-size: 14px; }
.unit-price { margin-top: .1rem; color: var(--muted); font-size: 14px; }
.line-side { display: flex; flex: 0 0 auto; flex-direction: column; align-items: flex-end; gap: .3rem; }
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
.quantity-stepper button:disabled { opacity: .38; cursor: default; }
.quantity-stepper span {
    min-width: 30px;
    color: var(--ink);
    text-align: center;
    font-size: 14px;
    font-weight: 800;
}
.line-total { color: var(--wp-primary); font-size: 14px; font-weight: 800; }
.remove-line {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 10px;
    color: #dc2626;
    background: transparent;
    font-size: 15px;
    cursor: pointer;
}
.remove-line:hover { background: var(--canvas); }
@media (max-width: 390px) {
    .cart-line { align-items: flex-start; }
    .quantity-stepper button { width: 40px; }
}

/* Task-specific component layout. */
.cart-line{padding:16px 0;border:0;border-bottom:1px solid var(--line);border-radius:0;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px}.line-name{font-size:17px;line-height:1.5}.line-modifiers,.line-exclusions,.line-note,.unit-price{font-size:14px;line-height:1.6}.line-side{display:grid;justify-items:end;gap:10px}.quantity-stepper{border-radius:6px;background:var(--canvas)}.quantity-stepper button{width:40px;height:40px}.line-total{font-size:17px}.remove-line{width:38px;height:38px;border-radius:6px}.line-info{min-width:0}
</style>
