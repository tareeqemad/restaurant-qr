<script setup>
import { computed, reactive } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'
import Pagination from '../../../Components/Ui/Pagination.vue'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    asOf: { type: String, required: true },
    taxAmounts: { type: Object, required: true },
    tipsPayable: { type: Number, required: true },
    wallets: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    recentSettlements: { type: Object, required: true },
    showTaxSettlement: { type: Boolean, default: false },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
})

const today = new Date().toISOString().slice(0, 10)
const filters = reactive({ from: props.from, to: props.to, as_of: props.asOf })

function freshToken() {
    return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

function preferredMethod(code) {
    return props.paymentMethods.find((method) => method.code === code)?.code
        ?? props.paymentMethods[0]?.code
        ?? ''
}

const firstTransferableWallet = props.wallets.find((wallet) => wallet.transferable && Number(wallet.balance) > 0)
    ?? props.wallets.find((wallet) => wallet.transferable)
    ?? props.wallets[0]

const walletForm = useForm({
    _idem: freshToken(),
    wallet_method: firstTransferableWallet?.method ?? '',
    posted_on: props.asOf,
    amount: firstTransferableWallet?.balance ?? 0,
    notes: '',
})
const taxForm = useForm({
    from: props.from,
    to: props.to,
    posted_on: today,
    payment_method: preferredMethod('transfer'),
})
const tipsForm = useForm({
    posted_on: props.asOf,
    amount: props.tipsPayable,
    payment_method: preferredMethod('cash'),
    notes: '',
})

const selectedWallet = computed(() => (
    props.wallets.find((wallet) => wallet.method === walletForm.wallet_method) ?? null
))
const walletAmount = computed(() => Number(walletForm.amount || 0))
const canTransferWallet = computed(() => (
    Boolean(selectedWallet.value?.transferable)
    && walletAmount.value > 0
    && walletAmount.value <= Number(selectedWallet.value?.balance || 0) + 0.0001
))

function money(value) {
    const formatted = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0))
    return `${formatted} ${props.currency.symbol}`
}

function selectWallet(wallet) {
    walletForm.wallet_method = wallet.method
    walletForm.amount = wallet.balance
    walletForm.clearErrors()
}

function refresh() {
    router.get(props.urls.index, filters, { preserveState: true, preserveScroll: true })
}

function transferWallet() {
    walletForm.post(props.urls.walletTransfer, {
        preserveScroll: true,
        onFinish: () => { walletForm._idem = freshToken() },
    })
}

function payTax() {
    taxForm.post(props.urls.taxPayment, { preserveScroll: true })
}

function payTips() {
    tipsForm.post(props.urls.tipsPayout, { preserveScroll: true })
}

function settlementIcon(type) {
    return {
        wallet_to_bank: 'bi-wallet2',
        tips_payout: 'bi-cash-coin',
        tax_payment: 'bi-percent',
    }[type] ?? 'bi-arrow-left-right'
}
</script>

