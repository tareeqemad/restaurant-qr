<script setup>
import { computed, ref } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import AdminLayout from "../../../Layouts/AdminLayout.vue";
import PageHeader from "../../../Components/Ui/PageHeader.vue";
import AccountingNav from "../../../Components/Accounting/AccountingNav.vue";
import AccountingPanel from "../../../Components/Accounting/AccountingPanel.vue";
defineOptions({ layout: AdminLayout });
const props = defineProps({
    accounts: Array,
    openingEntries: Array,
    equityAccount: Object,
    currencies: Array,
    baseCurrencyCode: String,
    suggestedOpeningDate: String,
    generalOpeningPosted: Boolean,
    customers: Array,
    suppliers: Array,
    hasBranch: Boolean,
    urls: Object,
});
const today = new Date().toISOString().slice(0, 10);
const active = ref("accounts");
const search = ref("");
const accountForm = useForm({
    posted_on: props.suggestedOpeningDate || today,
    description: "الأرصدة الافتتاحية",
    _idem: crypto.randomUUID(),
    balances: props.accounts.map((a) => ({
        account_id: a.id,
        amount: "",
        side: a.normalBalance,
        currency_code: props.baseCurrencyCode,
        exchange_rate: 1,
    })),
});
const customerForm = useForm({
    _idem: crypto.randomUUID(),
    customer_id: "",
    amount: "",
    posted_on: today,
    due_date: "",
    description: "",
});
const advanceForm = useForm({
    _idem: crypto.randomUUID(),
    customer_id: "",
    amount: "",
    posted_on: props.suggestedOpeningDate || today,
    description: "",
});
const supplierForm = useForm({
    _idem: crypto.randomUUID(),
    supplier_id: "",
    reference: "",
    amount: "",
    currency_code: props.baseCurrencyCode,
    exchange_rate: 1,
    posted_on: today,
    due_date: "",
    description: "",
});
const meta = {
    asset: {
        label: "الأصول",
        hint: "الصندوق والبنك والمخزون والأصول الثابتة",
        icon: "bi-safe2",
    },
    liability: {
        label: "الالتزامات",
        hint: "مبالغ مستحقة لا ترتبط بمورد معين",
        icon: "bi-receipt-cutoff",
    },
    equity: {
        label: "حقوق الملكية",
        hint: "رأس المال والأرباح المرحلة من النظام السابق",
        icon: "bi-building",
    },
};
const visibleGroups = computed(() =>
    Object.entries(meta)
        .map(([type, data]) => ({
            type,
            ...data,
            items: props.accounts
                .map((account, index) => ({
                    account,
                    index,
                    line: accountForm.balances[index],
                }))
                .filter(
                    (row) =>
                        row.account.type === type &&
                        (!search.value.trim() ||
                            `${row.account.code} ${row.account.name} ${row.account.parentName || ""}`
                                .toLowerCase()
                                .includes(search.value.trim().toLowerCase())),
                ),
        }))
        .filter((g) => g.items.length),
);
const debit = computed(() =>
    accountForm.balances.reduce(
        (sum, line) =>
            sum +
            (line.side === "debit"
                ? Number(line.amount || 0) * Number(line.exchange_rate || 0)
                : 0),
        0,
    ),
);
const credit = computed(() =>
    accountForm.balances.reduce(
        (sum, line) =>
            sum +
            (line.side === "credit"
                ? Number(line.amount || 0) * Number(line.exchange_rate || 0)
                : 0),
        0,
    ),
);
const difference = computed(() => debit.value - credit.value);
const currency = (code) => props.currencies.find((c) => c.code === code);
const syncRate = (line) => {
    const item = currency(line.currency_code);
    line.exchange_rate = item?.base ? 1 : item?.rate || "";
};
const money = (v) =>
    `${new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v || 0))} ${props.baseCurrencyCode}`;
const submitAccounts = () => {
    if (props.generalOpeningPosted) return;
    accountForm.post(props.urls.storeAccounts, {
        preserveScroll: true,
        onSuccess: () => {
            accountForm._idem = crypto.randomUUID();
        },
    });
};
const submitCustomer = () =>
    customerForm.post(props.urls.storeCustomer, {
        preserveScroll: true,
        onSuccess: () => {
            customerForm.reset();
            customerForm.posted_on = today;
            customerForm._idem = crypto.randomUUID();
        },
    });
