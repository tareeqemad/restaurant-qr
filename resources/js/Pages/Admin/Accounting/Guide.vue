<script setup>
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    postingRoles: { type: Object, default: () => ({}) },
    postingGroups: { type: Array, default: () => [] },
    paymentPaths: { type: Array, default: () => [] },
    workflow: { type: Array, default: () => [] },
    accounting: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
})

const roleGroups = computed(() => Object.entries(props.postingRoles).map(([name, roles]) => ({ name, roles })))
const activeGroupKey = ref(props.postingGroups[0]?.key ?? '')
const activeGroup = computed(() => props.postingGroups.find((group) => group.key === activeGroupKey.value) ?? props.postingGroups[0])
const totalEntries = computed(() => props.postingGroups.reduce((total, group) => total + group.entries.length, 0))

function statusIcon(status) {
    return {
        ready: 'bi-check2-circle',
        warning: 'bi-exclamation-triangle',
        action: 'bi-arrow-left-circle',
        current: 'bi-clock',
    }[status] ?? 'bi-circle'
}
</script>

<template>
    <Head title="دليل المحاسب" />
    <PageHeader
        title="دليل المحاسب"
        icon="bi-signpost-split-fill"
        subtitle="المسار الصحيح من التأسيس إلى الإقفال، وكل قيد ينشئه النظام من مصدره"
    />
    <AccountingNav :urls="urls" active="guide" />

    <section class="guide-hero">
        <div class="guide-hero__copy">
            <span class="guide-hero__eyebrow"><i class="bi bi-shield-check"></i> الدفتر هو المرجع النهائي</span>
            <h2>ابدأ من المستند، ودع النظام ينشئ القيد</h2>
            <p>
                الفاتورة والدفعة والاستلام والهدر والمصروف هي مصدر الحقيقة. القيد اليدوي للحركة المستقلة أو التصحيح
                الموثق فقط، ولا يُستخدم لتكرار عملية رحّلها النظام.
            </p>
            <div class="guide-hero__actions">
                <Link :href="urls.journal" class="guide-button guide-button--primary">
                    <i class="bi bi-journal-text"></i> راجع القيود
                </Link>
                <Link v-if="urls.accounts" :href="urls.accounts" class="guide-button">
                    <i class="bi bi-diagram-3"></i> افتح شجرة الحسابات
                </Link>
            </div>
        </div>
        <div class="guide-hero__facts">
            <article>
                <i class="bi bi-currency-exchange"></i>
                <span><small>عملة الدفتر</small><strong><bdi>{{ accounting.baseCurrency }}</bdi></strong></span>
            </article>
            <article>
                <i class="bi bi-calendar2-week"></i>
                <span><small>الفترة الحالية</small><strong>{{ accounting.currentPeriod?.name || 'غير مهيأة' }}</strong></span>
            </article>
            <article :class="{ muted: !accounting.taxEnabled }">
                <i class="bi bi-percent"></i>
                <span>
                    <small>ضريبة فاتورة الزبون</small>
                    <strong>{{ accounting.taxEnabled ? `${accounting.taxRate}% مفعّلة` : 'متوقفة' }}</strong>
                </span>
            </article>
            <article :class="{ warning: accounting.missingMappings > 0 }">
                <i class="bi bi-link-45deg"></i>
                <span><small>ربط الحسابات</small><strong>{{ accounting.missingMappings ? `${accounting.missingMappings} ناقص` : 'مكتمل' }}</strong></span>
            </article>
        </div>
    </section>

    <section class="workflow-section" aria-labelledby="workflow-title">
        <header class="section-heading">
            <div>
                <span>الترتيب المحاسبي الصحيح</span>
                <h2 id="workflow-title">مسار العمل في ست خطوات</h2>
            </div>
            <p>نفّذ التأسيس مرة واحدة، ثم كرّر التسجيل والمراجعة والإقفال لكل فترة.</p>
        </header>
        <div class="workflow-track">
            <component
                :is="step.url ? Link : 'article'"
                v-for="step in workflow"
                :key="step.key"
                :href="step.url || undefined"
                class="workflow-step"
                :data-state="step.status"
                preserve-scroll
            >
                <span class="workflow-step__number">{{ step.number }}</span>
                <span class="workflow-step__icon"><i class="bi" :class="step.icon"></i></span>
                <div>
                    <strong>{{ step.title }}</strong>
                    <p>{{ step.description }}</p>
                    <small><i class="bi" :class="statusIcon(step.status)"></i> {{ step.statusLabel }}</small>
                </div>
                <i v-if="step.url" class="bi bi-chevron-left workflow-step__arrow"></i>
            </component>
        </div>
    </section>

    <div class="guide-layout">
        <main>
            <AccountingPanel
                title="ماذا يرحّل النظام؟"
                :description="`${totalEntries} حالة موثقة حسب المصدر والحساب الفعلي`"
                icon="bi-lightning-charge-fill"
            >
                <div class="posting-tabs" role="tablist" aria-label="أنواع القيود">
                    <button
                        v-for="group in postingGroups"
                        :key="group.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeGroup?.key === group.key"
                        :class="{ active: activeGroup?.key === group.key }"
                        @click="activeGroupKey = group.key"
                    >
                        <i class="bi" :class="group.icon"></i>
                        <span>{{ group.label }}</span>
                        <b>{{ group.entries.length }}</b>
                    </button>
                </div>

                <section v-if="activeGroup" class="posting-group">
                    <header>
                        <span><i class="bi" :class="activeGroup.icon"></i></span>
                        <div><strong>{{ activeGroup.label }}</strong><small>{{ activeGroup.description }}</small></div>
                    </header>

                    <div class="posting-list">
                        <details
                            v-for="(entry, index) in activeGroup.entries"
                            :key="entry.eventType"
                            class="posting-entry"
                            :open="index === 0"
                        >
                            <summary>
                                <span class="posting-entry__state" :class="{ manual: !entry.automatic }">
                                    <i class="bi" :class="entry.automatic ? 'bi-lightning-charge-fill' : 'bi-person-check-fill'"></i>
                                    {{ entry.automatic ? 'تلقائي' : 'بقرار المحاسب' }}
                                </span>
                                <span class="posting-entry__title"><strong>{{ entry.title }}</strong><small>{{ entry.trigger }}</small></span>
                                <i class="bi bi-chevron-down posting-entry__chevron"></i>
                            </summary>
                            <div class="posting-entry__body">
                                <div class="posting-sides">
                                    <section class="posting-side posting-side--debit">
                                        <header><span>مدين</span><small>القيمة تدخل إلى</small></header>
                                        <article v-for="(line, lineIndex) in entry.debits" :key="`${entry.eventType}-d-${lineIndex}`">
                                            <div><strong>{{ line.label }}</strong><small v-if="line.condition">{{ line.condition }}</small></div>
                                            <span v-if="line.account"><bdi>{{ line.account.code }}</bdi>{{ line.account.name }}</span>
                                            <span v-else class="variable">حسب المستند</span>
                                        </article>
                                    </section>
                                    <section class="posting-side posting-side--credit">
                                        <header><span>دائن</span><small>القيمة تخرج من</small></header>
                                        <article v-for="(line, lineIndex) in entry.credits" :key="`${entry.eventType}-c-${lineIndex}`">
                                            <div><strong>{{ line.label }}</strong><small v-if="line.condition">{{ line.condition }}</small></div>
                                            <span v-if="line.account"><bdi>{{ line.account.code }}</bdi>{{ line.account.name }}</span>
                                            <span v-else class="variable">حسب المستند</span>
                                        </article>
                                    </section>
                                </div>
                                <div class="posting-entry__note">
                                    <i class="bi bi-info-circle"></i>
                                    <p>{{ entry.note }}</p>
                                    <Link :href="entry.journalUrl" preserve-scroll>اعرض القيود <i class="bi bi-arrow-left"></i></Link>
                                </div>
                            </div>
                        </details>
                    </div>
                </section>
            </AccountingPanel>
        </main>

        <aside class="guide-aside">
            <section class="rule-card rule-card--primary">
                <span><i class="bi bi-journal-check"></i></span>
                <div>
                    <small>القاعدة الذهبية</small>
                    <strong>صحّح المستند من مصدره</strong>
                    <p>إلغاء فاتورة أو دفعة أو فاتورة مورد ينشئ العكس المناسب. لا تعالجها بقيد يدوي موازٍ.</p>
                </div>
            </section>

            <section class="rule-card">
                <header><i class="bi bi-pencil-square"></i><strong>متى أستخدم القيد اليدوي؟</strong></header>
                <ul>
                    <li>تسوية مستقلة لا يوجد لها مستند تشغيلي.</li>
                    <li>إثبات رأس مال أو سحب مالك بعد الافتتاح.</li>
                    <li>تصحيح موثق عبر عكس القيد الخطأ ثم الصحيح.</li>
                    <li>قيد يطلبه المحاسب مع مرجع ومرفق واضح.</li>
                </ul>
                <Link v-if="urls.manualEntry" :href="urls.manualEntry" class="aside-link">
                    قيد يدوي <i class="bi bi-arrow-left"></i>
                </Link>
            </section>

            <section class="rule-card">
                <header><i class="bi bi-currency-dollar"></i><strong>الشيكل والدولار</strong></header>
                <p>
                    يُحفظ مبلغ العملية بعملته الأصلية وسعر الصرف في تاريخ القيد، ويُرحّل دفتر الأستاذ بعملة الأساس
                    <bdi>{{ accounting.baseCurrency }}</bdi>. لا تغيّر سعراً قديماً لتصحيح قيد مرحّل.
                </p>
            </section>

            <section class="rule-card rule-card--warning">
                <header><i class="bi bi-lock"></i><strong>قبل إقفال الشهر</strong></header>
                <ol>
                    <li>أغلق جلسات وفواتير التشغيل.</li>
                    <li>راجع ميزان المراجعة والذمم.</li>
                    <li>طابق الصندوق والبنك والمحافظ.</li>
                    <li>رحّل الهدر والجرد والإهلاك.</li>
                </ol>
                <Link v-if="urls.periods" :href="urls.periods" class="aside-link">قائمة الإقفال <i class="bi bi-arrow-left"></i></Link>
            </section>
        </aside>
    </div>

    <div class="guide-panels">
        <AccountingPanel
            title="مسار كل وسيلة دفع"
            description="كود الدفتر جاهز، أما رقم البنك أو المحفظة الحقيقي فيُسجّل من إدارة الربط"
            icon="bi-wallet2"
        >
            <div class="payment-paths">
                <article v-for="method in paymentPaths" :key="method.code" :class="{ disabled: !method.enabled }">
                    <span><i class="bi" :class="method.icon"></i></span>
                    <div>
                        <header><strong>{{ method.label }}</strong><em>{{ method.enabled ? 'مفعّلة' : 'متوقفة' }}</em></header>
                        <p>{{ method.description }}</p>
                        <small v-if="method.account"><bdi>{{ method.account.code }}</bdi> · {{ method.account.name }}</small>
                        <small v-else class="missing"><i class="bi bi-exclamation-triangle"></i> حساب الدفتر غير موجود</small>
                        <small v-if="method.destination" class="destination"><i class="bi bi-check2-circle"></i> {{ method.destination }}</small>
                        <small v-else-if="method.code !== 'cash'" class="destination missing"><i class="bi bi-info-circle"></i> لم تُسجّل بيانات الاستقبال الفعلية</small>
                    </div>
                </article>
            </div>
            <template #footer>
                <Link v-if="urls.settlements" :href="urls.settlements" class="panel-link">
                    أرصدة المحافظ والتحويل إلى البنك <i class="bi bi-arrow-left"></i>
                </Link>
            </template>
        </AccountingPanel>

        <hr class="guide-divider">

        <AccountingPanel
            title="الحسابات التي يستخدمها النظام"
            description="الأرقام مثل 1000 و1010 أكواد دفتر داخلية؛ تغيير الربط يؤثر في القيود الجديدة فقط"
            icon="bi-diagram-3-fill"
            compact
        >
            <div class="role-groups">
                <details v-for="group in roleGroups" :key="group.name">
                    <summary><strong>{{ group.name }}</strong><span>{{ group.roles.length }} حساباً</span><i class="bi bi-chevron-down"></i></summary>
                    <div>
                        <article v-for="role in group.roles" :key="role.key">
                            <span><strong>{{ role.label }}</strong><small>{{ role.description }}</small></span>
                            <b v-if="role.account"><bdi>{{ role.account.code }}</bdi>{{ role.account.name }}<em v-if="role.isCustom">مخصص</em></b>
                            <b v-else class="missing"><i class="bi bi-exclamation-triangle"></i> غير مربوط</b>
                        </article>
                    </div>
                </details>
            </div>
            <template #footer>
                <a v-if="urls.mappings" :href="urls.mappings" class="panel-link panel-link--button">
                    إدارة ربط الحسابات وبيانات البنك <i class="bi bi-sliders"></i>
                </a>
            </template>
        </AccountingPanel>
    </div>
