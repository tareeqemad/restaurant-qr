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
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; place-items: center; padding: .7rem; background: rgba(15, 27, 19, .48); backdrop-filter: blur(3px); }.order-sheet { display: flex; width: min(1120px, 100%); max-height: calc(100dvh - 1.4rem); box-sizing: border-box; flex-direction: column; padding: .85rem; border: 1px solid #dce5df; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .58); overflow: hidden; }.order-sheet > header, .modifier-card > header { display: flex; align-items: center; justify-content: space-between; }.order-sheet > header > div, .modifier-card > header > div { display: flex; flex-direction: column; }.order-sheet > header span, .modifier-card > header span { color: #68776e; font-size: .62rem; font-weight: 750; }.order-sheet h2, .modifier-card h2 { margin: .08rem 0 0; color: #26382d; font-size: .92rem; }.order-sheet > header button, .modifier-card > header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #dfe6e2; border-radius: 10px; color: #617067; background: #fff; }.sheet-error { margin-top: .45rem; padding: .45rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .66rem; }.builder-layout { display: grid; min-height: 270px; flex: 1; grid-template-columns: minmax(0, 1.65fr) minmax(290px, .75fr); gap: .6rem; margin-top: .55rem; overflow: hidden; }.catalog-pane, .cart-pane { min-height: 0; border: 1px solid #e2e8e4; border-radius: 12px; overflow: hidden; }.catalog-pane { display: flex; flex-direction: column; }.catalog-tools { display: grid; grid-template-columns: 1fr 190px; gap: .4rem; padding: .5rem; border-bottom: 1px solid #e8ede9; }.catalog-tools label { position: relative; }.catalog-tools i { position: absolute; top: 50%; inset-inline-start: .6rem; color: #7b887f; transform: translateY(-50%); }.catalog-tools input { padding-inline-start: 1.8rem; }.catalog-tools input, .catalog-tools select { width: 100%; min-height: 42px; box-sizing: border-box; border: 1px solid #dce4df; border-radius: 9px; font: inherit; font-size: .68rem; }.item-grid { display: grid; min-height: 0; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .4rem; padding: .5rem; overflow-y: auto; }.item-grid > button { display: grid; min-height: 74px; grid-template-columns: 34px minmax(0, 1fr); gap: .4rem; align-items: center; padding: .45rem; border: 1px solid #e1e8e3; border-radius: 10px; color: #314137; background: #fff; text-align: start; font: inherit; }.item-grid > button > span:nth-child(2) { display: flex; min-width: 0; flex-direction: column; }.item-grid strong { overflow: hidden; font-size: .67rem; text-overflow: ellipsis; white-space: nowrap; }.item-grid small { margin-top: .08rem; color: #7c8980; font-size: .56rem; }.item-grid b { grid-column: 2; color: #23623a; font-size: .65rem; }.item-mark { display: grid; width: 34px; height: 34px; grid-row: 1 / 3; place-items: center; border-radius: 9px; color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); font-size: .72rem; font-weight: 850; }.item-grid button:disabled { opacity: .42; }.cart-pane { display: flex; flex-direction: column; }.cart-pane > h3 { display: flex; min-height: 42px; align-items: center; justify-content: space-between; margin: 0; padding-inline: .55rem; border-bottom: 1px solid #e8ede9; font-size: .68rem; }.cart-pane > h3 span { display: flex; gap: .3rem; }.cart-pane > h3 b { display: grid; min-width: 22px; height: 22px; place-items: center; border-radius: 999px; color: #23623a; background: #eaf5ed; font-size: .58rem; }.cart-lines { min-height: 0; flex: 1; padding: .3rem .45rem; overflow-y: auto; }.cart-lines article { display: grid; grid-template-columns: minmax(0, 1fr) auto auto 28px; gap: .3rem; align-items: center; padding: .4rem 0; border-bottom: 1px solid #edf1ee; }.cart-lines article > span { display: flex; min-width: 0; flex-direction: column; }.cart-lines article strong { font-size: .63rem; }.cart-lines article small { color: #7c887f; font-size: .54rem; }.cart-lines article > div { display: flex; align-items: center; gap: .22rem; }.cart-lines article > div button, .cart-lines .remove { display: grid; width: 28px; height: 28px; place-items: center; border: 0; border-radius: 7px; color: #43584a; background: #edf3ef; }.cart-lines article > div b { min-width: 18px; text-align: center; font-size: .62rem; }.cart-lines .remove { color: #9d3039; background: #fff0f1; }.empty-cart { display: flex; min-height: 140px; align-items: center; justify-content: center; flex-direction: column; gap: .35rem; color: #839087; font-size: .66rem; }.empty-cart i { font-size: 1.2rem; }.cart-total { display: flex; align-items: center; justify-content: space-between; padding: .55rem; border-top: 1px solid #e0e8e2; color: #496052; background: #f7faf8; font-size: .66rem; }.cart-total strong { color: #1f6538; font-size: .82rem; }.order-details { margin-top: .5rem; border: 1px solid #e1e8e3; border-radius: 11px; overflow: hidden; }.order-details summary { min-height: 38px; padding: .55rem; box-sizing: border-box; color: #536159; cursor: pointer; font-size: .65rem; font-weight: 800; }.detail-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: .4rem; padding: 0 .55rem .55rem; }.detail-grid label { display: flex; flex-direction: column; gap: .2rem; }.detail-grid label > span { color: #65736a; font-size: .58rem; font-weight: 750; }.detail-grid input, .detail-grid select, .detail-grid textarea { width: 100%; min-height: 39px; box-sizing: border-box; padding: .45rem; border: 1px solid #dce4df; border-radius: 8px; resize: vertical; font: inherit; font-size: .65rem; }.detail-grid small { color: #a62f38; font-size: .56rem; }.detail-grid .wide { grid-column: span 2; }.order-sheet > footer { display: flex; align-items: center; gap: .4rem; margin-top: .55rem; }.order-sheet > footer > span { flex: 1; color: #78857d; font-size: .6rem; }.order-sheet footer button, .modifier-card footer button { min-height: 44px; padding-inline: .8rem; border-radius: 10px; font: inherit; font-size: .68rem; font-weight: 800; }.secondary { border: 1px solid #dce4df; color: #536159; background: #fff; }.primary { border: 1px solid rgb(var(--primary-rgb, 22 101 52)); color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); }button:disabled { opacity: .45; }.modifier-layer { position: fixed; z-index: 1200; inset: 0; display: grid; place-items: center; padding: 1rem; background: rgba(15, 27, 19, .38); }.modifier-card { width: min(500px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: .85rem; border-radius: 15px; background: #fff; overflow-y: auto; box-shadow: 0 20px 60px -28px #000; }.quantity { display: flex; align-items: center; justify-content: space-between; margin-top: .6rem; color: #56645b; font-size: .65rem; }.quantity > div { display: flex; align-items: center; gap: .4rem; }.quantity button { width: 34px; height: 34px; border: 0; border-radius: 8px; background: #edf4ef; }.quantity b { min-width: 24px; text-align: center; }fieldset { display: flex; gap: .35rem; margin: .6rem 0 0; padding: .5rem; border: 1px solid #e2e8e4; border-radius: 10px; flex-wrap: wrap; }legend { padding-inline: .3rem; color: #536159; font-size: .65rem; font-weight: 800; }legend small { margin-inline-start: .3rem; color: #8a958e; font-size: .55rem; }fieldset button { display: inline-flex; min-height: 38px; align-items: center; gap: .25rem; padding-inline: .5rem; border: 1px solid #dce4df; border-radius: 8px; color: #56665c; background: #fff; font: inherit; font-size: .62rem; }fieldset button b { color: #23623a; font-size: .57rem; }fieldset button.active { border-color: #43865a; color: #1e6b39; background: #eff8f1; }.line-note { display: flex; margin-top: .6rem; flex-direction: column; gap: .2rem; color: #65736a; font-size: .62rem; }.line-note input { min-height: 40px; border: 1px solid #dce4df; border-radius: 8px; }.modifier-card footer { display: flex; gap: .4rem; margin-top: .65rem; }.modifier-card footer button { flex: 1; }@media (max-width: 820px) { .sheet-layer { padding: 0; }.order-sheet { max-height: 100dvh; height: 100dvh; border-radius: 0; }.builder-layout { grid-template-columns: 1fr; overflow-y: auto; }.catalog-pane { min-height: 300px; }.cart-pane { min-height: 260px; }.item-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }.detail-grid { grid-template-columns: 1fr 1fr; }.order-sheet > footer { flex-wrap: wrap; }.order-sheet > footer > span { width: 100%; flex-basis: 100%; } }@media (max-width: 480px) { .catalog-tools { grid-template-columns: 1fr; }.item-grid { grid-template-columns: 1fr 1fr; }.detail-grid { grid-template-columns: 1fr; }.detail-grid .wide { grid-column: span 1; } }
/* Keep the essential phone-order data visible without a collapsible flow. */
.builder-layout { flex: 1 1 360px; }
.order-details {
    flex: 0 0 auto;
    max-height: min(250px, 34dvh);
    border-color: #dce5df;
    background: #fbfcfb;
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
    background: #f7faf8;
}
.details-heading > span { display: flex; min-width: 0; align-items: center; gap: .35rem; color: #536159; font-size: .65rem; }
.details-heading small { color: #7d8981; font-size: .57rem; font-weight: 550; }
.details-heading > strong { display: inline-flex; align-items: center; gap: .3rem; padding: .3rem .5rem; border-radius: 999px; color: #1f6538; background: #eaf5ed; font-size: .58rem; white-space: nowrap; }
.detail-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .48rem;
    padding: .55rem .65rem .65rem;
    border-top: 1px solid #e5ebe7;
}
.detail-grid label { min-width: 0; gap: .22rem; }
.detail-grid label > span { color: #53645a; font-size: .62rem; }
.detail-grid input, .detail-grid select, .detail-grid textarea {
    min-height: 44px;
    padding: .48rem .55rem;
    border-color: #d7e1da;
    border-radius: 9px;
    background: #fff;
    font-size: .68rem;
}
.detail-grid input:focus, .detail-grid select:focus, .detail-grid textarea:focus {
    border-color: rgba(var(--primary-rgb, 22 101 52), .5);
    outline: none;
    box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 22 101 52), .08);
}
@media (max-width: 820px) {
    .order-details { max-height: 38dvh; }
    .details-heading small { display: none; }
}
</style>
