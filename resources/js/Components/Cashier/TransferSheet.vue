<script setup>
import { ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    open: { type: Boolean, required: true },
    transfers: { type: Array, default: () => [] },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: () => true,
    verify: (payload) => Number(payload?.transfer?.id) > 0 && Number(payload?.verified_amount) > 0,
    reject: (payload) => Number(payload?.transfer?.id) > 0 && Boolean(payload?.reason),
});

const action = ref(null);
const selected = ref(null);
const verifiedAmount = ref('');
const verificationNotes = ref('');
const rejectionReason = ref('');
const proofOpen = ref(null);

watch(
    () => [props.open, props.transfers],
    ([open]) => {
        if (!open) return;
        if (selected.value && !props.transfers.some((transfer) => transfer.id === selected.value.id)) {
            resetAction();
        }
    },
    { immediate: true, deep: true },
);

function start(nextAction, transfer) {
    action.value = nextAction;
    selected.value = transfer;
    verifiedAmount.value = Number(transfer.amount).toFixed(2);
    verificationNotes.value = '';
    rejectionReason.value = '';
}

function resetAction() {
    action.value = null;
    selected.value = null;
    verifiedAmount.value = '';
    verificationNotes.value = '';
    rejectionReason.value = '';
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section class="transfer-sheet" role="dialog" aria-modal="true" aria-labelledby="transfer-title">
                <header>
                    <div><span>مطابقة مباشرة مع حساب البنك</span><h2 id="transfer-title">تحويلات بانتظار التأكيد</h2></div>
                    <button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
                </header>

                <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>

                <div v-if="transfers.length" class="transfer-list">
                    <article v-for="transfer in transfers" :key="transfer.id" :class="{ selected: selected?.id === transfer.id }">
                        <div class="transfer-main">
                            <span class="bank-mark"><i class="bi bi-bank"></i></span>
                            <span><strong>{{ transfer.sender_name }}</strong><small>{{ transfer.customer_name || 'زبون الطاولة' }} · {{ transfer.customer_phone || 'بدون هاتف' }}</small></span>
                            <b>{{ formatMoney(transfer.amount, currency) }}</b>
                        </div>
                        <p v-if="transfer.notes">{{ transfer.notes }}</p>
                        <div class="transfer-actions">
                            <button v-if="transfer.has_proof" type="button" class="proof" @click="proofOpen = proofOpen === transfer.id ? null : transfer.id"><i class="bi bi-image"></i> {{ proofOpen === transfer.id ? 'إخفاء الوصل' : 'عرض الوصل' }}</button>
                            <button type="button" class="reject" :disabled="busy" @click="start('reject', transfer)">رفض</button>
                            <button type="button" class="verify" :disabled="busy" @click="start('verify', transfer)">تأكيد</button>
                        </div>
                        <div v-if="proofOpen === transfer.id" class="proof-frame">
                            <img :src="transfer.proof_url" alt="صورة وصل التحويل" loading="lazy">
                            <a :href="transfer.proof_url" target="_blank" rel="noopener">فتح بالحجم الكامل</a>
                        </div>
                    </article>
                </div>
                <div v-else class="empty-transfer"><i class="bi bi-check2-circle"></i><strong>لا توجد تحويلات معلّقة لهذه الفاتورة.</strong></div>

                <div v-if="action === 'verify' && selected" class="decision-card verify-card">
                    <h3>تأكيد تحويل {{ selected.sender_name }}</h3>
                    <label><span>المبلغ الظاهر فعلياً في البنك *</span><input v-model="verifiedAmount" type="number" min="0.01" step="0.01" inputmode="decimal"><small v-if="errors.verified_amount">{{ errors.verified_amount[0] }}</small></label>
                    <label><span>ملاحظة المطابقة <em>اختياري</em></span><textarea v-model="verificationNotes" maxlength="500" rows="2" placeholder="مثال: ظهر في حساب بنك فلسطين"></textarea></label>
                    <p><i class="bi bi-journal-check"></i> سيُسجل المبلغ كدفعة تحويل ويُرحّل قيده تلقائياً. إن كان أقل من الفاتورة سيبقى الفرق للتحصيل.</p>
                    <div><button type="button" class="secondary" :disabled="busy" @click="resetAction">رجوع</button><button type="button" class="verify" :disabled="busy || Number(verifiedAmount) <= 0" @click="emit('verify', { transfer: selected, verified_amount: Number(verifiedAmount), verification_notes: verificationNotes.trim() || null })">{{ busy ? 'جاري التأكيد…' : 'تأكيد وتسجيل الدفعة' }}</button></div>
                </div>

                <div v-if="action === 'reject' && selected" class="decision-card reject-card">
                    <h3>رفض تحويل {{ selected.sender_name }}</h3>
                    <label><span>سبب الرفض *</span><textarea v-model="rejectionReason" maxlength="500" rows="3" placeholder="مثال: لم يظهر في كشف البنك"></textarea><small v-if="errors.reason">{{ errors.reason[0] }}</small></label>
                    <p><i class="bi bi-exclamation-triangle"></i> الرفض لا يسجل أي دفعة ولا يؤثر على الفاتورة، ويحفظ السبب للمراجعة.</p>
                    <div><button type="button" class="secondary" :disabled="busy" @click="resetAction">رجوع</button><button type="button" class="reject" :disabled="busy || !rejectionReason.trim()" @click="emit('reject', { transfer: selected, reason: rejectionReason.trim() })">{{ busy ? 'جاري الرفض…' : 'تأكيد الرفض' }}</button></div>
                </div>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .46); backdrop-filter: blur(3px); }
