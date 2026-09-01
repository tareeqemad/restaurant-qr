<script setup>
import { computed, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    postingGroups: { type: Array, default: () => [] },
    expenseCategories: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    expenseAccounts: { type: Array, default: () => [] },
    paymentAccounts: { type: Array, default: () => [] },
    paymentIdentifiers: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
})

const tab = ref('automatic')
const query = ref('')
const identifierDefaults = {
    bank_name: '',
    bank_account_holder: '',
    bank_account_number: '',
    bank_iban: '',
    palpay_wallet_number: '',
    jawwal_pay_wallet_number: '',
    ...props.paymentIdentifiers,
}
const form = useForm({
    posting_role_accounts: Object.fromEntries(props.postingGroups.flatMap((group) => group.roles).map((role) => [role.key, role.selected || ''])),
    expense_category_accounts: Object.fromEntries(props.expenseCategories.map((category) => [category.id, category.selected || ''])),
    payment_method_accounts: Object.fromEntries(props.paymentMethods.map((method) => [method.code, method.selected || ''])),
    payment_identifiers: identifierDefaults,
})

const groups = computed(() => props.postingGroups
    .map((group) => ({
        ...group,
        roles: group.roles.filter((role) => !query.value.trim()
            || `${role.label} ${role.description} ${role.key}`.toLowerCase().includes(query.value.trim().toLowerCase())),
    }))
    .filter((group) => group.roles.length))

const filledIdentifiers = computed(() => Object.values(form.payment_identifiers).filter((value) => String(value || '').trim()).length)
const tabs = computed(() => [
    { key: 'automatic', icon: 'bi-lightning-charge', label: 'قواعد الترحيل', count: props.postingGroups.flatMap((group) => group.roles).length },
    { key: 'expenses', icon: 'bi-receipt', label: 'فئات المصاريف', count: props.expenseCategories.length },
    { key: 'payments', icon: 'bi-credit-card', label: 'طرق الدفع', count: props.paymentMethods.length },
    { key: 'destinations', icon: 'bi-bank', label: 'بيانات الاستقبال', count: filledIdentifiers.value },
])

function submit() {
    form.post(props.urls.store, {
        preserveScroll: true,
        onSuccess: () => form.defaults({ ...form.data() }),
    })
}
</script>

