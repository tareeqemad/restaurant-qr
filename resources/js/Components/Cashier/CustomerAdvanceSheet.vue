<script setup>
import { computed, ref, watch } from "vue";
import { formatMoney } from "../../Composables/useMoney";

const props = defineProps({
    open: { type: Boolean, required: true },
    lookup: { type: Object, default: null },
    methods: { type: Array, required: true },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    lookupBusy: { type: Boolean, default: false },
    error: { type: String, default: "" },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: () => true,
    lookup: (phone) => typeof phone === "string",
    submit: (payload) =>
        Number(payload?.amount) > 0 && typeof payload?.phone === "string",
    reverse: (transaction) => Number(transaction?.id) > 0,
});

const phone = ref("");
const name = ref("");
const amount = ref("");
const method = ref("cash");
const reference = ref("");
const notes = ref("");
const searchedPhone = ref("");

const customer = computed(() => props.lookup?.customer ?? null);
const lookupMatchesPhone = computed(
    () =>
        searchedPhone.value !== "" &&
        searchedPhone.value === phone.value.trim(),
);
const canSubmit = computed(
    () =>
        !props.busy &&
        lookupMatchesPhone.value &&
        Number(amount.value || 0) > 0,
);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        phone.value = "";
        name.value = "";
        amount.value = "";
        method.value = props.methods[0]?.code ?? "cash";
        reference.value = "";
        notes.value = "";
        searchedPhone.value = "";
    },
    { immediate: true },
);

watch(
    () => props.lookup,
    (value) => {
        if (value?.customer?.name) name.value = value.customer.name;
    },
);

function lookupCustomer() {
    const value = phone.value.trim();
    if (!value || props.lookupBusy) return;
    searchedPhone.value = value;
    emit("lookup", value);
}

