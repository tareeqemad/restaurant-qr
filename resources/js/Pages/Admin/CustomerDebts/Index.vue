<script setup>
/**
 * Global customer debt desk. Collection is intentionally available on every
 * card so the cashier does not have to open the customer statement first.
 */
import { computed, reactive, ref, watch } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import AdminLayout from "../../../Layouts/AdminLayout.vue";
import CollectionNav from "../../../Components/Collections/CollectionNav.vue";
import CollectionSheet from "../../../Components/Collections/CollectionSheet.vue";
import DataPanel from "../../../Components/Ui/DataPanel.vue";
import EmptyState from "../../../Components/Ui/EmptyState.vue";
import PageHeader from "../../../Components/Ui/PageHeader.vue";
import Pagination from "../../../Components/Ui/Pagination.vue";
import StatRail from "../../../Components/Ui/StatRail.vue";

defineOptions({ layout: AdminLayout });

const props = defineProps({
    debts: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    paymentMethods: { type: Array, default: () => [] },
    collectionNav: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const filter = reactive({ search: props.filters.search ?? "" });
const collecting = ref(null);
const payment = useForm({
    amount: "",
    method: props.paymentMethods[0]?.value ?? "cash",
    reference: "",
    notes: "",
});
const hasSearch = computed(() => Boolean(filter.search.trim()));
const visiblePaymentMethods = computed(() =>
    props.paymentMethods.filter(
        (method) =>
            method.value !== "customer_advance" ||
            Number(collecting.value?.advanceBalance || 0) > 0,
    ),
);
const collectionMax = computed(() =>
    payment.method === "customer_advance"
        ? Math.min(
              Number(collecting.value?.debt || 0),
              Number(collecting.value?.advanceBalance || 0),
          )
        : Number(collecting.value?.debt || 0),
);

watch(
    () => payment.method,
    () => {
        if (collecting.value) {
            payment.amount = collectionMax.value.toFixed(2);
        }
    },
);

function search() {
    router.get(
        props.urls.index,
        { search: filter.search.trim() || undefined },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function clearSearch() {
    filter.search = "";
    search();
}

function openCollection(customer) {
    collecting.value = customer;
    payment.clearErrors();
    payment.amount = customer.debt.toFixed(2);
    payment.method = props.paymentMethods[0]?.value ?? "cash";
    payment.reference = "";
    payment.notes = "";
}

function submitPayment() {
    if (!collecting.value) return;
    payment.post(collecting.value.urls.payment, {
        preserveScroll: true,
        onSuccess: () => {
            collecting.value = null;
        },
    });
}

const creditWidth = (customer) => {
    if (!customer.credit.limit || customer.credit.limit <= 0) return 0;
    return Math.min(
        100,
        Math.round((customer.debt / customer.credit.limit) * 100),
    );
};
</script>

<template>
    <Head title="ديون الزبائن" />

    <PageHeader
        title="ديون الزبائن"
        icon="bi-wallet2"
        subtitle="دفتر واحد لكل الفروع، والتحصيل يوزّع تلقائياً على أقدم الفواتير"
    >
        <template #actions>
            <a :href="urls.cashier" class="btn btn-light"
                ><i class="bi bi-cash-register"></i> الكاشير</a
            >
        </template>
    </PageHeader>

    <CollectionNav :items="collectionNav" active="debts" />

    <StatRail
        :stats="[
            {
                label: 'إجمالي الدين',
                value: stats.totalDebtFormatted,
                icon: 'bi-cash-stack',
                color: 'danger',
            },
            {
                label: 'زبائن عليهم دين',
                value: stats.customersOwing,
                icon: 'bi-people-fill',
                color: 'primary',
            },
            {
                label: 'فواتير مفتوحة',
                value: stats.openInvoices,
                icon: 'bi-file-earmark-text',
                color: 'accent',
            },
        ]"
    />

    <DataPanel
        title="من يحتاج تحصيلاً"
        :count="debts.total"
        icon="bi-person-exclamation"
    >
        <template #filters>
            <form class="debt-search" @submit.prevent="search">
                <label
                    ><i class="bi bi-search"></i
                    ><input
                        v-model="filter.search"
                        placeholder="اسم الزبون أو رقم الجوال"
                /></label>
                <button class="btn btn-primary">
                    <i class="bi bi-search"></i> بحث
                </button>
                <button
                    v-if="hasSearch"
                    type="button"
                    class="btn btn-light"
                    @click="clearSearch"
                >
                    <i class="bi bi-x-lg"></i> مسح
                </button>
            </form>
        </template>

        <div v-if="debts.data.length" class="debt-grid">
            <article
                v-for="customer in debts.data"
                :key="customer.id"
                class="debt-card"
                :class="{
                    overdue: customer.oldest.days > 30,
                    overlimit: customer.credit.overLimit,
                }"
            >
                <div class="debt-card__top">
                    <span class="debt-avatar">{{
                        customer.name?.trim()?.charAt(0) || "ز"
                    }}</span>
                    <div class="debt-identity">
                        <strong>{{ customer.name }}</strong>
                        <span>{{ customer.phone || "بدون رقم جوال" }}</span>
                    </div>
                    <div class="debt-total">
                        <small>الرصيد المطلوب</small
                        ><b>{{ customer.debtFormatted }}</b>
                    </div>
                </div>

                <div class="debt-signals">
                    <span
                        ><i class="bi bi-receipt"></i
                        >{{ customer.invoiceCount }} فاتورة</span
                    >
                    <span :class="{ danger: customer.oldest.days > 30 }"
                        ><i class="bi bi-clock-history"></i>أقدمها
                        {{ customer.oldest.human }}</span
                    >
                    <span
                        v-if="customer.credit.limitFormatted"
                        :class="{ danger: customer.credit.overLimit }"
                        ><i class="bi bi-speedometer2"></i>حد
                        {{ customer.credit.limitFormatted }}</span
                    >
                    <span v-else
                        ><i class="bi bi-infinity"></i>بدون حد ائتماني</span
                    >
                    <span v-if="customer.advanceBalance > 0" class="advance"
                        ><i class="bi bi-wallet2"></i>رصيد مقدم
                        {{ customer.advanceBalanceFormatted }}</span
                    >
                </div>

                <div v-if="customer.credit.limit !== null" class="debt-credit">
                    <div>
                        <span>استخدام الحد</span
                        ><b>{{ creditWidth(customer) }}%</b>
                    </div>
                    <div class="debt-credit__track">
                        <span
                            :style="{ width: `${creditWidth(customer)}%` }"
                        ></span>
                    </div>
                </div>

                <div class="debt-actions">
                    <button
                        v-if="customer.canCollect"
                        type="button"
                        class="collect"
                        @click="openCollection(customer)"
                    >
                        <i class="bi bi-cash-coin"></i> تحصيل الآن
                    </button>
                    <Link :href="customer.urls.show" class="details"
                        ><i class="bi bi-file-text"></i> كشف الزبون</Link
                    >
                </div>
            </article>
        </div>

        <EmptyState
            v-else
            icon="bi-emoji-smile"
            title="لا توجد ديون مفتوحة"
            :message="
                hasSearch
                    ? 'لم نجد زبوناً مطابقاً للبحث.'
                    : 'كل أرصدة الزبائن مسددة حالياً.'
            "
        />

        <template #footer><Pagination :links="debts.links" /></template>
    </DataPanel>

    <CollectionSheet
        :open="Boolean(collecting)"
        title="تحصيل دفعة"
        eyebrow="تُوزّع على أقدم الفواتير أولاً"
        icon="bi-cash-coin"
        @close="collecting = null"
    >
        <form
            v-if="collecting"
            id="debtPaymentForm"
            class="collection-form"
            @submit.prevent="submitPayment"
        >
            <div class="collection-customer">
                <span>{{ collecting.name }}</span
                ><strong>{{ collecting.debtFormatted }}</strong
                ><small>إجمالي الرصيد المفتوح</small
                ><em v-if="collecting.advanceBalance > 0"
                    >رصيد مقدم متاح:
                    {{ collecting.advanceBalanceFormatted }}</em
                >
            </div>
            <label
                ><span>المبلغ المحصّل *</span>
                <div class="money-input">
                    <input
                        v-model="payment.amount"
                        type="number"
                        min="0.01"
                        :max="collectionMax"
                        step="0.01"
                        required
                    /><i class="bi bi-cash"></i>
                </div>
                <small v-if="payment.method === 'customer_advance'"
                    >الحد المتاح من الرصيد:
                    {{ collecting.advanceBalanceFormatted }}</small
                ><small v-if="payment.errors.amount" class="error">{{
                    payment.errors.amount
                }}</small></label
            >
            <div class="method-grid">
                <label
                    v-for="method in visiblePaymentMethods"
                    :key="method.value"
                    :class="{ selected: payment.method === method.value }"
                >
                    <input
                        v-model="payment.method"
                        type="radio"
                        :value="method.value"
                    />
                    <i
                        class="bi"
                        :class="
                            method.value === 'cash'
                                ? 'bi-cash-stack'
                                : method.value === 'customer_advance'
                                  ? 'bi-wallet2'
                                  : 'bi-bank'
                        "
                    ></i
                    >{{ method.label }}
                </label>
            </div>
            <label
                ><span>مرجع العملية <small>اختياري</small></span
                ><input
                    v-model="payment.reference"
                    maxlength="255"
                    placeholder="رقم التحويل أو المرجع"
            /></label>
            <label
                ><span>ملاحظة <small>اختياري</small></span
                ><textarea
                    v-model="payment.notes"
                    rows="2"
                    maxlength="500"
                    placeholder="معلومة تظهر في سجل التحصيل"
                ></textarea>
            </label>
            <p class="fifo-note">
                <i class="bi bi-info-circle"></i> النظام يوزّع المبلغ تلقائياً
                على الفواتير الأقدم ثم الأحدث.
            </p>
        </form>
        <template #footer>
            <button
                type="button"
                class="btn btn-light"
                @click="collecting = null"
            >
                تراجع
            </button>
            <button
                type="submit"
                form="debtPaymentForm"
                class="btn btn-success"
                :disabled="payment.processing"
            >
                <i class="bi bi-check2-circle"></i> تسجيل الدفعة
            </button>
        </template>
    </CollectionSheet>
</template>

<style scoped>
.debt-search {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) auto auto;
    gap: 0.5rem;
}
.debt-search label {
    min-height: 44px;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0 0.75rem;
    border: 1px solid #dce6e0;
    border-radius: 10px;
    background: #fff;
}
.debt-search label:focus-within {
    border-color: #16804a;
    box-shadow: 0 0 0 3px rgba(22, 128, 74, 0.08);
}
.debt-search input {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
}
.debt-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.7rem;
}
.debt-card {
    position: relative;
    padding: 0.9rem;
    border: 1px solid #dfe9e3;
    border-radius: 15px;
    background: #fff;
    overflow: hidden;
}
.debt-card::before {
    content: "";
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    width: 4px;
    background: #d6a035;
}
.debt-card.overdue::before,
.debt-card.overlimit::before {
    background: #c43e3e;
}
.debt-card__top {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.65rem;
}
.debt-avatar {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: #edf6f0;
    color: #11703d;
    font-size: 1rem;
    font-weight: 850;
}
.debt-identity strong,
.debt-identity span {
    display: block;
}
.debt-identity strong {
    color: #17372a;
    font-size: 0.88rem;
}
.debt-identity span {
    margin-top: 0.12rem;
    color: #839087;
    font-size: 0.67rem;
}
.debt-total {
    text-align: end;
}
.debt-total small,
.debt-total b {
    display: block;
}
.debt-total small {
    color: #8a968f;
    font-size: 0.61rem;
}
.debt-total b {
    color: #b93232;
    font-family: ui-monospace, monospace;
    font-size: 1rem;
}
.debt-signals {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.75rem;
}
.debt-signals span {
    padding: 0.25rem 0.45rem;
    border-radius: 7px;
    background: #f3f6f4;
    color: #66776e;
    font-size: 0.64rem;
}
.debt-signals i {
    margin-inline-end: 0.25rem;
    color: #177746;
}
.debt-signals .danger {
    color: #ad3030;
    background: #fff0f0;
}
.debt-signals .danger i {
    color: inherit;
}
.debt-signals .advance {
    color: #12693c;
    background: #eaf7ef;
}
.debt-credit {
    margin-top: 0.65rem;
}
.debt-credit > div:first-child {
    display: flex;
    justify-content: space-between;
    color: #829087;
    font-size: 0.61rem;
}
.debt-credit__track {
    height: 5px;
    margin-top: 0.25rem;
    overflow: hidden;
    border-radius: 99px;
    background: #edf1ef;
}
.debt-credit__track span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: #d69a2b;
}
.overlimit .debt-credit__track span {
    background: #c43d3d;
}
.debt-actions {
    display: flex;
    gap: 0.45rem;
    margin-top: 0.8rem;
    padding-top: 0.7rem;
    border-top: 1px solid #edf1ef;
}
.debt-actions button,
.debt-actions a {
    min-height: 44px;
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    border-radius: 10px;
    font-size: 0.72rem;
    font-weight: 800;
    text-decoration: none;
}
.debt-actions .collect {
    border: 1px solid #16804a;
    background: #16804a;
    color: #fff;
}
.debt-actions .details {
    border: 1px solid #dce5e0;
    background: #fff;
    color: #52685c;
}
.collection-form {
    display: grid;
    gap: 0.75rem;
}
.collection-customer {
    display: grid;
    grid-template-columns: 1fr auto;
    padding: 0.75rem;
    border-radius: 12px;
    background: #f1f8f4;
}
.collection-customer span {
    color: #1d3c2e;
    font-weight: 800;
}
.collection-customer strong {
    color: #a52f2f;
    font-family: ui-monospace, monospace;
}
.collection-customer small {
    grid-column: 1 / -1;
    color: #839087;
    font-size: 0.63rem;
}
.collection-customer em {
    grid-column: 1 / -1;
    margin-top: 0.3rem;
    color: #126c3e;
    font-size: 0.64rem;
    font-style: normal;
    font-weight: 750;
}
.collection-form > label {
    display: grid;
    gap: 0.3rem;
    color: #4e6257;
    font-size: 0.72rem;
    font-weight: 750;
}
.collection-form label span small {
    color: #929e97;
    font-weight: 500;
}
.collection-form input,
.collection-form textarea {
    min-height: 44px;
    width: 100%;
    padding: 0.6rem 0.7rem;
    border: 1px solid #dce5e0;
    border-radius: 10px;
    outline: 0;
}
.collection-form textarea {
    min-height: 70px;
    resize: vertical;
}
.money-input {
    position: relative;
}
.money-input input {
    padding-inline-end: 2.2rem;
    font-size: 1.05rem;
    font-weight: 850;
}
.money-input i {
    position: absolute;
    inset-inline-end: 0.8rem;
    inset-block-start: 0.8rem;
    color: #1a7545;
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
.fifo-note {
    margin: 0;
    padding: 0.55rem 0.65rem;
    border-radius: 9px;
    background: #f5f7f6;
    color: #6e7d75;
    font-size: 0.65rem;
}
.fifo-note i {
    margin-inline-end: 0.3rem;
    color: #167542;
}
.error {
    color: #b62f2f;
}
@media (max-width: 900px) {
    .debt-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 575.98px) {
    .debt-search {
        grid-template-columns: 1fr 1fr;
    }
    .debt-search label {
        grid-column: 1 / -1;
    }
    .debt-card__top {
        grid-template-columns: auto 1fr;
    }
    .debt-total {
        grid-column: 1 / -1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 0.55rem;
        border-top: 1px solid #edf1ef;
        text-align: start;
    }
}
</style>
