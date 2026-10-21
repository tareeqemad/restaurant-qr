<script setup>
import { computed, ref } from 'vue';
import { sendCashierCommand } from '../../Api/cashierApi';
import { formatMoney } from '../../Composables/useMoney';
import { createAccountingToken } from '../Accounting/accountingToken';
import { confirmsCustody, confirmsRejection, readCustodyRecovery } from '../../Stores/cashierAcknowledgement';
import { withCashierRecoveryLock } from '../../Stores/cashierRecoveryLock';

const props = defineProps({ shifts: Object, commands: Object, currency: Object, blocked: Boolean, canCollect: Boolean, recoveryScope: String, currentBranch: Number });
const emit = defineEmits(['updated']);
const active = computed(() => props.shifts?.active);
const differentBranch = computed(() => active.value && Number(active.value.branch_id) !== Number(props.currentBranch));
const opening = ref('');
const counted = ref('');
const notes = ref('');
const closing = ref(false);
const busy = ref(false);
const error = ref('');
const manualOpen = ref(false);
const movement = ref({ direction: 'out', amount: '', reason: '', reference: '' });
const pendingMovement = ref(null);
const storageError = ref(false);
const storageKey = `cashier-custody:v1:${props.recoveryScope}`;
try {
    pendingMovement.value = readCustodyRecovery(window.localStorage, storageKey);
} catch { storageError.value = true; error.value = 'تعذّر قراءة سجل حركة العهدة. تحقّق من السجل مع المسؤول قبل حركة بديلة.'; }
const money = value => formatMoney(value, props.currency);
const difference = computed(() => counted.value === '' ? null : Number(counted.value) - Number(active.value?.expected_cash || 0));
async function submit(close = false) {
    if (busy.value || props.blocked || pendingMovement.value || storageError.value) return;
    busy.value = true;
    error.value = '';
    try {
        const closingId = active.value?.id;
        const result = await sendCashierCommand(close ? props.commands.close_shift.replace(':shift', closingId) : props.commands.open_shift,
            close ? { counted_cash: counted.value, notes: notes.value } : { opening_cash: opening.value });
        if (!result.ok) throw new Error(result.data.message || Object.values(result.data.errors || {}).flat()[0] || 'تعذّر حفظ الوردية.');
        if (!(Number(result.data.data?.id) > 0) || (close ? Number(result.data.data.id) !== Number(closingId) || !result.data.data.closed_at : !result.data.data.opened_at || result.data.data.closed_at)) throw new Error('لم يصل تأكيد صالح. تحقّق من تحديث الوردية قبل إعادة المحاولة.');
        closing.value = false;
        opening.value = counted.value = notes.value = '';
        emit('updated');
    } catch (exception) {
        error.value = exception.message || 'تحقّق من تحديث الوردية قبل إعادة المحاولة.';
        emit('updated');
    } finally { busy.value = false; }
}
async function submitMovement() {
    if (busy.value || props.blocked || storageError.value) return;
    busy.value = true;
    error.value = '';
    try {
        await withCashierRecoveryLock(storageKey, () => {
        if (!pendingMovement.value) {
            const existing = readCustodyRecovery(window.localStorage, storageKey);
            if (existing) { pendingMovement.value = existing; }
            else {
                if (differentBranch.value) throw new Error(`اختر فرع ${active.value.branch} من مبدّل الفروع قبل تسجيل حركة نقدية.`);
                const operation = { token: createAccountingToken(), shiftId: active.value.id, payload: { ...movement.value } };
                window.localStorage.setItem(storageKey, JSON.stringify(operation));
                if (window.localStorage.getItem(storageKey) !== JSON.stringify(operation)) throw new Error('تعذّر حفظ معلومات الاستعادة؛ لم تُرسل الحركة.');
                pendingMovement.value = operation;
            }
        }
        });
        const operation = pendingMovement.value;
        const result = await sendCashierCommand(props.commands.shift_movement.replace(':shift', operation.shiftId), { token: operation.token, ...operation.payload });
        const confirmed = result.ok && confirmsCustody(result.data.data, operation);
        if (confirmed || (!result.ok && result.status === 422 && confirmsRejection(result.data, operation))) {
            await withCashierRecoveryLock(storageKey, () => { if (readCustodyRecovery(window.localStorage, storageKey)?.token === operation.token) window.localStorage.removeItem(storageKey); });
            pendingMovement.value = null;
            if (!confirmed) throw new Error(result.data.message || 'رُفضت الحركة ولم تسجّل.');
            manualOpen.value = false;
            movement.value = { direction: 'out', amount: '', reason: '', reference: '' };
            emit('updated');
        } else error.value = result.data.message || 'لم تتأكد نتيجة الحركة. أعد إرسال نفس الحركة للتحقق.';
    } catch (exception) { error.value = pendingMovement.value ? 'لم تتأكد نتيجة الحركة؛ بياناتها محفوظة. أعد إرسال نفس الحركة للتحقق دون تكرارها.' : exception.message; }
    finally { busy.value = false; }
}
</script>
<template>
<section v-if="canCollect || active" class="cash-custody" aria-label="ورديتي النقدية">
    <header><div><strong>{{ active ? `ورديتي #${active.id} · ${active.branch}` : 'ابدأ ورديتك النقدية' }}</strong><small>{{ active ? `مفتوحة منذ ${active.opened_at}` : 'كل موظف يفتح عهدته ويعدّ النقد عند انتهاء عمله.' }}</small></div><button v-if="active" type="button" :disabled="blocked || busy || Boolean(pendingMovement) || storageError" @click="closing = !closing">{{ closing ? 'متابعة العمل' : 'عدّ النقد وإغلاق ورديتي' }}</button></header>
    <div v-if="active" class="cash-totals"><span>افتتاح <bdi>{{ money(active.opening_cash) }}</bdi></span><span>نقد داخل <bdi>{{ money(active.cash_in) }}</bdi></span><span>نقد خارج <bdi>{{ money(active.cash_out) }}</bdi></span><span>متوقع الآن <bdi>{{ money(active.expected_cash) }}</bdi></span></div>
    <form v-if="!active" @submit.prevent="submit(false)"><label>النقد الموجود عند الافتتاح<input v-model="opening" required type="number" min="0" max="99999999.99" step=".01" placeholder="0.00"></label><button :disabled="blocked || busy">{{ busy ? 'جارٍ الحفظ…' : 'افتتاح ورديتي' }}</button></form>
    <form v-else-if="closing" @submit.prevent="submit(true)"><label>النقد الذي عددته فعليًا<input v-model="counted" required type="number" min="0" max="99999999.99" step=".01" placeholder="المبلغ الموجود"></label><span v-if="difference !== null">الفرق الآن <bdi>{{ money(difference) }}</bdi></span><label>ملاحظات التسليم والفرق<textarea v-model="notes" :required="difference !== null && Math.abs(difference) >= .01" maxlength="1000" rows="2" placeholder="وثّق سبب أي فرق أو ما يحتاج مراجعة"></textarea></label><button :disabled="blocked || busy">{{ busy ? 'جارٍ الحفظ…' : 'تأكيد العدّ وإغلاق الوردية' }}</button></form>
    <p v-if="error" class="shift-error" role="alert">{{ error }}</p>
    <p v-if="differentBranch" class="shift-help">وردية العهدة في {{ active.branch }}. اختر هذا الفرع من مبدّل الفروع قبل إدخال أو إخراج النقد.</p>
    <button v-if="active && !closing && !pendingMovement" type="button" :disabled="blocked || busy || storageError || differentBranch" @click="manualOpen = !manualOpen">{{ manualOpen ? 'إخفاء حركة العهدة' : 'إدخال / إخراج نقد من العهدة' }}</button>
    <form v-if="manualOpen && !pendingMovement" @submit.prevent="submitMovement"><label>الاتجاه<select v-model="movement.direction"><option value="out">إخراج نقد</option><option value="in">إدخال نقد</option></select></label><label>المبلغ<input v-model="movement.amount" type="number" min=".01" max="99999999.99" step=".01" required></label><label>سبب الحركة<input v-model="movement.reason" required maxlength="500" placeholder="مثال: سحب المدير من الدرج"></label><label>مرجع التسليم أو المستند<input v-model="movement.reference" required maxlength="255" placeholder="رقم المصروف / إيصال التسليم"></label><p class="shift-help">حركة عهدة فقط، لا تُسجل مصروفًا أو قيدًا محاسبيًا مرة ثانية. لا تكرر القبض والاسترداد المسجلين تلقائيًا.</p><button :disabled="blocked || busy">حفظ حركة النقد</button></form>
    <div v-if="pendingMovement" class="pending-custody" role="status"><p>حركة بانتظار التأكيد في الوردية #{{ pendingMovement.shiftId }}: {{ pendingMovement.payload.direction === 'out' ? 'إخراج' : 'إدخال' }} {{ money(pendingMovement.payload.amount) }} · {{ pendingMovement.payload.reference }}</p><button type="button" :disabled="blocked || busy" @click="submitMovement">تحقّق بإعادة نفس الحركة</button></div>
    <p v-if="blocked" class="shift-help">أكمل العملية الجارية أو تحقّق من الدفعة غير المؤكدة قبل تغيير الوردية.</p>
    <details v-if="shifts?.recent?.length"><summary>آخر وردياتي المغلقة</summary><article v-for="row in shifts.recent" :key="row.id"><strong>#{{ row.id }} · {{ row.branch }}</strong><span>{{ row.opened_at }} ← {{ row.closed_at }}</span><span>المعدود {{ money(row.counted_cash) }} · الفرق {{ money(row.variance) }}</span><small v-if="row.notes">{{ row.notes }}</small></article></details>
    <details><summary>ما الذي يدخل في النقد المتوقع؟</summary><p>رصيد الافتتاح + قبض الفواتير والأرصدة المقدمة − الاسترداد النقدي وعكس القبض، مع حركات إدخال وإخراج العهدة الموثقة. يشمل العكس اللاحق في وردية منفّذه. وثّق المصروف أو التسليم من الدرج بحركة إخراج ومرجعه؛ اعتماد المصروف الإداري وحده لا يخصم من هذه العهدة. إغلاق الوردية يحفظ العدّ ولا ينشئ قيد تسوية تلقائيًا.</p></details>
