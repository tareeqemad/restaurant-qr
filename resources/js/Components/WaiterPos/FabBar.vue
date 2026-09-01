<script setup>
import { formatMoney } from '../../Composables/useMoney';

defineProps({
    count: { type: Number, required: true },
    total: { type: Number, required: true },
    currency: { type: Object, required: true },
    label: { type: String, default: 'عرض السلة' },
});

const emit = defineEmits({
    open: () => true,
});
</script>

<template>
    <button type="button" class="cart-fab" :aria-label="label" @click="emit('open')">
        <span class="fab-count">{{ count }}</span>
        <span class="fab-label"><i class="bi bi-cart3"></i> {{ label }}</span>
        <strong class="fab-total">{{ formatMoney(total, currency) }}</strong>
    </button>
</template>

<style scoped>
.cart-fab {
    position: fixed;
    inset-inline: .9rem;
    bottom: .9rem;
    z-index: 1055;
    display: flex;
    align-items: center;
    gap: .6rem;
    width: calc(100% - 1.8rem);
    max-width: 430px;
    min-height: 58px;
    margin-inline: auto;
    padding: .6rem 1rem;
    border: 0;
    border-radius: 999px;
    color: #fff;
    background: var(--wp-primary);
    box-shadow: 0 10px 26px -8px rgba(15, 71, 49, .55);
    cursor: pointer;
}
.cart-fab:hover { background: var(--wp-primary-dark); }
.fab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding-inline: .45rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, .24);
    font-size: 14px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.fab-label {
    display: inline-flex;
    flex: 1;
    align-items: center;
    gap: .45rem;
    font-size: 14.5px;
    font-weight: 800;
    text-align: start;
}
.fab-total { font-size: 15.5px; font-weight: 800; font-variant-numeric: tabular-nums; }
@media (max-width: 370px) {
    .cart-fab { gap: .4rem; padding-inline: .75rem; }
    .fab-label { font-size: 13px; }
    .fab-total { font-size: 13.5px; }
}
</style>
