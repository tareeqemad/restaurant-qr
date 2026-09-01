<script setup>
import { computed, ref, watch } from "vue";
import { formatMoney } from "../../Composables/useMoney";

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
            dueDate.value = date.toISOString().slice(0, 10);
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
                    ><input v-model="dueDate" type="date" :min="new Date().toISOString().slice(0, 10)"
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
    backdrop-filter: blur(3px);
}
.debt-sheet {
    width: min(540px, 100%);
    max-height: calc(100dvh - 2rem);
    box-sizing: border-box;
    padding: 1rem;
    border: 1px solid #e6dfcf;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 24px 70px -28px rgba(0, 0, 0, 0.55);
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
    font-size: 0.64rem;
    font-weight: 750;
}
header h2 {
    margin: 0.1rem 0 0;
    color: #3b3423;
    font-size: 0.94rem;
}
header button {
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    border: 1px solid #e6e1d6;
    border-radius: 10px;
    color: #706958;
    background: #fff;
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
    color: #756e5d;
    background: #f7f5ee;
}
.debt-grid small {
    font-size: 0.59rem;
}
.debt-grid strong {
    color: #67510f;
    font-size: 0.73rem;
}
.debt-grid .result {
    background: #fff3cf;
}
.credit-line {
    margin: 0.45rem 0 0;
    color: #71684f;
    font-size: 0.62rem;
}
.sheet-error {
    margin-top: 0.55rem;
    padding: 0.5rem;
    border-radius: 9px;
    color: #922d36;
    background: #fff0f1;
    font-size: 0.68rem;
}
label {
    display: flex;
    margin-top: 0.7rem;
    flex-direction: column;
    gap: 0.27rem;
    color: #5d594c;
    font-size: 0.67rem;
    font-weight: 750;
}
label em {
    color: #8d897d;
    font-style: normal;
    font-weight: 500;
}
textarea, input[type="date"] {
    min-height: 80px;
    box-sizing: border-box;
    padding: 0.6rem;
    border: 1px solid #e1ddd3;
    border-radius: 10px;
    resize: vertical;
    font: inherit;
    font-size: 0.73rem;
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
    font-size: 0.63rem;
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
    border-radius: 11px;
    font: inherit;
    font-size: 0.7rem;
    font-weight: 800;
}
.secondary {
    border: 1px solid #dfddd5;
    color: #625e54;
    background: #fff;
}
.primary {
    border: 1px solid #7d6418;
    color: #fff;
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
        border-radius: 18px 18px 0 0;
    }
    .debt-grid {
        grid-template-columns: 1fr 1fr;
    }
    .debt-grid .result {
        grid-column: 1 / -1;
    }
}
</style>
