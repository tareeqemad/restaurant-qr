<script setup>
/**
 * Dish details + customization sheet. Modifier-group min/max is enforced
 * here for INSTANT feedback only — the server re-validates and re-prices
 * everything at submit (server-is-truth doctrine). Same sheet language as
 * the rest of the app: bottom sheet on phones, centered card on wide.
 */
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    item: { type: Object, default: null },   // open when non-null
    symbol: { type: String, required: true },
    t: { type: Function, required: true },
    busy: { type: Boolean, default: false },
    orderingEnabled: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'add']);

const qty = ref(1);
const notes = ref('');
const excludedIds = ref([]);
const picked = reactive(new Map()); // groupId → Set(modifierId)

watch(() => props.item, (item) => {
    if (! item) return;
    qty.value = 1;
    notes.value = '';
    excludedIds.value = [];
    picked.clear();
    item.modifier_groups.forEach((g) => picked.set(g.id, new Set()));
});

const toggleMod = (group, mod) => {
    const set = picked.get(group.id);
    if (set.has(mod.id)) {
        set.delete(mod.id);
    } else {
        // Single-choice groups swap instead of erroring on max.
        if (group.max_select === 1) set.clear();
        else if (group.max_select && set.size >= group.max_select) return;
        set.add(mod.id);
    }
    // Reassign to trigger reactivity on the Map.
    picked.set(group.id, new Set(set));
};

const isPicked = (group, mod) => picked.get(group.id)?.has(mod.id) ?? false;
const isExcluded = (ingredient) => excludedIds.value.includes(Number(ingredient.id));
const toggleIngredient = (ingredient) => {
    const id = Number(ingredient.id);
    excludedIds.value = isExcluded(ingredient)
        ? excludedIds.value.filter((value) => value !== id)
        : [...excludedIds.value, id];
};

const groupProblem = (group) => {
    const n = picked.get(group.id)?.size ?? 0;
    const min = group.required ? Math.max(1, group.min_select ?? 0) : (group.min_select ?? 0);
    if (n < min) return props.t('pick_at_least', { count: min }) ?? `اختر ${min} على الأقل`;
    return null;
};

const firstProblem = computed(() => {
    for (const g of props.item?.modifier_groups ?? []) {
        const p = groupProblem(g);
        if (p) return { group: g, message: p };
    }
    return null;
});

const chosenModifiers = computed(() => {
    const all = [];
    for (const g of props.item?.modifier_groups ?? []) {
        const set = picked.get(g.id);
        if (! set) continue;
        g.modifiers.forEach((m) => { if (set.has(m.id)) all.push(m); });
    }
    return all;
});

const lineTotal = computed(() => {
    if (! props.item) return 0;
    const mods = chosenModifiers.value.reduce((s, m) => s + Number(m.price_delta), 0);
    return (Number(props.item.price) + mods) * qty.value;
});

const money = (n) => (Number(n) || 0).toFixed(2);

const confirm = () => {
    if (firstProblem.value || props.busy || ! props.orderingEnabled) return;
    emit('add', {
        item: props.item,
        quantity: qty.value,
        modifierIds: chosenModifiers.value.map((m) => m.id),
        modifiers: chosenModifiers.value.map((m) => ({ id: m.id, name: m.name, price_delta: m.price_delta })),
        excludedIngredientIds: [...excludedIds.value],
        exclusions: (props.item.removable_ingredients ?? [])
            .filter((ingredient) => excludedIds.value.includes(Number(ingredient.id))),
        notes: notes.value.trim() || null,
    });
};
</script>

