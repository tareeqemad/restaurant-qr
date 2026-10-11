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
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .46); backdrop-filter: none; }
.transfer-sheet { width: min(650px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid var(--line, var(--line)); border-radius: 12px; background: var(--surface, #FFFDFA); box-shadow: none; overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; } header > div { display: flex; flex-direction: column; } header span { color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; } header h2 { margin: .1rem 0 0; color: var(--ink); font-size: .94rem; } header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid var(--line); border-radius: 8px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }
.sheet-error { margin-top: .55rem; padding: .5rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .875rem; }
.transfer-list { display: flex; flex-direction: column; gap: .45rem; margin-top: .7rem; }.transfer-list article { padding: .6rem; border: 1px solid var(--line, var(--line)); border-radius: 11px; background: var(--surface, #FFFDFA); }.transfer-list article.selected { border-color: #c6ad54; box-shadow: 0 0 0 2px rgba(154, 119, 13, .08); }
.transfer-main { display: flex; align-items: center; gap: .5rem; }.bank-mark { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 9px; color: #795d05; background: #fff4cc; }.transfer-main > span:nth-child(2) { display: flex; min-width: 0; flex: 1; flex-direction: column; }.transfer-main strong { font-size: .875rem; }.transfer-main small { margin-top: .1rem; color: var(--muted, var(--muted)); font-size: .875rem; }.transfer-main b { color: #6f570a; font-size: .875rem; }.transfer-list article > p { margin: .4rem 0 0; color: var(--muted, var(--muted)); font-size: .875rem; }
.transfer-actions { display: flex; gap: .35rem; margin-top: .5rem; }.transfer-actions button { min-height: 38px; padding-inline: .65rem; border-radius: 8px; font: inherit; font-size: .875rem; font-weight: 800; }.proof { margin-inline-end: auto; border: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }.verify { border: 1px solid var(--brand); color: #FFFDFA; background: var(--brand, #A3422B); }.reject { border: 1px solid #b13a44; color: #FFFDFA; background: #b13a44; }
.proof-frame { display: flex; margin-top: .55rem; flex-direction: column; gap: .35rem; padding: .4rem; border-radius: 9px; background: var(--surface, #FFFDFA); }.proof-frame img { width: 100%; max-height: 300px; border-radius: 7px; object-fit: contain; background: var(--surface, #FFFDFA); }.proof-frame a { color: var(--ink, var(--ink)); text-align: center; font-size: .875rem; font-weight: 750; }
.decision-card { margin-top: .7rem; padding: .7rem; border-radius: 11px; }.verify-card { background: var(--surface, #FFFDFA); }.reject-card { background: #fff3f4; }.decision-card h3 { margin: 0; font-size: .875rem; }.decision-card label { display: flex; margin-top: .55rem; flex-direction: column; gap: .25rem; color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; }.decision-card em { color: var(--muted, var(--muted)); font-style: normal; font-weight: 500; }.decision-card input, .decision-card textarea { min-height: 43px; box-sizing: border-box; padding: .55rem .65rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; resize: vertical; font: inherit; font-size: .875rem; }.decision-card small { color: #aa313b; }.decision-card > p { display: flex; gap: .35rem; margin: .55rem 0 0; color: var(--muted, var(--muted)); font-size: .875rem; line-height: 1.6; }.decision-card > div { display: flex; gap: .4rem; margin-top: .6rem; }.decision-card button { min-height: 44px; flex: 1; border-radius: 8px; font: inherit; font-size: .875rem; font-weight: 800; }.secondary { border: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }
.empty-transfer { display: flex; min-height: 130px; align-items: center; justify-content: center; flex-direction: column; gap: .35rem; color: var(--brand, #A3422B); }.empty-transfer i { font-size: 1.4rem; }.empty-transfer strong { font-size: .875rem; } button:disabled { opacity: .45; }
@media (min-width: 700px) { .sheet-layer { align-items: center; } } @media (max-width: 520px) { .sheet-layer { padding: 0; }.transfer-sheet { max-height: 94dvh; border-radius: 12px 12px 0 0; }.transfer-actions { flex-wrap: wrap; }.proof { width: 100%; margin: 0; } }

/* Task-specific component layout. */
.sheet-layer{padding:0;display:flex;align-items:stretch;justify-content:flex-end;background:rgb(37 52 58 / 42%);backdrop-filter:none}.transfer-sheet{width:min(560px,100%);max-width:100%;height:100dvh;max-height:100dvh;min-height:0;margin:0;border:0;border-radius:0;overflow-y:auto;background:var(--surface);box-shadow:none;padding:0}.transfer-sheet>header{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line);background:var(--surface);margin:0}.transfer-sheet>header h2{font-size:23px;line-height:1.4;margin:4px 0 0;color:var(--ink)}.transfer-sheet>header span,.transfer-sheet>header small{font-size:14px;color:var(--muted)}.transfer-sheet>header button{min-width:44px;min-height:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.transfer-sheet>form{padding:22px 24px;display:grid;gap:18px}.transfer-sheet>form>footer,.transfer-sheet>footer{position:sticky;bottom:0;z-index:2;display:flex;gap:12px;justify-content:flex-end;padding:16px 24px;margin:0;background:var(--surface);border-top:1px solid var(--line)}.transfer-sheet footer button{min-height:48px;border-radius:8px;font-size:15px;flex:1}.transfer-sheet label{font-size:14px;line-height:1.6}.transfer-sheet input:not([type=checkbox]):not([type=radio]),.transfer-sheet select{min-height:48px;border-radius:6px;font-size:16px}.transfer-sheet textarea{border-radius:6px;font-size:15px;min-height:90px}.sheet-error{margin:16px 24px;padding:12px;border-radius:6px;font-size:14px;line-height:1.7}.method-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.method-list button{min-height:62px;border-radius:6px;font-size:15px;padding:12px}.method-list button.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}.amount-field input,.money-input input{font-size:26px!important;min-height:60px!important;font-variant-numeric:tabular-nums}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:20px 24px;padding:18px;border:1px solid var(--line);border-radius:8px;background:var(--canvas)}.balance-banner strong,.refund-limit strong,.total-banner strong{font-size:30px;line-height:1.35;color:var(--ink)}.amount-choice,.type-switch{margin:18px 24px 0;border-radius:6px;gap:8px}.amount-choice button,.type-switch button{min-height:46px;border-radius:6px;font-size:14px}.optional-tool{border:1px solid var(--line);border-radius:6px;padding:12px}.optional-tool summary{font-size:14px;min-height:28px}.warning,.accounting-note,.explanation,.explain,.inventory-note,.method-hint{font-size:14px;line-height:1.7;border-radius:6px}.transfer-list,.split-manager,.split-builder,.return-items{padding:18px 24px}.transfer-list>article,.split-manager>article{padding:14px;border-radius:6px;border:1px solid var(--line);margin-bottom:10px}.transfer-actions button{min-height:44px;border-radius:6px}.decision-card,.confirm-card,.payment-confirm{padding:20px;margin:16px 24px;border-radius:8px;border:1px solid var(--line)}@media(max-width:560px){.transfer-sheet>header{padding:18px}.transfer-sheet>form{padding:18px}.transfer-sheet>form>footer,.transfer-sheet>footer{padding:14px 18px}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:18px}.method-list{grid-template-columns:1fr 1fr}}
</style>