<template>
    <Head title="ربط الحسابات" />
    <PageHeader
        title="ربط الحسابات التلقائية"
        icon="bi-diagram-3-fill"
        subtitle="وجّه العمليات الجديدة إلى الحساب الصحيح، وسجّل بيانات البنك والمحافظ الحقيقية في مكانها"
    />
    <AccountingNav :urls="urls" active="mappings" />

    <section class="mapping-note">
        <i class="bi bi-shield-check"></i>
        <div>
            <strong>افصل بين كود الحساب ورقم الاستقبال الحقيقي</strong>
            <small>مثل 1010 هو كود دفتر داخلي جاهز في شجرة الحسابات. رقم البنك أو IBAN ورقم المحفظة يُدخل في «بيانات الاستقبال»، ولا يغيّر أكواد الشجرة أو القيود السابقة.</small>
        </div>
        <a :href="urls.guide">افهم الأثر <i class="bi bi-arrow-left"></i></a>
    </section>

    <form class="mapping-form" @submit.prevent="submit">
        <nav class="mapping-tabs" aria-label="أقسام ربط الحسابات">
            <button
                v-for="item in tabs"
                :key="item.key"
                type="button"
                :class="{ active: tab === item.key }"
                @click="tab = item.key"
            >
                <i class="bi" :class="item.icon"></i>
                <span>{{ item.label }}</span>
                <b>{{ item.count }}</b>
            </button>
        </nav>

        <template v-if="tab === 'automatic'">
            <div class="search">
                <i class="bi bi-search"></i>
                <input v-model="query" type="search" placeholder="ابحث باسم الدور أو وصفه...">
            </div>
            <div class="groups">
                <details v-for="(group, index) in groups" :key="group.name" :open="index === 0 || Boolean(query)">
                    <summary><span><i class="bi bi-folder2-open"></i><strong>{{ group.name }}</strong></span><b>{{ group.roles.length }}</b></summary>
                    <div>
                        <article v-for="role in group.roles" :key="role.key">
                            <div><strong>{{ role.label }}</strong><small>{{ role.description }}</small><em>{{ role.key }}</em></div>
                            <label>
                                <span>الحساب المستخدم</span>
                                <select v-model="form.posting_role_accounts[role.key]" class="form-select">
                                    <option value="">الافتراضي: {{ role.defaultLabel }}</option>
                                    <option v-for="account in role.accounts" :key="account.id" :value="account.id">{{ account.code }} — {{ account.name }}</option>
                                </select>
                                <small v-if="!form.posting_role_accounts[role.key]">سيستخدم النظام الحساب الافتراضي الجاهز</small>
                                <small v-else class="custom">ربط مخصص للعمليات الجديدة</small>
                            </label>
                        </article>
                    </div>
                </details>
            </div>
        </template>

        <div v-else-if="tab === 'expenses'" class="two-panel">
            <AccountingPanel title="فئات المصاريف" description="عند اعتماد مصروف، يذهب إلى الحساب المختار بدلاً من المصروف التشغيلي العام." icon="bi-receipt">
                <div class="simple-mappings">
                    <article v-for="category in expenseCategories" :key="category.id">
                        <strong>{{ category.label }}</strong>
                        <select v-model="form.expense_category_accounts[category.id]" class="form-select">
                            <option value="">5100 — المصاريف التشغيلية</option>
                            <option v-for="account in expenseAccounts" :key="account.id" :value="account.id">{{ account.code }} — {{ account.name }}</option>
                        </select>
                    </article>
                </div>
            </AccountingPanel>
            <aside class="mapping-help">
                <i class="bi bi-lightbulb-fill"></i><strong>متى تخصص حساباً؟</strong>
                <p>خصص حساباً للكهرباء والإيجار والرواتب عندما تريد رؤية كل مصروف منفصلاً في الأرباح والخسائر. اترك الفئات الصغيرة على الحساب العام كي تبقى الشجرة بسيطة.</p>
            </aside>
        </div>

        <div v-else-if="tab === 'payments'" class="two-panel">
            <AccountingPanel title="طرق الدفع" description="اختر حساب الدفتر الذي يستقبل التحصيل أو يخرج منه الدفع والاسترداد." icon="bi-credit-card-2-front" tone="blue">
                <div class="simple-mappings">
                    <article v-for="method in paymentMethods" :key="method.code">
                        <span><strong>{{ method.label }}</strong><small>{{ method.code }}</small></span>
                        <select v-model="form.payment_method_accounts[method.code]" class="form-select">
                            <option value="">الافتراضي: {{ method.fallback }}</option>
                            <option v-for="account in paymentAccounts" :key="account.id" :value="account.id">{{ account.code }} — {{ account.name }}</option>
                        </select>
                    </article>
                </div>
            </AccountingPanel>
            <aside class="mapping-help blue">
                <i class="bi bi-bank"></i><strong>هذه حسابات دفتر وليست أرقام بنك</strong>
                <p>التحويل المباشر والفيزا يرحّلان إلى حساب البنك. أما PalPay وJawwal Pay فيبقيان في حساب محفظة مستقل حتى يسجّل المحاسب تحويلهما إلى البنك.</p>
            </aside>
        </div>

        <div v-else class="destination-layout">
            <AccountingPanel title="الحساب البنكي الحقيقي" description="بيانات الاستقبال التي يعتمدها المطعم ويحتاجها المحاسب والزبون عند التحويل." icon="bi-bank" tone="green">
                <div class="identifier-grid">
                    <label><span>اسم البنك</span><input v-model.trim="form.payment_identifiers.bank_name" class="form-control" maxlength="120" placeholder="مثال: بنك فلسطين"></label>
                    <label><span>اسم صاحب الحساب</span><input v-model.trim="form.payment_identifiers.bank_account_holder" class="form-control" maxlength="160" placeholder="الاسم المسجل لدى البنك"></label>
                    <label><span>رقم الحساب</span><input v-model.trim="form.payment_identifiers.bank_account_number" class="form-control" maxlength="80" placeholder="Account number"></label>
                    <label><span>IBAN</span><input v-model.trim="form.payment_identifiers.bank_iban" class="form-control" maxlength="60" placeholder="PS00 0000 0000..."></label>
                </div>
            </AccountingPanel>

            <AccountingPanel title="أرقام المحافظ" description="تظهر كبيانات استقبال فعلية؛ الرصيد المحاسبي يبقى في حساب المحفظة حتى تحويله إلى البنك." icon="bi-wallet2" tone="blue">
                <div class="identifier-grid">
                    <label><span>رقم محفظة PalPay</span><input v-model.trim="form.payment_identifiers.palpay_wallet_number" class="form-control" inputmode="tel" maxlength="40" placeholder="0592632026"></label>
                    <label><span>رقم محفظة Jawwal Pay</span><input v-model.trim="form.payment_identifiers.jawwal_pay_wallet_number" class="form-control" inputmode="tel" maxlength="40" placeholder="0592632026"></label>
                </div>
            </AccountingPanel>

            <aside class="identifier-rule">
                <i class="bi bi-journal-check"></i>
                <span><strong>القاعدة المحاسبية</strong><small>تغيير الرقم الحقيقي لا يغيّر أي قيد. تغيير حساب الدفتر من تبويب «طرق الدفع» يؤثر فقط في القيود الجديدة.</small></span>
            </aside>
        </div>

        <footer class="save-bar">
            <span><i class="bi bi-info-circle"></i> بيانات البنك والمحافظ إعدادات تعريفية؛ ربط الدفتر يطبّق على القيود الجديدة فقط.</span>
            <a :href="urls.home" class="btn btn-light">إلغاء</a>
            <button class="btn btn-primary" :disabled="form.processing">
                <i class="bi bi-save"></i> حفظ الإعدادات
            </button>
        </footer>
    </form>