<template>
    <Head title="التسويات المحاسبية" />
    <PageHeader
        title="التسويات المحاسبية"
        icon="bi-arrow-left-right"
        subtitle="حوّل المحافظ، اصرف المستحقات، وسوِّ الرصيد من مكان واحد"
    />
    <AccountingNav :urls="urls" active="settlements" />

    <section class="settlement-note">
        <span><i class="bi bi-bank"></i></span>
        <div>
            <strong>الفيزا والتحويل البنكي يصلان إلى البنك مباشرة</strong>
            <small>PalPay وJawwal Pay فقط يبقيان كرصيد محفظة حتى يؤكد المحاسب تحويلهما إلى البنك.</small>
        </div>
    </section>

    <form class="settlement-filter" @submit.prevent="refresh">
        <label v-if="showTaxSettlement"><span>الضريبة من</span><input v-model="filters.from" type="date" class="form-control"></label>
        <label v-if="showTaxSettlement"><span>الضريبة إلى</span><input v-model="filters.to" type="date" class="form-control"></label>
        <label><span>الأرصدة حتى</span><input v-model="filters.as_of" type="date" class="form-control"></label>
        <button class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> تحديث</button>
    </form>

    <div class="settlement-summary">
        <article v-for="wallet in wallets" :key="wallet.method" :class="{ warn: Number(wallet.balance) > 0 }">
            <span><i class="bi bi-wallet2"></i></span>
            <div><small>{{ wallet.label }}</small><strong>{{ money(wallet.balance) }}</strong><em>{{ wallet.accountCode }}</em></div>
        </article>
        <article :class="{ warn: tipsPayable > 0 }">
            <span><i class="bi bi-cash-coin"></i></span>
            <div><small>إكراميات مستحقة</small><strong>{{ money(tipsPayable) }}</strong><em>للموظفين</em></div>
        </article>
        <article v-if="showTaxSettlement" :class="{ warn: taxAmounts.payable > 0 }">
            <span><i class="bi bi-percent"></i></span>
            <div><small>{{ taxAmounts.label }}</small><strong>{{ money(taxAmounts.payable) }}</strong><em>صافي مستحق</em></div>
        </article>
    </div>

    <div class="settlement-grid">
        <AccountingPanel title="تحويل محفظة إلى البنك" description="ينقل الرصيد محاسبياً بعد تنفيذ التحويل الفعلي من تطبيق المحفظة." icon="bi-wallet2" tone="blue">
            <div class="wallet-tabs">
                <button
                    v-for="wallet in wallets"
                    :key="wallet.method"
                    type="button"
                    :class="{ active: walletForm.wallet_method === wallet.method }"
                    @click="selectWallet(wallet)"
                >
                    <span><strong>{{ wallet.label }}</strong><small>{{ wallet.accountCode }}</small></span>
                    <bdi>{{ money(wallet.balance) }}</bdi>
                </button>
            </div>

            <form class="settlement-action" @submit.prevent="transferWallet">
                <label><span>تاريخ وصول المبلغ للبنك</span><input v-model="walletForm.posted_on" type="date" :max="today" class="form-control" required></label>
                <label><span>المبلغ المحوّل</span><input v-model="walletForm.amount" type="number" min=".01" :max="selectedWallet?.balance ?? 0" step=".01" class="form-control" required></label>
                <label class="wide"><span>مرجع التحويل أو ملاحظة</span><input v-model="walletForm.notes" class="form-control" maxlength="1000" placeholder="اختياري"></label>
                <p v-if="selectedWallet && !selectedWallet.configured" class="inline-warning"><i class="bi bi-exclamation-triangle"></i> حساب هذه المحفظة غير مهيأ في شجرة الحسابات. راجع «ربط العمليات» أولاً.</p>
                <p v-else-if="selectedWallet && !selectedWallet.transferable" class="inline-warning"><i class="bi bi-info-circle"></i> هذه المحفظة مرتبطة بالبنك نفسه ولا تحتاج قيد تحويل.</p>
                <p v-else-if="walletAmount > Number(selectedWallet?.balance || 0)" class="inline-warning"><i class="bi bi-exclamation-triangle"></i> المبلغ أكبر من رصيد المحفظة المتاح.</p>
                <small class="entry-preview"><i class="bi bi-journal-check"></i> القيد: البنك مدين، ومحفظة {{ selectedWallet?.label }} دائنة.</small>
                <button class="btn btn-primary wide" :disabled="!canTransferWallet || walletForm.processing">
                    <i class="bi bi-arrow-left-right"></i> تأكيد التحويل للبنك
                </button>
            </form>
        </AccountingPanel>

        <AccountingPanel title="صرف الإكراميات" description="يخفض التزام الإكراميات ويخرج المبلغ من وسيلة الدفع المختارة." icon="bi-cash-coin" tone="amber">
            <div class="payable-balance">
                <small>المستحق للموظفين</small>
                <strong>{{ money(tipsPayable) }}</strong>
            </div>
            <form class="settlement-action" @submit.prevent="payTips">
                <label><span>تاريخ الصرف</span><input v-model="tipsForm.posted_on" type="date" class="form-control" required></label>
                <label><span>المبلغ المصروف</span><input v-model="tipsForm.amount" type="number" min=".01" :max="tipsPayable" step=".01" class="form-control" required></label>
                <label><span>طريقة الصرف</span><select v-model="tipsForm.payment_method" class="form-select" required><option v-for="method in paymentMethods" :key="method.code" :value="method.code">{{ method.label }}</option></select></label>
                <label><span>ملاحظات</span><input v-model="tipsForm.notes" class="form-control" maxlength="1000"></label>
                <button class="btn btn-primary wide" :disabled="tipsPayable <= .01 || tipsForm.processing"><i class="bi bi-journal-check"></i> ترحيل الصرف</button>
            </form>
        </AccountingPanel>

        <AccountingPanel v-if="showTaxSettlement" :title="`سداد ${taxAmounts.label}`" description="يظهر فقط عند وجود ضريبة مفعلة أو رصيد تاريخي يحتاج إلى تسوية." icon="bi-percent">
            <div class="tax-breakdown">
                <span><small>مخرجات الزبائن</small><strong>{{ money(taxAmounts.output) }}</strong></span>
                <span><small>مدخلات الموردين</small><strong>{{ money(taxAmounts.input) }}</strong></span>
                <span class="total"><small>الصافي المستحق</small><strong>{{ money(taxAmounts.payable) }}</strong></span>
            </div>
            <form class="settlement-action" @submit.prevent="payTax">
                <label><span>تاريخ السداد</span><input v-model="taxForm.posted_on" type="date" class="form-control" required></label>
                <label><span>طريقة السداد</span><select v-model="taxForm.payment_method" class="form-select" required><option v-for="method in paymentMethods" :key="method.code" :value="method.code">{{ method.label }}</option></select></label>
                <button class="btn btn-primary wide" :disabled="taxAmounts.payable <= .01 || taxForm.processing"><i class="bi bi-journal-check"></i> ترحيل سداد الضريبة</button>
            </form>
        </AccountingPanel>
    </div>

    <AccountingPanel title="سجل التسويات" :description="`${recentSettlements.total} حركة محفوظة وقابلة للتدقيق`" icon="bi-clock-history" compact>
        <div v-if="recentSettlements.data.length" class="settlement-history">
            <Link v-for="entry in recentSettlements.data" :key="entry.id" :href="entry.journalUrl" preserve-scroll>
                <span class="event"><i class="bi" :class="settlementIcon(entry.type)"></i></span>
                <div><strong>{{ entry.typeLabel }}</strong><small>{{ entry.number }} · {{ entry.description }}</small></div>
                <time>{{ entry.date }}</time>
                <bdi>{{ money(entry.debit) }}</bdi>
                <span class="creator">{{ entry.creator || '—' }}</span>
                <i class="bi bi-chevron-left"></i>
            </Link>
        </div>
        <div v-else class="settlement-empty"><i class="bi bi-arrow-left-right"></i><strong>لا توجد تسويات بعد</strong><small>ستظهر هنا أول عملية تحويل أو صرف.</small></div>
        <template #footer><Pagination :links="recentSettlements.links" /></template>
    </AccountingPanel>
