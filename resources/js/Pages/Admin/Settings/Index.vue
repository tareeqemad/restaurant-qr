<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import FieldError from '../../../Components/Settings/FieldError.vue'
import SettingsSection from '../../../Components/Settings/SettingsSection.vue'
import ToggleField from '../../../Components/Settings/ToggleField.vue'
import { useConfirm } from '../../../Composables/useConfirm'
import { localDateInput } from '../../../Utils/dateInput'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    can: { type: Object, required: true },
    values: { type: Object, required: true },
    market: { type: Object, required: true },
    paymentMethods: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    statusColors: { type: Array, default: () => [] },
    themeDefaults: { type: Object, required: true },
    brand: { type: Object, required: true },
    smsPasswordConfigured: { type: Boolean, default: false },
    currencies: { type: Array, default: () => [] },
    exchangeRates: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
})

const { ask } = useConfirm()
const settingsForm = useForm({ ...props.values })

const sections = [
    { id: 'restaurant', label: 'المطعم والهوية', short: 'الهوية', icon: 'bi-shop-window', description: 'الاسم، الشعار، والألوان', keywords: 'اسم قانوني شعار ألوان هوية ثيم favicon' },
    { id: 'billing', label: 'الفاتورة والمال', short: 'المال', icon: 'bi-receipt-cutoff', description: 'الخدمة، الدفع، والعملات', keywords: 'فاتورة ضريبة خدمة نقد تحويل بنك حساب iban محفظة palpay jawwal عملة صرف' },
    { id: 'operations', label: 'الطلبات والمخزون', short: 'التشغيل', icon: 'bi-diagram-3', description: 'مسار الطلب والخصم من المخزون', keywords: 'طلب qr إلغاء مخزون مطبخ بار حالة اعتماد' },
    { id: 'staff', label: 'الموظفون والصلاحيات', short: 'الموظفون', icon: 'bi-people', description: 'تجهيز الفريق، الوجبات والخصومات', keywords: 'موظف حساب دخول وجبة دين خصم صلاحية دور تجريبي' },
    { id: 'sms', label: 'الاتصالات', short: 'SMS', icon: 'bi-chat-dots', description: 'مزود الرسائل وقوالب الاستعادة', keywords: 'رسائل sms جوال كلمة مرور' },
]

const oldHashes = {
    'tab-general': 'restaurant', 'tab-brand': 'restaurant', 'tab-theme': 'restaurant',
    'tab-billing': 'billing', 'tab-currencies': 'billing',
    'tab-operations': 'operations', 'tab-statuses': 'operations',
    'tab-discounts': 'staff', 'tab-sms': 'sms',
}
const hash = typeof window === 'undefined' ? '' : window.location.hash.slice(1)
const initialSection = sections.some((item) => item.id === hash) ? hash : (oldHashes[hash] ?? 'restaurant')
const activeSection = ref(initialSection)
const navSearch = ref('')

const visibleSections = computed(() => {
    const term = navSearch.value.trim().toLocaleLowerCase('ar')
    if (!term) return sections
    return sections.filter((item) => `${item.label} ${item.short} ${item.description} ${item.keywords}`.toLocaleLowerCase('ar').includes(term))
})

const currentSection = computed(() => sections.find((item) => item.id === activeSection.value) ?? sections[0])
const hasFormErrors = computed(() => Object.keys(settingsForm.errors).length > 0)
const transferMethod = computed(() => props.paymentMethods.find((method) => method.code === 'transfer'))
const transferEnabled = computed(() => transferMethod.value ? Boolean(settingsForm[transferMethod.value.key]) : false)
const nonBaseCurrencies = computed(() => props.currencies.filter((currency) => !currency.isBase))

watch(activeSection, (section) => {
    if (typeof window !== 'undefined') window.history.replaceState(null, '', `#${section}`)
})

function openSection(id) {
    activeSection.value = id
    if (typeof window !== 'undefined' && window.innerWidth < 900) {
        document.querySelector('.settings-content')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
}

function saveSettings() {
    if (!props.can.edit || settingsForm.processing) return
    settingsForm.put(props.urls.update, {
        preserveScroll: true,
        onSuccess: () => {
            settingsForm.defaults({ ...settingsForm.data() })
            settingsForm.clearErrors()
        },
    })
}

function resetUnsaved() {
    settingsForm.reset()
    settingsForm.clearErrors()
}

function onShortcut(event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault()
        saveSettings()
    }
}
if (typeof window !== 'undefined') window.addEventListener('keydown', onShortcut)
onBeforeUnmount(() => { if (typeof window !== 'undefined') window.removeEventListener('keydown', onShortcut) })

const brandForm = useForm({ brand_logo: null, brand_favicon: null })
function uploadBrand() {
    if (!props.can.edit || (!brandForm.brand_logo && !brandForm.brand_favicon)) return
    brandForm.post(props.urls.brandUpdate, { forceFormData: true, preserveScroll: true, onSuccess: () => brandForm.reset() })
}

async function deleteBrand(type) {
    const label = type === 'logo' ? 'الشعار' : 'أيقونة المتصفح'
    const yes = await ask({ title: `حذف ${label} المخصص؟`, message: 'سيعود النظام مباشرة إلى الشكل الافتراضي.', confirmLabel: 'حذف', danger: true })
    if (yes) router.delete(type === 'logo' ? props.urls.deleteLogo : props.urls.deleteFavicon, { preserveScroll: true })
}

const themePresets = [
    { name: 'أخضر غابي', primary: '#1f6b50', dark: '#123f31', header: '#ffffff', accent: '#d97706', menu: '#ffffff' },
    { name: 'زيتوني دافئ', primary: '#4d6227', dark: '#34451d', header: '#fffdf6', accent: '#c46c16', menu: '#fffaf0' },
    { name: 'ليلي أنيق', primary: '#355f54', dark: '#18382f', header: '#18382f', accent: '#d8a63f', menu: '#122c26' },
]

function applyPreset(preset) {
    settingsForm.theme_primary = preset.primary
    settingsForm.theme_dark = preset.dark
    settingsForm.theme_header = preset.header
    settingsForm.theme_accent = preset.accent
    settingsForm.theme_menu = preset.menu
}

async function resetTheme() {
    const yes = await ask({ title: 'استعادة ألوان النظام؟', message: 'ستُحذف تخصيصات الألوان فقط، ولن تتأثر بقية الإعدادات.', confirmLabel: 'استعادة' })
    if (yes) router.post(props.urls.resetTheme, {}, { preserveScroll: true })
}

const currencyCreate = useForm({ code: '', name: '', symbol: '', rate_to_base: '', is_active: true })
function createCurrency() {
    if (!props.can.edit) return
    currencyCreate.post(props.urls.currencyStore, { preserveScroll: true, onSuccess: () => currencyCreate.reset() })
}

const currencyRates = useForm({
    rates: Object.fromEntries(nonBaseCurrencies.value.map((currency) => [currency.id, currency.rate])),
    active: Object.fromEntries(nonBaseCurrencies.value.map((currency) => [currency.id, currency.isActive])),
})
function updateCurrencyRates() {
    if (!props.can.edit) return
    currencyRates.put(props.urls.currencyRates, { preserveScroll: true })
}

