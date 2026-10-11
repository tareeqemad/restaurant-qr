<script setup>
/**
 * Dish details + customization sheet. Modifier-group min/max is enforced
 * here for INSTANT feedback only — the server re-validates and re-prices
 * everything at submit (server-is-truth doctrine). Same sheet language as
 * the rest of the app: bottom sheet on phones, centered card on wide.
 */
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';

const props = defineProps({
    item: { type: Object, default: null },   // open when non-null
    symbol: { type: String, required: true },
    t: { type: Function, required: true },
    busy: { type: Boolean, default: false },
    orderingEnabled: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'add']);
const dialogPanel = ref(null);
const closeButton = ref(null);
let previousFocus = null;
let focusRevision = 0;

function restoreDialogFocus() {
    const target = previousFocus;
    previousFocus = null;
    const revision = ++focusRevision;
    nextTick(() => {
        if (revision === focusRevision && target?.isConnected) target.focus({ preventScroll: true });
    });
}

watch(() => Boolean(props.item), (open) => {
    if (!open) {
        restoreDialogFocus();
        return;
    }
    previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const revision = ++focusRevision;
    nextTick(() => {
        if (revision !== focusRevision || !props.item) return;
        (closeButton.value || dialogPanel.value)?.focus({ preventScroll: true });
    });
}, { immediate: true });

onBeforeUnmount(restoreDialogFocus);

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
                <div ref="dialogPanel" class="cs-sheet" role="dialog" aria-modal="true" :aria-label="item.name"
                     tabindex="-1" @keydown="handleDialogKeydown">
                    <div v-if="item.image" class="cs-hero">
                        <img :src="item.image" :alt="item.name">
                        <button ref="closeButton" type="button" class="cs-close" aria-label="إغلاق" @click="$emit('close')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <header v-else class="cs-plainhead">
                        <button ref="closeButton" type="button" class="cs-close is-inline" aria-label="إغلاق" @click="$emit('close')">
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

                        <details v-if="item.ingredients?.length" class="cs-ingredients cs-disclosure">
                            <summary>{{ t('dish_ingredients') ?? 'مكوّنات الطبق' }}</summary>
                            <div class="cs-tags">
                                <span v-for="ing in item.ingredients" :key="ing" class="cs-tag">{{ ing }}</span>
                            </div>
                        </details>

                        <div v-if="item.allergens.length" class="cs-allergy">
                            <i class="bi bi-exclamation-diamond-fill"></i>
                            <span>{{ item.allergens.join('، ') }}</span>
                        </div>

                        <template v-if="item.can_order && orderingEnabled">
                            <details v-if="item.removable_ingredients?.length" class="cs-exclusions cs-disclosure">
                                <summary>
                                    تعديل المكونات (اختياري)
                                    <span v-if="excludedIds.length">{{ excludedIds.length }} مستبعد</span>
                                </summary>
                                <div class="cs-exclusion-options">
                                    <button v-for="ingredient in item.removable_ingredients" :key="ingredient.id" type="button"
                                            class="cs-exclusion" :class="{ 'is-on': isExcluded(ingredient) }"
                                            :aria-pressed="isExcluded(ingredient)" @click="toggleIngredient(ingredient)">
                                        <i class="bi" :class="isExcluded(ingredient) ? 'bi-x-circle-fill' : 'bi-circle'"></i>
                                        <span>{{ isExcluded(ingredient) ? 'بدون ' : '' }}{{ ingredient.name }}</span>
                                        <small v-if="ingredient.requires_confirmation">مكوّن أساسي</small>
                                    </button>
                                </div>
                            </details>

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
    background: var(--surface);
    border-radius: 12px 12px 0 0;
    overflow: hidden;
}
@media (min-width: 768px) {
    .cs-backdrop { align-items: center; padding: 1rem; }
    .cs-sheet { border-radius: 12px; }
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
    color: #FFFDFA;
    cursor: pointer;
}
.cs-close.is-inline { position: static; background: var(--canvas); color: var(--ink); }

