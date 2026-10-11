<script setup>
import { computed, ref, watch } from "vue";
import { formatMoney } from "../../Composables/useMoney";
import { localDateInput } from "../../Utils/dateInput";

const props = defineProps({
    open: { type: Boolean, required: true },
    invoice: { type: Object, default: null },
    customer: { type: Object, default: null },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: "" },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: () => true,
    submit: (payload) => typeof payload === "object" && Boolean(payload?.due_date),
});
const notes = ref("");
const dueDate = ref("");
const today = localDateInput();
const resultingDebt = computed(
    () =>
        Number(props.customer?.debt || 0) + Number(props.invoice?.balance || 0),
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            notes.value = "";
            const date = new Date();
            date.setDate(date.getDate() + 30);
            dueDate.value = localDateInput(date);
        }
    },
    { immediate: true },
);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section
                class="debt-sheet"
                role="dialog"
                aria-modal="true"
                aria-labelledby="debt-title"
            >
                <header>
                    <div>
                        <span>تسجيل الفاتورة كاملة أو المتبقي منها ديناً</span>
                        <h2 id="debt-title">{{ customer?.name }}</h2>
                    </div>
                    <button
                        type="button"
                        aria-label="إغلاق"
                        :disabled="busy"
                        @click="emit('close')"
                    >
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>
                <div class="debt-grid">
                    <span
                        ><small>الدين الحالي</small
                        ><strong>{{
                            formatMoney(customer?.debt, currency)
                        }}</strong></span
                    >
                    <span
                        ><small>المتبقي الحالي</small
                        ><strong>{{
                            formatMoney(invoice?.balance, currency)
                        }}</strong></span
                    >
                    <span class="result"
                        ><small>الدين بعد التأجيل</small
                        ><strong>{{
                            formatMoney(resultingDebt, currency)
                        }}</strong></span
                    >
                </div>
                <p v-if="customer?.credit_limit !== null" class="credit-line">
                    الحد الائتماني:
                    <strong>{{
                        formatMoney(customer.credit_limit, currency)
                    }}</strong>
                    · المتاح قبل العملية:
                    {{ formatMoney(customer.credit_available, currency) }}
                </p>
                <div v-if="error" class="sheet-error">
                    <i class="bi bi-exclamation-circle"></i> {{ error }}
                </div>
                <label
                    ><span>تاريخ الاستحقاق *</span
                    ><input v-model="dueDate" type="date" :min="today"
                    ><small v-if="errors.due_date">{{ errors.due_date[0] }}</small></label
                >
                <label
                    ><span>ملاحظة التحصيل <em>اختياري</em></span
                    ><textarea
                        v-model="notes"
                        maxlength="500"
                        rows="3"
                        placeholder="موعد السداد أو اتفاق الزبون"
                    ></textarea
                    ><small v-if="errors.notes">{{
                        errors.notes[0]
                    }}</small></label
                >
                <p class="warning">
                    <i class="bi bi-door-closed"></i> سيُغلق حساب الطاولة وتصبح
                    متاحة، ويبقى المبلغ ذمة مدينة على الزبون. لا يُنشأ قيد جديد
                    لأن قيد إصدار الفاتورة سجّل الذمة مسبقاً.
                </p>
                <footer>
                    <button
                        type="button"
                        class="secondary"
                        :disabled="busy"
                        @click="emit('close')"
                    >
                        رجوع</button
                    ><button
                        type="button"
                        class="primary"
                        :disabled="busy || !dueDate"
                        @click="emit('submit', { notes: notes.trim(), due_date: dueDate })"
                    >
                        {{
                            busy
                                ? "جاري التأجيل…"
                                : "تأكيد الدين وإغلاق الطاولة"
                        }}
                    </button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer {
    position: fixed;
    z-index: 1100;
    inset: 0;
    display: grid;
    align-items: end;
    justify-items: center;
    padding: 1rem;
    background: rgba(15, 27, 19, 0.46);
    backdrop-filter: none;
}
.debt-sheet {
    width: min(540px, 100%);
    max-height: calc(100dvh - 2rem);
    box-sizing: border-box;
    padding: 1rem;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--surface, #FFFDFA);
    box-shadow: none;
    overflow-y: auto;
}
header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
header > div {
    display: flex;
    flex-direction: column;
}
header span {
    color: #806e42;
    font-size: .875rem;
    font-weight: 750;
}
header h2 {
    margin: 0.1rem 0 0;
    color: var(--ink);
    font-size: 0.94rem;
}
header button {
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
}
.debt-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.4rem;
    margin-top: 0.7rem;
}
.debt-grid span {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.55rem;
    border-radius: 10px;
    color: var(--muted, var(--muted));
    background: #f7f5ee;
}
.debt-grid small {
    font-size: .875rem;
}
.debt-grid strong {
    color: #67510f;
    font-size: .875rem;
}
.debt-grid .result {
    background: #fff3cf;
}
.credit-line {
    margin: 0.45rem 0 0;
    color: var(--muted);
    font-size: .875rem;
}
.sheet-error {
    margin-top: 0.55rem;
    padding: 0.5rem;
    border-radius: 9px;
    color: #922d36;
    background: #fff0f1;
    font-size: .875rem;
}
label {
    display: flex;
    margin-top: 0.7rem;
    flex-direction: column;
    gap: 0.27rem;
    color: var(--muted, var(--muted));
    font-size: .875rem;
    font-weight: 750;
}
label em {
    color: var(--muted, var(--muted));
    font-style: normal;
    font-weight: 500;
}
textarea, input[type="date"] {
    min-height: 80px;
    box-sizing: border-box;
    padding: 0.6rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    resize: vertical;
    font: inherit;
    font-size: .875rem;
}
label small {
    color: #a62e38;
}
.warning {
    display: flex;
    gap: 0.4rem;
    margin: 0.65rem 0 0;
    padding: 0.55rem;
    border-radius: 9px;
    color: #6c5720;
    background: #fff9e8;
    font-size: .875rem;
    line-height: 1.65;
}
footer {
    display: flex;
    gap: 0.45rem;
    margin-top: 0.8rem;
}
footer button {
    min-height: 46px;
    flex: 1;
    border-radius: 8px;
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.secondary {
    border: 1px solid var(--line, var(--line));
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
}
.primary {
    border: 1px solid #7d6418;
    color: #FFFDFA;
    background: #7d6418;
}
button:disabled {
    opacity: 0.45;
}
@media (min-width: 700px) {
    .sheet-layer {
        align-items: center;
    }
}
@media (max-width: 520px) {
    .sheet-layer {
        padding: 0;
    }
    .debt-sheet {
        border-radius: 12px 12px 0 0;
    }
    .debt-grid {
        grid-template-columns: 1fr 1fr;
    }
    .debt-grid .result {
        grid-column: 1 / -1;
    }
}

/* Task-specific component layout. */
.sheet-layer{padding:0;display:flex;align-items:stretch;justify-content:flex-end;background:rgb(37 52 58 / 42%);backdrop-filter:none}.debt-sheet{width:min(560px,100%);max-width:100%;height:100dvh;max-height:100dvh;min-height:0;margin:0;border:0;border-radius:0;overflow-y:auto;background:var(--surface);box-shadow:none;padding:0}.debt-sheet>header{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line);background:var(--surface);margin:0}.debt-sheet>header h2{font-size:23px;line-height:1.4;margin:4px 0 0;color:var(--ink)}.debt-sheet>header span,.debt-sheet>header small{font-size:14px;color:var(--muted)}.debt-sheet>header button{min-width:44px;min-height:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.debt-sheet>form{padding:22px 24px;display:grid;gap:18px}.debt-sheet>form>footer,.debt-sheet>footer{position:sticky;bottom:0;z-index:2;display:flex;gap:12px;justify-content:flex-end;padding:16px 24px;margin:0;background:var(--surface);border-top:1px solid var(--line)}.debt-sheet footer button{min-height:48px;border-radius:8px;font-size:15px;flex:1}.debt-sheet label{font-size:14px;line-height:1.6}.debt-sheet input:not([type=checkbox]):not([type=radio]),.debt-sheet select{min-height:48px;border-radius:6px;font-size:16px}.debt-sheet textarea{border-radius:6px;font-size:15px;min-height:90px}.sheet-error{margin:16px 24px;padding:12px;border-radius:6px;font-size:14px;line-height:1.7}.method-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.method-list button{min-height:62px;border-radius:6px;font-size:15px;padding:12px}.method-list button.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}.amount-field input,.money-input input{font-size:26px!important;min-height:60px!important;font-variant-numeric:tabular-nums}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:20px 24px;padding:18px;border:1px solid var(--line);border-radius:8px;background:var(--canvas)}.balance-banner strong,.refund-limit strong,.total-banner strong{font-size:30px;line-height:1.35;color:var(--ink)}.amount-choice,.type-switch{margin:18px 24px 0;border-radius:6px;gap:8px}.amount-choice button,.type-switch button{min-height:46px;border-radius:6px;font-size:14px}.optional-tool{border:1px solid var(--line);border-radius:6px;padding:12px}.optional-tool summary{font-size:14px;min-height:28px}.warning,.accounting-note,.explanation,.explain,.inventory-note,.method-hint{font-size:14px;line-height:1.7;border-radius:6px}.transfer-list,.split-manager,.split-builder,.return-items{padding:18px 24px}.transfer-list>article,.split-manager>article{padding:14px;border-radius:6px;border:1px solid var(--line);margin-bottom:10px}.transfer-actions button{min-height:44px;border-radius:6px}.decision-card,.confirm-card,.payment-confirm{padding:20px;margin:16px 24px;border-radius:8px;border:1px solid var(--line)}@media(max-width:560px){.debt-sheet>header{padding:18px}.debt-sheet>form{padding:18px}.debt-sheet>form>footer,.debt-sheet>footer{padding:14px 18px}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:18px}.method-list{grid-template-columns:1fr 1fr}}
</style>