</template>

<style scoped>
.mapping-note { display: flex; align-items: center; gap: 12px; margin: 16px 0; padding: 14px 16px; border: 1px solid #acd2b7; border-radius: 14px; color: #1f6b50; background: #eff8f2; }
.mapping-note > i { font-size: 1.05rem; }.mapping-note > div { display: grid; flex: 1; gap: 2px; }.mapping-note strong { font-size: .78rem; }.mapping-note small { font-size: .65rem; line-height: 1.65; }.mapping-note a { color: #1f6b50; font-size: .66rem; font-weight: 850; }
.mapping-form { display: grid; gap: 14px; }.mapping-tabs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; padding-bottom: 14px; border-bottom: 1px solid #e2e8e4; }
.mapping-tabs button { display: flex; min-height: 46px; align-items: center; justify-content: center; gap: 7px; padding: 10px; border: 1px solid #dfe7e2; border-radius: 12px; color: #59685e; background: #fff; font-size: .7rem; font-weight: 850; }.mapping-tabs button b { display: grid; min-width: 21px; height: 21px; place-items: center; border-radius: 999px; background: #eef2ef; font-size: .56rem; }.mapping-tabs button.active { color: #1f6b50; border-color: #99c7a7; background: #f0f8f2; box-shadow: inset 0 -2px #1f6b50; }
.search { display: flex; align-items: center; gap: 7px; padding: 0 11px; border: 1px solid #dfe7e2; border-radius: 11px; background: #fff; }.search input { width: 100%; padding: 10px 0; border: 0; outline: 0; font-size: .7rem; }
.groups { display: grid; gap: 10px; }.groups details { overflow: hidden; border: 1px solid #dfe7e2; border-radius: 14px; background: #fff; }.groups summary { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; cursor: pointer; list-style: none; }.groups summary > span { display: flex; align-items: center; gap: 7px; color: #31453a; }.groups summary i { color: #1f6b50; }.groups summary strong { font-size: .74rem; }.groups summary b { display: grid; width: 24px; height: 24px; place-items: center; border-radius: 999px; background: #eff3f0; font-size: .58rem; }.groups details > div { border-top: 1px solid #edf1ee; }.groups article { display: grid; grid-template-columns: minmax(0, 1fr) minmax(320px, .8fr); align-items: center; gap: 12px; padding: 11px 14px; border-bottom: 1px solid #edf1ee; }.groups article:last-child { border-bottom: 0; }.groups article > div, .groups article label { display: grid; gap: 3px; }.groups article > div strong { font-size: .7rem; }.groups article > div small { color: #7d8a82; font-size: .61rem; }.groups article > div em { color: #a0aaa3; font-size: .53rem; font-style: normal; }.groups article label > span { font-size: .59rem; font-weight: 850; }.groups article label > small { color: #849188; font-size: .54rem; }.groups article label > small.custom { color: #1f6b50; }
.two-panel { display: grid; grid-template-columns: minmax(0, 1fr) 280px; align-items: start; gap: 14px; }.simple-mappings { display: grid; }.simple-mappings article { display: grid; grid-template-columns: minmax(140px, .6fr) 1fr; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #edf1ee; }.simple-mappings article:last-child { border-bottom: 0; }.simple-mappings article > span { display: grid; }.simple-mappings strong { font-size: .7rem; }.simple-mappings small { color: #89958d; font-size: .56rem; }
.mapping-help { display: grid; gap: 6px; padding: 16px; border: 1px solid #e4c98d; border-radius: 14px; color: #8d5200; background: #fff7e8; }.mapping-help > i { font-size: 1.1rem; }.mapping-help strong { font-size: .74rem; }.mapping-help p { margin: 0; font-size: .64rem; line-height: 1.75; }.mapping-help.blue { color: #1d6388; border-color: #bdd8e6; background: #f0f8fc; }
.destination-layout { display: grid; gap: 14px; }.identifier-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }.identifier-grid label { display: grid; gap: 6px; }.identifier-grid label > span { color: #34463b; font-size: .68rem; font-weight: 850; }.identifier-rule { display: flex; align-items: center; gap: 10px; padding: 13px 15px; border: 1px dashed #c9d8cf; border-radius: 13px; color: #4e6357; background: #f8faf8; }.identifier-rule > i { color: #1f6b50; font-size: 1rem; }.identifier-rule > span { display: grid; gap: 2px; }.identifier-rule strong { font-size: .7rem; }.identifier-rule small { color: #7d8a82; font-size: .61rem; line-height: 1.6; }
.save-bar { position: sticky; z-index: 9; bottom: 8px; display: flex; align-items: center; gap: 8px; padding: 11px 13px; border: 1px solid #ccddd2; border-radius: 13px; background: rgba(255, 255, 255, .97); box-shadow: 0 12px 35px rgba(20, 50, 31, .12); backdrop-filter: blur(10px); }.save-bar > span { display: flex; flex: 1; gap: 6px; color: #75837a; font-size: .62rem; }
@media (max-width: 850px) { .groups article { grid-template-columns: 1fr; }.two-panel { grid-template-columns: 1fr; }.mapping-help { order: -1; }.mapping-tabs { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .mapping-note { align-items: flex-start; flex-wrap: wrap; }.mapping-note a { width: 100%; padding-inline-start: 28px; }.mapping-tabs button span { font-size: .63rem; }.simple-mappings article, .identifier-grid { grid-template-columns: 1fr; }.save-bar > span { display: none; }.save-bar .btn { padding-inline: 10px; } }
</style>