.cs-body { padding: 1rem 1.1rem; overflow-y: auto; display: flex; flex-direction: column; gap: .8rem; }
.cs-title { display: flex; align-items: flex-start; justify-content: space-between; gap: .8rem; }
.cs-title h3 { margin: 0; font-size: 1.12rem; font-weight: 800; color: var(--ink); }
.cs-price { text-align: end; flex-shrink: 0; }
.cs-price strong { display: block; font-size: 1.05rem; font-weight: 800; color: var(--brand); }
.cs-price s { font-size: .875rem; color: #315B6B; }
.cs-desc { margin: 0; font-size: .875rem; color: var(--ink); line-height: 1.6; }
.cs-unavailable {
    display: flex;
    align-items: flex-start;
    gap: .65rem;
    padding: .7rem .75rem;
    border: 1px solid var(--accent);
    border-radius: 12px;
    background: var(--canvas);
    color: #9a3412;
}
.cs-unavailable > i { margin-top: .1rem; font-size: 1rem; }
.cs-unavailable div { display: flex; flex-direction: column; gap: .14rem; }
.cs-unavailable strong { font-size: .875rem; font-weight: 800; }
.cs-unavailable span { color: #9a5a36; font-size: .875rem; line-height: 1.5; }
.cs-readonly { border-color: var(--line); background: var(--canvas); color: #315B6B; }
.cs-readonly span { color: var(--ink); }

.cs-ingredients {
    padding: .7rem .75rem;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--canvas);
}
.cs-ingredients h4 {
    display: flex;
    align-items: center;
    gap: .4rem;
    margin: 0 0 .55rem;
    color: var(--brand);
    font-size: .875rem;
    font-weight: 800;
}
.cs-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
.cs-tag {
    background: var(--surface);
    border: 1px solid var(--line);
    color: var(--brand);
    border-radius: 12px;
    padding: .18rem .6rem;
    font-size: .875rem;
    font-weight: 600;
}
.cs-exclusions {
    padding: .72rem .75rem;
    border: 1px solid #fecaca;
    border-radius: 12px;
    background: var(--surface);
}
.cs-exclusions header { display: flex; align-items: flex-start; justify-content: space-between; gap: .55rem; margin-bottom: .55rem; }
.cs-exclusions header div { display: grid; gap: .12rem; }
.cs-exclusions header strong { display: flex; align-items: center; gap: .35rem; color: #991b1b; font-size: .875rem; }
.cs-exclusions header small { color: #7f1d1d; font-size: .875rem; line-height: 1.45; }
.cs-exclusions header > span { flex: 0 0 auto; padding: .18rem .45rem; border-radius: 12px; background: #fee2e2; color: #991b1b; font-size: .875rem; font-weight: 800; }
.cs-exclusion-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .38rem; }
.cs-exclusion {
    min-height: 44px; display: flex; align-items: center; gap: .38rem;
    padding: .42rem .52rem; border: 1px solid #fecaca; border-radius: 10px;
    color: #7f1d1d; background: var(--surface); text-align: start; font: inherit; cursor: pointer;
}
.cs-exclusion > span { flex: 1; min-width: 0; font-size: .875rem; font-weight: 750; }
.cs-exclusion > small { color: #a16207; font-size: .875rem; }
.cs-exclusion > i { color: #fca5a5; }
.cs-exclusion.is-on { border-color: #ef4444; background: #fee2e2; color: #991b1b; }
.cs-exclusion.is-on > i { color: #dc2626; }
.cs-allergy {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: var(--canvas);
    border: 1px solid var(--accent);
    color: #92400e;
    border-radius: 10px;
    padding: .5rem .7rem;
    font-size: .875rem;
    font-weight: 700;
}

.cs-group header {
    display: flex;
    align-items: baseline;
    gap: .5rem;
    margin-bottom: .45rem;
}
.cs-group header strong { font-size: .9rem; color: var(--ink); }
.cs-group header small { color: #315B6B; font-weight: 600; }
.cs-group header small.is-need { color: #b45309; }
.cs-mods { display: flex; flex-direction: column; gap: .35rem; }
.cs-mod {
    display: flex;
    align-items: center;
    gap: .6rem;
    min-height: 46px;
    padding: 0 .8rem;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    background: var(--surface);
    font: inherit;
    font-size: .875rem;
    font-weight: 600;
    color: var(--ink);
    cursor: pointer;
    text-align: start;
}
.cs-mod > span { flex: 1; min-width: 0; }
.cs-mod > small { color: #315B6B; font-weight: 700; }
.cs-mod > i { color: #FFFDFA; font-size: 1rem; }
.cs-mod.is-on { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 5%, transparent); }
.cs-mod.is-on > i { color: var(--brand); }

.cs-notes { display: flex; flex-direction: column; gap: .35rem; margin: 0; }
.cs-notes span { font-size: .875rem; font-weight: 700; color: var(--ink); }
.cs-notes textarea {
    border: 1.5px solid var(--line);
    border-radius: 12px;
    padding: .6rem .8rem;
    font: inherit;
    font-size: .875rem;
    resize: none;
}
.cs-notes textarea:focus { outline: none; border-color: var(--brand); }

.cs-foot {
    display: flex;
    gap: .6rem;
    padding: .8rem 1.1rem calc(.8rem + env(safe-area-inset-bottom));
    border-top: 1px solid var(--line);
    background: var(--surface);
    flex-shrink: 0;
}
.cs-foot--unavailable { display: block; }
.cs-back-menu {
    width: 100%;
    min-height: 48px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--canvas);
    color: var(--brand);
    font: inherit;
    font-size: .9rem;
    font-weight: 800;
    cursor: pointer;
}
.cs-stepper {
    display: inline-flex;
    align-items: center;
    gap: .1rem;
    background: var(--canvas);
    border-radius: 8px;
    padding: 3px;
}
.cs-stepper button {
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--ink);
    font-size: .95rem;
    cursor: pointer;
}
.cs-stepper button:disabled { opacity: .35; }
.cs-stepper span { min-width: 2em; text-align: center; font-weight: 800; font-size: 1.05rem; }
.cs-add {
    flex: 1;
    min-height: 48px;
    border: 0;
    border-radius: 12px;
    background: var(--brand);
    color: #FFFDFA;
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

/* Task-specific component layout. */
.cs-sheet{border-radius:12px;max-height:94dvh;overflow-y:auto;background:var(--surface)}.cs-hero{height:210px;border-radius:0}.cs-hero img{object-fit:cover;height:100%;width:100%}.cs-close{width:44px;height:44px;border-radius:8px;top:16px;inset-inline-end:16px}.cs-body{padding:24px}.cs-title{display:flex;justify-content:space-between;gap:16px;align-items:start;border-bottom:1px solid var(--line);padding-bottom:18px;margin-bottom:18px}.cs-title h2{font-size:25px;line-height:1.4}.cs-price{font-size:23px;white-space:nowrap}.cs-desc{font-size:15px;line-height:1.75}.cs-ingredients,.cs-group,.cs-exclusions,.cs-notes{padding:18px 0;border:0;border-top:1px solid var(--line);border-radius:0;background:transparent}.cs-group h3,.cs-ingredients h3,.cs-exclusions h3{font-size:17px}.cs-mods,.cs-exclusion-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.cs-mod,.cs-exclusion{min-height:54px;padding:12px;border-radius:6px;font-size:15px}.cs-foot{position:sticky;bottom:0;display:flex;gap:14px;padding:18px 24px;background:var(--surface);border-top:1px solid var(--line);z-index:2}.cs-add{min-height:52px;border-radius:8px;font-size:16px}.cs-stepper button{width:44px;height:48px}.cs-notes textarea{font-size:15px;min-height:85px;border-radius:6px}.cs-allergy{font-size:14px;line-height:1.65;padding:12px;border-radius:6px}@media(max-width:560px){.cs-body{padding:18px}.cs-foot{padding:16px 18px;gap:10px}.cs-mods,.cs-exclusion-options{grid-template-columns:1fr}.cs-title h2{font-size:22px}.cs-price{font-size:20px}}
.cs-disclosure > summary { min-height: 44px; padding: 10px 0; cursor: pointer; color: var(--ink); font-size: 15px; font-weight: 750; }
.cs-disclosure > summary::marker { color: var(--brand); }
.cs-disclosure > summary:focus-visible { outline: 2px solid var(--brand); outline-offset: 3px; border-radius: 4px; }
.cs-disclosure > summary > span { display: inline-block; margin-inline-start: 8px; padding: 2px 6px; border-radius: 4px; background: var(--brand-soft); color: var(--brand); font-size: 14px; }
.cs-disclosure.cs-ingredients, .cs-disclosure.cs-exclusions { padding: 4px 0; }
.cs-disclosure[open] > summary { margin-bottom: 8px; }
.cs-disclosure[open] { padding-bottom: 14px; }
</style>
