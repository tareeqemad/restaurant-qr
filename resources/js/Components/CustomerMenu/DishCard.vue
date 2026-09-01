<script setup>
/**
 * One dish card. The server decided everything: effective (promo) price,
 * live availability + the reason, badges. The card only renders and emits.
 * Tap anywhere → details sheet; the +/− stepper appears once the item is
 * in the cart (quantity aggregated across its rows).
 */
defineProps({
    item: { type: Object, required: true },
    qty: { type: Number, default: 0 },
    symbol: { type: String, required: true },
    t: { type: Function, required: true },
    orderingEnabled: { type: Boolean, default: true },
});

defineEmits(['open', 'plus', 'minus']);

const money = (n) => (Number(n) || 0).toFixed(2);
</script>

<template>
    <article class="dish" :class="{ 'is-off': ! item.can_order, 'in-cart': qty > 0 }"
             role="group" :aria-label="item.name" @click="$emit('open', item)">
        <div class="dish-media">
            <img v-if="item.image" :src="item.image" :alt="item.name" loading="lazy">
            <div v-else class="dish-media-empty"><i class="bi bi-egg-fried"></i></div>

            <span v-if="item.discount_pct" class="dish-badge dish-badge--promo">-{{ item.discount_pct }}%</span>
            <span v-else-if="item.featured && item.can_order" class="dish-badge dish-badge--star">
                <i class="bi bi-star-fill"></i>
            </span>

            <span v-if="! item.can_order" class="dish-off-veil">
                <i class="bi bi-calendar-x"></i>
                {{ item.unavailable_reason }}
            </span>
        </div>

        <div class="dish-body">
            <h4 class="dish-name">{{ item.name }}</h4>
            <p v-if="item.description" class="dish-desc">{{ item.description }}</p>

            <button v-if="item.ingredients?.length" type="button" class="dish-ingredients"
                    :aria-label="`عرض مكونات وتفاصيل ${item.name}`" @click.stop="$emit('open', item)">
                <span class="dish-ingredients-top">
                    <span class="dish-ingredients-label">
                        <i class="bi bi-list-check"></i>
                        {{ t('dish_ingredients') ?? 'مكوّنات الطبق' }}
                    </span>
                    <span class="dish-details-cue">عرض الكل <i class="bi bi-chevron-left"></i></span>
                </span>
                <p :title="item.ingredients.join('، ')">{{ item.ingredients.join('، ') }}</p>
            </button>
            <button v-else type="button" class="dish-details-only" @click.stop="$emit('open', item)">
                <i class="bi bi-info-circle"></i>
                عرض التفاصيل
                <i class="bi bi-chevron-left"></i>
            </button>

            <div class="dish-meta">
                <span v-if="item.prep_minutes" class="dish-prep">
                    <i class="bi bi-clock"></i> {{ item.prep_minutes }}د
                </span>
                <span v-if="item.allergens.length" class="dish-allergy" :title="item.allergens.join('، ')">
                    <i class="bi bi-exclamation-diamond"></i> {{ item.allergens.length }}
                </span>
                <span v-if="item.has_modifiers" class="dish-mods"><i class="bi bi-sliders"></i></span>
            </div>

            <div class="dish-foot" @click.stop>
                <div class="dish-price">
                    <strong>{{ money(item.price) }} <small>{{ symbol }}</small></strong>
                    <s v-if="item.original_price">{{ money(item.original_price) }}</s>
                </div>

                <div v-if="item.can_order && orderingEnabled && qty === 0" class="dish-add">
                    <button type="button" class="dish-add-btn" :aria-label="`${t('add') ?? 'أضف'} ${item.name}`"
                            @click="$emit('plus', item)">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div v-else-if="item.can_order && orderingEnabled" class="dish-stepper">
                    <button type="button" aria-label="أنقص" @click="$emit('minus', item)"><i class="bi bi-dash-lg"></i></button>
                    <span>{{ qty }}</span>
                    <button type="button" aria-label="زد" @click="$emit('plus', item)"><i class="bi bi-plus-lg"></i></button>
                </div>
                <span v-else-if="item.can_order" class="dish-readonly">
                    <i class="bi bi-phone"></i> من الهاتف الأول
                </span>
            </div>
        </div>
    </article>
</template>