<template>
    <Teleport to="body">
        <Transition name="cs">
            <div v-if="item" class="cs-backdrop" @click.self="$emit('close')">
                <div class="cs-sheet" role="dialog" aria-modal="true" :aria-label="item.name">
                    <div v-if="item.image" class="cs-hero">
                        <img :src="item.image" :alt="item.name">
                        <button type="button" class="cs-close" aria-label="إغلاق" @click="$emit('close')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <header v-else class="cs-plainhead">
                        <button type="button" class="cs-close is-inline" aria-label="إغلاق" @click="$emit('close')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>

                    <div class="cs-body">
                        <div class="cs-title">
                            <h3>{{ item.name }}</h3>
                            <div class="cs-price">
                                <strong>{{ money(item.price) }} {{ symbol }}</strong>
                                <s v-if="item.original_price">{{ money(item.original_price) }}</s>
                            </div>
                        </div>

                        <p v-if="item.description" class="cs-desc">{{ item.description }}</p>

                        <div v-if="! item.can_order" class="cs-unavailable">
                            <i class="bi bi-calendar-x"></i>
                            <div>
                                <strong>{{ item.unavailable_reason ?? 'غير متوفر اليوم' }}</strong>
                                <span>تقدر تشوف المكونات الآن وتختار صنفاً آخر من المنيو.</span>
                            </div>
                        </div>

                        <div v-else-if="! orderingEnabled" class="cs-unavailable cs-readonly">
                            <i class="bi bi-phone"></i>
                            <div>
                                <strong>الإضافات من الهاتف الذي أرسل أول طلب</strong>
                                <span>تقدر تتصفح المكونات والتفاصيل، أو تطلب الإضافة من صاحب الهاتف أو الجرسون.</span>
                            </div>
                        </div>

                        <section v-if="item.ingredients?.length" class="cs-ingredients">
                            <h4><i class="bi bi-list-check"></i> {{ t('dish_ingredients') ?? 'مكوّنات الطبق' }}</h4>
                            <div class="cs-tags">
                                <span v-for="ing in item.ingredients" :key="ing" class="cs-tag">{{ ing }}</span>
                            </div>
                        </section>

                        <div v-if="item.allergens.length" class="cs-allergy">
                            <i class="bi bi-exclamation-diamond-fill"></i>
                            <span>{{ item.allergens.join('، ') }}</span>
                        </div>

                        <template v-if="item.can_order && orderingEnabled">
                            <section v-if="item.removable_ingredients?.length" class="cs-exclusions">
                                <header>
                                    <div>
                                        <strong><i class="bi bi-slash-circle"></i> بدك الطبق بدون مكوّن؟</strong>
                                        <small>اختره بوضوح؛ سيظهر مع الصنف للمطبخ أثناء التحضير.</small>
                                    </div>
                                    <span v-if="excludedIds.length">{{ excludedIds.length }} مستبعد</span>
                                </header>
                                <div class="cs-exclusion-options">
                                    <button v-for="ingredient in item.removable_ingredients" :key="ingredient.id" type="button"
                                            class="cs-exclusion" :class="{ 'is-on': isExcluded(ingredient) }"
                                            :aria-pressed="isExcluded(ingredient)" @click="toggleIngredient(ingredient)">
                                        <i class="bi" :class="isExcluded(ingredient) ? 'bi-x-circle-fill' : 'bi-circle'"></i>
                                        <span>{{ isExcluded(ingredient) ? 'بدون ' : '' }}{{ ingredient.name }}</span>
                                        <small v-if="ingredient.requires_confirmation">مكوّن أساسي</small>
                                    </button>
                                </div>
                            </section>

                            <section v-for="g in item.modifier_groups" :key="g.id" class="cs-group">
                                <header>
                                    <strong>{{ g.name }}</strong>
                                    <small v-if="groupProblem(g)" class="is-need">{{ groupProblem(g) }}</small>
                                    <small v-else-if="g.max_select">{{ g.max_select === 1 ? 'اختيار واحد' : `حتى ${g.max_select}` }}</small>
                                </header>
                                <div class="cs-mods">
                                    <button v-for="m in g.modifiers" :key="m.id" type="button"
                                            class="cs-mod" :class="{ 'is-on': isPicked(g, m) }"
                                            :aria-pressed="isPicked(g, m)"
                                            @click="toggleMod(g, m)">
                                        <i class="bi" :class="isPicked(g, m) ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                                        <span>{{ m.name }}</span>
                                        <small v-if="Number(m.price_delta) !== 0">
                                            {{ Number(m.price_delta) > 0 ? '+' : '' }}{{ money(m.price_delta) }}
                                        </small>
                                    </button>
                                </div>
                            </section>

                            <label class="cs-notes">
                                <span>{{ t('notes_label') ?? 'ملاحظات للمطبخ (اختياري)' }}</span>
                                <textarea v-model="notes" rows="2" maxlength="500"
                                          :placeholder="t('notes_placeholder') ?? 'بدون بصل، حار زيادة…'"></textarea>
                            </label>
                        </template>
                    </div>

                    <footer v-if="item.can_order && orderingEnabled" class="cs-foot">
                        <div class="cs-stepper">
                            <button type="button" aria-label="أنقص" :disabled="qty <= 1" @click="qty--"><i class="bi bi-dash-lg"></i></button>
                            <span>{{ qty }}</span>
                            <button type="button" aria-label="زد" :disabled="qty >= 99" @click="qty++"><i class="bi bi-plus-lg"></i></button>
                        </div>
                        <button type="button" class="cs-add" :disabled="Boolean(firstProblem) || busy" @click="confirm">
                            <i class="bi" :class="busy ? 'bi-arrow-repeat cs-spin' : 'bi-bag-plus'"></i>
                            {{ t('add_to_cart') ?? 'أضف للسلة' }} · {{ money(lineTotal) }} {{ symbol }}
                        </button>
                    </footer>
                    <footer v-else class="cs-foot cs-foot--unavailable">
                        <button type="button" class="cs-back-menu" @click="$emit('close')">
                            <i class="bi bi-arrow-right"></i>
                            رجوع للمنيو
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.cs-backdrop {
    position: fixed;
    inset: 0;
    z-index: 17000;
    background: rgba(15, 23, 42, .5);
    display: flex;
    align-items: flex-end;
    justify-content: center;
}
.cs-sheet {
    width: min(560px, 100%);
    max-height: 94dvh;
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 20px 20px 0 0;
    overflow: hidden;
}
@media (min-width: 768px) {
    .cs-backdrop { align-items: center; padding: 1rem; }
    .cs-sheet { border-radius: 20px; }
}

