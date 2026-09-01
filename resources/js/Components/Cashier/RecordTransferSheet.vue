<script setup>
import { computed, ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    open: { type: Boolean, required: true },
    workspace: { type: Object, default: null },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: () => true,
    submit: (payload) => Number(payload?.amount) > 0 && Boolean(payload?.sender_name),
});

const amount = ref('');
const senderName = ref('');
const customerName = ref('');
const customerPhone = ref('');
const notes = ref('');

const suggestedAmount = computed(() => {
    if (Number(props.workspace?.invoice?.balance) > 0) {
        return Number(props.workspace.invoice.balance);
    }

    return (props.workspace?.orders ?? [])
        .filter((order) => order.status !== 'cancelled')
        .reduce((total, order) => total + Number(order.total || 0), 0);
});

watch(
    () => [props.open, props.workspace?.id],
    ([open]) => {
        if (!open) return;
        amount.value = suggestedAmount.value > 0 ? suggestedAmount.value.toFixed(2) : '';
        senderName.value = props.workspace?.customer?.name ?? '';
        customerName.value = props.workspace?.customer?.name ?? '';
        customerPhone.value = props.workspace?.customer?.phone ?? '';
        notes.value = '';
    },
    { immediate: true },
);

function submit() {
    emit('submit', {
        amount: Number(amount.value),
        sender_name: senderName.value.trim(),
        customer_name: customerName.value.trim() || null,
        customer_phone: customerPhone.value.trim() || null,
        notes: notes.value.trim() || null,
    });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" role="presentation" @click.self="emit('close')">
            <section class="transfer-record-sheet" role="dialog" aria-modal="true" aria-labelledby="transfer-record-title">
                <header>
                    <div>
                        <span>حوالة غير مؤكدة · {{ workspace?.label }}</span>
                        <h2 id="transfer-record-title">تسجيل حوالة للمطابقة</h2>
                    </div>
                    <button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
                </header>

                <p class="explanation">
                    <i class="bi bi-shield-check"></i>
                    هذا لا يسجل دفعة الآن. ستبقى الحوالة معلّقة حتى يطابقها الكاشير مع حساب البنك ويؤكد المبلغ.
                </p>

                <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>

                <div class="field-grid">
                    <label class="field amount-field">
                        <span>المبلغ المرسل</span>
                        <div class="money-input">
                            <input v-model="amount" type="number" min="0.01" step="0.01" inputmode="decimal" :aria-invalid="Boolean(errors.amount)">
                            <b>{{ currency.symbol }}</b>
                        </div>
                        <small v-if="errors.amount">{{ errors.amount[0] }}</small>
                        <small v-else-if="suggestedAmount > 0" class="hint">المبلغ المتوقع {{ formatMoney(suggestedAmount, currency) }}</small>
                    </label>

                    <label class="field">
                        <span>اسم صاحب الحساب المحوّل</span>
                        <input v-model="senderName" type="text" maxlength="120" autocomplete="off" placeholder="كما يظهر في تطبيق البنك" :aria-invalid="Boolean(errors.sender_name)">
                        <small v-if="errors.sender_name">{{ errors.sender_name[0] }}</small>
                    </label>

                    <label class="field">
                        <span>اسم الزبون <em>اختياري</em></span>
                        <input v-model="customerName" type="text" maxlength="120" autocomplete="off">
                        <small v-if="errors.customer_name">{{ errors.customer_name[0] }}</small>
                    </label>

                    <label class="field">
                        <span>هاتف الزبون <em>اختياري</em></span>
                        <input v-model="customerPhone" type="tel" maxlength="32" inputmode="tel" autocomplete="off">
                        <small v-if="errors.customer_phone">{{ errors.customer_phone[0] }}</small>
                    </label>
                </div>

                <label class="field">
                    <span>ملاحظة للمطابقة <em>اختياري</em></span>
                    <input v-model="notes" type="text" maxlength="500" placeholder="وقت التحويل أو آخر أرقام المرجع">
                    <small v-if="errors.notes">{{ errors.notes[0] }}</small>
                </label>

                <footer>
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">إلغاء</button>
                    <button type="button" class="primary" :disabled="busy || Number(amount) <= 0 || !senderName.trim()" @click="submit">
                        <i v-if="busy" class="bi bi-arrow-clockwise spinning"></i>
                        {{ busy ? 'جاري التسجيل…' : 'تسجيل بانتظار المطابقة' }}
                    </button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .44); backdrop-filter: blur(3px); }
.transfer-record-sheet { width: min(620px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid #dce5df; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .55); overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; }
header > div { display: flex; flex-direction: column; }
header span { color: #7a867e; font-size: .66rem; }
header h2 { margin: .1rem 0 0; color: #213529; font-size: .94rem; }
header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #dfe6e2; border-radius: 10px; color: #617067; background: #fff; }
.explanation, .sheet-error { display: flex; align-items: flex-start; gap: .4rem; margin: .7rem 0 0; padding: .55rem .65rem; border-radius: 9px; font-size: .67rem; line-height: 1.6; }
.explanation { color: #6d550c; background: #fff8e5; }
.sheet-error { color: #922d36; background: #fff0f1; }
.field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .55rem; }
.field { display: flex; margin-top: .7rem; flex-direction: column; gap: .27rem; }
.field > span { color: #526158; font-size: .67rem; font-weight: 750; }
.field em { color: #8a968e; font-size: .59rem; font-style: normal; font-weight: 500; }
.field input { width: 100%; min-height: 44px; box-sizing: border-box; padding-inline: .7rem; border: 1px solid #dce4df; border-radius: 10px; outline: none; font: inherit; font-size: .76rem; }
.field input:focus { border-color: rgba(var(--primary-rgb, 22 101 52), .5); box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 22 101 52), .08); }
.field small { color: #a52d37; font-size: .59rem; }
.field .hint { color: #7a867e; }
.money-input { position: relative; }
.money-input input { padding-inline-end: 2.7rem; color: #183723; font-size: 1rem; font-weight: 850; }
.money-input b { position: absolute; top: 50%; inset-inline-end: .8rem; color: #6d7b72; font-size: .72rem; transform: translateY(-50%); }
footer { display: flex; gap: .45rem; margin-top: .85rem; }
footer button { min-height: 46px; flex: 1; border-radius: 11px; font: inherit; font-size: .72rem; font-weight: 800; }
.secondary { border: 1px solid #dce4df; color: #536159; background: #fff; }
.primary { border: 1px solid rgb(var(--primary-rgb, 22 101 52)); color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); }
button:disabled { opacity: .48; cursor: not-allowed; }
.spinning { animation: spin .75s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (min-width: 700px) { .sheet-layer { align-items: center; } }
@media (max-width: 520px) { .sheet-layer { padding: 0; }.transfer-record-sheet { max-height: 94dvh; border-radius: 18px 18px 0 0; }.field-grid { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) { .spinning { animation: none; } }
</style>
