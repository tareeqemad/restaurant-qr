<script setup>
import { computed, ref, watch } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import AdminLayout from "../../../Layouts/AdminLayout.vue";
import CollectionNav from "../../../Components/Collections/CollectionNav.vue";
import CollectionSheet from "../../../Components/Collections/CollectionSheet.vue";
import DataPanel from "../../../Components/Ui/DataPanel.vue";
import EmptyState from "../../../Components/Ui/EmptyState.vue";
import PageHeader from "../../../Components/Ui/PageHeader.vue";
import StatRail from "../../../Components/Ui/StatRail.vue";
import { useConfirm } from "../../../Composables/useConfirm";

defineOptions({ layout: AdminLayout });

const props = defineProps({
    customer: { type: Object, required: true },
    stats: { type: Object, required: true },
    openInvoices: { type: Array, default: () => [] },
    timeline: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    collectionNav: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const collecting = ref(false);
const editingLimit = ref(false);
const adjusting = ref(false);
const writingOff = ref(false);
const reverseTarget = ref(null);
const payment = useForm({
    amount: props.stats.outstanding.toFixed(2),
    method: props.paymentMethods[0]?.value ?? "cash",
    reference: "",
    notes: "",
});
const credit = useForm({ credit_limit: props.customer.creditLimit ?? "" });
const adjustment = useForm({
    invoice_id: props.openInvoices[0]?.id ?? "",
    amount: "",
    reason_type: "billing_correction",
    reason: "",
    notes: "",
});
const writeoff = useForm({
    invoice_id: props.openInvoices[0]?.id ?? "",
    amount: "",
    reason: "",
});
const reversal = useForm({ reason: "" });
const adjustmentInvoice = computed(() => props.openInvoices.find((invoice) => Number(invoice.id) === Number(adjustment.invoice_id)));
const writeoffInvoice = computed(() => props.openInvoices.find((invoice) => Number(invoice.id) === Number(writeoff.invoice_id)));
const visiblePaymentMethods = computed(() =>
    props.paymentMethods.filter(
        (method) =>
            method.value !== "customer_advance" ||
            Number(props.customer.advanceBalance || 0) > 0,
    ),
);
const collectionMax = computed(() =>
    payment.method === "customer_advance"
        ? Math.min(
              Number(props.stats.outstanding || 0),
              Number(props.customer.advanceBalance || 0),
          )
        : Number(props.stats.outstanding || 0),
);

watch(
    () => payment.method,
    () => {
        if (collecting.value) {
            payment.amount = collectionMax.value.toFixed(2);
        }
    },
);

function openCollection() {
    payment.clearErrors();
    payment.amount = props.stats.outstanding.toFixed(2);
    collecting.value = true;
}

function submitPayment() {
    payment.post(props.urls.payment, {
        preserveScroll: true,
        onSuccess: () => {
            collecting.value = false;
        },
    });
}

function saveLimit() {
    credit.post(props.urls.creditLimit, {
        preserveScroll: true,
        onSuccess: () => {
            editingLimit.value = false;
        },
    });
}

function openAdjustment() {
    adjustment.reset();
    adjustment.invoice_id = props.openInvoices[0]?.id ?? "";
    adjustment.clearErrors();
    adjusting.value = true;
}

function submitAdjustment() {
    adjustment.post(props.urls.adjustment, {
        preserveScroll: true,
        onSuccess: () => { adjusting.value = false; },
    });
}

function openWriteoff() {
    writeoff.reset();
    writeoff.invoice_id = props.openInvoices[0]?.id ?? "";
    writeoff.clearErrors();
    writingOff.value = true;
}

function submitWriteoff() {
    writeoff.post(props.urls.writeoff, {
        preserveScroll: true,
        onSuccess: () => { writingOff.value = false; },
    });
}

function openReversal(item) {
    reversal.reset();
    reversal.clearErrors();
    reverseTarget.value = item;
}

function submitReversal() {
    if (!reverseTarget.value?.reverseUrl) return;
    reversal.post(reverseTarget.value.reverseUrl, {
        preserveScroll: true,
        onSuccess: () => { reverseTarget.value = null; },
    });
}

async function unpark(invoice) {
    const yes = await ask({
        title: `إلغاء تأجيل ${invoice.number}؟`,
        message:
            "ستخرج الفاتورة من دفتر الدين وتعود إلى التحصيل العادي. لا يُحذف أي دفع أو قيد.",
        confirmLabel: "إلغاء التأجيل",
        danger: true,
    });
    if (yes) router.post(invoice.unparkUrl, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`دين ${customer.name}`" />

    <PageHeader
        :title="customer.name"
        icon="bi-person-lines-fill"
        :subtitle="`${customer.phone || 'بدون رقم جوال'} · ${stats.openInvoices} فاتورة مفتوحة`"
        :crumbs="[{ label: 'ديون الزبائن', url: urls.index }]"
    >
        <template #actions>
            <button
                v-if="can.collect && stats.outstanding > 0"
                type="button"
                class="btn btn-success"
                @click="openCollection"
            >
                <i class="bi bi-cash-coin"></i> تحصيل دفعة
            </button>
            <button v-if="can.adjust && openInvoices.length" type="button" class="btn btn-primary" @click="openAdjustment">
                <i class="bi bi-file-earmark-minus"></i> تخفيض دين
            </button>
            <button v-if="can.writeoff && openInvoices.length" type="button" class="btn btn-dark" @click="openWriteoff">
                <i class="bi bi-slash-circle"></i> شطب
            </button>
            <button
                v-if="can.updateCreditLimit"
                type="button"
                class="btn btn-light"
                @click="editingLimit = true"
            >
                <i class="bi bi-speedometer2"></i> الحد الائتماني
            </button>
        </template>
    </PageHeader>

    <CollectionNav :items="collectionNav" active="debts" />

    <StatRail
        :stats="[
            {
                label: 'الرصيد المفتوح',
                value: stats.outstandingFormatted,
                icon: 'bi-wallet2',
                color: 'danger',
            },
            {
                label: 'رصيد مقدم متاح',
                value: customer.advanceBalanceFormatted,
                icon: 'bi-wallet-fill',
                color: 'success',
            },
            {
                label: 'فواتير مفتوحة',
                value: stats.openInvoices,
                icon: 'bi-file-earmark-text',
                color: 'primary',
            },
            {
                label: 'الحد الائتماني',
                value: customer.creditLimitFormatted || 'بدون حد',
                icon: 'bi-speedometer2',
                color: 'accent',
            },
            {
                label: 'المتاح من الحد',
                value: customer.creditAvailableFormatted || 'غير محدود',
                icon: 'bi-shield-check',
                color: 'success',
            },
        ]"
    />

    <div class="debt-detail-grid">
        <DataPanel
            title="الفواتير المفتوحة"
            :count="openInvoices.length"
            icon="bi-receipt"
        >
            <div v-if="openInvoices.length" class="invoice-list">
                <article
                    v-for="invoice in openInvoices"
                    :key="invoice.id"
                    class="invoice-row"
                    :class="{ late: invoice.overdueDays > 0 }"
                >
                    <div>
                        <code>{{ invoice.number }}</code
                        ><span
                            ><i class="bi bi-calendar3"></i
                            >سُجّل {{ invoice.settledAt }}</span
                        >
                        <span :class="{ 'is-overdue': invoice.overdueDays > 0 }"><i class="bi bi-alarm"></i> الاستحقاق {{ invoice.dueDate || 'غير محدد' }}</span>
                        <span class="invoice-origin"
                            ><i class="bi bi-person-check"></i> سجّله
                            {{ invoice.registeredBy }}<template
                                v-if="invoice.branchName"
                            >
                                · {{ invoice.branchName }}</template
                            ></span
                        >
                    </div>
                    <div
                        class="invoice-age"
                        :class="{ late: invoice.overdueDays > 0 }"
                    >
                        <b>{{ invoice.overdueDays }}</b
                        ><small>{{ invoice.overdueDays ? 'يوم تأخير' : 'ضمن المهلة' }}</small>
                    </div>
                    <div class="invoice-money">
                        <small>المتبقي</small
                        ><strong>{{ invoice.balanceFormatted }}</strong
                        ><span>من {{ invoice.totalFormatted }}</span>
                    </div>
                    <button
                        v-if="invoice.canUnpark"
                        type="button"
                        @click="unpark(invoice)"
                    >
                        <i class="bi bi-arrow-counterclockwise"></i> إلغاء
                        التأجيل
                    </button>
                </article>
            </div>
            <EmptyState
                v-else
                icon="bi-check2-circle"
                title="الرصيد مسدّد"
                message="لا توجد فواتير دين مفتوحة لهذا الزبون."
            />
        </DataPanel>

        <aside class="debt-summary-card">
            <span class="debt-summary-card__icon"
                ><i class="bi bi-cash-stack"></i
            ></span>
            <small>المطلوب الآن</small>
            <strong>{{ stats.outstandingFormatted }}</strong>
            <p>
                أي دفعة تُرحّل من أقدم فاتورة إلى الأحدث تلقائياً، مهما كان
                الفرع الذي صدرت منه.
            </p>
            <button
                v-if="can.collect && stats.outstanding > 0"
                type="button"
                @click="openCollection"
            >
                <i class="bi bi-cash-coin"></i> تحصيل كامل أو جزئي
            </button>
            <Link :href="urls.index"
                ><i class="bi bi-arrow-right"></i> العودة إلى كل الديون</Link
            >
        </aside>
    </div>

    <DataPanel
        title="حركة دفتر الدين"
        :count="timeline.length"
        icon="bi-clock-history"
    >
        <div v-if="timeline.length" class="debt-timeline">
            <article
                v-for="item in timeline"
                :key="item.key"
                class="debt-event"
                :class="`tone-${item.tone}`"
            >
                <span class="debt-event__icon"
                    ><i class="bi" :class="item.icon"></i
                ></span>
                <div class="debt-event__main">
                    <div class="debt-event__title">
                        <strong>{{ item.title }}</strong>
                        <b v-if="item.amountFormatted">{{
                            item.amountFormatted
                        }}</b>
                    </div>
                    <p>{{ item.description }}</p>
                    <div class="debt-event__meta">
                        <span
                            ><i class="bi bi-person-check"></i> نفّذها
                            {{ item.performedBy }}</span
                        >
                        <span
                            ><i class="bi bi-calendar3"></i>
                            {{ item.occurredAt }}</span
                        >
                        <span v-if="item.branchName"
                            ><i class="bi bi-shop"></i>
                            {{ item.branchName }}</span
                        >
                    </div>
                </div>
                <div class="debt-event__document">
                    <code v-if="item.invoiceNumber">{{
                        item.invoiceNumber
                    }}</code>
                    <span v-if="item.method">{{ item.method }}</span>
                    <small v-if="item.reference"
                        >مرجع: {{ item.reference }}</small
                    >
                    <small v-if="item.notes">{{ item.notes }}</small>
                    <button v-if="can.reverse && item.reverseUrl" type="button" class="event-reverse" @click="openReversal(item)">
                        <i class="bi bi-arrow-counterclockwise"></i> عكس المستند
                    </button>
                </div>
            </article>
        </div>
        <EmptyState
            v-else
            icon="bi-clock-history"
            title="لا توجد حركة سابقة"
            message="يظهر هنا تسجيل الدين والتحصيل والاسترداد والشطب وتعديل الحد الائتماني."
        />
    </DataPanel>

    <CollectionSheet
        :open="collecting"
        title="تحصيل دفعة"
        :eyebrow="customer.name"
        icon="bi-cash-coin"
        @close="collecting = false"
    >
        <form
            id="customerDebtPayment"
            class="sheet-form"
            @submit.prevent="submitPayment"
        >
            <div class="amount-due">
                <span>الرصيد المفتوح</span
                ><strong>{{ stats.outstandingFormatted }}</strong
                ><small v-if="customer.advanceBalance > 0"
                    >رصيد مقدم متاح:
                    {{ customer.advanceBalanceFormatted }}</small
                >
            </div>
            <label
                ><span>المبلغ *</span
                ><input
                    v-model="payment.amount"
                    type="number"
                    min="0.01"
                    :max="collectionMax"
                    step="0.01"
                    required
                /><small v-if="payment.method === 'customer_advance'"
                    >الحد المتاح من الرصيد:
                    {{ customer.advanceBalanceFormatted }}</small
                ><small v-if="payment.errors.amount" class="error">{{
                    payment.errors.amount
                }}</small></label
            >
            <div class="method-grid">
                <label
                    v-for="method in visiblePaymentMethods"
                    :key="method.value"
                    :class="{ selected: payment.method === method.value }"
                    ><input
                        v-model="payment.method"
                        type="radio"
                        :value="method.value"
                    /><i
                        class="bi"
                        :class="
                            method.value === 'cash'
                                ? 'bi-cash-stack'
                                : method.value === 'customer_advance'
                                  ? 'bi-wallet2'
                                  : 'bi-bank'
                        "
                    ></i
                    >{{ method.label }}</label
                >
            </div>
            <label
                ><span>مرجع اختياري</span
                ><input
                    v-model="payment.reference"
                    maxlength="255"
                    placeholder="رقم التحويل أو المرجع"
            /></label>
            <label
                ><span>ملاحظة اختيارية</span
                ><textarea
                    v-model="payment.notes"
                    rows="2"
                    maxlength="500"
                ></textarea>
            </label>
        </form>
        <template #footer
            ><button
                type="button"
                class="btn btn-light"
                @click="collecting = false"
            >
                تراجع</button
            ><button
                type="submit"
                form="customerDebtPayment"
                class="btn btn-success"
                :disabled="payment.processing"
            >
                <i class="bi bi-check2-circle"></i> تسجيل الدفعة
            </button></template
        >
    </CollectionSheet>

    <CollectionSheet :open="adjusting" title="تخفيض دين بإشعار دائن" :eyebrow="customer.name" icon="bi-file-earmark-minus" @close="adjusting = false">
        <form id="debtAdjustmentForm" class="sheet-form" @submit.prevent="submitAdjustment">
            <p class="limit-note"><i class="bi bi-journal-check"></i> يصحح هذا قيمة البيع والضريبة ويخفض الذمة بلا حركة نقدية. استخدم التحصيل إذا استلمت مالاً.</p>
            <label><span>الفاتورة *</span><select v-model="adjustment.invoice_id" required><option v-for="invoice in openInvoices" :key="invoice.id" :value="invoice.id">{{ invoice.number }} — {{ invoice.balanceFormatted }}</option></select></label>
            <label><span>قيمة التخفيض *</span><input v-model="adjustment.amount" type="number" min="0.01" :max="adjustmentInvoice?.balance" step="0.01" required><small v-if="adjustment.errors.amount" class="error">{{ adjustment.errors.amount }}</small></label>
            <label><span>نوع السبب *</span><select v-model="adjustment.reason_type"><option value="billing_correction">تصحيح فاتورة</option><option value="returned_item">صنف مُعاد</option><option value="settlement_discount">خصم تسوية</option><option value="goodwill">تعويض للزبون</option></select></label>
            <label><span>السبب التفصيلي *</span><textarea v-model="adjustment.reason" rows="2" maxlength="500" required placeholder="ما الذي تغيّر ولماذا؟"></textarea><small v-if="adjustment.errors.reason" class="error">{{ adjustment.errors.reason }}</small></label>
            <label><span>ملاحظات داخلية</span><textarea v-model="adjustment.notes" rows="2" maxlength="1000"></textarea></label>
        </form>
        <template #footer><button type="button" class="btn btn-light" @click="adjusting = false">تراجع</button><button type="submit" form="debtAdjustmentForm" class="btn btn-primary" :disabled="adjustment.processing"><i class="bi bi-check2-circle"></i> إصدار الإشعار</button></template>
    </CollectionSheet>

    <CollectionSheet :open="writingOff" title="شطب دين غير قابل للتحصيل" :eyebrow="customer.name" icon="bi-slash-circle" @close="writingOff = false">
        <form id="debtWriteoffForm" class="sheet-form" @submit.prevent="submitWriteoff">
            <p class="danger-note"><i class="bi bi-exclamation-triangle"></i> الشطب مصروف محاسبي وليس خصماً للزبون. استخدمه فقط بعد اعتماد عدم إمكانية التحصيل، ويمكن عكسه لاحقاً.</p>
            <label><span>الفاتورة *</span><select v-model="writeoff.invoice_id" required><option v-for="invoice in openInvoices" :key="invoice.id" :value="invoice.id">{{ invoice.number }} — {{ invoice.balanceFormatted }}</option></select></label>
            <label><span>المبلغ المشطوب *</span><input v-model="writeoff.amount" type="number" min="0.01" :max="writeoffInvoice?.balance" step="0.01" required><small v-if="writeoff.errors.amount" class="error">{{ writeoff.errors.amount }}</small></label>
            <label><span>مبرر الشطب *</span><textarea v-model="writeoff.reason" rows="3" maxlength="500" required placeholder="إجراءات التحصيل التي تمت وسبب الاعتماد"></textarea><small v-if="writeoff.errors.reason" class="error">{{ writeoff.errors.reason }}</small></label>
        </form>
        <template #footer><button type="button" class="btn btn-light" @click="writingOff = false">تراجع</button><button type="submit" form="debtWriteoffForm" class="btn btn-dark" :disabled="writeoff.processing"><i class="bi bi-slash-circle"></i> اعتماد الشطب</button></template>
    </CollectionSheet>

    <CollectionSheet :open="Boolean(reverseTarget)" title="عكس مستند محاسبي" :eyebrow="reverseTarget?.reference || reverseTarget?.invoiceNumber" icon="bi-arrow-counterclockwise" @close="reverseTarget = null">
        <form id="debtReversalForm" class="sheet-form" @submit.prevent="submitReversal">
            <p class="limit-note"><i class="bi bi-shield-check"></i> سيُنشئ النظام قيداً عكسياً ويعيد رصيد الفاتورة، ولن يحذف المستند الأصلي.</p>
            <label><span>سبب العكس *</span><textarea v-model="reversal.reason" rows="3" maxlength="500" required placeholder="سبب واضح للمراجع والمحاسب"></textarea><small v-if="reversal.errors.reason" class="error">{{ reversal.errors.reason }}</small></label>
        </form>
        <template #footer><button type="button" class="btn btn-light" @click="reverseTarget = null">تراجع</button><button type="submit" form="debtReversalForm" class="btn btn-warning" :disabled="reversal.processing"><i class="bi bi-arrow-counterclockwise"></i> تأكيد العكس</button></template>
    </CollectionSheet>

    <CollectionSheet
        :open="editingLimit"
        title="الحد الائتماني"
        :eyebrow="customer.name"
        icon="bi-speedometer2"
        @close="editingLimit = false"
    >
        <form
            id="creditLimitForm"
            class="sheet-form"
            @submit.prevent="saveLimit"
        >
            <p class="limit-note">
                <i class="bi bi-info-circle"></i> اترك الحقل فارغاً ليبقى الزبون
                بلا سقف ائتماني. التغيير لا يعدّل الديون الموجودة.
            </p>
            <label
                ><span>السقف الجديد</span
                ><input
                    v-model="credit.credit_limit"
                    type="number"
                    min="0"
                    max="9999999"
                    step="0.01"
                    placeholder="بدون حد"
                /><small v-if="credit.errors.credit_limit" class="error">{{
                    credit.errors.credit_limit
                }}</small></label
            >
        </form>
        <template #footer
            ><button
                type="button"
                class="btn btn-light"
                @click="editingLimit = false"
            >
                تراجع</button
            ><button
                type="submit"
                form="creditLimitForm"
                class="btn btn-primary"
                :disabled="credit.processing"
            >
                حفظ الحد
            </button></template
        >
    </CollectionSheet>
</template>

<style scoped>
.debt-detail-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 280px;
    gap: 0.8rem;
    align-items: start;
}
.invoice-list {
    display: grid;
    gap: 0.55rem;
}
.invoice-row {
    display: grid;
    grid-template-columns: minmax(180px, 1fr) auto minmax(125px, auto) auto;
    gap: 0.7rem;
    align-items: center;
    padding: 0.7rem;
    border: 1px solid #e1e9e4;
    border-radius: 12px;
}
.invoice-row > div:first-child code,
.invoice-row > div:first-child span {
    display: block;
}
.invoice-row code {
    color: #254536;
    font-weight: 800;
}
.invoice-row > div:first-child span {
    margin-top: 0.2rem;
    color: #849189;
    font-size: 0.62rem;
}
.invoice-row > div:first-child .invoice-origin {
    color: #48695a;
    font-weight: 700;
}
.invoice-row > div:first-child .is-overdue { color: #b43333; font-weight: 800; }
.invoice-row > div:first-child i {
    margin-inline-end: 0.25rem;
}
.invoice-age {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    align-content: center;
    border-radius: 11px;
    background: #fff5df;
    color: #986815;
}
.invoice-age.late {
    background: #fff0f0;
    color: #b43333;
}
.invoice-age b {
    line-height: 1;
}
.invoice-age small {
    font-size: 0.55rem;
}
.invoice-money {
    text-align: end;
}
.invoice-money > * {
    display: block;
}
.invoice-money small,
.invoice-money span {
    color: #8a968f;
    font-size: 0.58rem;
}
.invoice-money strong {
    color: #b53232;
    font-family: ui-monospace, monospace;
}
.invoice-row button {
    min-height: 40px;
    padding: 0.45rem 0.6rem;
    border: 1px solid #e1c278;
    border-radius: 9px;
    background: #fffaf0;
    color: #8d6114;
    font-size: 0.65rem;
    font-weight: 750;
}
.debt-summary-card {
    padding: 1rem;
    border-radius: 16px;
    background: linear-gradient(145deg, #123f2b, #0b6a39);
    color: #fff;
    box-shadow: 0 14px 30px rgba(10, 87, 46, 0.17);
}
.debt-summary-card__icon {
    width: 44px;
    height: 44px;
    display: grid;
    place-items: center;
    margin-bottom: 0.8rem;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.13);
    font-size: 1.1rem;
}
.debt-summary-card > small,
.debt-summary-card > strong {
    display: block;
}
.debt-summary-card > small {
    opacity: 0.7;
}
.debt-summary-card > strong {
    margin-top: 0.15rem;
    font-family: ui-monospace, monospace;
    font-size: 1.4rem;
}
.debt-summary-card p {
    margin: 0.7rem 0;
    opacity: 0.75;
    font-size: 0.66rem;
    line-height: 1.8;
}
.debt-summary-card button,
.debt-summary-card a {
    width: 100%;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 800;
    text-decoration: none;
}
.debt-summary-card button {
    border: 0;
    background: #fff;
    color: #0c6a39;
}
.debt-summary-card a {
    margin-top: 0.35rem;
    color: #fff;
}
.debt-timeline {
    display: grid;
    gap: 0;
}
.debt-event {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) minmax(145px, auto);
    align-items: start;
    gap: 0.75rem;
    padding: 0.75rem 0.25rem;
    border-bottom: 1px solid #edf1ef;
}
.debt-event:last-child {
    border-bottom: 0;
}
.debt-event__icon {
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: #e9f7ee;
    color: #168047;
}
.debt-event.tone-danger .debt-event__icon {
    background: #fff0f0;
    color: #b23434;
}
.debt-event.tone-warning .debt-event__icon {
    background: #fff6df;
    color: #9d6a12;
}
.debt-event.tone-dark .debt-event__icon {
    background: #edf0ee;
    color: #34443b;
}
.debt-event.tone-primary .debt-event__icon {
    background: #edf4ff;
    color: #38699f;
}
.debt-event__title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}
.debt-event__title strong {
    color: #233f31;
    font-size: 0.74rem;
}
.debt-event__title b {
    color: #146f40;
    font-family: ui-monospace, monospace;
    font-size: 0.76rem;
}
.debt-event__main p {
    margin: 0.18rem 0 0.35rem;
    color: #718078;
    font-size: 0.66rem;
}
.debt-event__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.75rem;
    color: #7c8b83;
    font-size: 0.6rem;
}
.debt-event__meta i {
    margin-inline-end: 0.18rem;
    color: #416a56;
}
.debt-event__document {
    display: grid;
    justify-items: end;
    gap: 0.12rem;
    text-align: end;
}
.debt-event__document code {
    color: #385948;
    font-weight: 800;
}
.debt-event__document span,
.debt-event__document small {
    color: #839088;
    font-size: 0.59rem;
}
.event-reverse { margin-top: .3rem; padding: .3rem .5rem; border: 1px solid #d8c990; border-radius: 7px; color: #7d6418; background: #fffaf0; font-size: .58rem; font-weight: 800; }
.sheet-form {
    display: grid;
    gap: 0.75rem;
}
.sheet-form > label {
    display: grid;
    gap: 0.3rem;
    color: #4e6257;
    font-size: 0.72rem;
    font-weight: 750;
}
.sheet-form input,
.sheet-form textarea,
.sheet-form select {
    min-height: 44px;
    padding: 0.6rem 0.7rem;
    border: 1px solid #dce5e0;
    border-radius: 10px;
    outline: 0;
}
.sheet-form textarea {
    min-height: 70px;
    resize: vertical;
}
.amount-due {
    display: flex;
    justify-content: space-between;
    padding: 0.7rem;
    border-radius: 11px;
    background: #f1f8f4;
}
.amount-due strong {
    color: #b43232;
    font-family: ui-monospace, monospace;
}
.amount-due small {
    align-self: center;
    color: #126c3e;
    font-size: 0.64rem;
    font-weight: 750;
}
.method-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.45rem;
}
.method-grid label {
    min-height: 46px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    border: 1px solid #dce5e0;
    border-radius: 10px;
    color: #56695f;
    cursor: pointer;
}
.method-grid label.selected {
    border-color: #16804a;
    background: #eef8f2;
    color: #0d6d3a;
}
.method-grid input {
    display: none;
}
.limit-note {
    margin: 0;
    padding: 0.65rem;
    border-radius: 10px;
    background: #f4f7f5;
    color: #687971;
    font-size: 0.68rem;
    line-height: 1.7;
}
.limit-note i {
    color: #167542;
}
.danger-note { margin: 0; padding: .65rem; border-radius: 10px; color: #852d34; background: #fff0f1; font-size: .68rem; line-height: 1.7; }
.danger-note i { margin-inline-end: .25rem; }
.error {
    color: #b62f2f;
}
@media (max-width: 950px) {
    .debt-detail-grid {
        grid-template-columns: 1fr;
    }
    .debt-summary-card {
        order: -1;
    }
}
@media (max-width: 700px) {
    .invoice-row {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    .invoice-money {
        text-align: start;
    }
    .invoice-row button {
        grid-column: 1 / -1;
        min-height: 44px;
    }
    .debt-event {
        grid-template-columns: auto 1fr;
    }
    .debt-event__document {
        grid-column: 2;
        justify-items: start;
        text-align: start;
    }
}
</style>