.transfer-sheet { width: min(650px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid #dce5df; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .55); overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; } header > div { display: flex; flex-direction: column; } header span { color: #7b7252; font-size: .64rem; font-weight: 750; } header h2 { margin: .1rem 0 0; color: #353222; font-size: .94rem; } header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #e5e1d4; border-radius: 10px; color: #6e6958; background: #fff; }
.sheet-error { margin-top: .55rem; padding: .5rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .68rem; }
.transfer-list { display: flex; flex-direction: column; gap: .45rem; margin-top: .7rem; }.transfer-list article { padding: .6rem; border: 1px solid #e4e5dc; border-radius: 11px; background: #fff; }.transfer-list article.selected { border-color: #c6ad54; box-shadow: 0 0 0 2px rgba(154, 119, 13, .08); }
.transfer-main { display: flex; align-items: center; gap: .5rem; }.bank-mark { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 9px; color: #795d05; background: #fff4cc; }.transfer-main > span:nth-child(2) { display: flex; min-width: 0; flex: 1; flex-direction: column; }.transfer-main strong { font-size: .73rem; }.transfer-main small { margin-top: .1rem; color: #7d847c; font-size: .6rem; }.transfer-main b { color: #6f570a; font-size: .78rem; }.transfer-list article > p { margin: .4rem 0 0; color: #68736b; font-size: .63rem; }
.transfer-actions { display: flex; gap: .35rem; margin-top: .5rem; }.transfer-actions button { min-height: 38px; padding-inline: .65rem; border-radius: 8px; font: inherit; font-size: .64rem; font-weight: 800; }.proof { margin-inline-end: auto; border: 1px solid #d7dfd9; color: #43564a; background: #fff; }.verify { border: 1px solid #277344; color: #fff; background: #277344; }.reject { border: 1px solid #b13a44; color: #fff; background: #b13a44; }
.proof-frame { display: flex; margin-top: .55rem; flex-direction: column; gap: .35rem; padding: .4rem; border-radius: 9px; background: #f4f6f4; }.proof-frame img { width: 100%; max-height: 300px; border-radius: 7px; object-fit: contain; background: #fff; }.proof-frame a { color: #315e41; text-align: center; font-size: .62rem; font-weight: 750; }
.decision-card { margin-top: .7rem; padding: .7rem; border-radius: 11px; }.verify-card { background: #f0f8f2; }.reject-card { background: #fff3f4; }.decision-card h3 { margin: 0; font-size: .75rem; }.decision-card label { display: flex; margin-top: .55rem; flex-direction: column; gap: .25rem; color: #59665e; font-size: .64rem; font-weight: 750; }.decision-card em { color: #89928c; font-style: normal; font-weight: 500; }.decision-card input, .decision-card textarea { min-height: 43px; box-sizing: border-box; padding: .55rem .65rem; border: 1px solid #d9e1db; border-radius: 9px; resize: vertical; font: inherit; font-size: .72rem; }.decision-card small { color: #aa313b; }.decision-card > p { display: flex; gap: .35rem; margin: .55rem 0 0; color: #667169; font-size: .61rem; line-height: 1.6; }.decision-card > div { display: flex; gap: .4rem; margin-top: .6rem; }.decision-card button { min-height: 44px; flex: 1; border-radius: 10px; font: inherit; font-size: .68rem; font-weight: 800; }.secondary { border: 1px solid #d9e1db; color: #536159; background: #fff; }
.empty-transfer { display: flex; min-height: 130px; align-items: center; justify-content: center; flex-direction: column; gap: .35rem; color: #267342; }.empty-transfer i { font-size: 1.4rem; }.empty-transfer strong { font-size: .7rem; } button:disabled { opacity: .45; }
@media (min-width: 700px) { .sheet-layer { align-items: center; } } @media (max-width: 520px) { .sheet-layer { padding: 0; }.transfer-sheet { max-height: 94dvh; border-radius: 18px 18px 0 0; }.transfer-actions { flex-wrap: wrap; }.proof { width: 100%; margin: 0; } }
</style>
