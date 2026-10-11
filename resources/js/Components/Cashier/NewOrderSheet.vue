<script setup>
import { computed, ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    open: { type: Boolean, required: true },
    catalog: { type: Object, required: true },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits({ close: () => true, submit: (payload) => Array.isArray(payload?.cart) && payload.cart.length > 0 });

const search = ref('');
const category = ref('');
const cart = ref([]);
const customerName = ref('');
const customerPhone = ref('');
const notes = ref('');
const configItem = ref(null);
const draftQuantity = ref(1);
const draftModifiers = ref([]);
const draftNotes = ref('');
let wasOpen = false;

const visibleItems = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('ar');
    return (props.catalog.items ?? []).filter((item) => {
        if (category.value && Number(item.category_id) !== Number(category.value)) return false;
        return !term || item.name.toLocaleLowerCase('ar').includes(term) || String(item.sku || '').toLocaleLowerCase().includes(term);
    });
});
const total = computed(() => cart.value.reduce((sum, line) => sum + line.unit_price * line.quantity, 0));
const draftValid = computed(() => !configItem.value || configItem.value.modifier_groups.every((group) => {
    const count = group.modifiers.filter((modifier) => draftModifiers.value.includes(modifier.id)).length;
    return (!group.required || count >= Number(group.min_select || 0))
        && (Number(group.max_select || 0) <= 0 || count <= Number(group.max_select));
}));

watch(() => props.open, (open) => {
    if (open && !wasOpen) reset();
    wasOpen = open;
}, { immediate: true });

function reset() {
    search.value = ''; category.value = ''; cart.value = [];
    customerName.value = ''; customerPhone.value = ''; notes.value = ''; configItem.value = null;
}

function chooseItem(item) {
    if (!item.in_stock) return;
    if (item.modifier_groups?.length) {
        configItem.value = item; draftQuantity.value = 1; draftModifiers.value = []; draftNotes.value = '';
        return;
    }
    appendLine(item, 1, [], '');
}