async function deleteCurrency(currency) {
    const yes = await ask({ title: `حذف عملة ${currency.code}؟`, message: 'لن تُحذف العملة الأساسية. تأكد أن العملة غير مستخدمة في مستندات حالية.', confirmLabel: 'حذف', danger: true })
    if (yes) router.delete(currency.destroyUrl, { preserveScroll: true })
}

const today = localDateInput()
const exchangeForm = useForm({ currency_code: nonBaseCurrencies.value[0]?.code ?? '', rate: '', valid_from: today, valid_to: '', note: '' })
function createExchangeRate() {
    if (!props.can.edit) return
    exchangeForm.post(props.urls.exchangeRateStore, { preserveScroll: true, onSuccess: () => exchangeForm.reset('rate', 'valid_to', 'note') })
}

async function deleteExchangeRate(rate) {
    const yes = await ask({ title: 'حذف سعر الصرف التاريخي؟', message: `${rate.currencyCode} — ${rate.validFrom}`, confirmLabel: 'حذف', danger: true })
    if (yes) router.delete(rate.destroyUrl, { preserveScroll: true })
}

function testSms() {
    if (props.can.edit) router.post(props.urls.testSms, {}, { preserveScroll: true })
}

</script>

<template>
    <Head title="الإعدادات" />

    <PageHeader title="الإعدادات" icon="bi-sliders2-vertical" subtitle="كل ما يغيّر سلوك المطعم، مرتب في خمس مساحات واضحة فقط">
        <template #actions>
            <button v-if="can.edit" type="button" class="btn btn-primary" :disabled="settingsForm.processing || !settingsForm.isDirty" @click="saveSettings">
                <span v-if="settingsForm.processing" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <i v-else class="bi bi-check2-circle"></i>
                حفظ التغييرات
            </button>
        </template>
    </PageHeader>

    <div v-if="!can.edit" class="settings-notice settings-notice--info">
        <i class="bi bi-eye"></i>
        <div><strong>عرض فقط</strong><span>يمكن للمدير مراجعة الإعدادات، أما تعديلها فمحصور بمدير النظام.</span></div>
    </div>

    <div v-if="hasFormErrors" class="settings-notice settings-notice--danger">
        <i class="bi bi-exclamation-triangle"></i>
        <div><strong>راجع الحقول المعلّمة</strong><span>لم يتم الحفظ لأن بعض القيم تحتاج تصحيحاً.</span></div>
    </div>

    <div class="settings-shell">
        <aside class="settings-rail" aria-label="أقسام الإعدادات">
            <div class="settings-rail__heading">
                <strong>مساحات الإعداد</strong>
                <span>اختر ما تريد تعديله فقط</span>
            </div>
            <label class="settings-search">
                <i class="bi bi-search"></i>
                <input v-model="navSearch" type="search" placeholder="ابحث عن إعداد...">
            </label>
            <nav class="settings-nav">
                <button v-for="section in visibleSections" :key="section.id" type="button" :class="{ active: activeSection === section.id }" @click="openSection(section.id)">
                    <i class="bi" :class="section.icon"></i>
                    <span><strong>{{ section.label }}</strong><small>{{ section.description }}</small></span>
                    <i class="bi bi-chevron-left settings-nav__arrow"></i>
                </button>
                <p v-if="visibleSections.length === 0" class="settings-nav__empty">لا يوجد قسم مطابق. جرّب كلمة أخرى.</p>
            </nav>
            <div class="settings-rail__tip"><i class="bi bi-command"></i><span><bdi>Ctrl + S</bdi> للحفظ السريع</span></div>
        </aside>

        <main class="settings-content">
            <header class="settings-content__intro">
                <div class="settings-content__icon"><i class="bi" :class="currentSection.icon"></i></div>
                <div><span>أنت الآن في</span><h2>{{ currentSection.label }}</h2><p>{{ currentSection.description }}</p></div>
                <span v-if="settingsForm.isDirty" class="unsaved-badge"><i class="bi bi-dot"></i> تغييرات غير محفوظة</span>
            </header>

            <div v-if="activeSection === 'restaurant'" class="settings-stack">
                <SettingsSection title="بيانات المطعم" description="البيانات التي تظهر في النظام وعلى الفواتير." icon="bi-shop">
                    <div class="form-grid">
                        <label class="field field--wide"><span>اسم المطعم <b>*</b></span><input v-model="settingsForm.site_name" class="form-control" :disabled="!can.edit" maxlength="120"><FieldError :message="settingsForm.errors.site_name" /></label>
                        <label class="field"><span>الاسم القانوني الافتراضي</span><input v-model="settingsForm.legal_name" class="form-control" :disabled="!can.edit" maxlength="160" placeholder="اختياري"><small>احتياطي للفروع القديمة؛ الهوية الفعلية تسجل من إدارة كل فرع.</small><FieldError :message="settingsForm.errors.legal_name" /></label>
                        <label class="field"><span>{{ market.taxNumberLabel }} الافتراضي</span><input v-model="settingsForm.tax_number" class="form-control" :disabled="!can.edit" maxlength="80" placeholder="اختياري"><small>كل فرع يستخدم رقمه المسجل أولاً، ولا تظهر ضريبة إذا كانت متوقفة.</small><FieldError :message="settingsForm.errors.tax_number" /></label>
                    </div>
                </SettingsSection>

                <SettingsSection title="الشعار وأيقونة المتصفح" description="ارفع الهوية مرة واحدة؛ ستظهر في الإدارة والمنيو والفاتورة." icon="bi-image" tone="blue">
                    <div class="brand-grid">
                        <div class="brand-card">
                            <div class="brand-preview brand-preview--logo"><img :src="brand.logo" alt="شعار المطعم"></div>
                            <div><strong>الشعار الرئيسي</strong><small>PNG أو JPG أو WEBP أو SVG — حتى 2MB</small></div>
                            <input type="file" accept=".png,.jpg,.jpeg,.webp,.svg" :disabled="!can.edit" @change="brandForm.brand_logo = $event.target.files[0] ?? null">
                            <FieldError :message="brandForm.errors.brand_logo" />
                            <button v-if="brand.hasLogo && can.edit" type="button" class="text-action text-action--danger" @click="deleteBrand('logo')"><i class="bi bi-trash3"></i> حذف المخصص</button>
                        </div>
                        <div class="brand-card">
                            <div class="brand-preview brand-preview--favicon"><img :src="brand.favicon" alt="أيقونة المتصفح"></div>
                            <div><strong>أيقونة المتصفح</strong><small>صورة مربعة واضحة — حتى 512KB</small></div>
                            <input type="file" accept=".png,.ico,.jpg,.webp,.svg" :disabled="!can.edit" @change="brandForm.brand_favicon = $event.target.files[0] ?? null">
                            <FieldError :message="brandForm.errors.brand_favicon" />
                            <button v-if="brand.hasFavicon && can.edit" type="button" class="text-action text-action--danger" @click="deleteBrand('favicon')"><i class="bi bi-trash3"></i> حذف المخصص</button>
                        </div>
                    </div>
                    <template #footer><button v-if="can.edit" type="button" class="btn btn-outline-primary" :disabled="brandForm.processing || (!brandForm.brand_logo && !brandForm.brand_favicon)" @click="uploadBrand"><i class="bi bi-cloud-arrow-up"></i> رفع الصور المختارة</button></template>
                </SettingsSection>

                <SettingsSection title="ألوان الواجهة" description="اختر قالباً جاهزاً أو عدّل الألوان وشاهد النتيجة قبل الحفظ." icon="bi-palette">
                    <div class="theme-layout">
                        <div class="theme-controls">
                            <div class="preset-list">
                                <button v-for="preset in themePresets" :key="preset.name" type="button" :disabled="!can.edit" @click="applyPreset(preset)">
                                    <span><i v-for="color in [preset.primary, preset.accent, preset.header]" :key="color" :style="{ background: color }"></i></span>{{ preset.name }}
                                </button>
                            </div>
                            <div class="color-grid">
                                <label v-for="color in [
                                    ['theme_primary', 'اللون الرئيسي'], ['theme_dark', 'اللون الداكن'], ['theme_header', 'رأس الصفحة'], ['theme_accent', 'لون التنبيه'], ['theme_menu', 'خلفية المنيو']
                                ]" :key="color[0]" class="color-field"><input v-model="settingsForm[color[0]]" type="color" :disabled="!can.edit"><span>{{ color[1] }}<bdi>{{ settingsForm[color[0]] }}</bdi></span></label>
                            </div>
                            <div class="form-grid form-grid--compact">
                                <label class="field"><span>نمط رأس الإدارة</span><select v-model="settingsForm.theme_header_style" class="form-select" :disabled="!can.edit"><option value="light">فاتح</option><option value="dark">داكن</option><option value="color">بلون الهوية</option></select></label>
                                <label class="field"><span>نمط رأس المنيو</span><select v-model="settingsForm.theme_menu_style" class="form-select" :disabled="!can.edit"><option value="light">فاتح</option><option value="dark">داكن</option><option value="brand">بلون الهوية</option></select></label>
                            </div>
                        </div>
                        <div class="theme-preview" :style="{ '--p': settingsForm.theme_primary, '--d': settingsForm.theme_dark, '--h': settingsForm.theme_header, '--a': settingsForm.theme_accent, '--m': settingsForm.theme_menu }">
                            <div class="theme-preview__header"><img :src="brand.logo" alt=""><span>{{ settingsForm.site_name }}</span><i class="bi bi-list"></i></div>
                            <div class="theme-preview__hero"><small>تجربة العميل</small><strong>أهلاً بك في مطعمنا</strong><button>شاهد المنيو</button></div>
                            <div class="theme-preview__cards"><i></i><i></i><i></i></div>
                        </div>
                    </div>
                    <template #footer><button v-if="can.edit" type="button" class="text-action" @click="resetTheme"><i class="bi bi-arrow-counterclockwise"></i> استعادة الألوان الافتراضية</button></template>
                </SettingsSection>
            </div>

            <div v-else-if="activeSection === 'billing'" class="settings-stack">
                <SettingsSection title="رسوم الخدمة والضريبة" description="رسوم الخدمة اختيارية ومستقلة تماماً عن الضريبة القانونية." icon="bi-percent" tone="amber">
                    <div class="toggle-grid">
                        <ToggleField v-model="settingsForm.service_enabled" label="إضافة رسوم خدمة" :description="`تظهر على فاتورة المطعم باسم «${market.serviceLabel}»`" icon="bi-stars" :disabled="!can.edit" />
                    </div>
                    <div class="form-grid settings-subgrid">
                        <label class="field"><span>نسبة رسوم الخدمة</span><div class="input-suffix"><input v-model.number="settingsForm.service_rate" type="number" min="0" max="100" step="0.01" class="form-control" :disabled="!can.edit || !settingsForm.service_enabled"><bdi>%</bdi></div><small>يمكن تغييرها في أي وقت. ليست ضريبة ولا تُرحّل إلى حساب ضريبة.</small><FieldError :message="settingsForm.errors.service_rate" /></label>
                        <div class="field field--read"><span>عرض الضريبة للزبون</span><strong>{{ settingsForm.customer_tax_display === 'inclusive' ? 'السعر شامل الضريبة' : 'الضريبة منفصلة عن السعر' }}</strong><small>تفعيل الضريبة ونسبتها وتاريخ سريانها من مركز المحاسبة فقط.</small><a :href="urls.accounting">فتح مركز المحاسبة <i class="bi bi-arrow-left"></i></a></div>
                    </div>
                </SettingsSection>

                <SettingsSection title="طرق الدفع" description="اترك طريقة واحدة على الأقل حتى يبقى التحصيل متاحاً للكاشير." icon="bi-wallet2">
                    <div class="toggle-grid">
                        <ToggleField v-for="method in paymentMethods" :key="method.code" v-model="settingsForm[method.key]" :label="method.label" :description="method.description" :icon="method.icon" :disabled="!can.edit" />
                    </div>
                    <FieldError :message="settingsForm.errors.payment_method_cash_enabled" />
                    <div class="payment-destinations settings-subgrid">
                        <header class="destination-heading">
                            <i class="bi bi-bank"></i>
                            <span><strong>بيانات الاستقبال الحقيقية</strong><small>هذه أرقام البنك والمحافظ الفعلية وليست أكواد شجرة الحسابات. تغييرها لا يغيّر أي قيد محاسبي.</small></span>
                        </header>
                        <div class="form-grid">
                            <label class="field"><span>اسم البنك</span><input v-model.trim="settingsForm.bank_name" class="form-control" maxlength="120" :disabled="!can.edit" placeholder="مثال: بنك فلسطين"><FieldError :message="settingsForm.errors.bank_name" /></label>
                            <label class="field"><span>اسم صاحب الحساب</span><input v-model.trim="settingsForm.bank_account_holder" class="form-control" maxlength="160" :disabled="!can.edit" placeholder="الاسم المسجل لدى البنك"><FieldError :message="settingsForm.errors.bank_account_holder" /></label>
                            <label class="field"><span>رقم الحساب البنكي</span><input v-model.trim="settingsForm.bank_account_number" class="form-control" maxlength="80" :disabled="!can.edit" placeholder="Account number"><FieldError :message="settingsForm.errors.bank_account_number" /></label>
                            <label class="field"><span>IBAN</span><input v-model.trim="settingsForm.bank_iban" class="form-control" maxlength="60" :disabled="!can.edit" placeholder="PS00 0000 0000..."><FieldError :message="settingsForm.errors.bank_iban" /></label>
                            <label class="field"><span>رقم محفظة PalPay</span><input v-model.trim="settingsForm.palpay_wallet_number" class="form-control" inputmode="tel" maxlength="40" :disabled="!can.edit" placeholder="0592632026"><FieldError :message="settingsForm.errors.palpay_wallet_number" /></label>
                            <label class="field"><span>رقم محفظة Jawwal Pay</span><input v-model.trim="settingsForm.jawwal_pay_wallet_number" class="form-control" inputmode="tel" maxlength="40" :disabled="!can.edit" placeholder="0592632026"><FieldError :message="settingsForm.errors.jawwal_pay_wallet_number" /></label>
                        </div>
                        <label v-if="transferEnabled" class="field transfer-note"><span>تعليمات التحويل التي تظهر للزبون</span><textarea v-model="settingsForm.bank_transfer_details" class="form-control" rows="3" maxlength="1000" :disabled="!can.edit" placeholder="تعليمات مختصرة يراها الزبون عند اختيار التحويل"></textarea><small>اكتب هنا فقط ما تريد عرضه للزبون. بيانات الحساب المنظمة أعلاه تبقى لإدارة المطعم ما لم تذكرها أنت في هذا النص.</small><FieldError :message="settingsForm.errors.bank_transfer_details" /></label>
                    </div>
                </SettingsSection>

                <SettingsSection title="شكل الفاتورة" description="نص مختصر وواضح يظهر أسفل فاتورة الزبون." icon="bi-file-earmark-text">
                    <label class="field"><span>تذييل الفاتورة</span><textarea v-model="settingsForm.receipt_footer" class="form-control" rows="3" maxlength="500" :disabled="!can.edit" placeholder="شكراً لزيارتكم"></textarea><FieldError :message="settingsForm.errors.receipt_footer" /></label>
                </SettingsSection>

                <SettingsSection title="العملات وسعر الصرف" description="العملة الأساسية ثابتة على 1؛ حدّث العملات الأخرى من مكان واحد." icon="bi-currency-exchange" tone="blue">
                    <div class="currency-list">
                        <article v-for="currency in currencies" :key="currency.id" class="currency-row" :class="{ 'is-base': currency.isBase }">
                            <div class="currency-code"><strong>{{ currency.code }}</strong><small>{{ currency.name }} · {{ currency.symbol }}</small></div>
                            <span v-if="currency.isBase" class="currency-base">أساسية</span>
                            <template v-else>
                                <label><span>مقابل {{ market.baseCurrency }}</span><input v-model="currencyRates.rates[currency.id]" type="number" min="0.000001" step="0.000001" class="form-control" :disabled="!can.edit"></label>
                                <label class="currency-active"><input v-model="currencyRates.active[currency.id]" type="checkbox" :disabled="!can.edit"><span>مفعلة</span></label>
                                <button v-if="can.edit" type="button" class="icon-action icon-action--danger" title="حذف العملة" @click="deleteCurrency(currency)"><i class="bi bi-trash3"></i></button>
                            </template>
                        </article>
                    </div>
                    <button v-if="can.edit && nonBaseCurrencies.length" type="button" class="btn btn-outline-primary mt-3" :disabled="currencyRates.processing" @click="updateCurrencyRates"><i class="bi bi-arrow-repeat"></i> تحديث الأسعار الحالية</button>
                    <details v-if="can.edit" class="settings-disclosure">
                        <summary><i class="bi bi-plus-circle"></i><span><strong>إضافة عملة</strong><small>للاستخدام في العرض والتحويلات المستقبلية</small></span></summary>
                        <form class="form-grid settings-disclosure__body" @submit.prevent="createCurrency">
                            <label class="field"><span>الرمز</span><input v-model="currencyCreate.code" class="form-control" maxlength="3" placeholder="USD"><FieldError :message="currencyCreate.errors.code" /></label>
                            <label class="field"><span>الاسم</span><input v-model="currencyCreate.name" class="form-control" placeholder="دولار أمريكي"><FieldError :message="currencyCreate.errors.name" /></label>
                            <label class="field"><span>العلامة</span><input v-model="currencyCreate.symbol" class="form-control" maxlength="10" placeholder="$"><FieldError :message="currencyCreate.errors.symbol" /></label>
                            <label class="field"><span>سعرها مقابل الأساسية</span><input v-model="currencyCreate.rate_to_base" type="number" min="0.000001" step="0.000001" class="form-control"><FieldError :message="currencyCreate.errors.rate_to_base" /></label>
                            <button class="btn btn-primary align-self-end" :disabled="currencyCreate.processing"><i class="bi bi-plus-lg"></i> إضافة</button>
                        </form>
                    </details>
                    <details v-if="can.edit && nonBaseCurrencies.length" class="settings-disclosure">
                        <summary><i class="bi bi-calendar3"></i><span><strong>إضافة سعر صرف بتاريخ</strong><small>للقيد المحاسبي الصحيح في يوم سابق أو فترة محددة</small></span></summary>
                        <form class="form-grid settings-disclosure__body" @submit.prevent="createExchangeRate">
                            <label class="field"><span>العملة</span><select v-model="exchangeForm.currency_code" class="form-select"><option v-for="currency in nonBaseCurrencies" :key="currency.id" :value="currency.code">{{ currency.code }} — {{ currency.name }}</option></select></label>
                            <label class="field"><span>السعر</span><input v-model="exchangeForm.rate" type="number" min="0.000001" step="0.000001" class="form-control"><FieldError :message="exchangeForm.errors.rate" /></label>
                            <label class="field"><span>ساري من</span><input v-model="exchangeForm.valid_from" type="date" class="form-control"><FieldError :message="exchangeForm.errors.valid_from" /></label>
                            <label class="field"><span>ساري حتى</span><input v-model="exchangeForm.valid_to" type="date" class="form-control"><FieldError :message="exchangeForm.errors.valid_to" /></label>
                            <label class="field field--wide"><span>ملاحظة</span><input v-model="exchangeForm.note" class="form-control" maxlength="500" placeholder="سبب التعديل أو مصدر السعر"></label>
                            <button class="btn btn-primary align-self-end" :disabled="exchangeForm.processing"><i class="bi bi-plus-lg"></i> تسجيل السعر</button>
                        </form>
                    </details>
                    <details v-if="exchangeRates.length" class="settings-disclosure">
                        <summary><i class="bi bi-clock-history"></i><span><strong>سجل أسعار الصرف</strong><small>آخر {{ exchangeRates.length }} سعراً مسجلاً</small></span></summary>
                        <div class="rate-history">
                            <article v-for="rate in exchangeRates" :key="rate.id"><strong>{{ rate.currencyCode }} / {{ rate.baseCurrencyCode }}</strong><bdi>{{ rate.rate }}</bdi><span>{{ rate.validFrom }}<template v-if="rate.validTo"> — {{ rate.validTo }}</template></span><small>{{ rate.note || (rate.source === 'manual' ? 'إدخال يدوي' : 'تحديث يومي') }}</small><button v-if="can.edit" type="button" class="icon-action icon-action--danger" @click="deleteExchangeRate(rate)"><i class="bi bi-trash3"></i></button></article>
                        </div>
                    </details>
                </SettingsSection>
            </div>

            <div v-else-if="activeSection === 'operations'" class="settings-stack">
                <SettingsSection title="مسار الطلب" description="اضبط ما يحدث من لحظة إرسال الطلب حتى وصوله للمطبخ والبار." icon="bi-send-check">
                    <div class="toggle-grid">
                        <ToggleField v-model="settingsForm.auto_approve" label="اعتماد الطلب تلقائياً" description="يرسل الطلب مباشرة إلى محطة التحضير المناسبة دون انتظار الجرسون." icon="bi-lightning-charge" :disabled="!can.edit" warning="فعّله فقط إذا كانت الوصفات والمخزون مضبوطين." />
                        <ToggleField v-model="settingsForm.customer_currency_switcher" label="اختيار العملة في المنيو" description="يسمح للزبون بتغيير عملة عرض الأسعار؛ التسجيل يبقى بالعملة الأساسية." icon="bi-currency-exchange" :disabled="!can.edit" />
                    </div>
                    <div class="form-grid settings-subgrid">
                        <label class="field"><span>مهلة إلغاء الزبون</span><div class="input-suffix"><input v-model.number="settingsForm.customer_cancel_window_seconds" type="number" min="0" max="900" class="form-control" :disabled="!can.edit"><bdi>ثانية</bdi></div><small>بعدها يحتاج الإلغاء موافقة موظف حتى لا يضيع تحضير بدأ فعلياً.</small><FieldError :message="settingsForm.errors.customer_cancel_window_seconds" /></label>
                        <label class="field"><span>انتهاء جلسة QR غير النشطة</span><div class="input-suffix"><input v-model.number="settingsForm.session_ttl_minutes" type="number" min="30" max="1440" class="form-control" :disabled="!can.edit"><bdi>دقيقة</bdi></div><small>فتح المنيو وحده لا يشغل الطاولة؛ الجلسة القديمة تنظف تلقائياً.</small><FieldError :message="settingsForm.errors.session_ttl_minutes" /></label>
                        <label class="field"><span>هامش وقت التحضير</span><div class="input-suffix"><input v-model.number="settingsForm.prep_time_buffer_pct" type="number" min="0" max="200" class="form-control" :disabled="!can.edit"><bdi>%</bdi></div><small>يضاف إلى أطول زمن صنف لتقدير موعد الجاهزية في وقت الضغط.</small><FieldError :message="settingsForm.errors.prep_time_buffer_pct" /></label>
                    </div>
                </SettingsSection>

                <SettingsSection title="المخزون والوصفات" description="حدد متى يخصم النظام مكونات الوصفة وكيف يتعامل مع النقص." icon="bi-box-seam">
                    <ToggleField v-model="settingsForm.strict_stock" label="منع الطلب عند نقص المكونات" description="يحمي المطبخ من استقبال صنف لا يمكن تحضيره، ويظهر للزبون «غير متوفر اليوم»." icon="bi-shield-check" :disabled="!can.edit" :warning="!settingsForm.strict_stock ? 'إيقافه يسمح باستمرار الطلب رغم النقص ويسجل رصيداً سالباً.' : ''" />
                    <div class="stage-picker settings-subgrid">
                        <span>لحظة خصم مكونات الوصفة</span>
                        <div>
                            <label v-for="stage in [
                                ['approve', 'عند الاعتماد', 'حجز مبكر للمكونات'], ['preparing', 'عند بدء التحضير', 'الموصى به للمطاعم الصغيرة'], ['ready', 'عند الجاهزية', 'قد يتأخر كشف النقص'], ['served', 'عند التسليم', 'أكثر مرونة وأقل دقة لحظية']
                            ]" :key="stage[0]" :class="{ active: settingsForm.inventory_deduction_stage === stage[0] }"><input v-model="settingsForm.inventory_deduction_stage" type="radio" :value="stage[0]" :disabled="!can.edit"><strong>{{ stage[1] }}</strong><small>{{ stage[2] }}</small></label>
                        </div>
                        <FieldError :message="settingsForm.errors.inventory_deduction_stage" />
                    </div>
                </SettingsSection>

                <SettingsSection title="أسماء حالات الطلب" description="اترك الحقول فارغة لاستخدام الاسم واللون الافتراضيين." icon="bi-tags" tone="blue">
                    <div class="status-grid">
                        <article v-for="status in statuses" :key="status.value">
                            <span class="status-dot" :class="`bg-${settingsForm[status.colorKey] || status.defaultColor}`"></span>
                            <div><strong>{{ status.defaultLabel }}</strong><small>{{ status.value }}</small></div>
                            <input v-model="settingsForm[status.labelKey]" class="form-control" :disabled="!can.edit" :placeholder="status.defaultLabel" maxlength="60">
                            <select v-model="settingsForm[status.colorKey]" class="form-select" :disabled="!can.edit"><option value="">اللون الافتراضي</option><option v-for="color in statusColors" :key="color" :value="color">{{ color }}</option></select>
                        </article>
                    </div>
                </SettingsSection>
            </div>

            <div v-else-if="activeSection === 'staff'" class="settings-stack">
                <SettingsSection title="المستخدمون والصلاحيات" description="إنشاء حسابات التشغيل وتعديلها يتم من شاشة واحدة مخصصة؛ هذه الصفحة تضبط قواعد الموظفين العامة فقط." icon="bi-people" tone="blue">
                    <div class="staff-management-links">
                        <a :href="urls.users"><i class="bi bi-people-fill"></i><span><strong>إدارة المستخدمين</strong><small>إضافة موظف، تعديل بياناته وربطه بالفروع.</small></span><i class="bi bi-arrow-left"></i></a>
                        <a :href="urls.roles"><i class="bi bi-shield-lock-fill"></i><span><strong>الأدوار والصلاحيات</strong><small>راجع شجرة الصلاحيات ومنح الوصول الفعلي.</small></span><i class="bi bi-arrow-left"></i></a>
                    </div>
                    <p class="settings-inline-note"><i class="bi bi-info-circle"></i> الإنشاء السريع للفريق يظهر فقط أثناء تجهيز المطعم لأول مرة؛ بعد التشغيل تُستخدم إدارة المستخدمين حتى تبقى كل التغييرات موثقة.</p>
                </SettingsSection>

                <SettingsSection title="وجبات الموظفين" description="حدد تعامل النظام مع رسوم الخدمة وتجاوز سقف دين الموظف." icon="bi-cup-hot">
                    <ToggleField v-model="settingsForm.staff_meal_include_service" label="إضافة رسوم الخدمة على وجبات الموظفين" description="تبقى مستقلة عن سعر الوجبة وسقف الدين." icon="bi-percent" :disabled="!can.edit || !settingsForm.service_enabled" :warning="!settingsForm.service_enabled ? 'رسوم الخدمة العامة متوقفة حالياً.' : ''" />
                    <label class="field settings-subgrid"><span>عند تجاوز سقف دين الموظف</span><select v-model="settingsForm.staff_meal_over_limit_policy" class="form-select" :disabled="!can.edit"><option value="block">منع الطلب</option><option value="require_approval">طلب موافقة المدير</option><option value="warn">السماح مع تنبيه وتسجيل</option><option value="allow_log">السماح والتسجيل فقط</option></select><small>الخيار المتوازن للمطعم الصغير: السماح مع تنبيه وتسجيل.</small></label>
                </SettingsSection>

                <SettingsSection title="حدود الخصم حسب الدور" description="صفر يعني أن الدور لا يستطيع منح خصم. المديرون المالكون غير مقيدين بهذه الحدود." icon="bi-percent" tone="amber">
                    <div v-if="roles.length" class="role-grid">
                        <article v-for="role in roles" :key="role.id">
                            <div><strong>{{ role.label }}</strong><small>{{ role.custom ? 'دور مخصص' : role.name }}</small></div>
                            <label><span>أقصى نسبة</span><div class="input-suffix"><input v-model.number="settingsForm[role.percentKey]" type="number" min="0" max="100" step="0.01" class="form-control" :disabled="!can.edit"><bdi>%</bdi></div></label>
                            <label><span>أقصى مبلغ</span><div class="input-suffix"><input v-model.number="settingsForm[role.fixedKey]" type="number" min="0" step="0.01" class="form-control" :disabled="!can.edit"><bdi>{{ settingsForm.currency_symbol }}</bdi></div></label>
                        </article>
                    </div>
                    <div v-else class="empty-inline"><i class="bi bi-person-lock"></i><span>لا توجد أدوار قابلة لتحديد الخصم حالياً.</span></div>
                    <template #footer><div class="footer-links"><a :href="urls.roles"><i class="bi bi-shield-lock"></i> إدارة الأدوار والصلاحيات</a><a :href="urls.lookups"><i class="bi bi-tags"></i> فئات الخصم</a></div></template>
                </SettingsSection>
            </div>

            <div v-else class="settings-stack">
                <SettingsSection title="رسائل SMS" description="تستخدم حالياً لاستعادة كلمات المرور. أوقفها إن لم تكن لديك خدمة رسائل." icon="bi-chat-square-text" tone="blue">
                    <ToggleField v-model="settingsForm.sms_enabled" label="تفعيل الرسائل النصية" description="لن يرسل النظام أي رسالة عندما يكون هذا الخيار متوقفاً." icon="bi-phone" :disabled="!can.edit" />
                    <div v-if="settingsForm.sms_enabled" class="sms-fields settings-subgrid">
                        <div class="form-grid">
                            <label class="field"><span>المزوّد</span><input v-model="settingsForm.sms_provider" class="form-control" maxlength="40" :disabled="!can.edit" placeholder="tweetsms"></label>
                            <label class="field"><span>رابط API</span><input v-model="settingsForm.sms_api_url" type="url" class="form-control" maxlength="255" :disabled="!can.edit"><FieldError :message="settingsForm.errors.sms_api_url" /></label>
                            <label class="field"><span>اسم المستخدم</span><input v-model="settingsForm.sms_username" class="form-control" maxlength="120" :disabled="!can.edit" autocomplete="off"></label>
                            <label class="field"><span>كلمة المرور</span><input v-model="settingsForm.sms_password" type="password" class="form-control" maxlength="200" :disabled="!can.edit" autocomplete="new-password" :placeholder="smsPasswordConfigured ? 'محفوظة — اتركها فارغة للإبقاء عليها' : 'أدخل كلمة المرور'"><small v-if="smsPasswordConfigured">كلمة المرور الحالية مشفرة ولن تظهر هنا.</small></label>
                            <label class="field"><span>اسم المرسل</span><input v-model="settingsForm.sms_sender" class="form-control" maxlength="40" :disabled="!can.edit"></label>
                        </div>
                        <div class="template-grid">
                            <label class="field"><span>رسالة استعادة الموظف</span><textarea v-model="settingsForm.sms_template_forgot_staff" class="form-control" rows="5" maxlength="500" :disabled="!can.edit" placeholder="استخدم {brand} و{password} و{login_url}"></textarea><small>المتاح: <bdi>{brand}</bdi>، <bdi>{password}</bdi>، <bdi>{login_url}</bdi></small></label>
                        </div>
                        <div class="sms-test"><div><strong>اختبار الاتصال</strong><span>احفظ بيانات المزوّد أولاً، ثم اختبر الرصيد والاتصال.</span></div><button v-if="can.edit" type="button" class="btn btn-outline-primary" @click="testSms"><i class="bi bi-broadcast"></i> اختبار المزود</button></div>
                    </div>
                </SettingsSection>
            </div>

            <div v-if="can.edit" class="save-dock" :class="{ 'is-dirty': settingsForm.isDirty }">
                <div><i class="bi" :class="settingsForm.isDirty ? 'bi-pencil-square' : 'bi-check2-circle'"></i><span><strong>{{ settingsForm.isDirty ? 'لديك تغييرات غير محفوظة' : 'كل التغييرات محفوظة' }}</strong><small>{{ settingsForm.isDirty ? 'راجع القسم الحالي ثم احفظ دون مغادرة الشاشة.' : 'يمكنك الانتقال بين الأقسام بأمان.' }}</small></span></div>
                <div><button v-if="settingsForm.isDirty" type="button" class="btn btn-light" :disabled="settingsForm.processing" @click="resetUnsaved">تراجع</button><button type="button" class="btn btn-primary" :disabled="settingsForm.processing || !settingsForm.isDirty" @click="saveSettings"><span v-if="settingsForm.processing" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-check2"></i> حفظ</button></div>
            </div>
        </main>
    </div>