const submitAdvance = () =>
    advanceForm.post(props.urls.storeCustomerAdvance, {
        preserveScroll: true,
        onSuccess: () => {
            advanceForm.reset();
            advanceForm.posted_on = props.suggestedOpeningDate || today;
            advanceForm._idem = crypto.randomUUID();
        },
    });
const submitSupplier = () =>
    supplierForm.post(props.urls.storeSupplier, {
        preserveScroll: true,
        onSuccess: () => {
            supplierForm.reset();
            supplierForm.currency_code = props.baseCurrencyCode;
            supplierForm.exchange_rate = 1;
            supplierForm.posted_on = today;
            supplierForm._idem = crypto.randomUUID();
        },
    });
</script>
<template>
    <Head title="الأرصدة الافتتاحية" /><PageHeader
        title="الأرصدة الافتتاحية"
        icon="bi-door-open-fill"
        subtitle="صورة المطعم يوم بدء استخدام النظام، دون بناء القيود يدوياً"
    /><AccountingNav :urls="urls" active="openingBalances" />
    <section v-if="!hasBranch" class="branch-warning">
        <i class="bi bi-shop"></i>
        <div>
            <strong>اختر فرعاً محدداً أولاً</strong
            ><small
                >الأرصدة الافتتاحية مرتبطة بالفرع حتى تبقى تقارير الفروع
                والتقرير المجمع صحيحة.</small
            >
        </div>
    </section>
    <section v-else-if="generalOpeningPosted" class="branch-warning">
        <i class="bi bi-shield-check"></i>
        <div>
            <strong>تم اعتماد رصيد البداية لهذا الفرع</strong
            ><small
                >لا ننشئ افتتاحاً ثانياً. أي تصحيح لاحق يتم بقيد عكس أو تسوية
                حتى يبقى الأثر قابلاً للتدقيق.</small
            >
        </div>
    </section>
    <nav class="mode-tabs">
        <button
            v-for="tab in [
                [
                    'accounts',
                    'bi-wallet2',
                    'أرصدة الحسابات',
                    'صندوق، بنك، مخزون ورأس مال',
                ],
                [
                    'customers',
                    'bi-person-down',
                    'ديون الزبائن',
                    'دين باسم صاحبه يظهر في التحصيل',
                ],
                [
                    'advances',
                    'bi-wallet-fill',
                    'رصيد الزبائن المقدم',
                    'التزام باسم الزبون قابل للاستخدام',
                ],
                [
                    'suppliers',
                    'bi-truck',
                    'أرصدة الموردين',
                    'فاتورة قديمة قابلة للسداد',
                ],
            ]"
            :key="tab[0]"
            type="button"
            :class="{ active: active === tab[0] }"
            @click="active = tab[0]"
        >
            <i class="bi" :class="tab[1]"></i
            ><span
                ><strong>{{ tab[2] }}</strong
                ><small>{{ tab[3] }}</small></span
            ><i class="bi bi-chevron-left"></i>
        </button>
    </nav>
    <template v-if="active === 'accounts'"
        ><section class="tip">
            <i class="bi bi-magic"></i>
            <div>
                <strong>أدخل المبالغ فقط</strong
                ><small
                    >سيوازن النظام الفرق تلقائياً في {{ equityAccount.code }} —
                    {{ equityAccount.name }}. ديون الزبائن والموردين لها بطاقات
                    مستقلة حتى تظهر في التحصيل والسداد.</small
                >
            </div>
        </section>
        <form @submit.prevent="submitAccounts">
            <fieldset :disabled="!hasBranch || accountForm.processing">
                <div class="account-head">
                    <label
                        ><span>تاريخ بداية الدفاتر</span
                        ><input
                            v-model="accountForm.posted_on"
                            type="date"
                            class="form-control"
                            required /></label
                    ><label
                        ><span>البيان</span
                        ><input
                            v-model="accountForm.description"
                            class="form-control"
                            required
                            maxlength="255" /></label
                    ><label class="search"
                        ><span>بحث عن حساب</span>
                        <div>
                            <i class="bi bi-search"></i
                            ><input
                                v-model="search"
                                type="search"
                                placeholder="الكود أو الاسم..."
                            /></div
                    ></label>
                </div>
                <details
                    v-for="(group, index) in visibleGroups"
                    :key="group.type"
                    class="account-group"
                    :open="index === 0 || Boolean(search)"
                >
                    <summary>
                        <span
                            ><i class="bi" :class="group.icon"></i
                            ><span
                                ><strong>{{ group.label }}</strong
                                ><small>{{ group.hint }}</small></span
                            ></span
                        ><b>{{ group.items.length }}</b>
                    </summary>
                    <div>
                        <article
                            v-for="row in group.items"
                            :key="row.account.id"
                        >
                            <div class="account-name">
                                <bdi>{{ row.account.code }}</bdi
                                ><span
                                    ><strong>{{ row.account.name }}</strong
                                    ><small v-if="row.account.parentName"
                                        >تحت {{ row.account.parentName }}</small
                                    ></span
                                >
                            </div>
                            <label
                                ><span>المبلغ</span
                                ><input
                                    v-model="row.line.amount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="form-control"
                                    placeholder="0.00" /></label
                            ><label
                                ><span>الجهة</span
                                ><select
                                    v-model="row.line.side"
                                    class="form-select"
                                >
                                    <option value="debit">مدين</option>
                                    <option value="credit">دائن</option>
                                </select></label
                            ><label
                                ><span>العملة</span
                                ><select
                                    v-model="row.line.currency_code"
                                    class="form-select"
                                    @change="syncRate(row.line)"
                                >
                                    <option
                                        v-for="item in currencies"
                                        :key="item.code"
                                        :value="item.code"
                                    >
                                        {{ item.code }}
                                    </option>
                                </select></label
                            ><label
                                v-if="
                                    row.line.currency_code !== baseCurrencyCode
                                "
                                ><span>سعر الصرف</span
                                ><input
                                    v-model="row.line.exchange_rate"
                                    type="number"
                                    min=".000001"
                                    step=".000001"
                                    class="form-control"
                                    required
                            /></label>
                        </article>
                    </div>
                </details>
                <div class="totals">
                    <span
                        ><small>إجمالي مدين</small
                        ><strong>{{ money(debit) }}</strong></span
                    ><span
                        ><small>إجمالي دائن</small
                        ><strong>{{ money(credit) }}</strong></span
                    ><span class="auto"
                        ><small>الموازنة التلقائية</small
                        ><strong v-if="Math.abs(difference) < 0.005"
                            >لا يوجد فرق</strong
                        ><strong v-else
                            >{{ money(Math.abs(difference)) }} إلى حقوق الملكية
                            ({{ difference > 0 ? "دائن" : "مدين" }})</strong
                        ></span
                    ><button
                        class="btn btn-primary"
                        :disabled="accountForm.processing"
                    >
                        <i class="bi bi-check2-circle"></i> ترحيل الأرصدة
                    </button>
                </div>
            </fieldset>
        </form></template
    >
    <template v-else-if="active === 'customers'"
        ><section class="tip blue">
            <i class="bi bi-person-check"></i>
            <div>
                <strong>كل دين باسم صاحبه</strong
                ><small
                    >سينشئ النظام فاتورة افتتاحية تظهر في كشف الزبون وأعمار
                    الديون ويمكن تحصيلها نقداً أو بتحويل بنكي.</small
                >
            </div>
        </section>
        <AccountingPanel
            title="دين زبون سابق"
            description="أضف كل زبون على حدة؛ يمكنك البقاء في الشاشة وإضافة التالي."
            icon="bi-person-plus"
            ><form class="debt-form" @submit.prevent="submitCustomer">
                <fieldset :disabled="!hasBranch || customerForm.processing">
                    <label class="wide"
                        ><span>الزبون *</span
                        ><select
                            v-model="customerForm.customer_id"
                            class="form-select"
                            required
                        >
                            <option value="">اختر الزبون</option>
                            <option
                                v-for="customer in customers"
                                :key="customer.id"
                                :value="customer.id"
                            >
                                {{ customer.name
                                }}{{
                                    customer.phone ? ` — ${customer.phone}` : ""
                                }}
                            </option>
                        </select></label
                    ><label
                        ><span>المبلغ ({{ baseCurrencyCode }}) *</span
                        ><input
                            v-model="customerForm.amount"
                            type="number"
                            min=".01"
                            step=".01"
                            class="form-control"
                            required /></label
                    ><label
                        ><span>تاريخ بداية الدين *</span
                        ><input
                            v-model="customerForm.posted_on"
                            type="date"
                            class="form-control"
                            required /></label
                    ><label
                        ><span>تاريخ الاستحقاق</span
                        ><input
                            v-model="customerForm.due_date"
                            type="date"
                            :min="customerForm.posted_on"
                            class="form-control" /></label
                    ><label class="wide"
                        ><span>ملاحظة أو مرجع قديم</span
                        ><input
                            v-model="customerForm.description"
                            class="form-control"
                            maxlength="500" /></label
                    ><button class="btn btn-primary">
                        <i class="bi bi-check2"></i> حفظ دين الزبون
                    </button>
                </fieldset>
            </form></AccountingPanel
        ></template
    >
    <template v-else-if="active === 'advances'"
        ><section class="tip green">
            <i class="bi bi-wallet-fill"></i>
            <div>
                <strong>رصيد محفوظ باسم الزبون، وليس إيراداً جديداً</strong
                ><small
                    >استخدمه فقط لنقل رصيد حقيقي كان لدى الزبون قبل بدء العمل
                    بالنظام. سيظهر للكاشير عند البحث برقم الجوال ويُخصم تلقائياً
                    عند استخدامه في فاتورة.</small
                >
            </div>
        </section>
        <AccountingPanel
            title="رصيد مقدم افتتاحي"
            description="يُسجل كالتزام على المطعم باسم الزبون، ويقابله حساب الأرصدة الافتتاحية."
            icon="bi-wallet-fill"
            ><form class="debt-form" @submit.prevent="submitAdvance">
                <fieldset :disabled="!hasBranch || advanceForm.processing">
                    <label class="wide"
                        ><span>الزبون *</span
                        ><select
                            v-model="advanceForm.customer_id"
                            class="form-select"
                            required
                        >
                            <option value="">اختر الزبون</option>
                            <option
                                v-for="customer in customers"
                                :key="customer.id"
                                :value="customer.id"
                            >
                                {{ customer.name
                                }}{{
                                    customer.phone ? ` — ${customer.phone}` : ""
                                }}
                                — رصيده الحالي
                                {{ money(customer.advanceBalance) }}
                            </option>
                        </select></label
                    ><label
                        ><span>المبلغ ({{ baseCurrencyCode }}) *</span
                        ><input
                            v-model="advanceForm.amount"
                            type="number"
                            min=".01"
                            step=".01"
                            class="form-control"
                            required /></label
                    ><label
                        ><span>تاريخ الرصيد *</span
                        ><input
                            v-model="advanceForm.posted_on"
                            type="date"
                            class="form-control"
                            required /></label
                    ><label class="wide"
                        ><span>ملاحظة أو مرجع قديم</span
                        ><input
                            v-model="advanceForm.description"
                            class="form-control"
                            maxlength="500"
                            placeholder="مثال: رصيد مرحّل من النظام السابق" /></label
                    ><button class="btn btn-primary">
                        <i class="bi bi-check2"></i> حفظ الرصيد المقدم
                    </button>
                </fieldset>
            </form></AccountingPanel
        ></template
    >
    <template v-else
        ><section class="tip amber">
            <i class="bi bi-receipt"></i>
            <div>
                <strong>الرصيد كفاتورة مورد قابلة للسداد</strong
                ><small
                    >يدعم الشيكل والدولار وسعر الصرف، ويظهر في كشف المورد والذمم
                    الدائنة دون إدخال مزدوج.</small
                >
            </div>
        </section>
        <AccountingPanel
            title="رصيد مورد سابق"
            description="يُحفظ كمستند قابل للسداد ويرحّل قيده تلقائياً."
            icon="bi-truck"
            tone="amber"
            ><form class="debt-form" @submit.prevent="submitSupplier">
                <fieldset :disabled="!hasBranch || supplierForm.processing">
                    <label class="wide"
                        ><span>المورد *</span
                        ><select
                            v-model="supplierForm.supplier_id"
                            class="form-select"
                            required
                        >
                            <option value="">اختر المورد</option>
                            <option
                                v-for="supplier in suppliers"
                                :key="supplier.id"
                                :value="supplier.id"
                            >
                                {{ supplier.name }}
                            </option>
                        </select></label
                    ><label
                        ><span>المبلغ *</span
                        ><input
                            v-model="supplierForm.amount"
                            type="number"
                            min=".01"
                            step=".01"
                            class="form-control"
                            required /></label
                    ><label
                        ><span>العملة *</span
                        ><select
                            v-model="supplierForm.currency_code"
                            class="form-select"
                            @change="syncRate(supplierForm)"
                            required
                        >
                            <option
                                v-for="item in currencies"
                                :key="item.code"
                                :value="item.code"
                            >
                                {{ item.code }}
                            </option>
                        </select></label
                    ><label
                        ><span>سعر الصرف *</span
                        ><input
                            v-model="supplierForm.exchange_rate"
                            type="number"
                            min=".000001"
                            step=".000001"
                            class="form-control"
                            :readonly="
                                supplierForm.currency_code === baseCurrencyCode
                            "
                            required /></label
                    ><label
                        ><span>تاريخ الفاتورة *</span
                        ><input
                            v-model="supplierForm.posted_on"
                            type="date"
                            class="form-control"
                            required /></label
                    ><label
                        ><span>تاريخ الاستحقاق</span
                        ><input
                            v-model="supplierForm.due_date"
                            type="date"
                            :min="supplierForm.posted_on"
                            class="form-control" /></label
                    ><label
                        ><span>رقم الفاتورة القديمة</span
                        ><input
                            v-model="supplierForm.reference"
                            class="form-control"
                            maxlength="60" /></label
                    ><label class="wide"
                        ><span>ملاحظة</span
                        ><input
                            v-model="supplierForm.description"
                            class="form-control"
                            maxlength="500" /></label
                    ><button class="btn btn-primary">
                        <i class="bi bi-check2"></i> حفظ رصيد المورد
                    </button>
                </fieldset>
            </form></AccountingPanel
        ></template
    >
    <details v-if="openingEntries.length" class="history">
        <summary>
            <span
                ><i class="bi bi-clock-history"></i> آخر القيود الافتتاحية</span
            ><b>{{ openingEntries.length }}</b>
        </summary>
        <div>
            <a
                v-for="entry in openingEntries"
                :key="entry.id"
                :href="entry.journalUrl"
                ><span
                    ><strong>{{ entry.description }}</strong
                    ><small
                        >{{ entry.number }} · {{ entry.creator || "—" }}</small
                    ></span
                ><time>{{ entry.date }}</time
                ><i class="bi bi-chevron-left"></i
            ></a>
        </div>
    </details>
