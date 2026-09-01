<script setup>
import { computed, ref, watch } from "vue";
import { formatMoney } from "../../Composables/useMoney";

const props = defineProps({
    open: { type: Boolean, required: true },
    invoice: { type: Object, default: null },
    customer: { type: Object, default: null },
    methods: { type: Array, required: true },
    currency: { type: Object, required: true },
    fullCash: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    error: { type: String, default: "" },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: () => true,
    submit: (payload) =>
        Number(payload?.amount) > 0 && typeof payload?.method === "string",
});

const amount = ref("");
const amountMode = ref("full");
const method = ref("cash");
const reference = ref("");
const notes = ref("");
const tendered = ref("");
const showTendered = ref(false);
const showNotes = ref(false);
const saveChangeAsAdvance = ref(false);

const balance = computed(() => Number(props.invoice?.balance || 0));
const advanceBalance = computed(() =>
    Number(props.customer?.advance_balance || 0),
);
const availableMethods = computed(() => {
    const methods = [...props.methods];
    if (props.customer?.id && advanceBalance.value > 0) {
        methods.push({
            code: "customer_advance",
            label: "رصيد الزبون",
            icon: "bi-wallet2",
        });
    }
    return methods;
});
const selectedMethod = computed(() =>
    availableMethods.value.find((item) => item.code === method.value),
);
const paymentAmount = computed(() => Number(amount.value || 0));
const changeDue = computed(() =>
    Math.max(0, Number(tendered.value || 0) - paymentAmount.value),
);
const amountTooHigh = computed(
    () => paymentAmount.value > balance.value + 0.001,
);
const advanceTooHigh = computed(
    () =>
        method.value === "customer_advance" &&
        paymentAmount.value > advanceBalance.value + 0.001,
);
const cashTenderedTooLow = computed(
    () =>
        method.value === "cash" &&
        tendered.value !== "" &&
        Number(tendered.value || 0) + 0.001 < paymentAmount.value,
);
const canSubmit = computed(
    () =>
        !props.busy &&
        paymentAmount.value > 0 &&
        !amountTooHigh.value &&
        !advanceTooHigh.value &&
        !cashTenderedTooLow.value,
);
const submitLabel = computed(() => {
    const methodLabel = selectedMethod.value?.label ?? "الدفع";
    return `تحصيل ${formatMoney(paymentAmount.value, props.currency)} ${methodLabel}`;
});

watch(
    () => [props.open, props.invoice?.id, props.fullCash],
    ([open]) => {
        if (!open) return;

        amountMode.value = "full";
        amount.value = balance.value.toFixed(2);
        method.value = props.fullCash
            ? "cash"
            : (props.methods[0]?.code ?? "cash");
        reference.value = "";
        notes.value = "";
        tendered.value = "";
        showTendered.value = false;
        showNotes.value = false;
        saveChangeAsAdvance.value = false;
    },
    { immediate: true },
);

function setAmountMode(mode) {
    amountMode.value = mode;
    if (mode === "full") {
        amount.value = balance.value.toFixed(2);
        return;
    }

    amount.value = "";
}

function selectMethod(code) {
    method.value = code;
    reference.value = "";
    if (code !== "cash") {
        showTendered.value = false;
        tendered.value = "";
        saveChangeAsAdvance.value = false;
    }
    if (code === "customer_advance") {
        amount.value = Math.min(balance.value, advanceBalance.value).toFixed(2);
        amountMode.value =
            advanceBalance.value >= balance.value ? "full" : "partial";
    }
}