</template>

<style scoped>
.settlement-note {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    padding: 12px 14px;
    border: 1px solid #add1b8;
    border-radius: 14px;
    color: #24583b;
    background: #eff8f2;
}

.settlement-note > span {
    display: grid;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 11px;
    color: #fff;
    background: #1f6b50;
}

.settlement-note > div {
    display: grid;
}

.settlement-note strong {
    font-size: .75rem;
}

.settlement-note small {
    color: #708078;
    font-size: .62rem;
    line-height: 1.55;
}

.settlement-filter {
    display: flex;
    align-items: end;
    gap: 8px;
    margin-bottom: 10px;
    padding: 10px;
    border: 1px solid #dce6df;
    border-radius: 13px;
    background: #fff;
}

.settlement-filter label,
.settlement-action label {
    display: grid;
    flex: 1;
    gap: 4px;
}

.settlement-filter label > span,
.settlement-action label > span {
    color: #5e6e64;
    font-size: .61rem;
    font-weight: 850;
}

.settlement-filter .form-control,
.settlement-filter .btn,
.settlement-action .form-control,
.settlement-action .form-select,
.settlement-action .btn {
    min-height: 44px;
}

.settlement-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}

.settlement-summary article {
    display: flex;
    min-height: 78px;
    align-items: center;
    gap: 9px;
    padding: 10px;
    border: 1px solid #dfe7e2;
    border-radius: 13px;
    background: #fff;
}

.settlement-summary article.warn {
    border-color: #e4c28c;
    background: #fffaf1;
}

.settlement-summary article > span {
    display: grid;
    flex: 0 0 36px;
    width: 36px;
    height: 36px;
    place-items: center;
    border-radius: 10px;
    color: #1f6b50;
    background: #e9f4ed;
}

.settlement-summary article.warn > span {
    color: #9b5a08;
    background: #fff0da;
}

.settlement-summary article > div {
    display: grid;
    min-width: 0;
}

.settlement-summary small,
.settlement-summary em {
    color: #839087;
    font-size: .55rem;
    font-style: normal;
}