</section>
</template>
<style scoped>
.cash-custody{padding:16px;background:var(--surface,#FFFDFA);border:1px solid var(--line,#DFD7CB);border-inline-start:4px solid var(--brand,#A3422B);border-radius:8px;font-size:14px}.cash-custody header{display:flex;gap:16px;justify-content:space-between;align-items:center}.cash-custody header>div{display:grid;gap:4px}.cash-custody small,.shift-help{color:var(--muted,#626B6D)}.cash-custody form,.cash-totals{display:flex;gap:16px;align-items:end;flex-wrap:wrap;margin-top:14px}.cash-totals span{display:grid;gap:4px;min-width:120px}.cash-totals bdi{font-size:18px;font-weight:800}.cash-custody label{display:grid;gap:6px;flex:1;min-width:180px}.cash-custody input,.cash-custody textarea{min-height:44px;padding:8px;border:1px solid var(--line,#DFD7CB);border-radius:6px;font:inherit;background:var(--surface,#FFFDFA)}.cash-custody button{min-height:44px;padding:10px 14px;border:1px solid var(--brand,#A3422B);background:var(--brand,#A3422B);color:#fff;border-radius:6px;font:inherit;font-weight:700}.cash-custody button:disabled{opacity:.5}.cash-custody details{margin-top:12px}.cash-custody summary{cursor:pointer;color:var(--muted,#626B6D)}.cash-custody article{display:flex;gap:12px;flex-wrap:wrap;border-top:1px solid var(--line,#DFD7CB);padding:10px 0}.cash-custody p{line-height:1.8;margin:8px 0 0}.shift-error{color:#9d2835}@media(max-width:600px){.cash-custody header{align-items:stretch;flex-direction:column}.cash-custody form{flex-direction:column;align-items:stretch}}
</style>