function submit() {
    if (!canSubmit.value) return;

    emit("submit", {
        amount: paymentAmount.value,
        method: method.value,
        reference:
            method.value === "cash" ? null : reference.value.trim() || null,
        notes: notes.value.trim() || null,
        tendered_amount:
            method.value === "cash" && tendered.value !== ""
                ? Number(tendered.value)
                : null,
        save_change_as_advance:
            method.value === "cash" &&
            changeDue.value > 0 &&
            saveChangeAsAdvance.value,
    });
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="sheet-layer"
            role="presentation"
            @click.self="emit('close')"
        >
            <section
                class="payment-sheet"
                role="dialog"
                aria-modal="true"
                aria-labelledby="payment-title"
            >
                <header>
                    <div>
                        <span>تحصيل الفاتورة</span>
                        <h2 id="payment-title">{{ invoice?.number }}</h2>
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

                <div class="balance-banner">
                    <span>المبلغ المطلوب</span>
                    <strong>{{
                        formatMoney(invoice?.balance, currency)
                    }}</strong>
                </div>

                <div v-if="error" class="sheet-error">
                    <i class="bi bi-exclamation-circle"></i> {{ error }}
                </div>

                <div
                    v-if="!fullCash"
                    class="amount-choice"
                    aria-label="قيمة التحصيل"
                >
                    <button
                        type="button"
                        :class="{ active: amountMode === 'full' }"
                        @click="setAmountMode('full')"
                    >
                        <i class="bi bi-check-circle"></i>
                        <span
                            ><strong>دفع كامل</strong
                            ><small>{{
                                formatMoney(balance, currency)
                            }}</small></span
                        >
                    </button>
                    <button
                        type="button"
                        :class="{ active: amountMode === 'partial' }"
                        @click="setAmountMode('partial')"
                    >
                        <i class="bi bi-pie-chart"></i>
                        <span
                            ><strong>دفع جزئي</strong
                            ><small>اكتب المبلغ</small></span
                        >
                    </button>
                </div>

                <label
                    v-if="amountMode === 'partial'"
                    class="field amount-field"
                >
                    <span>المبلغ المراد تحصيله</span>
                    <div class="money-input">
                        <input
                            v-model="amount"
                            type="number"
                            min="0.01"
                            :max="invoice?.balance"
                            step="0.01"
                            inputmode="decimal"
                            autofocus
                            placeholder="0.00"
                            :aria-invalid="
                                Boolean(errors.amount) || amountTooHigh
                            "
                            @keydown.enter.prevent="submit"
                        />
                        <b>{{ currency.symbol }}</b>
                    </div>
                    <small v-if="amountTooHigh"
                        >المبلغ أكبر من المتبقي على الفاتورة.</small
                    >
                    <small v-else-if="advanceTooHigh"
                        >رصيد الزبون المتاح لا يغطي هذا المبلغ.</small
                    >
                    <small v-else-if="errors.amount">{{
                        errors.amount[0]
                    }}</small>
                </label>

                <div class="section-label">طريقة الدفع</div>
                <div class="method-list" aria-label="طريقة الدفع">
                    <button
                        v-for="option in availableMethods"
                        :key="option.code"
                        type="button"
                        :class="{ active: method === option.code }"
                        :disabled="fullCash && option.code !== 'cash'"
                        @click="selectMethod(option.code)"
                    >
                        <i class="bi" :class="option.icon"></i>
                        <span>{{ option.label }}</span>
                        <i
                            v-if="method === option.code"
                            class="bi bi-check-circle-fill method-check"
                        ></i>
                    </button>
                </div>

                <p
                    v-if="method === 'customer_advance'"
                    class="advance-balance-note"
                >
                    <i class="bi bi-wallet2"></i>
                    المتاح على جوال {{ customer?.phone }}:
                    <strong>{{ formatMoney(advanceBalance, currency) }}</strong>
                </p>

                <label
                    v-if="method !== 'cash' && method !== 'customer_advance'"
                    class="field"
                >
                    <span>رقم الحوالة أو المرجع <em>اختياري</em></span>
                    <input
                        v-model="reference"
                        type="text"
                        maxlength="255"
                        placeholder="اكتبه إن كان متوفراً"
                    />
                </label>

                <div v-else-if="method === 'cash'" class="optional-tool">
                    <button
                        v-if="!showTendered"
                        type="button"
                        @click="showTendered = true"
                    >
                        <i class="bi bi-calculator"></i>
                        استلمت مبلغاً أكبر؟ احسب الفكة
                    </button>
                    <div v-else class="change-helper">
                        <label class="field">
                            <span>المبلغ المستلم</span>
                            <input
                                v-model="tendered"
                                type="number"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                placeholder="0.00"
                            />
                        </label>
                        <div
                            class="change-due"
                            :class="{ visible: changeDue > 0 }"
                        >
                            <span>الباقي للزبون</span>
                            <strong>{{
                                formatMoney(changeDue, currency)
                            }}</strong>
                        </div>
                    </div>
                    <p v-if="cashTenderedTooLow" class="change-link-warning">
                        <i class="bi bi-exclamation-circle"></i> المبلغ المستلم
                        أقل من قيمة الدفعة.
                    </p>
                    <div
                        v-if="changeDue > 0 && customer?.id"
                        class="change-choice"
                    >
                        <button
                            type="button"
                            :class="{ active: !saveChangeAsAdvance }"
                            @click="saveChangeAsAdvance = false"
                        >
                            <i class="bi bi-arrow-return-left"></i
                            ><span
                                ><strong>إرجاع الباقي</strong
                                ><small
                                    >سلّم
                                    {{
                                        formatMoney(changeDue, currency)
                                    }}
                                    للزبون</small
                                ></span
                            >
                        </button>
                        <button
                            type="button"
                            :class="{ active: saveChangeAsAdvance }"
                            @click="saveChangeAsAdvance = true"
                        >
                            <i class="bi bi-wallet2"></i
                            ><span
                                ><strong>حفظه رصيداً</strong
                                ><small
                                    >على جوال {{ customer.phone }}</small
                                ></span
                            >
                        </button>
                    </div>
                    <p
                        v-else-if="changeDue > 0 && !customer?.id"
                        class="change-link-warning"
                    >
                        <i class="bi bi-info-circle"></i> اربط زبوناً برقم جواله
                        أولاً إذا أراد الاحتفاظ بالباقي.
                    </p>
                </div>

                <div class="optional-tool notes-tool">
                    <button
                        v-if="!showNotes"
                        type="button"
                        @click="showNotes = true"
                    >
                        <i class="bi bi-chat-left-text"></i>
                        إضافة ملاحظة
                    </button>
                    <label v-else class="field">
                        <span>ملاحظة الدفعة <em>اختياري</em></span>
                        <input
                            v-model="notes"
                            type="text"
                            maxlength="1000"
                            placeholder="تظهر في سجل الدفعة"
                        />
                    </label>
                </div>

                <footer>
                    <button
                        type="button"
                        class="secondary"
                        :disabled="busy"
                        @click="emit('close')"
                    >
                        إلغاء
                    </button>
                    <button
                        type="button"
                        class="primary"
                        :disabled="!canSubmit"
                        @click="submit"
                    >
                        <i
                            class="bi"
                            :class="
                                busy
                                    ? 'bi-arrow-clockwise spinning'
                                    : 'bi-check2-circle'
                            "
                        ></i>
                        {{ busy ? "جاري التحصيل…" : submitLabel }}
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
    background: rgba(15, 27, 19, 0.42);
    backdrop-filter: blur(3px);
}
.payment-sheet {
    width: min(500px, 100%);
    max-height: calc(100dvh - 2rem);
    box-sizing: border-box;
    padding: 1rem;
    border: 1px solid #dce5df;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 24px 70px -28px rgba(0, 0, 0, 0.55);
    overflow-y: auto;
}
.payment-sheet > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.payment-sheet > header > div {
    display: flex;
    flex-direction: column;
}
.payment-sheet > header span {
    color: #7a867e;
    font-size: 0.68rem;
}
.payment-sheet > header h2 {
    margin: 0.1rem 0 0;
    color: #213529;
    font-size: 0.92rem;
}
.payment-sheet > header button {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border: 1px solid #dfe6e2;
    border-radius: 10px;
    color: #617067;
    background: #fff;
}
.balance-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.75rem;
    padding: 0.75rem 0.85rem;
    border-radius: 12px;
    color: #23603a;
    background: #eef8f1;
}
.balance-banner span {
    font-size: 0.72rem;
    font-weight: 700;
}
.balance-banner strong {
    font-size: 1.1rem;
}
.sheet-error {
    display: flex;
    align-items: flex-start;
    gap: 0.35rem;
    margin-top: 0.6rem;
    padding: 0.55rem;
    border-radius: 9px;
    color: #922d36;
    background: #fff0f1;
    font-size: 0.7rem;
}
.amount-choice {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
    margin-top: 0.75rem;
}
.amount-choice button {
    min-height: 58px;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid #dce4df;
    border-radius: 11px;
    color: #536159;
    background: #fff;
    font: inherit;
    text-align: start;
}
.amount-choice button > i {
    color: #7b8980;
    font-size: 1rem;
}
.amount-choice button span {
    display: flex;
    min-width: 0;
    flex-direction: column;
}
.amount-choice strong {
    font-size: 0.72rem;
}
.amount-choice small {
    margin-top: 0.08rem;
    color: #8a968e;
    font-size: 0.62rem;
}
.amount-choice button.active {
    border-color: rgb(var(--primary-rgb, 22 101 52));
    color: rgb(var(--primary-rgb, 22 101 52));
    background: #eff8f1;
    box-shadow: 0 0 0 2px rgba(var(--primary-rgb, 22 101 52), 0.07);
}
.amount-choice button.active > i {
    color: inherit;
}
.field {
    display: flex;
    margin-top: 0.7rem;
    flex-direction: column;
    gap: 0.28rem;
}
.field > span,
.section-label {
    color: #526158;
    font-size: 0.68rem;
    font-weight: 750;
}
.field em {
    color: #8a968e;
    font-size: 0.6rem;
    font-style: normal;
    font-weight: 500;
}
.field input {
    width: 100%;
    height: 44px;
    box-sizing: border-box;
    padding-inline: 0.7rem;
    border: 1px solid #dce4df;
    border-radius: 10px;
    outline: none;
    font: inherit;
    font-size: 0.78rem;
}
.field input:focus {
    border-color: rgba(var(--primary-rgb, 22 101 52), 0.5);
    box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 22 101 52), 0.08);
}
.field small {
    color: #b02a37;
    font-size: 0.62rem;
}
.money-input {
    position: relative;
}
.money-input input {
    height: 52px;
    padding-inline-end: 3rem;
    color: #183723;
    font-size: 1.15rem;
    font-weight: 850;
}
.money-input b {
    position: absolute;
    top: 50%;
    inset-inline-end: 0.8rem;
    color: #6d7b72;
    font-size: 0.75rem;
    transform: translateY(-50%);
}
.section-label {
    margin-top: 0.75rem;
}
.method-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.45rem;
    margin-top: 0.35rem;
}
.method-list button {
    position: relative;
    min-height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    border: 1px solid #dce4df;
    border-radius: 11px;
    color: #56665c;
    background: #fff;
    font: inherit;
    font-size: 0.72rem;
    font-weight: 750;
}
.method-list button.active {
    border-color: rgb(var(--primary-rgb, 22 101 52));
    color: rgb(var(--primary-rgb, 22 101 52));
    background: #eff8f1;
    box-shadow: 0 0 0 2px rgba(var(--primary-rgb, 22 101 52), 0.07);
}
.method-list button:disabled {
    opacity: 0.35;
}
.method-check {
    position: absolute;
    inset-inline-end: 0.55rem;
    font-size: 0.72rem;
}
.advance-balance-note {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    margin: 0.55rem 0 0;
    padding: 0.55rem 0.65rem;
    border-radius: 10px;
    color: #1f6541;
    background: #eff8f2;
    font-size: 0.67rem;
}
.optional-tool {
    margin-top: 0.65rem;
}
.optional-tool > button {
    min-height: 36px;
    padding: 0;
    border: 0;
    color: #607067;
    background: transparent;
    font: inherit;
    font-size: 0.66rem;
    font-weight: 750;
    cursor: pointer;
}
.optional-tool > button i {
    margin-inline-end: 0.25rem;
    color: rgb(var(--primary-rgb, 22 101 52));
}
.change-helper {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.5rem;
    align-items: end;
    padding: 0.6rem;
    border-radius: 11px;
    background: #f7f9f8;
}
.change-helper .field {
    margin-top: 0;
}
.change-due {
    display: flex;
    min-width: 130px;
    min-height: 44px;
    box-sizing: border-box;
    flex-direction: column;
    justify-content: center;
    padding: 0.4rem 0.6rem;
    border-radius: 10px;
    color: #7a5200;
    background: #fff;
    border: 1px solid #e4e9e6;
}
.change-due.visible {
    background: #fff7df;
    border-color: #f0dfae;
}
.change-due span {
    font-size: 0.58rem;
}
.change-due strong {
    font-size: 0.76rem;
}
.change-choice {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.4rem;
    margin-top: 0.45rem;
}
.change-choice button {
    min-height: 52px;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.55rem;
    border: 1px solid #dfe6e2;
    border-radius: 10px;
    color: #59675f;
    background: #fff;
    font: inherit;
    text-align: start;
}
.change-choice button > span {
    display: grid;
}
.change-choice strong {
    font-size: 0.67rem;
}
.change-choice small {
    color: #87938b;
    font-size: 0.58rem;
}
.change-choice button.active {
    border-color: rgb(var(--primary-rgb, 22 101 52));
    color: rgb(var(--primary-rgb, 22 101 52));
    background: #eff8f2;
}
.change-link-warning {
    margin: 0.4rem 0 0;
    color: #85630f;
    font-size: 0.61rem;
}
.notes-tool .field {
    margin-top: 0;
}
.payment-sheet footer {
    display: grid;
    grid-template-columns: minmax(90px, 0.55fr) minmax(0, 1.45fr);
    gap: 0.45rem;
    margin-top: 0.85rem;
    padding-top: 0.8rem;
    border-top: 1px solid #edf1ee;
}
.payment-sheet footer button {
    min-height: 48px;
    border-radius: 11px;
    font: inherit;
    font-size: 0.73rem;
    font-weight: 800;
}
.secondary {
    border: 1px solid #dce4df;
    color: #536159;
    background: #fff;
}
.primary {
    border: 1px solid rgb(var(--primary-rgb, 22 101 52));
    color: #fff;
    background: rgb(var(--primary-rgb, 22 101 52));
}
button:disabled {
    opacity: 0.48;
    cursor: not-allowed;
}
.spinning {
    animation: spin 0.75s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
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
    .payment-sheet {
        max-height: 94dvh;
        border-radius: 18px 18px 0 0;
    }
    .change-helper {
        grid-template-columns: 1fr;
    }
    .change-choice {
        grid-template-columns: 1fr;
    }
    .payment-sheet footer {
        position: sticky;
        bottom: -1rem;
        margin-inline: -1rem;
        margin-bottom: -1rem;
        padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom));
        background: #fff;
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning {
        animation: none;
    }
}
</style>
