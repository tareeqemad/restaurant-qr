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
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .44); backdrop-filter: none; }
.transfer-record-sheet { width: min(620px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid var(--line, var(--line)); border-radius: 12px; background: var(--surface, #FFFDFA); box-shadow: none; overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; }
header > div { display: flex; flex-direction: column; }
header span { color: var(--muted, var(--muted)); font-size: .875rem; }
header h2 { margin: .1rem 0 0; color: var(--ink, var(--ink)); font-size: .94rem; }
header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid var(--line, var(--line)); border-radius: 8px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }
.explanation, .sheet-error { display: flex; align-items: flex-start; gap: .4rem; margin: .7rem 0 0; padding: .55rem .65rem; border-radius: 9px; font-size: .875rem; line-height: 1.6; }
.explanation { color: #6d550c; background: #fff8e5; }
.sheet-error { color: #922d36; background: #fff0f1; }
.field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .55rem; }
.field { display: flex; margin-top: .7rem; flex-direction: column; gap: .27rem; }
.field > span { color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; }
.field em { color: var(--muted, var(--muted)); font-size: .875rem; font-style: normal; font-weight: 500; }
.field input { width: 100%; min-height: 44px; box-sizing: border-box; padding-inline: .7rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; outline: none; font: inherit; font-size: .875rem; }
.field input:focus { border-color: color-mix(in srgb, var(--brand, #A3422B) 50%, transparent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand, #A3422B) 8%, transparent); }
.field small { color: #a52d37; font-size: .875rem; }
.field .hint { color: var(--muted, var(--muted)); }
.money-input { position: relative; }
.money-input input { padding-inline-end: 2.7rem; color: var(--ink, var(--ink)); font-size: 1rem; font-weight: 800; }
.money-input b { position: absolute; top: 50%; inset-inline-end: .8rem; color: var(--muted, var(--muted)); font-size: .875rem; transform: translateY(-50%); }
footer { display: flex; gap: .45rem; margin-top: .85rem; }
footer button { min-height: 46px; flex: 1; border-radius: 8px; font: inherit; font-size: .875rem; font-weight: 800; }
.secondary { border: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }
.primary { border: 1px solid var(--brand, #A3422B); color: #FFFDFA; background: var(--brand, #A3422B); }
button:disabled { opacity: .48; cursor: not-allowed; }
.spinning { animation: spin .75s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (min-width: 700px) { .sheet-layer { align-items: center; } }
@media (max-width: 520px) { .sheet-layer { padding: 0; }.transfer-record-sheet { max-height: 94dvh; border-radius: 12px 12px 0 0; }.field-grid { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) { .spinning { animation: none; } }

/* Task-specific component layout. */
.sheet-layer{padding:0;display:flex;align-items:stretch;justify-content:flex-end;background:rgb(37 52 58 / 42%);backdrop-filter:none}.transfer-record-sheet{width:min(560px,100%);max-width:100%;height:100dvh;max-height:100dvh;min-height:0;margin:0;border:0;border-radius:0;overflow-y:auto;background:var(--surface);box-shadow:none;padding:0}.transfer-record-sheet>header{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line);background:var(--surface);margin:0}.transfer-record-sheet>header h2{font-size:23px;line-height:1.4;margin:4px 0 0;color:var(--ink)}.transfer-record-sheet>header span,.transfer-record-sheet>header small{font-size:14px;color:var(--muted)}.transfer-record-sheet>header button{min-width:44px;min-height:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.transfer-record-sheet>form{padding:22px 24px;display:grid;gap:18px}.transfer-record-sheet>form>footer,.transfer-record-sheet>footer{position:sticky;bottom:0;z-index:2;display:flex;gap:12px;justify-content:flex-end;padding:16px 24px;margin:0;background:var(--surface);border-top:1px solid var(--line)}.transfer-record-sheet footer button{min-height:48px;border-radius:8px;font-size:15px;flex:1}.transfer-record-sheet label{font-size:14px;line-height:1.6}.transfer-record-sheet input:not([type=checkbox]):not([type=radio]),.transfer-record-sheet select{min-height:48px;border-radius:6px;font-size:16px}.transfer-record-sheet textarea{border-radius:6px;font-size:15px;min-height:90px}.sheet-error{margin:16px 24px;padding:12px;border-radius:6px;font-size:14px;line-height:1.7}.method-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.method-list button{min-height:62px;border-radius:6px;font-size:15px;padding:12px}.method-list button.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}.amount-field input,.money-input input{font-size:26px!important;min-height:60px!important;font-variant-numeric:tabular-nums}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:20px 24px;padding:18px;border:1px solid var(--line);border-radius:8px;background:var(--canvas)}.balance-banner strong,.refund-limit strong,.total-banner strong{font-size:30px;line-height:1.35;color:var(--ink)}.amount-choice,.type-switch{margin:18px 24px 0;border-radius:6px;gap:8px}.amount-choice button,.type-switch button{min-height:46px;border-radius:6px;font-size:14px}.optional-tool{border:1px solid var(--line);border-radius:6px;padding:12px}.optional-tool summary{font-size:14px;min-height:28px}.warning,.accounting-note,.explanation,.explain,.inventory-note,.method-hint{font-size:14px;line-height:1.7;border-radius:6px}.transfer-list,.split-manager,.split-builder,.return-items{padding:18px 24px}.transfer-list>article,.split-manager>article{padding:14px;border-radius:6px;border:1px solid var(--line);margin-bottom:10px}.transfer-actions button{min-height:44px;border-radius:6px}.decision-card,.confirm-card,.payment-confirm{padding:20px;margin:16px 24px;border-radius:8px;border:1px solid var(--line)}@media(max-width:560px){.transfer-record-sheet>header{padding:18px}.transfer-record-sheet>form{padding:18px}.transfer-record-sheet>form>footer,.transfer-record-sheet>footer{padding:14px 18px}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:18px}.method-list{grid-template-columns:1fr 1fr}}
</style>
