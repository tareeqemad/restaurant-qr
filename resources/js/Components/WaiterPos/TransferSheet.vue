<script setup>
/**
 * إعلان حوالة بنكية — redesigned port of the classic Bootstrap modal.
 * Shows the restaurant's transfer details (so the waiter reads them to the
 * diner), prefills the amount with the session's outstanding total, and
 * records the claim into the cashier's queue. The backend is DELEGATION
 * ONLY into sol's transfers domain — see WaiterPosVueController::transfer.
 */
import { ref } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    details: { type: String, required: true },
    suggestedAmount: { type: Number, default: 0 },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits({
    close: () => true,
    record: (payload) => payload && Number(payload.amount) > 0 && typeof payload.sender_name === 'string',
});

const amount = ref(props.suggestedAmount > 0 ? String(props.suggestedAmount) : '');
const senderName = ref('');
const senderPhone = ref('');
const notes = ref('');

function submit() {
    if (props.busy || !Number(amount.value) || !senderName.value.trim()) return;
    emit('record', {
        amount: Number(amount.value),
        sender_name: senderName.value.trim(),
        customer_phone: senderPhone.value.trim() || null,
        notes: notes.value.trim() || null,
    });
}
</script>

<template>
    <div class="sheet-backdrop" @click.self="emit('close')" @keydown.escape.window="emit('close')">
        <div class="sheet" role="dialog" aria-label="إعلان حوالة بنكية">
            <header>
                <strong><i class="bi bi-bank"></i> حوالة بنكية</strong>
                <button type="button" class="close" aria-label="إغلاق" @click="emit('close')">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <div class="body">
                <div class="bank-details">
                    <i class="bi bi-info-circle"></i>
                    <pre>{{ details }}</pre>
                </div>

                <label class="field">
                    <span>المبلغ المحوّل</span>
                    <input v-model="amount" type="number" inputmode="decimal"
                           min="0.01" step="0.01" @keyup.enter="submit">
                </label>
                <p v-if="suggestedAmount > 0" class="suggestion">
                    مستحق الجلسة الحالي: <b>{{ formatMoney(suggestedAmount, currency) }}</b>
                </p>

                <label class="field">
                    <span>اسم المرسِل (كما يظهر بالتطبيق البنكي)</span>
                    <input v-model="senderName" type="text" maxlength="120" @keyup.enter="submit">
                </label>
                <label class="field">
                    <span>جوال الزبون (اختياري)</span>
                    <input v-model="senderPhone" type="tel" inputmode="tel" maxlength="32">
                </label>
                <label class="field">
                    <span>ملاحظات (اختياري)</span>
                    <input v-model="notes" type="text" maxlength="500">
                </label>

                <p class="disclaimer">
                    <i class="bi bi-shield-check"></i>
                    ما بينحسب أي دفع هلق — الادعاء بيروح لطابور الكاشير وبيتأكد بعد وصول المبلغ فعلياً.
                </p>

                <button type="button" class="go" :disabled="busy || !Number(amount) || !senderName.trim()" @click="submit">
                    <i class="bi" :class="busy ? 'bi-hourglass-split' : 'bi-send-check'"></i>
                    تسجيل الحوالة لدى الكاشير
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sheet-backdrop {
    position: fixed; inset: 0; z-index: 1080;
    display: flex; align-items: flex-end; justify-content: center;
    background: rgba(15, 23, 42, .5);
}
.sheet {
    width: 100%; max-width: 560px; max-height: 88vh; max-height: 88dvh;
    display: flex; flex-direction: column;
    border-radius: 12px 12px 0 0; background: var(--surface); overflow: hidden;
    box-shadow: none;
}
header {
    display: flex; align-items: center; gap: .6rem;
    padding: .9rem 1rem; color: #FFFDFA;
    background: color-mix(in srgb, var(--brand) 78%, var(--brand-dark));
    font-weight: 800;
}
header > strong { flex: 1; display: inline-flex; align-items: center; gap: .45rem; }
.close {
    width: 44px; height: 44px; border: 0; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #FFFDFA; background: rgba(255, 255, 255, .16); cursor: pointer;
}
.body { padding: 1rem; overflow-y: auto; overscroll-behavior: contain; display: grid; gap: .7rem; }
.bank-details {
    display: flex; gap: .6rem;
    padding: .7rem .8rem; border: 1px solid var(--line); border-radius: 12px; background: var(--canvas);
    color: #315B6B;
}
.bank-details pre { margin: 0; font-family: inherit; font-size: .875rem; white-space: pre-wrap; }
.field span { display: block; margin-bottom: .35rem; color: var(--ink); font-size: .875rem; font-weight: 700; }
.field input {
    width: 100%; min-height: 48px; box-sizing: border-box;
    padding: .55rem .7rem; border: 1.5px solid var(--line); border-radius: 8px;
    font-family: inherit; font-size: .95rem;
}
.field input:focus { outline: none; border-color: var(--brand); }
.suggestion { margin: -.3rem 0 0; color: var(--muted); font-size: .875rem; }
.disclaimer { margin: 0; display: flex; align-items: center; gap: .4rem; color: var(--muted); font-size: .875rem; }
.go {
    min-height: 50px; border: 0; border-radius: 12px;
    background: var(--brand); color: #FFFDFA;
    font-family: inherit; font-size: .95rem; font-weight: 800; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
}
.go:disabled { opacity: .6; cursor: not-allowed; }

/* Task-specific component layout. */
.sheet{border-radius:10px;background:var(--surface);box-shadow:none;max-height:90dvh;overflow-y:auto}.sheet > header{padding:20px 24px;background:var(--surface);color:var(--ink);border-bottom:1px solid var(--line);gap:14px}.sheet > header h2,.sheet > header strong{font-size:22px;color:var(--ink)}.sheet > header button{width:44px;height:44px;min-width:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.body{padding:20px 24px}.sheet > footer{padding:16px 24px;border-top:1px solid var(--line);background:var(--surface);position:sticky;bottom:0;z-index:2}.sheet input:not([type=checkbox]):not([type=radio]),.sheet select{min-height:48px;border-radius:6px;font-size:16px}.sheet textarea{min-height:90px;border-radius:6px;font-size:15px}.modifier-options,.exclusion-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}.modifier-option,.exclusion-option{min-height:52px;padding:12px;border-radius:6px;font-size:15px}.group-title{padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px}.quantity-row{padding:16px 0}.quantity-stepper button{width:44px;height:44px}.save-action,.customer-sheet__button,.go{min-height:50px;border-radius:8px;font-size:16px}.person{border:0;border-bottom:1px solid var(--line);border-radius:0;padding:16px 0;min-height:70px}.person .name{font-size:16px}.bill-summary{border-radius:8px;padding:18px;background:var(--canvas)}.bill-summary__primary strong{font-size:27px}.session-round{border-radius:6px;margin-bottom:14px}.session-item{padding:14px 12px;gap:12px}.session-item__name-row strong{font-size:16px}.session-item__detail{font-size:14px;line-height:1.6}.session-item__cancel{min-height:42px}.customer-card{border-radius:8px;padding:18px;background:var(--canvas)}@media(max-width:600px){.sheet > header,.body{padding:18px}.sheet > footer{padding:14px 18px}.modifier-options,.exclusion-options{grid-template-columns:1fr}}
</style>
