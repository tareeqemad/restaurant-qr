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
    border-radius: 18px 18px 0 0; background: #fff; overflow: hidden;
    box-shadow: 0 -18px 60px -12px rgba(15, 23, 42, .4);
}
header {
    display: flex; align-items: center; gap: .6rem;
    padding: .9rem 1rem; color: #fff;
    background: color-mix(in srgb, rgb(var(--primary-rgb, 22 101 52)) 78%, #04150d);
    font-weight: 800;
}
header > strong { flex: 1; display: inline-flex; align-items: center; gap: .45rem; }
.close {
    width: 44px; height: 44px; border: 0; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #fff; background: rgba(255, 255, 255, .16); cursor: pointer;
}
.body { padding: 1rem; overflow-y: auto; overscroll-behavior: contain; display: grid; gap: .7rem; }
.bank-details {
    display: flex; gap: .6rem;
    padding: .7rem .8rem; border: 1px solid #bfdbfe; border-radius: 12px; background: #eff6ff;
    color: #1e40af;
}
.bank-details pre { margin: 0; font-family: inherit; font-size: .85rem; white-space: pre-wrap; }
.field span { display: block; margin-bottom: .35rem; color: #374151; font-size: .82rem; font-weight: 700; }
.field input {
    width: 100%; min-height: 48px; box-sizing: border-box;
    padding: .55rem .7rem; border: 1.5px solid #e5e7eb; border-radius: 12px;
    font-family: inherit; font-size: .95rem;
}
.field input:focus { outline: none; border-color: rgb(var(--primary-rgb, 22 101 52)); }
.suggestion { margin: -.3rem 0 0; color: #6b7280; font-size: .8rem; }
.disclaimer { margin: 0; display: flex; align-items: center; gap: .4rem; color: #6b7280; font-size: .78rem; }
.go {
    min-height: 50px; border: 0; border-radius: 12px;
    background: rgb(var(--primary-rgb, 22 101 52)); color: #fff;
    font-family: inherit; font-size: .95rem; font-weight: 800; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
}
.go:disabled { opacity: .6; cursor: not-allowed; }
</style>