</template>

<style scoped>
.guide-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(300px, .75fr);
    gap: 18px;
    margin-bottom: 16px;
    padding: 22px;
    border: 1px solid #d7e7dc;
    border-radius: 20px;
    background: linear-gradient(135deg, #f1f8f4 0%, #fff 62%);
    box-shadow: 0 14px 38px rgba(34, 73, 50, .06);
}

.guide-hero__copy { display: grid; align-content: center; justify-items: start; }
.guide-hero__eyebrow { display: flex; align-items: center; gap: 7px; margin-bottom: 8px; color: rgb(var(--primary-rgb, 31, 107, 80)); font-size: .68rem; font-weight: 900; }
.guide-hero h2 { margin: 0 0 6px; color: #183628; font-size: clamp(1.15rem, 2vw, 1.55rem); font-weight: 950; }
.guide-hero p { max-width: 780px; margin: 0; color: #68786e; font-size: .75rem; line-height: 1.8; }
.guide-hero__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
.guide-button { display: inline-flex; min-height: 44px; align-items: center; gap: 7px; padding: 9px 14px; border: 1px solid #d8e4dc; border-radius: 11px; color: #41584b; background: #fff; font-size: .7rem; font-weight: 900; }
.guide-button--primary { border-color: rgb(var(--primary-rgb, 31, 107, 80)); color: #fff; background: rgb(var(--primary-rgb, 31, 107, 80)); }
.guide-hero__facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.guide-hero__facts article { display: flex; min-height: 76px; align-items: center; gap: 10px; padding: 12px; border: 1px solid #e1e9e4; border-radius: 14px; background: rgba(255, 255, 255, .86); }
.guide-hero__facts article > i { display: grid; flex: 0 0 34px; width: 34px; height: 34px; place-items: center; border-radius: 10px; color: rgb(var(--primary-rgb, 31, 107, 80)); background: #e9f4ed; }
.guide-hero__facts article span { display: grid; min-width: 0; gap: 2px; }
.guide-panels { display: grid; gap: 18px; margin-top: 18px; }
.guide-divider { width: 100%; height: 1px; margin: 0; border: 0; background: linear-gradient(90deg, transparent, #d8e2dc 12%, #d8e2dc 88%, transparent); }
.guide-hero__facts small { color: #839087; font-size: .58rem; }
.guide-hero__facts strong { overflow: hidden; color: #2e4337; font-size: .69rem; text-overflow: ellipsis; white-space: nowrap; }
.guide-hero__facts article.muted { opacity: .74; }
.guide-hero__facts article.warning { border-color: #efd4a9; background: #fffaf2; }

.workflow-section { margin-bottom: 16px; padding: 18px; border: 1px solid #e0e8e2; border-radius: 18px; background: #fff; }
.section-heading { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
.section-heading > div { display: grid; gap: 2px; }
.section-heading span { color: #7b8a80; font-size: .6rem; font-weight: 850; }
.section-heading h2 { margin: 0; font-size: .96rem; font-weight: 950; }
.section-heading p { max-width: 500px; margin: 0; color: #829087; font-size: .65rem; line-height: 1.6; }
.workflow-track { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; }
.workflow-step { position: relative; display: grid; min-width: 0; min-height: 168px; align-content: start; gap: 9px; padding: 12px; border: 1px solid #e1e8e3; border-radius: 14px; color: inherit; background: #fbfcfb; transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease; }
a.workflow-step:hover { transform: translateY(-2px); border-color: #a7cab2; box-shadow: 0 9px 22px rgba(35, 75, 50, .07); }
.workflow-step__number { position: absolute; inset-block-start: 9px; inset-inline-end: 9px; display: grid; width: 23px; height: 23px; place-items: center; border-radius: 999px; color: #6e7b73; background: #edf2ef; font-size: .59rem; font-weight: 950; }
.workflow-step__icon { display: grid; width: 37px; height: 37px; place-items: center; border-radius: 11px; color: rgb(var(--primary-rgb, 31, 107, 80)); background: #e9f4ed; }
.workflow-step > div { display: grid; gap: 5px; }
.workflow-step strong { padding-inline-end: 16px; font-size: .68rem; }
.workflow-step p { margin: 0; color: #7a887f; font-size: .58rem; line-height: 1.55; }
.workflow-step small { display: flex; align-items: center; gap: 5px; margin-top: 3px; color: #4c705b; font-size: .56rem; font-weight: 850; }
.workflow-step[data-state="warning"] small,
.workflow-step[data-state="action"] small { color: #a85b08; }
.workflow-step__arrow { position: absolute; inset-block-end: 11px; inset-inline-end: 12px; color: #a3afa7; font-size: .65rem; }

.guide-layout { display: grid; grid-template-columns: minmax(0, 1fr) 286px; align-items: start; gap: 14px; }
.guide-aside { position: sticky; top: 204px; display: grid; gap: 10px; }
.rule-card { padding: 14px; border: 1px solid #e0e7e2; border-radius: 15px; background: #fff; }
.rule-card > header { display: flex; align-items: center; gap: 8px; margin-bottom: 9px; color: #30483a; font-size: .7rem; }
.rule-card > header i { color: rgb(var(--primary-rgb, 31, 107, 80)); }
.rule-card p { margin: 0; color: #718077; font-size: .64rem; line-height: 1.75; }
.rule-card ul, .rule-card ol { display: grid; gap: 6px; margin: 0; padding-inline-start: 18px; color: #607168; font-size: .62rem; line-height: 1.55; }
.rule-card--primary { display: flex; gap: 11px; border-color: #a9d0b6; background: linear-gradient(135deg, #edf8f1, #fff); }
.rule-card--primary > span { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border-radius: 11px; color: #fff; background: rgb(var(--primary-rgb, 31, 107, 80)); }
.rule-card--primary div { display: grid; gap: 3px; }
.rule-card--primary small { color: #72877a; font-size: .55rem; }
.rule-card--primary strong { color: #244633; font-size: .73rem; }
.rule-card--warning { border-color: #efd5ab; background: #fffaf2; }
.aside-link, .panel-link { display: inline-flex; min-height: 40px; align-items: center; gap: 6px; margin-top: 10px; color: rgb(var(--primary-rgb, 31, 107, 80)); font-size: .65rem; font-weight: 900; }
.panel-link--button { min-height: 42px; padding: 9px 13px; border: 1px solid color-mix(in srgb, rgb(var(--primary-rgb, 31, 107, 80)) 32%, white); border-radius: 11px; background: color-mix(in srgb, rgb(var(--primary-rgb, 31, 107, 80)) 8%, white); }

.posting-tabs { display: flex; gap: 7px; margin-bottom: 12px; overflow-x: auto; scrollbar-width: thin; }
.posting-tabs button { display: flex; flex: 1 0 150px; min-height: 48px; align-items: center; gap: 7px; padding: 8px 10px; border: 1px solid #dfe7e2; border-radius: 12px; color: #63736a; background: #fff; text-align: start; }
.posting-tabs button.active { border-color: #8fbea0; color: rgb(var(--primary-rgb, 31, 107, 80)); background: #edf7f0; box-shadow: inset 0 -2px rgb(var(--primary-rgb, 31, 107, 80)); }
.posting-tabs button span { font-size: .64rem; font-weight: 900; }
.posting-tabs button b { display: grid; min-width: 20px; height: 20px; margin-inline-start: auto; place-items: center; border-radius: 999px; background: #edf2ef; font-size: .55rem; }
.posting-group > header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 10px 12px; border-radius: 12px; background: #f7f9f8; }
.posting-group > header > span { display: grid; width: 35px; height: 35px; place-items: center; border-radius: 10px; color: rgb(var(--primary-rgb, 31, 107, 80)); background: #e7f2eb; }
.posting-group > header > div { display: grid; gap: 2px; }
.posting-group > header strong { font-size: .72rem; }
.posting-group > header small { color: #7c8981; font-size: .6rem; }
.posting-list { display: grid; gap: 7px; }
.posting-entry { overflow: hidden; border: 1px solid #e0e7e2; border-radius: 13px; background: #fff; }
.posting-entry summary { display: flex; min-height: 60px; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; list-style: none; }
.posting-entry summary::-webkit-details-marker { display: none; }
.posting-entry[open] summary { border-bottom: 1px solid #edf1ee; background: #fbfcfb; }
.posting-entry__state { display: inline-flex; flex: 0 0 76px; min-height: 30px; align-items: center; justify-content: center; gap: 5px; border-radius: 9px; color: #256b4d; background: #e8f5ec; font-size: .56rem; font-weight: 900; }
.posting-entry__state.manual { color: #84570d; background: #fff2da; }
.posting-entry__title { display: grid; min-width: 0; gap: 2px; }
.posting-entry__title strong { color: #263e31; font-size: .7rem; }
.posting-entry__title small { overflow: hidden; color: #7c8981; font-size: .59rem; text-overflow: ellipsis; white-space: nowrap; }
.posting-entry__chevron { margin-inline-start: auto; color: #8c9991; transition: transform .15s ease; }
.posting-entry[open] .posting-entry__chevron { transform: rotate(180deg); }
.posting-entry__body { padding: 12px; }
.posting-sides { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
.posting-side { overflow: hidden; border: 1px solid #e2e8e4; border-radius: 11px; }
.posting-side > header { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; background: #f7f9f8; }
.posting-side > header span { color: #315f46; font-size: .63rem; font-weight: 950; }
.posting-side--credit > header span { color: #8c5b12; }
.posting-side > header small { color: #88948c; font-size: .53rem; }
.posting-side article { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 8px; padding: 9px 10px; border-top: 1px solid #edf1ee; }
.posting-side article > div { display: grid; gap: 2px; }
.posting-side article strong { font-size: .61rem; }
.posting-side article small { color: #89958d; font-size: .53rem; }
.posting-side article > span { display: flex; align-items: center; gap: 5px; color: #43594d; font-size: .57rem; }
.posting-side article bdi { padding: 2px 5px; border-radius: 6px; color: rgb(var(--primary-rgb, 31, 107, 80)); background: #eaf5ee; font-weight: 950; }
.posting-side article .variable { color: #8a7560; font-size: .54rem; }
.posting-entry__note { display: flex; align-items: flex-start; gap: 7px; margin-top: 9px; padding: 9px 10px; border-radius: 10px; background: #f5f8f6; }
.posting-entry__note > i { color: #729080; }
.posting-entry__note p { flex: 1; margin: 0; color: #6c7a72; font-size: .58rem; line-height: 1.6; }
.posting-entry__note a { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 5px; color: rgb(var(--primary-rgb, 31, 107, 80)); font-size: .56rem; font-weight: 900; }

.payment-paths { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; }
.payment-paths article { display: flex; gap: 9px; padding: 12px; border: 1px solid #e0e7e2; border-radius: 13px; background: #fff; }
.payment-paths article.disabled { opacity: .62; background: #f8faf9; }
.payment-paths article > span { display: grid; flex: 0 0 34px; width: 34px; height: 34px; place-items: center; border-radius: 10px; color: rgb(var(--primary-rgb, 31, 107, 80)); background: #eaf4ee; }
.payment-paths article > div { display: grid; min-width: 0; align-content: start; gap: 5px; }
.payment-paths header { display: flex; align-items: center; gap: 6px; }
.payment-paths strong { font-size: .65rem; }
.payment-paths em { padding: 2px 5px; border-radius: 999px; color: #4b6a58; background: #edf3ef; font-size: .49rem; font-style: normal; }
.payment-paths p { margin: 0; color: #7a887f; font-size: .55rem; line-height: 1.5; }
.payment-paths small { color: #4a6254; font-size: .55rem; }
.payment-paths bdi { color: rgb(var(--primary-rgb, 31, 107, 80)); font-weight: 950; }
.payment-paths .missing, .role-groups .missing { color: #ac5c0b; }
.payment-paths .destination { display: flex; align-items: flex-start; gap: 4px; padding-top: 5px; border-top: 1px dashed #e4eae6; color: #315f49; line-height: 1.45; }

.role-groups { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.role-groups details { overflow: hidden; border: 1px solid #e0e7e2; border-radius: 12px; background: #fff; }
.role-groups summary { display: flex; min-height: 46px; align-items: center; gap: 8px; padding: 9px 11px; cursor: pointer; list-style: none; }
.role-groups summary::-webkit-details-marker { display: none; }
.role-groups summary strong { font-size: .66rem; }
.role-groups summary span { margin-inline-start: auto; color: #819087; font-size: .55rem; }
.role-groups summary i { color: #93a097; font-size: .58rem; transition: transform .15s ease; }
.role-groups details[open] summary i { transform: rotate(180deg); }
.role-groups details > div { border-top: 1px solid #edf1ee; }
.role-groups article { display: grid; grid-template-columns: minmax(0, 1fr) minmax(145px, .65fr); align-items: center; gap: 10px; padding: 9px 11px; border-top: 1px solid #edf1ee; }
.role-groups article:first-child { border-top: 0; }
.role-groups article > span { display: grid; gap: 2px; }
.role-groups article strong { font-size: .6rem; }
.role-groups article small { color: #7e8b83; font-size: .53rem; line-height: 1.45; }
.role-groups article > b { display: flex; align-items: center; justify-content: flex-end; gap: 5px; color: #3d5548; font-size: .57rem; font-weight: 700; }
.role-groups article bdi { color: rgb(var(--primary-rgb, 31, 107, 80)); font-weight: 950; }
.role-groups article em { padding: 2px 5px; border-radius: 999px; color: #286a4c; background: #e8f3ec; font-size: .48rem; font-style: normal; }

@media (max-width: 1180px) {
    .workflow-track { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .payment-paths { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}

@media (max-width: 920px) {
    .guide-hero { grid-template-columns: 1fr; }
    .guide-layout { grid-template-columns: 1fr; }
    .guide-aside { position: static; grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 680px) {
    .guide-hero { padding: 16px; border-radius: 16px; }
    .guide-hero__facts { grid-template-columns: 1fr; }
    .guide-hero__facts article { min-height: 62px; }
    .section-heading { align-items: start; flex-direction: column; gap: 5px; }
    .workflow-section { padding: 13px; }
    .workflow-track { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; }
    .workflow-step { flex: 0 0 78%; min-height: 150px; scroll-snap-align: start; }
    .guide-aside { grid-template-columns: 1fr; }
    .posting-tabs button { flex-basis: 138px; }
    .posting-sides { grid-template-columns: 1fr; }
    .posting-entry summary { align-items: flex-start; flex-wrap: wrap; }
    .posting-entry__state { flex-basis: auto; order: 2; }
    .posting-entry__title { flex: 1 1 calc(100% - 28px); }
    .posting-entry__title small { white-space: normal; }
    .posting-entry__chevron { order: 1; }
    .posting-entry__note { flex-wrap: wrap; }
    .posting-entry__note p { flex-basis: calc(100% - 26px); }
    .posting-entry__note a { margin-inline-start: 24px; }
    .payment-paths, .role-groups { grid-template-columns: 1fr; }
    .role-groups article { grid-template-columns: 1fr; }
    .role-groups article > b { justify-content: flex-start; }
}

@media (prefers-reduced-motion: reduce) {
    .workflow-step,
    .posting-entry__chevron,
    .role-groups summary i { transition: none; }
}
</style>
