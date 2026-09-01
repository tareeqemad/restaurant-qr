<script setup>
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    item: { type: Object, required: true },
    quantity: { type: Number, default: 0 },
    currency: { type: Object, required: true },
});

const emit = defineEmits({
    'add-item': (id) => Number.isFinite(Number(id)),
});
</script>

<template>
    <button
        type="button"
        class="menu-tile"
        :class="{ 'is-out': !item.in_stock, 'is-in-cart': quantity > 0 }"
        :disabled="!item.in_stock"
        :aria-label="item.in_stock ? `إضافة ${item.name}` : `${item.name} نافد`"
        @click="emit('add-item', item.id)"
    >
        <span v-if="quantity > 0" class="tile-quantity">{{ quantity }}</span>
        <strong class="tile-name">{{ item.name }}</strong>
        <span class="tile-price">
            <template v-if="item.has_promo">
                <span class="old-price">{{ formatMoney(item.original_price, currency) }}</span>
                <b class="promo-price"><i class="bi bi-tag-fill"></i> {{ formatMoney(item.price, currency) }}</b>
            </template>
            <b v-else>{{ formatMoney(item.price, currency) }}</b>
        </span>
        <span class="tile-badges">
            <em v-if="!item.in_stock" class="is-out-badge"><i class="bi bi-slash-circle"></i> نفد</em>
            <em v-else-if="item.has_mods" class="has-modifiers"><i class="bi bi-sliders"></i> خيارات</em>
            <em v-else class="quick-add"><i class="bi bi-plus-circle"></i> إضافة سريعة</em>
        </span>
    </button>
</template>

<style scoped>
.menu-tile {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: .25rem;
    min-height: 104px;
    padding: .6rem .65rem .55rem;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    color: inherit;
    background: #fff;
    text-align: start;
    cursor: pointer;
    transition: border-color .12s, box-shadow .12s, transform .05s;
}
.menu-tile:hover:not(:disabled) {
    border-color: rgba(22, 101, 52, .45);
    box-shadow: 0 4px 14px -8px rgba(15, 71, 49, .4);
}
.menu-tile:active:not(:disabled) { transform: scale(.98); }
.menu-tile.is-in-cart { border-color: var(--wp-primary); background: #f0fdf4; }
.menu-tile.is-out { opacity: .55; background: #f8f9fa; cursor: not-allowed; }
.tile-name {
    flex-grow: 1;
    color: #1f2937;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.25;
}
.tile-price b { color: var(--wp-primary); font-size: 13.5px; font-weight: 800; }
.tile-price .promo-price { color: #dc2626; }
.old-price {
    margin-inline-end: .3rem;
    color: #9ca3af;
    font-size: 11px;
    text-decoration: line-through;
}
.tile-badges { margin-top: .15rem; }
.tile-badges em {
    display: inline-flex;
    align-items: center;
    gap: .2rem;
    padding: .1rem .35rem;
    border-radius: 6px;
    font-size: 10.5px;
    font-style: normal;
    font-weight: 700;
}
.has-modifiers { color: #0369a1; background: #e0f2fe; }
.is-out-badge { color: #92400e; background: #fef3c7; }
.quick-add { color: var(--wp-primary); background: #dcfce7; }
.tile-quantity {
    position: absolute;
    top: -8px;
    inset-inline-end: -8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    box-sizing: border-box;
    padding-inline: .35rem;
    border-radius: 999px;
    color: #fff;
    background: var(--wp-primary);
    box-shadow: 0 2px 6px rgba(15, 71, 49, .35);
    font-size: 12px;
    font-weight: 800;
}
@media (prefers-reduced-motion: reduce) {
    .menu-tile { transition: none; }
}
</style>