function submit() {
    if (!canSubmit.value) return;
    emit("submit", {
        phone: phone.value.trim(),
        name: name.value.trim() || null,
        amount: Number(amount.value),
        method: method.value,
        reference:
            method.value === "cash" ? null : reference.value.trim() || null,
        notes: notes.value.trim() || null,
    });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section
                class="advance-sheet"
                role="dialog"
                aria-modal="true"
                aria-labelledby="advance-title"
            >
                <header>
                    <div>
                        <span>محفظة مرتبطة برقم الجوال</span>
                        <h2 id="advance-title">رصيد مقدم للزبون</h2>
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

                <p class="explain">
                    <i class="bi bi-shield-check"></i> المبلغ يبقى التزاماً على
                    المطعم حتى يستخدمه الزبون في فاتورة؛ لا يُسجل إيراداً مرتين.
                </p>
                <div v-if="error" class="sheet-error">
                    <i class="bi bi-exclamation-circle"></i>{{ error }}
                </div>

                <div class="phone-search">
                    <label
                        ><span>رقم جوال الزبون</span
                        ><input
                            v-model="phone"
                            type="tel"
                            inputmode="numeric"
                            maxlength="10"
                            placeholder="0592632026"
                            @input="searchedPhone = ''"
                            @keydown.enter.prevent="lookupCustomer"
                    /></label>
                    <button
                        type="button"
                        :disabled="lookupBusy || !phone.trim()"
                        @click="lookupCustomer"
                    >
                        <i
                            class="bi"
                            :class="
                                lookupBusy
                                    ? 'bi-arrow-clockwise spinning'
                                    : 'bi-search'
                            "
                        ></i
                        >{{ lookupBusy ? "بحث…" : "بحث" }}
                    </button>
                </div>
                <small v-if="errors.phone" class="field-error">{{
                    errors.phone[0]
                }}</small>

                <article
                    v-if="lookupMatchesPhone && customer"
                    class="customer-card"
                >
                    <span class="avatar">{{ customer.name.slice(0, 1) }}</span>
                    <div>
                        <strong>{{ customer.name }}</strong
                        ><small>{{ customer.phone }}</small>
                    </div>
                    <span
                        ><small>الرصيد الحالي</small
                        ><b>{{
                            formatMoney(customer.advance_balance, currency)
                        }}</b></span
                    >
                    <span v-if="customer.debt > 0" class="debt"
                        >عليه دين
                        {{ formatMoney(customer.debt, currency) }}</span
                    >
                </article>
                <div
                    v-else-if="lookupMatchesPhone && lookup && !lookup.found"
                    class="new-customer"
                >
                    <i class="bi bi-person-plus"></i>
                    <div>
                        <strong>رقم جديد</strong
                        ><small>سننشئ ملف زبون على هذا الجوال عند الحفظ.</small>
                    </div>
                    <label
                        ><span>الاسم <em>اختياري</em></span
                        ><input
                            v-model="name"
                            type="text"
                            maxlength="120"
                            placeholder="يمكن إضافته الآن أو لاحقاً"
                    /></label>
                </div>

                <fieldset :disabled="!lookupMatchesPhone || busy">
                    <label class="amount-field"
                        ><span>قيمة الرصيد المقدم</span>
                        <div>
                            <input
                                v-model="amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                inputmode="decimal"
                                placeholder="0.00"
                            /><b>{{ currency.symbol }}</b>
                        </div>
                        <small v-if="errors.amount">{{
                            errors.amount[0]
                        }}</small></label
                    >
                    <div class="section-label">طريقة استلام المبلغ</div>
                    <div class="method-list">
                        <button
                            v-for="option in methods"
                            :key="option.code"
                            type="button"
                            :class="{ active: method === option.code }"
                            @click="
                                method = option.code;
                                reference = '';
                            "
                        >
                            <i class="bi" :class="option.icon"></i
                            >{{ option.label
                            }}<i
                                v-if="method === option.code"
                                class="bi bi-check-circle-fill"
                            ></i>
                        </button>
                    </div>
                    <label v-if="method !== 'cash'"
                        ><span>رقم الحوالة أو المرجع <em>اختياري</em></span
                        ><input v-model="reference" type="text" maxlength="191"
                    /></label>
                    <label
                        ><span>ملاحظة <em>اختياري</em></span
                        ><input
                            v-model="notes"
                            type="text"
                            maxlength="1000"
                            placeholder="سبب الإيداع أو أي توضيح"
                    /></label>
                </fieldset>

                <details v-if="customer?.transactions?.length" class="history">
                    <summary>
                        <span
                            ><i class="bi bi-clock-history"></i> آخر حركات
                            الرصيد</span
                        ><b>{{ customer.transactions.length }}</b>
                    </summary>
                    <div>
                        <p
                            v-for="transaction in customer.transactions"
                            :key="transaction.id"
                            :class="{ debit: transaction.signed_amount < 0 }"
                        >
                            <i
                                class="bi"
                                :class="
                                    transaction.signed_amount >= 0
                                        ? 'bi-plus-circle'
                                        : 'bi-dash-circle'
                                "
                            ></i>
                            <span
                                ><strong>{{ transaction.type_label }}</strong
                                ><small>{{
                                    transaction.invoice ||
                                    transaction.method_label ||
                                    transaction.creator ||
                                    "—"
                                }}</small></span
                            >
                            <b
                                >{{
                                    transaction.signed_amount >= 0 ? "+" : "−"
                                }}
                                {{
                                    formatMoney(
                                        Math.abs(transaction.signed_amount),
                                        currency,
                                    )
                                }}</b
                            >
                            <button
                                v-if="transaction.can_reverse"
                                type="button"
                                class="reverse-movement"
                                :disabled="busy"
                                @click="emit('reverse', transaction)"
                            >
                                عكس
                            </button>
                        </p>
                    </div>
                </details>

                <footer>
                    <button
                        type="button"
                        class="secondary"
                        :disabled="busy"
                        @click="emit('close')"
                    >
                        إلغاء</button
                    ><button
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
                                    : 'bi-wallet2'
                            "
                        ></i
                        >{{ busy ? "جاري الحفظ…" : "استلام وحفظ الرصيد" }}
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
.advance-sheet {
    width: min(590px, 100%);
    max-height: calc(100dvh - 2rem);
    box-sizing: border-box;
    padding: 1rem;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: none;
    overflow-y: auto;
}
.advance-sheet > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.advance-sheet > header > div {
    display: grid;
}
.advance-sheet > header span {
    color: var(--brand);
    font-size: .875rem;
}
.advance-sheet h2 {
    margin: 0.1rem 0 0;
    color: var(--brand);
    font-size: 1rem;
}
.advance-sheet > header button {
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--brand);
    background: var(--surface);
}
.explain {
    display: flex;
    gap: 0.4rem;
    margin: 0.7rem 0 0;
    padding: 0.6rem;
    border-radius: 10px;
    color: var(--ink, var(--ink));
    background: var(--surface, #FFFDFA);
    font-size: .875rem;
    line-height: 1.65;
}
.sheet-error {
    display: flex;
    gap: 0.35rem;
    margin-top: 0.55rem;
    padding: 0.55rem;
    border-radius: 9px;
    color: #922d36;
    background: #fff0f1;
    font-size: .875rem;
}
.phone-search {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0.45rem;
    align-items: end;
    margin-top: 0.7rem;
}
.advance-sheet label {
    display: grid;
    gap: 0.28rem;
    color: var(--brand);
    font-size: .875rem;
    font-weight: 750;
}
.advance-sheet label span em {
    color: var(--muted);
    font-style: normal;
    font-weight: 500;
}
.advance-sheet input {
    width: 100%;
    height: 44px;
    box-sizing: border-box;
    padding-inline: 0.7rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    outline: none;
    font: inherit;
    font-size: .875rem;
}
.advance-sheet input:focus {
    border-color: color-mix(in srgb, var(--brand, #A3422B) 50%, transparent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand, #A3422B) 8%, transparent);
}
.phone-search > button {
    min-width: 82px;
    height: 44px;
    border: 1px solid var(--brand, #A3422B);
    border-radius: 8px;
    color: #FFFDFA;
    background: var(--brand, #A3422B);
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.field-error {
    display: block;
    margin-top: 0.2rem;
    color: #a72d38;
    font-size: .875rem;
}
.customer-card {
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    gap: 0.6rem;
    align-items: center;
    margin-top: 0.6rem;
    padding: 0.65rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 12px;
    background: var(--surface, #FFFDFA);
}
.avatar {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 11px;
    color: #FFFDFA;
    background: var(--brand, #A3422B);
    font-weight: 800;
}
.customer-card > div,
.customer-card > span {
    display: grid;
}
.customer-card strong,
.customer-card b {
    font-size: .875rem;
}
.customer-card small {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.customer-card .debt {
    padding: 0.25rem 0.4rem;
    border-radius: 12px;
    color: #972b35;
    background: #fff0f1;
    font-size: .875rem;
}
.new-customer {
    display: grid;
    grid-template-columns: auto 1fr minmax(180px, 0.8fr);
    gap: 0.55rem;
    align-items: center;
    margin-top: 0.6rem;
    padding: 0.65rem;
    border: 1px dashed var(--line, var(--line));
    border-radius: 12px;
    background: var(--surface, #FFFDFA);
}
.new-customer > i {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 11px;
    color: var(--ink, var(--ink));
    background: var(--surface, #FFFDFA);
}
.new-customer > div {
    display: grid;
}
.new-customer strong {
    font-size: .875rem;
}
.new-customer small {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
fieldset {
    margin: 0;
    padding: 0;
    border: 0;
}
fieldset:disabled {
    opacity: 0.45;
}
.amount-field {
    margin-top: 0.7rem;
}
.amount-field > div {
    position: relative;
}
.amount-field input {
    height: 52px;
    padding-inline-end: 3rem;
    color: var(--ink, var(--ink));
    font-size: 1.1rem;
    font-weight: 800;
}
.amount-field b {
    position: absolute;
    top: 50%;
    inset-inline-end: 0.8rem;
    transform: translateY(-50%);
}
.section-label {
    margin-top: 0.7rem;
    color: var(--muted, var(--muted));
    font-size: .875rem;
    font-weight: 750;
}
.method-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.4rem;
    margin-top: 0.35rem;
}
.method-list button {
    position: relative;
    min-height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
    font: inherit;
    font-size: .875rem;
    font-weight: 750;
}
.method-list button.active {
    border-color: var(--brand, #A3422B);
    color: var(--brand, #A3422B);
    background: var(--surface, #FFFDFA);
}
.method-list button > i:last-child {
    position: absolute;
    inset-inline-end: 0.5rem;
}
.advance-sheet fieldset > label {
    margin-top: 0.6rem;
}
.history {
    overflow: hidden;
    margin-top: 0.7rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 11px;
}
.history summary {
    display: flex;
    justify-content: space-between;
    padding: 0.6rem 0.7rem;
    cursor: pointer;
    list-style: none;
    font-size: .875rem;
    font-weight: 800;
}
.history summary span {
    display: flex;
    gap: 0.35rem;
}
.history summary b {
    padding: 0.1rem 0.4rem;
    border-radius: 12px;
    background: var(--surface, #FFFDFA);
    font-size: .875rem;
}
.history > div {
    border-top: 1px solid var(--line, var(--line));
}
.history p {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin: 0;
    padding: 0.5rem 0.7rem;
    border-bottom: 1px solid var(--line, var(--line));
    color: var(--ink, var(--ink));
}
.history p:last-child {
    border: 0;
}
.history p.debit {
    color: #9d343d;
}
.history p span {
    display: grid;
    flex: 1;
}
.history p strong,
.history p > b {
    font-size: .875rem;
}
.history p small {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.reverse-movement {
    padding: 0.24rem 0.45rem;
    border: 1px solid #efb8bc;
    border-radius: 7px;
    color: #9d343d;
    background: var(--surface, #FFFDFA);
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.advance-sheet footer {
    display: grid;
    grid-template-columns: minmax(90px, 0.55fr) minmax(0, 1.45fr);
    gap: 0.45rem;
    margin-top: 0.8rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--line);
}
.advance-sheet footer button {
    min-height: 48px;
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
    border: 1px solid var(--brand, #A3422B);
    color: #FFFDFA;
    background: var(--brand, #A3422B);
}
button:disabled {
    opacity: 0.48;
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
@media (max-width: 560px) {
    .sheet-layer {
        padding: 0;
    }
    .advance-sheet {
        max-height: 94dvh;
        border-radius: 12px 12px 0 0;
    }
    .customer-card {
        grid-template-columns: auto 1fr;
    }
    .customer-card > span:not(.avatar) {
        grid-column: 2;
    }
    .new-customer {
        grid-template-columns: auto 1fr;
    }
    .new-customer label {
        grid-column: 1/-1;
    }
    .method-list {
        grid-template-columns: 1fr;
    }
    .advance-sheet footer {
        position: sticky;
        bottom: -1rem;
        margin-inline: -1rem;
        margin-bottom: -1rem;
        padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom));
        background: var(--surface);
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning {
        animation: none;
    }
}

/* Task-specific component layout. */
.sheet-layer{padding:0;display:flex;align-items:stretch;justify-content:flex-end;background:rgb(37 52 58 / 42%);backdrop-filter:none}.advance-sheet{width:min(560px,100%);max-width:100%;height:100dvh;max-height:100dvh;min-height:0;margin:0;border:0;border-radius:0;overflow-y:auto;background:var(--surface);box-shadow:none;padding:0}.advance-sheet>header{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line);background:var(--surface);margin:0}.advance-sheet>header h2{font-size:23px;line-height:1.4;margin:4px 0 0;color:var(--ink)}.advance-sheet>header span,.advance-sheet>header small{font-size:14px;color:var(--muted)}.advance-sheet>header button{min-width:44px;min-height:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.advance-sheet>form{padding:22px 24px;display:grid;gap:18px}.advance-sheet>form>footer,.advance-sheet>footer{position:sticky;bottom:0;z-index:2;display:flex;gap:12px;justify-content:flex-end;padding:16px 24px;margin:0;background:var(--surface);border-top:1px solid var(--line)}.advance-sheet footer button{min-height:48px;border-radius:8px;font-size:15px;flex:1}.advance-sheet label{font-size:14px;line-height:1.6}.advance-sheet input:not([type=checkbox]):not([type=radio]),.advance-sheet select{min-height:48px;border-radius:6px;font-size:16px}.advance-sheet textarea{border-radius:6px;font-size:15px;min-height:90px}.sheet-error{margin:16px 24px;padding:12px;border-radius:6px;font-size:14px;line-height:1.7}.method-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.method-list button{min-height:62px;border-radius:6px;font-size:15px;padding:12px}.method-list button.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}.amount-field input,.money-input input{font-size:26px!important;min-height:60px!important;font-variant-numeric:tabular-nums}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:20px 24px;padding:18px;border:1px solid var(--line);border-radius:8px;background:var(--canvas)}.balance-banner strong,.refund-limit strong,.total-banner strong{font-size:30px;line-height:1.35;color:var(--ink)}.amount-choice,.type-switch{margin:18px 24px 0;border-radius:6px;gap:8px}.amount-choice button,.type-switch button{min-height:46px;border-radius:6px;font-size:14px}.optional-tool{border:1px solid var(--line);border-radius:6px;padding:12px}.optional-tool summary{font-size:14px;min-height:28px}.warning,.accounting-note,.explanation,.explain,.inventory-note,.method-hint{font-size:14px;line-height:1.7;border-radius:6px}.transfer-list,.split-manager,.split-builder,.return-items{padding:18px 24px}.transfer-list>article,.split-manager>article{padding:14px;border-radius:6px;border:1px solid var(--line);margin-bottom:10px}.transfer-actions button{min-height:44px;border-radius:6px}.decision-card,.confirm-card,.payment-confirm{padding:20px;margin:16px 24px;border-radius:8px;border:1px solid var(--line)}@media(max-width:560px){.advance-sheet>header{padding:18px}.advance-sheet>form{padding:18px}.advance-sheet>form>footer,.advance-sheet>footer{padding:14px 18px}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:18px}.method-list{grid-template-columns:1fr 1fr}}
</style>
