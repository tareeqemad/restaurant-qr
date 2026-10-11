<script setup>
import { formatMoney } from '../../Composables/useMoney';
import { requiresChoices } from '../../Support/waiterChoices';

const props = defineProps({
    item: { type: Object, required: true },
    quantity: { type: Number, default: 0 },
    currency: { type: Object, required: true },
});

const emit = defineEmits({
    'add-item': (id) => Number.isFinite(Number(id)),
    'customize-item': (id) => Number.isFinite(Number(id)),
});
</script>

<template>
    <article
        class="menu-tile"
        :class="{ 'is-out': !item.in_stock, 'is-in-cart': quantity > 0 }"
    >
    <button
        type="button"
        class="tile-add"
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
            <em v-else-if="requiresChoices(item)" class="has-modifiers"><i class="bi bi-sliders"></i> اختر الخيارات</em>
            <em v-else class="quick-add" aria-label="إضافة"><i class="bi bi-plus-lg"></i></em>
        </span>
    </button>
    <button v-if="!requiresChoices(item) && (item.has_mods || item.removable_ingredients?.length)"
            type="button" class="tile-customize" :disabled="!item.in_stock"
            :aria-label="`تخصيص ${item.name}`" @click="emit('customize-item', item.id)">
        <i class="bi bi-sliders"></i> تخصيص
    </button>
    </article>
</template>

<style scoped>
.menu-tile {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: .25rem;
    min-height: 104px;
    padding: 0;
    border: 1px solid var(--line);
    border-radius: 12px;
    color: inherit;
    background: var(--surface);
    text-align: start;
    cursor: pointer;
    transition: border-color .12s, box-shadow .12s, transform .05s;
}
.tile-add { display: flex; flex: 1; flex-direction: column; gap: .25rem; width: 100%; min-height: 116px; padding: .85rem .8rem .7rem; border: 0; border-radius: inherit; color: inherit; background: transparent; text-align: start; font: inherit; cursor: pointer; }
.tile-add:disabled, .tile-customize:disabled { cursor: not-allowed; }
.tile-customize { min-height: 44px; border: 0; border-top: 1px solid var(--line); border-radius: 0 0 12px 12px; color: var(--muted); background: var(--surface); font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
.tile-add:focus-visible, .tile-customize:focus-visible { outline: 3px solid var(--wp-primary); outline-offset: 2px; }
.menu-tile:hover:not(:disabled) {
    border-color: color-mix(in srgb, var(--brand) 45%, transparent);
    box-shadow: none;
}
.menu-tile:active:not(:disabled) { transform: scale(.98); }
.menu-tile.is-in-cart { border-color: var(--wp-primary); background: var(--brand-soft); }
.menu-tile.is-out { opacity: .55; background: var(--canvas); cursor: not-allowed; }
.tile-name {
    flex-grow: 1;
    color: var(--ink);
    font-size: 16px;
    font-weight: 700;
    line-height: 1.5;
}
.tile-price b { color: var(--brand); font-size: 16px; font-weight: 800; }
.tile-price .promo-price { color: #dc2626; }
.old-price {
    margin-inline-end: .3rem;
    color: var(--muted);
    font-size: 14px;
    text-decoration: line-through;
}
.tile-badges { margin-top: .15rem; }
.tile-badges em {
    display: inline-flex;
    align-items: center;
    gap: .2rem;
    padding: .1rem .35rem;
    border-radius: 6px;
    font-size: 14px;
    font-style: normal;
    font-weight: 700;
}
.has-modifiers { color: var(--muted); background: var(--surface-muted); }
.is-out-badge { color: #92400e; background: #F8F0E2; }
.quick-add { color: var(--brand); background: var(--brand-soft); }
.tile-quantity {
    position: absolute;
    top: 8px;
    inset-inline-end: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 26px;
    box-sizing: border-box;
    padding-inline: .35rem;
    border-radius: 8px;
    color: #FFFDFA;
    background: var(--wp-primary);
    box-shadow: none;
    font-size: 14px;
    font-weight: 800;
}
@media (prefers-reduced-motion: reduce) {
    .menu-tile { transition: none; }
}
.menu-tile.is-in-cart .tile-name {
    padding-inline-end: 1.7rem;
}

/* Task-specific component layout. */
.menu-tile{border-radius:8px;gap:0;overflow:hidden;min-height:130px}.tile-add{display:grid;grid-template-columns:minmax(0,1fr) 32px;grid-template-rows:1fr auto;align-items:start;gap:14px 6px;min-height:130px;padding:16px}.tile-name{grid-column:1/-1;font-size:17px;line-height:1.5;padding:0}.is-in-cart .tile-name{padding-inline-end:24px}.tile-price{grid-column:1;grid-row:2;align-self:center}.tile-price b{font-size:17px;color:var(--ink)}.tile-badges{grid-column:2;grid-row:2;align-self:center;justify-self:end}.quick-add{display:grid;width:32px;height:32px;place-items:center;background:var(--brand);color:var(--surface);border-radius:6px;font-size:18px}.has-modifiers,.is-out-badge{font-size:14px;white-space:normal}.tile-badges:has(.has-modifiers),.tile-badges:has(.is-out-badge){grid-column:1/-1;grid-row:3;justify-self:start}.tile-quantity{top:10px;inset-inline-end:10px;width:25px;height:25px;border-radius:5px;font-size:14px}.tile-customize{border-radius:0;min-height:42px;background:var(--canvas);font-size:14px}.menu-tile.is-in-cart{background:var(--surface);box-shadow:inset 0 -3px var(--brand)}@media(max-width:500px){.tile-add{padding:13px;gap:12px 6px}.tile-name{font-size:16px}}
</style>
