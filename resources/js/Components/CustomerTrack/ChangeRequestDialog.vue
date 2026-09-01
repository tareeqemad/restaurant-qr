<script setup>
import { computed, reactive, watch } from 'vue';

const props = defineProps({
    order: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'submit', 'add']);
const form = reactive({ type: 'change_item', order_item_id: '', requested_quantity: '', request_note: '' });

const selectedItem = computed(() => props.order.changeableItems.find((item) => Number(item.id) === Number(form.order_item_id)) ?? null);
const quantityChanged = computed(() => selectedItem.value
    && form.requested_quantity !== ''
    && Number(form.requested_quantity) !== Number(selectedItem.value.qty));
const canSubmit = computed(() => {
    if (props.busy) return false;
    if (form.type === 'cancel_order') return true;
    if (! selectedItem.value) return false;
    if (form.type === 'cancel_item') return true;
    return quantityChanged.value || form.request_note.trim().length > 0;
});

const reset = () => Object.assign(form, {
    type: 'change_item',
    order_item_id: props.order.changeableItems[0]?.id ?? '',
    requested_quantity: props.order.changeableItems[0]?.qty ?? '',
    request_note: '',
});

watch(() => props.order.id, reset, { immediate: true });
watch(() => form.order_item_id, () => {
    form.requested_quantity = selectedItem.value?.qty ?? '';
    form.request_note = '';
});

const submit = () => {
    if (! canSubmit.value) return;
    emit('submit', {
        type: form.type,
        order_item_id: form.type === 'cancel_order' ? null : selectedItem.value?.id,
        requested_quantity: form.type === 'change_item' && quantityChanged.value ? form.requested_quantity : null,
        request_note: form.request_note.trim(),
    });
};
</script>

<template>
    <section class="chg-dialog" role="dialog" aria-modal="true" aria-labelledby="change-order-title">
        <header class="chg-head">
            <div>
                <small>الجولة {{ order.roundNumber }} · {{ order.number }}</small>
                <h3 id="change-order-title">اطلب تغيير الجولة</h3>
                <p>هذا طلب تغيير واضح، وليس تعديلاً صامتاً: الجرسون يراجعه والمحطة تتوقف عن الصنف المتأثر فقط.</p>
            </div>
            <button type="button" aria-label="إغلاق" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
        </header>

        <button type="button" class="chg-add" @click="emit('add')">
            <span><i class="bi bi-plus-lg"></i></span>
            <div><strong>أريد إضافة صنف جديد</strong><small>ارجع للمنيو وأرسله كجولة إضافية على نفس الجلسة والفاتورة.</small></div>
            <i class="bi bi-arrow-left"></i>
        </button>

        <div class="chg-divider"><span>أو غيّر صنفاً أرسلته</span></div>

        <label class="chg-field">
            <span>اختر الصنف</span>
            <select v-model="form.order_item_id" :disabled="form.type === 'cancel_order'">
                <option v-for="item in order.changeableItems" :key="item.id" :value="item.id">{{ item.label }} — {{ item.statusLabel }}</option>
            </select>
        </label>

        <div v-if="selectedItem && form.type !== 'cancel_order'" class="chg-state" :class="{ 'is-started': selectedItem.started, 'is-ready': selectedItem.ready }">
            <i class="bi" :class="selectedItem.ready ? 'bi-bag-check-fill' : (selectedItem.started ? 'bi-fire' : 'bi-check2-circle')"></i>
            <div><strong>{{ selectedItem.statusLabel }}</strong><small>{{ selectedItem.stationName }}</small></div>
            <p v-if="selectedItem.ready">جهّزته المحطة؛ سيقرر الجرسون إن كان يُعاد أو يُسجّل هدرًا.</p>
            <p v-else-if="selectedItem.started">بدأ العمل عليه؛ طلبك سيوقف المتابعة حتى يراجع الجرسون المحطة.</p>
            <p v-else>لم يبدأ بعد؛ يمكن للجرسون تنفيذ التغيير سريعاً.</p>
        </div>

        <div class="chg-kinds" role="group" aria-label="نوع التغيير">
            <button type="button" :class="{ 'is-active': form.type === 'change_item' }" @click="form.type = 'change_item'">
                <i class="bi bi-sliders"></i><span><strong>تعديل الصنف</strong><small>كمية أو ملاحظة</small></span>
            </button>
            <button type="button" :class="{ 'is-active is-danger': form.type === 'cancel_item' }" @click="form.type = 'cancel_item'">
                <i class="bi bi-x-circle"></i><span><strong>إلغاء الصنف</strong><small>يحذف من الحساب بعد الاعتماد</small></span>
            </button>
        </div>

        <template v-if="form.type === 'change_item'">
            <label class="chg-field">
                <span>الكمية الجديدة</span>
                <div class="chg-qty">
                    <button type="button" :disabled="Number(form.requested_quantity) <= 1" @click="form.requested_quantity = Math.max(1, Number(form.requested_quantity || 1) - 1)">−</button>
                    <input v-model="form.requested_quantity" type="number" min="1" max="50" step="1" inputmode="numeric">
                    <button type="button" :disabled="Number(form.requested_quantity) >= 50" @click="form.requested_quantity = Math.min(50, Number(form.requested_quantity || 0) + 1)">+</button>
                </div>
            </label>
            <label class="chg-field">
                <span>ما التعديل المطلوب؟ <small>اختياري عند تغيير الكمية</small></span>
                <textarea v-model="form.request_note" rows="2" maxlength="1000" placeholder="مثال: بدون بصل"></textarea>
            </label>
        </template>

        <label v-else-if="form.type === 'cancel_item'" class="chg-field">
            <span>سبب الإلغاء <small>اختياري</small></span>
            <textarea v-model="form.request_note" rows="2" maxlength="1000" placeholder="مثال: غيّرت رأيي"></textarea>
        </label>

        <button type="button" class="chg-whole" :class="{ 'is-active': form.type === 'cancel_order' }" @click="form.type = form.type === 'cancel_order' ? 'change_item' : 'cancel_order'">
            <i class="bi bi-receipt"></i><span><strong>إلغاء هذه الجولة كاملة</strong><small>لا يلغي الجولات السابقة في نفس الجلسة.</small></span>
            <i class="bi" :class="form.type === 'cancel_order' ? 'bi-check-circle-fill' : 'bi-chevron-left'"></i>
        </button>

        <div v-if="form.type === 'cancel_order'" class="chg-whole-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>إذا تم تقديم أحد أصناف الجولة فلن تُلغى كاملة؛ اختر صنفاً لم يُقدّم بعد.</span>
        </div>

        <footer class="chg-actions">
            <button type="button" @click="emit('close')">رجوع</button>
            <button type="button" class="is-primary" :disabled="! canSubmit" @click="submit">
                <i class="bi bi-send"></i> {{ busy ? 'جارٍ الإرسال…' : 'أرسل طلب التغيير للجرسون' }}
            </button>
        </footer>
    </section>