</template>
<style scoped>
.branch-warning,
.tip {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
    padding: 12px 14px;
    border: 1px solid #e5c886;
    border-radius: 13px;
    color: #875000;
    background: #fff7e7;
}
.branch-warning > i,
.tip > i {
    font-size: 1.15rem;
}
.branch-warning div,
.tip div {
    display: grid;
}
.branch-warning strong,
.tip strong {
    font-size: 0.75rem;
}
.branch-warning small,
.tip small {
    font-size: 0.64rem;
    line-height: 1.55;
}
.mode-tabs {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 10px;
}
.mode-tabs button {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 11px;
    border: 1px solid #dfe7e2;
    border-radius: 13px;
    color: #536158;
    background: #fff;
    text-align: start;
}
.mode-tabs button > i:first-child {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 10px;
    color: #1f6b50;
    background: #e9f4ed;
}
.mode-tabs button span {
    display: grid;
    flex: 1;
}
.mode-tabs strong {
    font-size: 0.72rem;
}
.mode-tabs small {
    color: #829087;
    font-size: 0.59rem;
}
.mode-tabs button > i:last-child {
    font-size: 0.58rem;
}
.mode-tabs button.active {
    border-color: #98c8a7;
    color: #1f6b50;
    background: #f1f8f3;
    box-shadow: inset 0 -2px #1f6b50;
}
.tip {
    border-color: #acd2b7;
    color: #1f6b50;
    background: #f0f8f2;
}
.tip.blue {
    border-color: #b9d7e8;
    color: #176088;
    background: #f0f8fc;
}
.tip.amber {
    border-color: #e5c886;
    color: #915500;
    background: #fff7e7;
}
.account-head {
    display: grid;
    grid-template-columns: 180px 1fr 260px;
    gap: 9px;
    margin-bottom: 10px;
    padding: 11px;
    border: 1px solid #dfe7e2;
    border-radius: 13px;
    background: #fff;
}
.account-head > label {
    display: grid;
    gap: 4px;
}
.account-head label > span,
.account-group article label > span,
.debt-form label > span {
    font-size: 0.62rem;
    font-weight: 850;
}
.search > div {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0 9px;
    border: 1px solid #dfe7e2;
    border-radius: 8px;
}
.search input {
    width: 100%;
    padding: 8px 0;
    border: 0;
    outline: 0;
    font-size: 0.68rem;
}
.account-group {
    overflow: hidden;
    margin-bottom: 8px;
    border: 1px solid #dfe7e2;
    border-radius: 14px;
    background: #fff;
}
.account-group summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 13px;
    cursor: pointer;
    list-style: none;
}
.account-group summary > span {
    display: flex;
    align-items: center;
    gap: 9px;
}
.account-group summary > span > i {
    display: grid;
    width: 36px;
    height: 36px;
    place-items: center;
    border-radius: 10px;
    color: #1f6b50;
    background: #e9f4ed;
}
.account-group summary > span > span {
    display: grid;
}
.account-group summary strong {
    font-size: 0.72rem;
}
.account-group summary small {
    color: #819087;
    font-size: 0.58rem;
}
.account-group summary > b {
    display: grid;
    min-width: 23px;
    height: 23px;
    place-items: center;
    border-radius: 999px;
    background: #eff3f0;
    font-size: 0.62rem;
}
.account-group > div {
    border-top: 1px solid #edf1ee;
}
.account-group article {
    display: grid;
    grid-template-columns: minmax(210px, 1.5fr) 130px 95px 85px 115px;
    align-items: end;
    gap: 8px;
    padding: 8px 12px;
    border-bottom: 1px solid #edf1ee;
}
.account-group article:last-child {
    border: 0;
}
.account-name {
    display: flex;
    align-items: center;
    gap: 8px;
    align-self: center;
}
.account-name > bdi {
    min-width: 48px;
    padding: 4px 6px;
    border-radius: 7px;
    color: #1f6b50;
    background: #eef6f0;
    font-size: 0.63rem;
    font-weight: 900;
    text-align: center;
}
.account-name > span {
    display: grid;
}
.account-name strong {
    font-size: 0.68rem;
}
.account-name small {
    color: #89958d;
    font-size: 0.56rem;
}
.account-group article label {
    display: grid;
    gap: 3px;
}
.totals {
    position: sticky;
    z-index: 8;
    bottom: 8px;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px 14px;
    border: 1px solid #cbded1;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.97);
    box-shadow: 0 12px 35px rgba(20, 50, 31, 0.12);
    backdrop-filter: blur(10px);
}
.totals span {
    display: grid;
}
.totals small {
    color: #7d8a82;
    font-size: 0.59rem;
}
.totals strong {
    font-size: 0.72rem;
}
.totals .auto {
    flex: 1;
}
.debt-form fieldset {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    align-items: end;
    gap: 10px;
    border: 0;
}
.debt-form label {
    display: grid;
    gap: 4px;
}
.debt-form .wide {
    grid-column: span 2;
}
.debt-form button {
    grid-column: 4;
}
.history {
    overflow: hidden;
    margin-top: 12px;
    border: 1px solid #dfe7e2;
    border-radius: 14px;
    background: #fff;
}
.history summary {
    display: flex;
    justify-content: space-between;
    padding: 12px 14px;
    cursor: pointer;
    list-style: none;
    font-size: 0.7rem;
    font-weight: 850;
}
.history summary span {
    display: flex;
    gap: 7px;
}
.history summary b {
    display: grid;
    width: 23px;
    height: 23px;
    place-items: center;
    border-radius: 999px;
    background: #eef3ef;
    font-size: 0.6rem;
}
.history > div {
    border-top: 1px solid #edf1ee;
}
.history a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 13px;
    border-bottom: 1px solid #edf1ee;
    color: #35463b;
}
.history a > span {
    display: grid;
    flex: 1;
}
.history a strong {
    font-size: 0.67rem;
}
.history a small,
.history time {
    color: #829087;
    font-size: 0.58rem;
}
.history a > i {
    font-size: 0.55rem;
}
@media (max-width: 950px) {
    .account-head {
        grid-template-columns: 1fr 1fr;
    }
    .account-head .search {
        grid-column: 1/-1;
    }
    .account-group article {
        grid-template-columns: 1fr 1fr 1fr;
    }
    .account-name {
        grid-column: 1/-1;
    }
    .debt-form fieldset {
        grid-template-columns: 1fr 1fr;
    }
    .debt-form button {
        grid-column: 2;
    }
    .totals {
        position: static;
        flex-wrap: wrap;
    }
    .totals .auto {
        flex-basis: 100%;
    }
}
@media (max-width: 620px) {
    .mode-tabs {
        grid-template-columns: 1fr;
    }
    .account-head {
        grid-template-columns: 1fr;
    }
    .account-head .search {
        grid-column: auto;
    }
    .account-group article {
        grid-template-columns: 1fr 1fr;
    }
    .account-name {
        grid-column: 1/-1;
    }
    .totals span {
        width: calc(50% - 8px);
    }
    .totals .auto,
    .totals button {
        width: 100%;
    }
    .debt-form fieldset {
        grid-template-columns: 1fr;
    }
    .debt-form .wide,
    .debt-form button {
        grid-column: auto;
    }
}
</style>