.cs-hero { position: relative; aspect-ratio: 16 / 9; flex-shrink: 0; }
.cs-hero img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cs-plainhead { display: flex; justify-content: flex-end; padding: .75rem .75rem 0; }
.cs-close {
    position: absolute;
    top: 10px;
    inset-inline-end: 10px;
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 50%;
    background: rgba(15, 23, 42, .55);
    color: #fff;
    cursor: pointer;
}
.cs-close.is-inline { position: static; background: #f1f5f9; color: #475569; }

.cs-body { padding: 1rem 1.1rem; overflow-y: auto; display: flex; flex-direction: column; gap: .8rem; }
.cs-title { display: flex; align-items: flex-start; justify-content: space-between; gap: .8rem; }
.cs-title h3 { margin: 0; font-size: 1.12rem; font-weight: 900; color: #0f172a; }
.cs-price { text-align: end; flex-shrink: 0; }
.cs-price strong { display: block; font-size: 1.05rem; font-weight: 900; color: rgb(var(--primary-rgb)); }
.cs-price s { font-size: .76rem; color: #94a3b8; }
.cs-desc { margin: 0; font-size: .86rem; color: #475569; line-height: 1.6; }
.cs-unavailable {
    display: flex;
    align-items: flex-start;
    gap: .65rem;
    padding: .7rem .75rem;
    border: 1px solid #fed7aa;
    border-radius: 13px;
    background: #fff7ed;
    color: #9a3412;
}
.cs-unavailable > i { margin-top: .1rem; font-size: 1rem; }
.cs-unavailable div { display: flex; flex-direction: column; gap: .14rem; }
.cs-unavailable strong { font-size: .84rem; font-weight: 850; }
.cs-unavailable span { color: #9a5a36; font-size: .75rem; line-height: 1.5; }
.cs-readonly { border-color: #bfdbfe; background: #eff6ff; color: #1e40af; }
.cs-readonly span { color: #475569; }

.cs-ingredients {
    padding: .7rem .75rem;
    border: 1px solid #e3ece6;
    border-radius: 13px;
    background: #f8faf9;
}
.cs-ingredients h4 {
    display: flex;
    align-items: center;
    gap: .4rem;
    margin: 0 0 .55rem;
    color: rgb(var(--primary-rgb));
    font-size: .82rem;
    font-weight: 850;
}
.cs-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
.cs-tag {
    background: #fff;
    border: 1px solid #dce8e0;
    color: #3f5147;
    border-radius: 999px;
    padding: .18rem .6rem;
    font-size: .72rem;
    font-weight: 600;
}
.cs-exclusions {
    padding: .72rem .75rem;
    border: 1px solid #fecaca;
    border-radius: 13px;
    background: #fff8f8;
}
.cs-exclusions header { display: flex; align-items: flex-start; justify-content: space-between; gap: .55rem; margin-bottom: .55rem; }
.cs-exclusions header div { display: grid; gap: .12rem; }
.cs-exclusions header strong { display: flex; align-items: center; gap: .35rem; color: #991b1b; font-size: .82rem; }
.cs-exclusions header small { color: #7f1d1d; font-size: .69rem; line-height: 1.45; }
.cs-exclusions header > span { flex: 0 0 auto; padding: .18rem .45rem; border-radius: 999px; background: #fee2e2; color: #991b1b; font-size: .67rem; font-weight: 850; }
.cs-exclusion-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .38rem; }
.cs-exclusion {
    min-height: 44px; display: flex; align-items: center; gap: .38rem;
    padding: .42rem .52rem; border: 1px solid #fecaca; border-radius: 10px;
    color: #7f1d1d; background: #fff; text-align: start; font: inherit; cursor: pointer;
}
.cs-exclusion > span { flex: 1; min-width: 0; font-size: .76rem; font-weight: 750; }
.cs-exclusion > small { color: #a16207; font-size: .61rem; }
.cs-exclusion > i { color: #fca5a5; }
.cs-exclusion.is-on { border-color: #ef4444; background: #fee2e2; color: #991b1b; }
.cs-exclusion.is-on > i { color: #dc2626; }
.cs-allergy {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 10px;
    padding: .5rem .7rem;
    font-size: .78rem;
    font-weight: 700;
}

.cs-group header {
    display: flex;
    align-items: baseline;
    gap: .5rem;
    margin-bottom: .45rem;
}
.cs-group header strong { font-size: .9rem; color: #0f172a; }
.cs-group header small { color: #94a3b8; font-weight: 600; }
.cs-group header small.is-need { color: #b45309; }
.cs-mods { display: flex; flex-direction: column; gap: .35rem; }
.cs-mod {
    display: flex;
    align-items: center;
    gap: .6rem;
    min-height: 46px;
    padding: 0 .8rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
    font: inherit;
    font-size: .86rem;
    font-weight: 600;
    color: #334155;
    cursor: pointer;
    text-align: start;
}
.cs-mod > span { flex: 1; min-width: 0; }
.cs-mod > small { color: #64748b; font-weight: 700; }
.cs-mod > i { color: #cbd5e1; font-size: 1rem; }
.cs-mod.is-on { border-color: rgb(var(--primary-rgb)); background: rgba(var(--primary-rgb), .05); }
.cs-mod.is-on > i { color: rgb(var(--primary-rgb)); }

.cs-notes { display: flex; flex-direction: column; gap: .35rem; margin: 0; }
.cs-notes span { font-size: .8rem; font-weight: 700; color: #334155; }
.cs-notes textarea {
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: .6rem .8rem;
    font: inherit;
    font-size: .86rem;
    resize: none;
}
.cs-notes textarea:focus { outline: none; border-color: rgb(var(--primary-rgb)); }

.cs-foot {
    display: flex;
    gap: .6rem;
    padding: .8rem 1.1rem calc(.8rem + env(safe-area-inset-bottom));
    border-top: 1px solid #eef2f6;
    background: #fff;
    flex-shrink: 0;
}
.cs-foot--unavailable { display: block; }
.cs-back-menu {
    width: 100%;
    min-height: 48px;
    border: 1px solid #d9e5dd;
    border-radius: 13px;
    background: #f8faf9;
    color: rgb(var(--primary-rgb));
    font: inherit;
    font-size: .9rem;
    font-weight: 850;
    cursor: pointer;
}
.cs-stepper {
    display: inline-flex;
    align-items: center;
    gap: .1rem;
    background: #f1f5f9;
    border-radius: 13px;
    padding: 3px;
}
.cs-stepper button {
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #0f172a;
    font-size: .95rem;
    cursor: pointer;
}
.cs-stepper button:disabled { opacity: .35; }
.cs-stepper span { min-width: 2em; text-align: center; font-weight: 900; font-size: 1.05rem; }
.cs-add {
    flex: 1;
    min-height: 48px;
    border: 0;
    border-radius: 13px;
    background: rgb(var(--primary-rgb));
    color: #fff;
    font: inherit;
    font-size: .95rem;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
}
.cs-add:disabled { opacity: .55; }
.cs-spin { animation: cs-rotate 1s linear infinite; }
@keyframes cs-rotate { to { transform: rotate(360deg); } }

.cs-enter-active, .cs-leave-active { transition: opacity .16s; }
.cs-enter-from, .cs-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
    .cs-enter-active, .cs-leave-active { transition: none; }
}
@media (max-width: 390px) { .cs-exclusion-options { grid-template-columns: 1fr; } }
</style>