</template>

<style scoped>
.settings-notice { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; padding: 12px 15px; border: 1px solid; border-radius: 14px; }
.settings-notice > i { font-size: 1.25rem; }.settings-notice div { display: grid; gap: 1px; }.settings-notice strong { font-size: .88rem; }.settings-notice span { font-size: .78rem; }
.settings-notice--info { color: #245d78; border-color: #c9e3ef; background: #f2f9fc; }.settings-notice--danger { color: #a52626; border-color: #f1c5c5; background: #fff5f5; }
.settings-shell { display: grid; grid-template-columns: 260px minmax(0, 1fr); align-items: start; gap: 18px; }
.settings-rail { position: sticky; top: 82px; overflow: hidden; border: 1px solid #dfe7e2; border-radius: 18px; background: #fff; box-shadow: 0 8px 28px rgba(15, 44, 30, .045); }
.settings-rail__heading { display: grid; gap: 3px; padding: 18px 18px 12px; }.settings-rail__heading strong { color: #183125; font-size: .95rem; }.settings-rail__heading span { color: #829087; font-size: .75rem; }
.settings-search { display: flex; align-items: center; gap: 8px; margin: 0 12px 10px; padding: 9px 11px; border: 1px solid #e1e8e3; border-radius: 11px; color: #829087; background: #f8faf8; }.settings-search input { min-width: 0; width: 100%; border: 0; outline: 0; background: transparent; font-size: .8rem; }
.settings-nav { display: grid; gap: 3px; padding: 0 8px 10px; }.settings-nav button { display: flex; align-items: center; gap: 10px; padding: 11px 10px; border: 0; border-radius: 12px; color: #536158; background: transparent; text-align: start; }.settings-nav button > i:first-child { display: grid; flex: 0 0 34px; width: 34px; height: 34px; place-items: center; border-radius: 10px; background: #f0f4f1; }.settings-nav button span { display: grid; flex: 1; gap: 2px; }.settings-nav button strong { font-size: .82rem; }.settings-nav button small { color: #89958e; font-size: .68rem; }.settings-nav__arrow { font-size: .7rem; opacity: .5; }.settings-nav button.active { color: #126633; background: #edf7f0; }.settings-nav button.active > i:first-child { color: #fff; background: #17713c; }.settings-nav button.active small { color: #5d876c; }.settings-nav__empty { padding: 14px; color: #8b978f; font-size: .76rem; text-align: center; }
.settings-rail__tip { display: flex; align-items: center; gap: 8px; padding: 11px 16px; border-top: 1px solid #edf1ee; color: #8a968e; background: #fbfcfb; font-size: .72rem; }
.settings-content { min-width: 0; scroll-margin-top: 70px; }.settings-content__intro { display: flex; align-items: center; gap: 12px; min-height: 72px; margin-bottom: 12px; padding: 12px 16px; border: 1px solid #dfe7e2; border-radius: 17px; background: linear-gradient(120deg, #f2f8f4, #fff 68%); }.settings-content__icon { display: grid; flex: 0 0 42px; width: 42px; height: 42px; place-items: center; border-radius: 13px; color: #fff; background: #17713c; }.settings-content__intro > div:nth-child(2) { display: grid; gap: 1px; }.settings-content__intro span { color: #829087; font-size: .68rem; }.settings-content__intro h2 { margin: 0; color: #15281d; font-size: 1.05rem; font-weight: 900; }.settings-content__intro p { margin: 0; color: #77847c; font-size: .73rem; }.unsaved-badge { margin-inline-start: auto; padding: 6px 10px; border-radius: 999px; color: #9a5600 !important; background: #fff1da; font-weight: 800; }
.settings-stack { display: grid; gap: 14px; }.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }.form-grid--compact { margin-top: 16px; }.field { display: grid; align-content: start; gap: 6px; color: #34463b; }.field > span, .stage-picker > span { font-size: .79rem; font-weight: 800; }.field > span b { color: #bd2d2d; }.field > small { color: #829087; font-size: .72rem; line-height: 1.55; }.field--read { padding: 12px 14px; border-radius: 12px; background: #f7f9f7; }.field--read strong { color: #21362a; font-size: .9rem; }.field--read a { color: #16713c; font-size: .75rem; font-weight: 800; }.field--wide { grid-column: 1 / -1; }.settings-subgrid { margin-top: 18px; padding-top: 18px; border-top: 1px dashed #dfe7e2; }.toggle-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }.input-suffix { position: relative; }.input-suffix input { padding-inline-end: 58px; }.input-suffix > bdi { position: absolute; inset-inline-end: 12px; top: 50%; color: #718078; font-size: .74rem; transform: translateY(-50%); }
.payment-destinations { display: grid; gap: 16px; }.destination-heading { display: flex; align-items: center; gap: 10px; }.destination-heading > i { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border-radius: 11px; color: #176b39; background: #e8f4ec; }.destination-heading > span { display: grid; gap: 2px; }.destination-heading strong { font-size: .82rem; }.destination-heading small { color: #829087; font-size: .7rem; line-height: 1.55; }.transfer-note { padding-top: 16px; border-top: 1px solid #e6ece8; }
.brand-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }.brand-card { display: grid; grid-template-columns: 76px 1fr; align-items: center; gap: 10px 12px; padding: 14px; border: 1px solid #e2e9e4; border-radius: 14px; }.brand-card > input, .brand-card > .field-error, .brand-card > button { grid-column: 1 / -1; }.brand-card > div:nth-child(2) { display: grid; gap: 3px; }.brand-card strong { font-size: .85rem; }.brand-card small { color: #829087; font-size: .7rem; }.brand-preview { display: grid; width: 76px; height: 58px; place-items: center; overflow: hidden; border: 1px dashed #cdd9d1; border-radius: 11px; background: #f8faf8; }.brand-preview img { max-width: 90%; max-height: 80%; object-fit: contain; }.brand-preview--favicon { width: 58px; height: 58px; }.text-action { width: fit-content; padding: 0; border: 0; color: #19733e; background: transparent; font-size: .76rem; font-weight: 800; }.text-action--danger { color: #bd2d2d; }
.theme-layout { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(260px, .85fr); gap: 18px; }.preset-list { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 14px; }.preset-list button { display: flex; align-items: center; gap: 7px; padding: 7px 10px; border: 1px solid #dfe7e2; border-radius: 999px; background: #fff; font-size: .72rem; font-weight: 800; }.preset-list button > span { display: flex; flex-direction: row-reverse; }.preset-list button i { width: 12px; height: 12px; margin-left: -3px; border: 1px solid #fff; border-radius: 50%; }.color-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }.color-field { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border: 1px solid #e2e9e4; border-radius: 11px; }.color-field input { width: 34px; height: 34px; padding: 2px; border: 0; background: transparent; }.color-field span { display: grid; font-size: .74rem; font-weight: 800; }.color-field bdi { color: #8b978f; font-size: .62rem; font-weight: 500; }.theme-preview { overflow: hidden; min-height: 295px; border: 1px solid #dce5df; border-radius: 17px; background: var(--m); box-shadow: 0 14px 30px rgba(23, 55, 37, .09); }.theme-preview__header { display: flex; align-items: center; gap: 8px; padding: 12px; color: var(--d); background: var(--h); }.theme-preview__header img { width: 28px; height: 28px; object-fit: contain; }.theme-preview__header span { flex: 1; font-size: .75rem; font-weight: 900; }.theme-preview__hero { display: grid; min-height: 154px; align-content: center; justify-items: center; gap: 6px; padding: 20px; color: #fff; background: linear-gradient(140deg, var(--d), var(--p)); }.theme-preview__hero small { opacity: .8; }.theme-preview__hero strong { font-size: 1rem; }.theme-preview__hero button { padding: 7px 14px; border: 0; border-radius: 9px; color: #fff; background: var(--a); font-size: .7rem; }.theme-preview__cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 12px; }.theme-preview__cards i { height: 70px; border-radius: 10px; background: color-mix(in srgb, var(--p) 13%, white); }
.currency-list { display: grid; gap: 8px; }.currency-row { display: grid; grid-template-columns: minmax(110px, 1fr) minmax(180px, 1fr) auto auto; align-items: end; gap: 12px; padding: 12px; border: 1px solid #e2e9e4; border-radius: 13px; }.currency-row.is-base { grid-template-columns: 1fr auto; align-items: center; background: #f3f8f4; }.currency-code { display: grid; }.currency-code strong { font-size: .9rem; }.currency-code small { color: #849087; font-size: .7rem; }.currency-row > label:not(.currency-active) { display: grid; gap: 4px; }.currency-row > label > span { color: #718078; font-size: .68rem; }.currency-base { padding: 5px 9px; border-radius: 999px; color: #176a38; background: #dff1e5; font-size: .68rem; font-weight: 800; }.currency-active { display: flex; align-items: center; gap: 5px; padding-bottom: 10px; }.icon-action { display: grid; width: 36px; height: 36px; place-items: center; border: 1px solid #dfe7e2; border-radius: 10px; background: #fff; }.icon-action--danger { color: #bb2b2b; }.settings-disclosure { margin-top: 12px; border: 1px solid #e2e9e4; border-radius: 13px; background: #fbfcfb; }.settings-disclosure summary { display: flex; align-items: center; gap: 10px; padding: 13px 14px; cursor: pointer; list-style: none; }.settings-disclosure summary > i { color: #17713c; }.settings-disclosure summary > span { display: grid; }.settings-disclosure summary strong { font-size: .8rem; }.settings-disclosure summary small { color: #839087; font-size: .68rem; }.settings-disclosure__body { padding: 15px; border-top: 1px solid #e5ebe7; }.rate-history { display: grid; border-top: 1px solid #e5ebe7; }.rate-history article { display: grid; grid-template-columns: 105px 100px 1fr 1fr auto; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid #edf1ee; font-size: .72rem; }.rate-history article:last-child { border: 0; }.rate-history small { color: #829087; }
.stage-picker > div { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-top: 8px; }.stage-picker label { display: grid; gap: 3px; padding: 12px; border: 1px solid #dfe7e2; border-radius: 12px; cursor: pointer; }.stage-picker label.active { border-color: #8cc29e; background: #f1f8f3; box-shadow: inset 0 0 0 1px #b9dac4; }.stage-picker input { accent-color: #17713c; }.stage-picker strong { font-size: .78rem; }.stage-picker small { color: #839087; font-size: .66rem; }.status-grid { display: grid; gap: 8px; }.status-grid article { display: grid; grid-template-columns: 12px minmax(120px, .8fr) minmax(180px, 1.4fr) minmax(140px, .7fr); align-items: center; gap: 10px; padding: 9px 10px; border: 1px solid #e5ebe7; border-radius: 12px; }.status-dot { width: 10px; height: 10px; border-radius: 50%; }.status-grid article > div { display: grid; }.status-grid strong { font-size: .75rem; }.status-grid small { color: #8b978f; font-size: .62rem; }
.staff-management-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }.staff-management-links a { display: grid; min-height: 82px; grid-template-columns: 42px minmax(0, 1fr) auto; align-items: center; gap: 10px; padding: 12px; border: 1px solid #dbe7e0; border-radius: 13px; color: #244535; background: #f9fbfa; text-decoration: none; }.staff-management-links a > i:first-child { display: grid; width: 42px; height: 42px; place-items: center; border-radius: 11px; color: #17713c; background: #e8f4ed; }.staff-management-links a > span { display: grid; }.staff-management-links strong { font-size: .76rem; }.staff-management-links small { margin-top: 2px; color: #7b8981; font-size: .62rem; line-height: 1.5; }.staff-management-links a > i:last-child { color: #7b9a89; }.settings-inline-note { display: flex; align-items: flex-start; gap: 7px; margin: 12px 2px 0; color: #718078; font-size: .66rem; line-height: 1.7; }.settings-inline-note i { margin-top: 2px; color: #17713c; }
.role-grid { display: grid; gap: 8px; }.role-grid article { display: grid; grid-template-columns: minmax(130px, 1fr) minmax(150px, .8fr) minmax(150px, .8fr); align-items: end; gap: 12px; padding: 12px; border: 1px solid #e3eae5; border-radius: 13px; }.role-grid article > div { display: grid; align-self: center; }.role-grid article > div strong { font-size: .83rem; }.role-grid article > div small { color: #8a968e; font-size: .67rem; }.role-grid label { display: grid; gap: 4px; }.role-grid label > span { color: #718078; font-size: .68rem; }.empty-inline { display: flex; align-items: center; gap: 8px; padding: 16px; border-radius: 12px; color: #78867d; background: #f7f9f7; font-size: .8rem; }.footer-links { display: flex; flex-wrap: wrap; gap: 14px; }.footer-links a { color: #176b39; font-size: .75rem; font-weight: 800; }
.sms-fields { border-top-style: dashed; }.template-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }.sms-test { display: flex; align-items: center; gap: 12px; margin-top: 14px; padding: 13px; border: 1px solid #dfe7e2; border-radius: 12px; background: #f8faf8; }.sms-test > div { display: grid; flex: 1; }.sms-test strong { font-size: .8rem; }.sms-test span { color: #829087; font-size: .7rem; }
.save-dock { position: sticky; z-index: 20; bottom: 12px; display: flex; align-items: center; gap: 16px; margin-top: 14px; padding: 11px 13px; border: 1px solid #d9e4dc; border-radius: 15px; background: rgba(255, 255, 255, .94); box-shadow: 0 12px 38px rgba(15, 45, 28, .15); backdrop-filter: blur(12px); }.save-dock.is-dirty { border-color: #e6c896; }.save-dock > div { display: flex; align-items: center; gap: 9px; }.save-dock > div:first-child { flex: 1; }.save-dock > div:first-child > i { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 10px; color: #17713c; background: #e8f4ec; }.save-dock.is-dirty > div:first-child > i { color: #9c5a00; background: #fff1d9; }.save-dock > div > span { display: grid; }.save-dock strong { font-size: .76rem; }.save-dock small { color: #829087; font-size: .66rem; }
@media (max-width: 1100px) { .settings-shell { grid-template-columns: 225px minmax(0, 1fr); }.theme-layout { grid-template-columns: 1fr; }.stage-picker > div { grid-template-columns: repeat(2, minmax(0, 1fr)); }.status-grid article { grid-template-columns: 12px 120px 1fr; }.status-grid article select { grid-column: 3; }.rate-history article { grid-template-columns: 90px 90px 1fr auto; }.rate-history article small { grid-column: 1 / -1; }.team-role-picker { grid-template-columns: repeat(2, minmax(0, 1fr)); }.team-create-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }.team-create-form__intro { border-inline-end: 0; }.team-create-form__submit { align-self: end; }.created-credentials { grid-template-columns: auto 1fr auto; }.created-credentials dl { grid-column: 2 / -1; } }
@media (max-width: 860px) { .settings-shell { display: block; }.settings-rail { position: sticky; z-index: 15; top: 58px; margin-bottom: 12px; border-radius: 14px; }.settings-rail__heading, .settings-rail__tip { display: none; }.settings-search { margin-top: 10px; }.settings-nav { display: flex; overflow-x: auto; padding: 0 10px 10px; scrollbar-width: none; }.settings-nav button { flex: 0 0 auto; padding: 8px 10px; }.settings-nav button > i:first-child { width: 30px; height: 30px; flex-basis: 30px; }.settings-nav button span { display: block; }.settings-nav button small, .settings-nav__arrow { display: none; }.settings-content__intro { display: none; }.settings-content { scroll-margin-top: 150px; } }
@media (max-width: 640px) { .form-grid, .toggle-grid, .brand-grid, .template-grid, .role-grid article, .team-role-picker, .team-create-form { grid-template-columns: 1fr; }.field--wide { grid-column: auto; }.theme-layout { grid-template-columns: 1fr; }.color-grid { grid-template-columns: 1fr; }.currency-row, .currency-row.is-base { grid-template-columns: 1fr auto; align-items: center; }.currency-row > label:not(.currency-active) { grid-column: 1 / -1; }.currency-active { padding: 0; }.stage-picker > div { grid-template-columns: 1fr; }.status-grid article { grid-template-columns: 12px 1fr; }.status-grid article input, .status-grid article select { grid-column: 1 / -1; }.rate-history article { grid-template-columns: 1fr auto; }.rate-history article span, .rate-history article small { grid-column: 1 / -1; }.save-dock > div:first-child small { display: none; }.save-dock { bottom: 6px; }.save-dock .btn { padding-inline: 10px; }.sms-test, .demo-cleanup { align-items: stretch; flex-direction: column; }.created-credentials { grid-template-columns: auto 1fr; }.created-credentials dl { grid-column: 1 / -1; }.created-credentials > button { grid-column: 1 / -1; }.team-create-form__intro { padding: 0 0 10px; border-bottom: 1px solid #e1e9e4; }.team-create-form__submit { width: 100%; } }
</style>