.settlement-summary strong {
    font-size: .72rem;
}

.settlement-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
    gap: 10px;
    margin-bottom: 10px;
}

.wallet-tabs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 7px;
    margin-bottom: 11px;
}

.wallet-tabs button {
    display: flex;
    min-height: 58px;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1px solid #dce5df;
    border-radius: 11px;
    color: #4f6056;
    background: #fff;
    text-align: start;
}

.wallet-tabs button.active {
    border-color: #8bb89d;
    color: #1f6b50;
    background: #eff8f2;
    box-shadow: inset 0 0 0 1px rgba(31, 107, 80, .08);
}

.wallet-tabs button > span {
    display: grid;
    flex: 1;
}

.wallet-tabs strong {
    font-size: .68rem;
}

.wallet-tabs small {
    color: #89958d;
    font-size: .54rem;
}

.wallet-tabs bdi {
    font-size: .66rem;
    font-weight: 900;
}

.settlement-action {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: end;
    gap: 8px;
}

.settlement-action .wide,
.inline-warning,
.entry-preview {
    grid-column: 1 / -1;
}

.inline-warning {
    display: flex;
    gap: 6px;
    margin: 0;
    padding: 8px;
    border-radius: 9px;
    color: #955707;
    background: #fff2de;
    font-size: .59rem;
}

.entry-preview {
    display: flex;
    gap: 6px;
    color: #718078;
    font-size: .57rem;
}

.payable-balance {
    display: grid;
    justify-items: center;
    margin-bottom: 11px;
    padding: 16px;
    border-radius: 12px;
    color: #915500;
    background: #fff1dd;
}

.payable-balance small {
    font-size: .61rem;
}

.payable-balance strong {
    font-size: 1rem;
}

.tax-breakdown {
    display: grid;
    margin-bottom: 11px;
}

.tax-breakdown > span {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #edf1ee;
}

.tax-breakdown small {
    color: #79877e;
    font-size: .61rem;
}

.tax-breakdown strong {
    font-size: .68rem;
}

.tax-breakdown .total {
    margin-top: 5px;
    padding: 10px;
    border: 0;
    border-radius: 10px;
    color: #1f6b50;
    background: #eaf5ed;
}

.settlement-history {
    display: grid;
}

.settlement-history > a {
    display: grid;
    grid-template-columns: 34px minmax(180px, 1fr) 86px 110px 100px 20px;
    min-height: 54px;
    align-items: center;
    gap: 8px;
    padding: 9px 13px;
    border-bottom: 1px solid #edf1ee;
    color: #35463b;
}

.settlement-history > a:last-child {
    border-bottom: 0;
}

.settlement-history .event {
    display: grid;
    width: 32px;
    height: 32px;
    place-items: center;
    border-radius: 9px;
    color: #1f6b50;
    background: #e8f4eb;
}

.settlement-history a > div {
    display: grid;
    min-width: 0;
}

.settlement-history strong,
.settlement-history small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.settlement-history strong {
    font-size: .66rem;
}

.settlement-history small,
.settlement-history time,
.settlement-history .creator {
    color: #819087;
    font-size: .57rem;
}

.settlement-history bdi {
    font-size: .64rem;
    font-weight: 850;
}

.settlement-history a > i {
    color: #94a098;
    font-size: .55rem;
}

.settlement-empty {
    display: grid;
    justify-items: center;
    gap: 4px;
    padding: 45px;
    color: #839087;
}

.settlement-empty > i {
    font-size: 1.5rem;
}

.settlement-empty strong {
    color: #526159;
    font-size: .72rem;
}

.settlement-empty small {
    font-size: .58rem;
}

@media (max-width: 980px) {
    .settlement-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .settlement-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .settlement-filter {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .settlement-filter .btn {
        grid-column: 1 / -1;
    }

    .settlement-history > a {
        grid-template-columns: 34px minmax(0, 1fr) auto;
    }

    .settlement-history time,
    .settlement-history .creator,
    .settlement-history a > i {
        display: none;
    }

    .settlement-history bdi {
        grid-column: 3;
        grid-row: 1;
    }
}

@media (max-width: 520px) {
    .settlement-summary,
    .settlement-filter,
    .wallet-tabs,
    .settlement-action {
        grid-template-columns: 1fr;
    }

    .settlement-filter .btn,
    .settlement-action .wide,
    .inline-warning,
    .entry-preview {
        grid-column: auto;
    }
}
</style>
