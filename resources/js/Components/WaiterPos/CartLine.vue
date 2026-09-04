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
.cart-line { display: flex; gap: .5rem; padding: .6rem .8rem; border-bottom: 1px solid #f1f5f9; }
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
.line-info:hover { background: #f8fafc; }
.line-name { color: #1f2937; font-size: 13.5px; font-weight: 700; }
.edit-hint { color: #9ca3af; font-size: 10px; }
.line-info:hover .line-name,
.line-info:hover .edit-hint { color: var(--wp-primary); }
.line-modifiers,
.line-exclusions,
.line-note,
.unit-price { display: block; overflow-wrap: anywhere; }
.line-modifiers { color: #6b7280; font-size: 11.5px; }
.line-exclusions { padding: .12rem .35rem; border-radius: 6px; color: #991b1b; background: #fee2e2; font-size: 11.5px; font-weight: 800; }
.line-note { color: #0369a1; font-size: 11.5px; }
.unit-price { margin-top: .1rem; color: #6b7280; font-size: 11px; }
.line-side { display: flex; flex: 0 0 auto; flex-direction: column; align-items: flex-end; gap: .3rem; }
.quantity-stepper {
    display: inline-flex;
    align-items: center;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}
.quantity-stepper button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    color: #374151;
    background: #f8fafc;
    font-size: 15px;
    cursor: pointer;
}
.quantity-stepper button:hover:not(:disabled) { background: #eef2f7; }
.quantity-stepper button:disabled { opacity: .38; cursor: default; }
.quantity-stepper span {
    min-width: 30px;
    color: #1f2937;
    text-align: center;
    font-size: 14px;
    font-weight: 800;
}
.line-total { color: var(--wp-primary); font-size: 13px; font-weight: 800; }
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
.remove-line:hover { background: #fef2f2; }
@media (max-width: 390px) {
    .cart-line { align-items: flex-start; }
    .quantity-stepper button { width: 40px; }
}
</style>