function modifierSelected(id) { return draftModifiers.value.includes(id); }
function toggleModifier(group, id) {
    if (modifierSelected(id)) {
        draftModifiers.value = draftModifiers.value.filter((value) => value !== id);
        return;
    }
    const count = group.modifiers.filter((modifier) => modifierSelected(modifier.id)).length;
    if (Number(group.max_select || 0) > 0 && count >= Number(group.max_select)) return;
    draftModifiers.value.push(id);
}
function addConfigured() {
    if (!draftValid.value) return;
    appendLine(configItem.value, draftQuantity.value, draftModifiers.value, draftNotes.value);
    configItem.value = null;
}
function appendLine(item, quantity, modifierIds, lineNotes) {
    const modifiers = item.modifier_groups.flatMap((group) => group.modifiers).filter((modifier) => modifierIds.includes(modifier.id));
    cart.value.push({
        key: `${Date.now()}-${Math.random()}`,
        menu_item_id: item.id,
        name: item.name,
        quantity: Number(quantity),
        modifier_ids: [...modifierIds],
        modifiers,
        notes: lineNotes.trim() || null,
        unit_price: Number(item.price) + modifiers.reduce((sum, modifier) => sum + Number(modifier.price_delta), 0),
    });
}
function submit() {
    if (!cart.value.length) return;
    emit('submit', {
        customer_name: customerName.value.trim() || null,
        customer_phone: customerPhone.value.trim(),
        notes: notes.value.trim() || null,
        cart: cart.value.map((line) => ({ menu_item_id: line.menu_item_id, quantity: line.quantity, modifier_ids: line.modifier_ids, notes: line.notes })),
    });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer">
            <section class="order-sheet" role="dialog" aria-modal="true" aria-labelledby="new-order-title">
                <header><div><span>من مكالمة الزبون إلى التحضير</span><h2 id="new-order-title">إدخال طلب هاتفي</h2></div><button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button></header>
                <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>
                <div class="builder-layout">
                    <div class="catalog-pane">
                        <div class="catalog-tools"><label><i class="bi bi-search"></i><input v-model="search" placeholder="ابحث بالاسم أو الرمز"></label><select v-model="category"><option value="">كل الأقسام</option><option v-for="item in catalog.categories" :key="item.id" :value="item.id">{{ item.name }} ({{ item.count }})</option></select></div>
                        <div class="item-grid">
                            <button v-for="item in visibleItems" :key="item.id" type="button" :disabled="!item.in_stock" @click="chooseItem(item)">
                                <span class="item-mark">{{ item.name.slice(0, 1) }}</span><span><strong>{{ item.name }}</strong><small v-if="!item.in_stock">نفد المخزون</small><small v-else-if="item.has_promo"><s>{{ formatMoney(item.original_price, currency) }}</s> عرض</small></span><b>{{ formatMoney(item.price, currency) }}</b>
                            </button>
                        </div>
                    </div>

                    <aside class="cart-pane">
                        <h3><span><i class="bi bi-bag"></i> الطلب</span><b>{{ cart.length }}</b></h3>
                        <div class="cart-lines">
                            <article v-for="line in cart" :key="line.key"><span><strong>{{ line.name }}</strong><small v-if="line.modifiers.length">{{ line.modifiers.map((item) => item.name).join('، ') }}</small></span><div><button type="button" @click="line.quantity = Math.max(1, line.quantity - 1)">−</button><b>{{ line.quantity }}</b><button type="button" @click="line.quantity++">+</button></div><strong>{{ formatMoney(line.unit_price * line.quantity, currency) }}</strong><button type="button" class="remove" @click="cart = cart.filter((item) => item.key !== line.key)"><i class="bi bi-x"></i></button></article>
                            <p v-if="!cart.length" class="empty-cart"><i class="bi bi-bag-plus"></i> اختر الأصناف من القائمة</p>
                        </div>
                        <div class="cart-total"><span>الإجمالي المتوقع</span><strong>{{ formatMoney(total, currency) }}</strong></div>
                    </aside>
                </div>

                <section class="order-details" aria-labelledby="phone-order-details-title">
                    <div class="details-heading">
                        <span><i class="bi bi-telephone"></i><b id="phone-order-details-title">بيانات الزبون والاستلام</b><small>رقم الهاتف يربط الطلب بملف الزبون، والاسم اختياري</small></span>
                        <strong><i class="bi bi-shop"></i> الاستلام من المطعم</strong>
                    </div>
                    <div class="detail-grid">
                        <label><span>رقم هاتف الزبون *</span><input v-model="customerPhone" type="tel" inputmode="tel" autocomplete="tel" maxlength="32" placeholder="مثال: 0599000000"><small v-if="errors.customer_phone">{{ errors.customer_phone[0] }}</small></label>
                        <label><span>اسم الزبون</span><input v-model="customerName" autocomplete="name" maxlength="120" placeholder="اختياري"></label>
                        <label class="wide"><span>ملاحظة للمطبخ أو البار</span><textarea v-model="notes" maxlength="500" rows="2" placeholder="مثال: بدون بصل"></textarea></label>
                    </div>
                </section>

                <footer><span>سيُرسل الطلب مباشرةً للمطبخ والبار حسب الأصناف، بلا رسوم أو التزام توصيل على المطعم.</span><button type="button" class="secondary" :disabled="busy" @click="emit('close')">إلغاء</button><button type="button" class="primary" :disabled="busy || !cart.length || !customerPhone.trim()" @click="submit">{{ busy ? 'جاري الإرسال…' : 'إنشاء وإرسال للمطبخ' }}</button></footer>
            </section>

            <section v-if="configItem" class="modifier-layer" @click.self="configItem = null"><div class="modifier-card"><header><div><span>خيارات الصنف</span><h2>{{ configItem.name }}</h2></div><button type="button" @click="configItem = null"><i class="bi bi-x-lg"></i></button></header><label class="quantity"><span>الكمية</span><div><button type="button" @click="draftQuantity = Math.max(1, draftQuantity - 1)">−</button><b>{{ draftQuantity }}</b><button type="button" @click="draftQuantity++">+</button></div></label><fieldset v-for="group in configItem.modifier_groups" :key="group.id"><legend>{{ group.name }} <small v-if="group.required">مطلوب {{ group.min_select }}</small><small v-if="group.max_select > 0">حتى {{ group.max_select }}</small></legend><button v-for="modifier in group.modifiers" :key="modifier.id" type="button" :class="{ active: modifierSelected(modifier.id) }" @click="toggleModifier(group, modifier.id)"><i :class="modifierSelected(modifier.id) ? 'bi bi-check2-square' : 'bi bi-square'"></i><span>{{ modifier.name }}</span><b>+{{ formatMoney(modifier.price_delta, currency) }}</b></button></fieldset><label class="line-note"><span>ملاحظة للصنف</span><input v-model="draftNotes" maxlength="500"></label><footer><button type="button" class="secondary" @click="configItem = null">إلغاء</button><button type="button" class="primary" :disabled="!draftValid" @click="addConfigured">إضافة للطلب</button></footer></div></section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; place-items: center; padding: .7rem; background: rgba(15, 27, 19, .48); backdrop-filter: none; }.order-sheet { display: flex; width: min(1120px, 100%); max-height: calc(100dvh - 1.4rem); box-sizing: border-box; flex-direction: column; padding: .85rem; border: 1px solid var(--line, var(--line)); border-radius: 12px; background: var(--surface, #FFFDFA); box-shadow: none; overflow: hidden; }.order-sheet > header, .modifier-card > header { display: flex; align-items: center; justify-content: space-between; }.order-sheet > header > div, .modifier-card > header > div { display: flex; flex-direction: column; }.order-sheet > header span, .modifier-card > header span { color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; }.order-sheet h2, .modifier-card h2 { margin: .08rem 0 0; color: var(--ink, var(--ink)); font-size: .92rem; }.order-sheet > header button, .modifier-card > header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid var(--line, var(--line)); border-radius: 8px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }.sheet-error { margin-top: .45rem; padding: .45rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .875rem; }.builder-layout { display: grid; min-height: 270px; flex: 1; grid-template-columns: minmax(0, 1.65fr) minmax(290px, .75fr); gap: .6rem; margin-top: .55rem; overflow: hidden; }.catalog-pane, .cart-pane { min-height: 0; border: 1px solid var(--line, var(--line)); border-radius: 12px; overflow: hidden; }.catalog-pane { display: flex; flex-direction: column; }.catalog-tools { display: grid; grid-template-columns: 1fr 190px; gap: .4rem; padding: .5rem; border-bottom: 1px solid var(--line, var(--line)); }.catalog-tools label { position: relative; }.catalog-tools i { position: absolute; top: 50%; inset-inline-start: .6rem; color: var(--muted, var(--muted)); transform: translateY(-50%); }.catalog-tools input { padding-inline-start: 1.8rem; }.catalog-tools input, .catalog-tools select { width: 100%; min-height: 42px; box-sizing: border-box; border: 1px solid var(--line, var(--line)); border-radius: 8px; font: inherit; font-size: .875rem; }.item-grid { display: grid; min-height: 0; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .4rem; padding: .5rem; overflow-y: auto; }.item-grid > button { display: grid; min-height: 74px; grid-template-columns: 34px minmax(0, 1fr); gap: .4rem; align-items: center; padding: .45rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; color: var(--ink, var(--ink)); background: var(--surface, #FFFDFA); text-align: start; font: inherit; }.item-grid > button > span:nth-child(2) { display: flex; min-width: 0; flex-direction: column; }.item-grid strong { overflow: hidden; font-size: .875rem; text-overflow: ellipsis; white-space: nowrap; }.item-grid small { margin-top: .08rem; color: var(--muted, var(--muted)); font-size: .875rem; }.item-grid b { grid-column: 2; color: var(--ink, var(--ink)); font-size: .875rem; }.item-mark { display: grid; width: 34px; height: 34px; grid-row: 1 / 3; place-items: center; border-radius: 9px; color: #FFFDFA; background: var(--brand, #A3422B); font-size: .875rem; font-weight: 800; }.item-grid button:disabled { opacity: .42; }.cart-pane { display: flex; flex-direction: column; }.cart-pane > h3 { display: flex; min-height: 42px; align-items: center; justify-content: space-between; margin: 0; padding-inline: .55rem; border-bottom: 1px solid var(--line, var(--line)); font-size: .875rem; }.cart-pane > h3 span { display: flex; gap: .3rem; }.cart-pane > h3 b { display: grid; min-width: 22px; height: 22px; place-items: center; border-radius: 12px; color: var(--ink, var(--ink)); background: var(--brand-soft, #F3E3D8); font-size: .875rem; }.cart-lines { min-height: 0; flex: 1; padding: .3rem .45rem; overflow-y: auto; }.cart-lines article { display: grid; grid-template-columns: minmax(0, 1fr) auto auto 28px; gap: .3rem; align-items: center; padding: .4rem 0; border-bottom: 1px solid var(--line, var(--line)); }.cart-lines article > span { display: flex; min-width: 0; flex-direction: column; }.cart-lines article strong { font-size: .875rem; }.cart-lines article small { color: var(--muted, var(--muted)); font-size: .875rem; }.cart-lines article > div { display: flex; align-items: center; gap: .22rem; }.cart-lines article > div button, .cart-lines .remove { display: grid; width: 28px; height: 28px; place-items: center; border: 0; border-radius: 7px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }.cart-lines article > div b { min-width: 18px; text-align: center; font-size: .875rem; }.cart-lines .remove { color: #9d3039; background: #fff0f1; }.empty-cart { display: flex; min-height: 140px; align-items: center; justify-content: center; flex-direction: column; gap: .35rem; color: var(--muted, var(--muted)); font-size: .875rem; }.empty-cart i { font-size: 1.2rem; }.cart-total { display: flex; align-items: center; justify-content: space-between; padding: .55rem; border-top: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); font-size: .875rem; }.cart-total strong { color: var(--ink, var(--ink)); font-size: .875rem; }.order-details { margin-top: .5rem; border: 1px solid var(--line, var(--line)); border-radius: 11px; overflow: hidden; }.order-details summary { min-height: 38px; padding: .55rem; box-sizing: border-box; color: var(--muted, var(--muted)); cursor: pointer; font-size: .875rem; font-weight: 800; }.detail-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: .4rem; padding: 0 .55rem .55rem; }.detail-grid label { display: flex; flex-direction: column; gap: .2rem; }.detail-grid label > span { color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; }.detail-grid input, .detail-grid select, .detail-grid textarea { width: 100%; min-height: 39px; box-sizing: border-box; padding: .45rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; resize: vertical; font: inherit; font-size: .875rem; }.detail-grid small { color: #a62f38; font-size: .875rem; }.detail-grid .wide { grid-column: span 2; }.order-sheet > footer { display: flex; align-items: center; gap: .4rem; margin-top: .55rem; }.order-sheet > footer > span { flex: 1; color: var(--muted, var(--muted)); font-size: .875rem; }.order-sheet footer button, .modifier-card footer button { min-height: 44px; padding-inline: .8rem; border-radius: 8px; font: inherit; font-size: .875rem; font-weight: 800; }.secondary { border: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }.primary { border: 1px solid var(--brand, #A3422B); color: #FFFDFA; background: var(--brand, #A3422B); }button:disabled { opacity: .45; }.modifier-layer { position: fixed; z-index: 1200; inset: 0; display: grid; place-items: center; padding: 1rem; background: rgba(15, 27, 19, .38); }.modifier-card { width: min(500px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: .85rem; border-radius: 12px; background: var(--surface, #FFFDFA); overflow-y: auto; box-shadow: none; }.quantity { display: flex; align-items: center; justify-content: space-between; margin-top: .6rem; color: var(--muted, var(--muted)); font-size: .875rem; }.quantity > div { display: flex; align-items: center; gap: .4rem; }.quantity button { width: 34px; height: 34px; border: 0; border-radius: 8px; background: var(--surface, #FFFDFA); }.quantity b { min-width: 24px; text-align: center; }fieldset { display: flex; gap: .35rem; margin: .6rem 0 0; padding: .5rem; border: 1px solid var(--line, var(--line)); border-radius: 10px; flex-wrap: wrap; }legend { padding-inline: .3rem; color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 800; }legend small { margin-inline-start: .3rem; color: var(--muted, var(--muted)); font-size: .875rem; }fieldset button { display: inline-flex; min-height: 38px; align-items: center; gap: .25rem; padding-inline: .5rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); font: inherit; font-size: .875rem; }fieldset button b { color: var(--ink, var(--ink)); font-size: .875rem; }fieldset button.active { border-color: var(--brand); color: var(--ink, var(--ink)); background: var(--surface, #FFFDFA); }.line-note { display: flex; margin-top: .6rem; flex-direction: column; gap: .2rem; color: var(--muted, var(--muted)); font-size: .875rem; }.line-note input { min-height: 40px; border: 1px solid var(--line, var(--line)); border-radius: 8px; }.modifier-card footer { display: flex; gap: .4rem; margin-top: .65rem; }.modifier-card footer button { flex: 1; }@media (max-width: 820px) { .sheet-layer { padding: 0; }.order-sheet { max-height: 100dvh; height: 100dvh; border-radius: 0; }.builder-layout { grid-template-columns: 1fr; overflow-y: auto; }.catalog-pane { min-height: 300px; }.cart-pane { min-height: 260px; }.item-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }.detail-grid { grid-template-columns: 1fr 1fr; }.order-sheet > footer { flex-wrap: wrap; }.order-sheet > footer > span { width: 100%; flex-basis: 100%; } }@media (max-width: 480px) { .catalog-tools { grid-template-columns: 1fr; }.item-grid { grid-template-columns: 1fr 1fr; }.detail-grid { grid-template-columns: 1fr; }.detail-grid .wide { grid-column: span 1; } }
/* Keep the essential phone-order data visible without a collapsible flow. */
.builder-layout { flex: 1 1 360px; }
.order-details {
    flex: 0 0 auto;
    max-height: min(250px, 34dvh);
    border-color: var(--line, var(--line));
    background: var(--surface, #FFFDFA);
    overflow-y: auto;
}
.details-heading {
    position: sticky;
    z-index: 1;
    top: 0;
    display: flex;
    min-height: 44px;
    align-items: center;
    justify-content: space-between;
    padding: .5rem .65rem;
    background: var(--surface, #FFFDFA);
}
.details-heading > span { display: flex; min-width: 0; align-items: center; gap: .35rem; color: var(--muted, var(--muted)); font-size: .875rem; }
.details-heading small { color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 550; }
.details-heading > strong { display: inline-flex; align-items: center; gap: .3rem; padding: .3rem .5rem; border-radius: 12px; color: var(--ink, var(--ink)); background: var(--brand-soft, #F3E3D8); font-size: .875rem; white-space: nowrap; }
.detail-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .48rem;
    padding: .55rem .65rem .65rem;
    border-top: 1px solid var(--line, var(--line));
}
.detail-grid label { min-width: 0; gap: .22rem; }
.detail-grid label > span { color: var(--muted, var(--muted)); font-size: .875rem; }
.detail-grid input, .detail-grid select, .detail-grid textarea {
    min-height: 44px;
    padding: .48rem .55rem;
    border-color: var(--line, var(--line));
    border-radius: 8px;
    background: var(--surface, #FFFDFA);
    font-size: .875rem;
}
.detail-grid input:focus, .detail-grid select:focus, .detail-grid textarea:focus {
    border-color: color-mix(in srgb, var(--brand, #A3422B) 50%, transparent);
    outline: none;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand, #A3422B) 8%, transparent);
}
@media (max-width: 820px) {
    .order-details { max-height: 38dvh; }
    .details-heading small { display: none; }
}

/* Task-specific component layout. */
.sheet-layer{padding:0;align-items:stretch;justify-content:flex-end}.order-sheet{width:min(1180px,100%);height:100dvh;max-height:100dvh;border-radius:0;padding:0;overflow-y:auto}.order-sheet>header{position:sticky;top:0;z-index:3;padding:20px 24px;background:var(--surface);border-bottom:1px solid var(--line)}.order-sheet>header h2{font-size:24px}.builder-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:0;min-height:420px}.catalog-pane{padding:20px}.catalog-tools{position:sticky;top:82px;background:var(--surface);padding-bottom:14px;z-index:1}.catalog-tools input,.catalog-tools select{min-height:46px;font-size:14px}.item-grid{grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}.item-grid>button{min-height:110px;padding:14px;border-radius:8px;text-align:start}.item-grid>button strong{font-size:16px}.cart-pane{padding:20px;border-inline-start:1px solid var(--line);background:var(--canvas)}.cart-lines{gap:0}.cart-lines>article{padding:14px 0;border:0;border-bottom:1px solid var(--line);border-radius:0}.cart-total{padding:18px 0;font-size:21px}.order-details{padding:22px 24px;border-top:1px solid var(--line);background:var(--surface)}.detail-grid{gap:18px}.order-sheet>footer{position:sticky;bottom:0;padding:16px 24px;background:var(--surface);border-top:1px solid var(--line);z-index:2}.order-sheet>footer button{min-height:48px;border-radius:8px}.modifier-card{padding:24px;border-radius:10px}.modifier-card label{min-height:46px;font-size:14px}@media(max-width:800px){.builder-layout{grid-template-columns:1fr}.cart-pane{border-inline-start:0;border-top:1px solid var(--line)}.catalog-tools{position:static}.item-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