</template>

<style scoped>
.chg-dialog { width: min(570px, calc(100vw - 1rem)); max-height: calc(100dvh - 1rem); overflow-y: auto; display: grid; gap: .75rem; padding: 1rem; border: 1px solid #dce5df; border-radius: 24px; background: #fff; color: #17251d; box-shadow: 0 30px 90px -30px rgba(8, 38, 24, .68); }
.chg-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .7rem; }
.chg-head small { color: #75837b; font-size: .66rem; }
.chg-head h3 { margin: .15rem 0; font-size: 1.05rem; font-weight: 950; }
.chg-head p { max-width: 430px; margin: 0; color: #6f7d75; font-size: .69rem; line-height: 1.55; }
.chg-head > button { width: 40px; height: 40px; flex: 0 0 40px; border: 1px solid #dfe7e2; border-radius: 12px; background: #fff; color: #526159; cursor: pointer; }
.chg-add { min-height: 66px; display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: .6rem; padding: .55rem .65rem; border: 1px solid rgba(var(--primary-rgb, 31, 107, 80), .25); border-radius: 15px; background: rgba(var(--primary-rgb, 31, 107, 80), .06); color: rgb(var(--primary-rgb, 31, 107, 80)); text-align: start; font: inherit; cursor: pointer; }
.chg-add > span { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 12px; background: rgb(var(--primary-rgb, 31, 107, 80)); color: #fff; }
.chg-add div, .chg-add strong, .chg-add small { display: block; }
.chg-add strong { font-size: .78rem; font-weight: 950; }
.chg-add small { margin-top: .08rem; color: #61756a; font-size: .63rem; line-height: 1.45; }
.chg-divider { display: flex; align-items: center; gap: .6rem; color: #89958e; font-size: .65rem; font-weight: 800; }
.chg-divider::before, .chg-divider::after { content: ''; height: 1px; flex: 1; background: #e7ece9; }
.chg-field { display: grid; gap: .32rem; }
.chg-field > span { color: #4c5e54; font-size: .69rem; font-weight: 900; }
.chg-field > span small { color: #8b9790; font-weight: 700; }
.chg-field select, .chg-field textarea { width: 100%; border: 1px solid #dce4df; border-radius: 12px; background: #fff; color: #24362c; font: inherit; outline: none; }
.chg-field select { min-height: 46px; padding: 0 .7rem; }
.chg-field textarea { min-height: 64px; resize: vertical; padding: .65rem .7rem; }
.chg-field select:focus, .chg-field textarea:focus, .chg-qty:focus-within { border-color: rgb(var(--primary-rgb, 31, 107, 80)); box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 31, 107, 80), .08); }
.chg-state { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: .08rem .5rem; padding: .55rem .65rem; border-radius: 12px; background: #edf8f2; color: #176044; }
.chg-state > i { grid-row: 1 / 3; align-self: center; }
.chg-state div { display: flex; align-items: center; gap: .35rem; }
.chg-state strong { font-size: .7rem; font-weight: 950; }
.chg-state small { font-size: .62rem; opacity: .78; }
.chg-state p { grid-column: 2; margin: 0; font-size: .63rem; line-height: 1.45; }
.chg-state.is-started { background: #fff5e8; color: #8d4a0a; }
.chg-state.is-ready { background: #fff0f0; color: #9a2929; }
.chg-kinds { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
.chg-kinds button { min-height: 58px; display: flex; align-items: center; gap: .45rem; padding: .45rem .55rem; border: 1px solid #e0e7e2; border-radius: 13px; background: #fff; color: #526159; text-align: start; font: inherit; cursor: pointer; }
.chg-kinds button > i { width: 31px; height: 31px; flex: 0 0 31px; display: grid; place-items: center; border-radius: 9px; background: #f1f5f2; }
.chg-kinds span, .chg-kinds strong, .chg-kinds small { display: block; }
.chg-kinds strong { font-size: .71rem; font-weight: 950; }
.chg-kinds small { margin-top: .03rem; font-size: .58rem; opacity: .72; }
.chg-kinds button.is-active { border-color: rgb(var(--primary-rgb, 31, 107, 80)); background: rgba(var(--primary-rgb, 31, 107, 80), .06); color: rgb(var(--primary-rgb, 31, 107, 80)); }
.chg-kinds button.is-danger { border-color: #dda4a4; background: #fff4f4; color: #a72c2c; }
.chg-qty { width: 160px; min-height: 43px; display: grid; grid-template-columns: 42px 1fr 42px; border: 1px solid #dce4df; border-radius: 12px; overflow: hidden; }
.chg-qty button { border: 0; background: #f2f6f3; color: #1f6b50; font: inherit; font-size: 1rem; font-weight: 900; cursor: pointer; }
.chg-qty button:disabled { opacity: .35; }
.chg-qty input { min-width: 0; border: 0; text-align: center; font: inherit; font-weight: 950; outline: none; -moz-appearance: textfield; }
.chg-qty input::-webkit-inner-spin-button { appearance: none; }
.chg-whole { min-height: 52px; display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: .5rem; padding: .45rem .6rem; border: 1px solid #e4e8e5; border-radius: 13px; background: #fafbfa; color: #59685f; text-align: start; font: inherit; cursor: pointer; }
.chg-whole span, .chg-whole strong, .chg-whole small { display: block; }
.chg-whole strong { font-size: .7rem; font-weight: 950; }
.chg-whole small { margin-top: .03rem; font-size: .59rem; opacity: .72; }
.chg-whole.is-active { border-color: #e5a2a2; background: #fff2f2; color: #a52b2b; }
.chg-whole-warning { display: flex; align-items: flex-start; gap: .4rem; padding: .5rem .6rem; border-radius: 11px; background: #fff7e8; color: #89500e; font-size: .65rem; line-height: 1.5; }
.chg-actions { display: grid; grid-template-columns: minmax(110px, .7fr) minmax(0, 1.3fr); gap: .5rem; padding-top: .1rem; }
.chg-actions button { min-height: 48px; border: 1px solid #dce4df; border-radius: 13px; background: #fff; color: #53635a; font: inherit; font-weight: 900; cursor: pointer; }
.chg-actions button.is-primary { border-color: rgb(var(--primary-rgb, 31, 107, 80)); background: rgb(var(--primary-rgb, 31, 107, 80)); color: #fff; }
.chg-actions button:disabled { opacity: .45; cursor: default; }

@media (max-width: 520px) {
    .chg-dialog { width: calc(100vw - .5rem); max-height: calc(100dvh - .5rem); gap: .65rem; padding: .8rem; border-radius: 20px; }
    .chg-kinds { grid-template-columns: 1fr; }
    .chg-qty { width: 100%; }
}
</style>