<style scoped>
.dish {
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #eef0f3;
    border-radius: 16px;
    overflow: hidden;
    cursor: pointer;
    transition: transform .12s ease, box-shadow .12s ease;
    min-width: 0;
}
.dish:active { transform: scale(.985); }
@media (hover: hover) {
    .dish:hover { box-shadow: 0 10px 26px -12px rgba(15, 23, 42, .18); transform: translateY(-2px); }
}
.dish.is-off { cursor: pointer; }
.dish.is-off .dish-desc { color: #94a3b8; }
.dish.in-cart { border-color: rgba(var(--primary-rgb), .45); box-shadow: 0 0 0 1px rgba(var(--primary-rgb), .25); }

.dish-media {
    position: relative;
    flex: 0 0 auto;
    width: 100%;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    background: #f4f6f8;
}
.dish-media img {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
}
.dish-media-empty {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #cbd5e1;
}
.dish-badge {
    position: absolute;
    top: 8px;
    inset-inline-start: 8px;
    border-radius: 999px;
    padding: .18rem .55rem;
    font-size: .72rem;
    font-weight: 800;
    color: #fff;
}
.dish-badge--promo { background: #dc2626; }
.dish-badge--star { background: rgba(180, 83, 9, .92); font-size: .66rem; }
.dish-off-veil {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, .72);
    backdrop-filter: blur(1px);
    font-size: .82rem;
    font-weight: 800;
    color: #64748b;
    text-align: center;
    padding: .5rem;
    gap: .4rem;
}

.dish-body { display: flex; flex-direction: column; gap: .3rem; padding: .65rem .7rem .7rem; flex: 1; }
.dish-name {
    margin: 0;
    font-size: .92rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.dish-desc {
    margin: 0;
    font-size: .74rem;
    color: #64748b;
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.1em;
}
.dish-ingredients {
    width: 100%;
    padding: .42rem .5rem;
    border-radius: 10px;
    background: #f8faf9;
    border: 1px solid #e6eee9;
    color: inherit;
    font: inherit;
    text-align: start;
    cursor: pointer;
}
.dish-ingredients:active { background: #eef6f1; }
.dish-ingredients-top { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.dish-ingredients-label {
    display: flex;
    align-items: center;
    gap: .3rem;
    color: rgb(var(--primary-rgb));
    font-size: .72rem;
    font-weight: 850;
}
.dish-details-cue { display: inline-flex; align-items: center; gap: .15rem; color: #64748b; font-size: .66rem; font-weight: 750; white-space: nowrap; }
.dish-ingredients p {
    margin: .22rem 0 0;
    color: #52615a;
    font-size: .75rem;
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.dish-details-only {
    width: 100%;
    min-height: 38px;
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: 0 .6rem;
    border: 1px solid #e6eee9;
    border-radius: 10px;
    background: #f8faf9;
    color: rgb(var(--primary-rgb));
    font: inherit;
    font-size: .74rem;
    font-weight: 800;
    cursor: pointer;
    text-align: start;
}
.dish-details-only .bi-chevron-left { margin-inline-start: auto; }
.dish-meta { display: flex; align-items: center; gap: .5rem; font-size: .68rem; color: #94a3b8; }
.dish-allergy { color: #b45309; }
.dish-foot { display: flex; align-items: center; justify-content: space-between; margin-top: auto; padding-top: .35rem; }
.dish-price { display: flex; align-items: baseline; gap: .4rem; }
.dish-price strong { font-size: 1rem; font-weight: 900; color: rgb(var(--primary-rgb)); }
.dish-price strong small { font-size: .68rem; font-weight: 700; }
.dish-price s { font-size: .72rem; color: #94a3b8; }
.dish-readonly {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    color: #64748b;
    font-size: .66rem;
    font-weight: 800;
}

.dish-add-btn {
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: 12px;
    background: rgb(var(--primary-rgb));
    color: #fff;
    font-size: 1rem;
    cursor: pointer;
    transition: filter .12s;
}
.dish-add-btn:active { filter: brightness(.9); }

.dish-stepper {
    display: inline-flex;
    align-items: center;
    gap: .15rem;
    background: rgba(var(--primary-rgb), .08);
    border-radius: 12px;
    padding: 2px;
}
.dish-stepper button {
    width: 36px;
    height: 36px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: rgb(var(--primary-rgb));
    font-size: .9rem;
    cursor: pointer;
}
.dish-stepper button:active { background: rgba(var(--primary-rgb), .15); }
.dish-stepper span {
    min-width: 1.6em;
    text-align: center;
    font-weight: 900;
    font-size: .95rem;
    color: #0f172a;
}
</style>
