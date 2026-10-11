<script setup>
import FinancialWorkspace from '../../../Components/Accounting/FinancialWorkspace.vue'
import { computed, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'
import { useConfirm } from '../../../Composables/useConfirm'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    can: { type: Object, required: true },
    baseCurrency: { type: Object, required: true },
    today: { type: String, required: true },
    health: { type: Object, required: true },
    attention: { type: Array, default: () => [] },
    latestEntries: { type: Array, default: () => [] },
    tax: { type: Object, required: true },
    urls: { type: Object, required: true },
})

const { ask } = useConfirm()
const taxOpen = ref(false)
const impactOpen = ref(false)
const taxForm = useForm({
    tax_enabled: Boolean(props.tax.switchOn),
    tax_rate: Number(props.tax.rate || 0),
    tax_effective_from: props.today,
})

const tasks = computed(() => [
    { label: 'راجع القيود', description: 'كل حركة سجلها النظام مع مصدرها وتوازنها.', icon: 'bi-journal-text', url: props.urls.journal, tone: 'green' },
    { label: 'افتح دفتر حساب', description: 'الرصيد السابق والحركة والرصيد المتراكم.', icon: 'bi-journal-bookmark-fill', url: props.urls.ledger, tone: 'blue' },
    { label: 'راجع نتيجة الفترة', description: 'الأرباح والخسائر وميزان المراجعة.', icon: 'bi-graph-up-arrow', url: props.urls.profitLoss, tone: 'green' },
    { label: 'ابدأ الأرصدة', description: 'حسابات وديون زبائن وموردين دون قيد معقد.', icon: 'bi-door-open-fill', url: props.urls.openingBalances, tone: 'amber' },
    { label: 'راجع الشهر وأقفله', description: 'قائمة تحقق واضحة قبل منع الترحيل.', icon: 'bi-calendar2-check', url: props.urls.periods, tone: 'amber' },
    { label: 'طابق الصندوق والبنك', description: 'قارن رصيد الدفتر مع الرصيد الفعلي.', icon: 'bi-bank', url: props.urls.reconciliations, tone: 'blue' },
].filter((task) => task.url))

const healthCards = computed(() => [
    {
        label: 'الفترة الحالية',
        value: props.health.currentPeriod?.name ?? 'غير محددة',
        detail: props.health.currentPeriod ? (props.health.currentPeriod.status === 'closed' ? 'مقفلة' : 'مفتوحة للترحيل') : 'أنشئ سنة وفترات مالية',
        icon: 'bi-calendar3', tone: props.health.currentPeriod?.status === 'open' ? 'good' : 'warn',
    },
    { label: 'قيود الدفتر', value: number(props.health.journalEntries), detail: 'قيد مرحّل ومحفوظ', icon: 'bi-journal-check', tone: 'plain' },
    { label: 'ذمم الزبائن', value: money(props.health.customerDebt), detail: 'رصيد مطلوب تحصيله', icon: 'bi-person-dash-fill', tone: Number(props.health.customerDebt) > 0 ? 'warn' : 'good' },
    { label: 'ذمم الموردين', value: money(props.health.supplierDebt), detail: 'رصيد مطلوب سداده', icon: 'bi-truck', tone: Number(props.health.supplierDebt) > 0 ? 'warn' : 'good' },
])

function number(value) {
    return new Intl.NumberFormat('ar', { maximumFractionDigits: 0 }).format(Number(value || 0))
}
function money(value) {
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0))
    return `${formatted} ${props.baseCurrency.symbol}`
}
function rate(value) {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 }).format(Number(value || 0))
}
function saveTax() {
    if (!props.can.update) return
    taxForm.post(props.urls.taxStore, { preserveScroll: true, onSuccess: () => { taxOpen.value = false } })
}
async function deleteSchedule(schedule) {
    const yes = await ask({
        title: 'حذف التغيير الضريبي المستقبلي؟',
        message: `التغيير المجدول في ${schedule.date} فقط هو الذي سيُحذف. لن تتأثر أي فاتورة سابقة.`,
        confirmLabel: 'حذف التغيير', danger: true,
    })
    if (yes) router.delete(schedule.destroyUrl, { preserveScroll: true })
}
</script>

<template>
<Head title="مركز المحاسبة" />
<FinancialWorkspace area="المحاسبة" mode="overview">
<template #heading>
<PageHeader title="مركز المحاسبة" icon="bi-calculator-fill" subtitle="القيود والأرصدة وجاهزية الإقفال">
        <template #actions>
            <Link v-if="urls.manualEntry" :href="urls.manualEntry" class="btn btn-primary"><i class="bi bi-plus-lg"></i> قيد يدوي</Link>
        </template>
    </PageHeader>
</template>
<template #navigation>
<AccountingNav :urls="urls" active="home" />
</template>
<template #metrics>
<div class="health-grid">
        <article v-for="card in healthCards" :key="card.label" :data-tone="card.tone">
            <span><i class="bi" :class="card.icon"></i></span>
            <div><small>{{ card.label }}</small><strong>{{ card.value }}</strong><em>{{ card.detail }}</em></div>
        </article>
    </div>
</template>
<details class="acc-help"><summary>دليل التسجيل والمراجعة</summary><p>العمليات اليومية تُسجل من شاشاتها. راجع القيود هنا، واستخدم القيد اليدوي للتسويات فقط.</p><Link :href="urls.guide">فتح الدليل</Link></details>
<section class="attention-center" :class="{ ready: !attention.length }" aria-labelledby="accounting-attention-title">
        <header>
            <span><i class="bi" :class="attention.length ? 'bi-exclamation-diamond-fill' : 'bi-check2-circle'"></i></span>
            <div>
                <small>قائمة عمل المحاسب</small>
                <strong id="accounting-attention-title">{{ attention.length ? 'مطلوب منك الآن' : 'لا توجد استثناءات معلّقة' }}</strong>
                <p>{{ attention.length ? 'مرتبة حسب أثرها على الترحيل والمطابقة والإقفال.' : 'الفترة والربط والمطابقات والذمم المتأخرة تحت السيطرة.' }}</p>
            </div>
            <b>{{ attention.length ? `${number(attention.length)} إجراء` : 'جاهز للمراجعة' }}</b>
        </header>
        <div v-if="attention.length" class="attention-list">
            <Link v-for="item in attention" :key="item.key" :href="item.url || urls.home" :data-severity="item.severity">
                <span class="attention-icon"><i class="bi" :class="item.icon"></i></span>
                <span class="attention-copy"><strong>{{ item.title }}</strong><small>{{ item.detail }}</small></span>
                <span v-if="item.countLabel || item.amount !== null" class="attention-metric"><small v-if="item.countLabel">{{ item.countLabel }}</small><bdi v-if="item.amount !== null">{{ money(item.amount) }}</bdi></span>
                <em>{{ item.actionLabel }} <i class="bi bi-arrow-left"></i></em>
            </Link>
        </div>
    </section>
<div class="acc-home-grid">
        <div class="acc-home-main">
            <AccountingPanel title="أدوات العمل" description="" icon="bi-check2-square">
                <div class="task-grid">
                    <Link v-for="task in tasks" :key="task.label" :href="task.url" :data-tone="task.tone">
                        <span><i class="bi" :class="task.icon"></i></span>
                        <div><strong>{{ task.label }}</strong><small>{{ task.description }}</small></div>
                        <i class="bi bi-chevron-left"></i>
                    </Link>
                </div>
            </AccountingPanel>

            <AccountingPanel title="آخر القيود" description="آخر ما وصل إلى الدفتر من جميع العمليات." icon="bi-clock-history" compact>
                <div v-if="latestEntries.length" class="entries-list">
                    <article v-for="entry in latestEntries" :key="entry.id">
                        <span class="entry-event"><i class="bi bi-journal-check"></i></span>
                        <div class="entry-copy"><strong>{{ entry.description }}</strong><small>{{ entry.eventLabel }} · {{ entry.number || `#${entry.id}` }}</small></div>
                        <time>{{ entry.date }}</time>
                        <bdi>{{ money(entry.amount) }}</bdi>
                        <Link v-if="entry.adjustUrl" :href="entry.adjustUrl" title="تصحيح القيد"><i class="bi bi-arrow-counterclockwise"></i></Link>
                    </article>
                </div>
                <div v-else class="acc-empty"><i class="bi bi-journal-x"></i><strong>لا توجد قيود بعد</strong><span>ستظهر هنا أول حركة مالية يسجلها النظام.</span></div>
                <template #footer><Link :href="urls.journal" class="panel-link">عرض دفتر القيود كاملاً <i class="bi bi-arrow-left"></i></Link></template>
            </AccountingPanel>
        </div>

        <aside class="acc-home-side">
            <AccountingPanel title="ضريبة فاتورة الزبون" description="مستقلة عن رسوم الخدمة وعن ضريبة فاتورة المورد." icon="bi-percent" :tone="tax.activeToday ? 'green' : 'amber'">
                <div class="tax-summary" :class="{ active: tax.activeToday }">
                    <span><i class="bi" :class="tax.activeToday ? 'bi-check-circle-fill' : 'bi-pause-circle-fill'"></i></span>
                    <div>
                        <strong>{{ tax.activeToday ? `مفعلة ${rate(tax.rate)}%` : 'متوقفة' }}</strong>
                        <small>{{ tax.activeToday ? 'تُضاف إلى الفواتير الجديدة فقط.' : 'هذا هو الوضع الافتراضي؛ لا يُرحّل شيء لحساب الضريبة.' }}</small>
                    </div>
                </div>
                <div v-if="tax.nextSchedule" class="tax-next"><i class="bi bi-calendar-event"></i><span>التغيير القادم {{ tax.nextSchedule.date }}: <b>{{ tax.nextSchedule.enabled ? `${rate(tax.nextSchedule.rate)}%` : 'إيقاف' }}</b></span></div>
                <button v-if="can.update" type="button" class="tax-config-btn" @click="taxOpen = !taxOpen"><i class="bi bi-sliders"></i>{{ taxOpen ? 'إخفاء الإعداد' : 'تعديل أو جدولة' }}</button>
                <form v-if="taxOpen" class="tax-form" @submit.prevent="saveTax">
                    <label class="tax-toggle"><input v-model="taxForm.tax_enabled" type="checkbox"><span><b>تفعيل الضريبة</b><small>فاتورة المطعم للزبون فقط</small></span></label>
                    <label><span>النسبة %</span><input v-model="taxForm.tax_rate" type="number" min="0" max="100" step="0.0001" class="form-control" :disabled="!taxForm.tax_enabled"></label>
                    <label><span>تاريخ السريان</span><input v-model="taxForm.tax_effective_from" type="date" class="form-control"></label>
                    <p v-if="taxForm.errors.tax_rate" class="tax-error">{{ taxForm.errors.tax_rate }}</p>
                    <button class="btn btn-primary" :disabled="taxForm.processing"><i class="bi bi-check2"></i> حفظ الجدول</button>
                </form>
                <details v-if="tax.schedules.length" class="tax-history">
                    <summary>السجل والجدول ({{ tax.schedules.length }})</summary>
                    <div><article v-for="schedule in tax.schedules" :key="schedule.id"><span><strong>{{ schedule.date }}</strong><small>{{ schedule.enabled ? `${rate(schedule.rate)}%` : 'متوقفة' }}</small></span><button v-if="schedule.isFuture && can.update" type="button" @click="deleteSchedule(schedule)"><i class="bi bi-trash3"></i></button><em v-else>محفوظ</em></article></div>
                </details>
            </AccountingPanel>

            <AccountingPanel title="جاهزية التأسيس" description="ثلاث نقاط تكفي لبدء محاسبة سليمة." icon="bi-shield-check" tone="blue">
                <div class="setup-checks">
                    <Link :href="urls.accounts"><i class="bi bi-check-circle-fill"></i><span><strong>شجرة الحسابات</strong><small>{{ health.accounts }} حساباً فعالاً</small></span><i class="bi bi-chevron-left"></i></Link>
                    <Link v-if="urls.openingBalances" :href="urls.openingBalances"><i class="bi" :class="health.journalEntries ? 'bi-check-circle-fill' : 'bi-circle'"></i><span><strong>الأرصدة الافتتاحية</strong><small>{{ health.journalEntries ? 'الدفتر يحتوي حركات' : 'أدخل أرصدة البداية عند الحاجة' }}</small></span><i class="bi bi-chevron-left"></i></Link>
                    <Link v-if="urls.fiscalYears" :href="urls.fiscalYears"><i class="bi" :class="health.currentPeriod ? 'bi-check-circle-fill' : 'bi-circle'"></i><span><strong>السنة والفترة</strong><small>{{ health.currentPeriod ? health.currentPeriod.name : 'غير محددة بعد' }}</small></span><i class="bi bi-chevron-left"></i></Link>
                </div>
            </AccountingPanel>
        </aside>
    </div>
<button type="button" class="impact-toggle" @click="impactOpen = !impactOpen"><span><i class="bi bi-signpost-split"></i><b>كيف تؤثر الحركات تلقائياً؟</b><small>خريطة مختصرة للمدين والدائن</small></span><i class="bi" :class="impactOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i></button>
<div v-if="impactOpen" class="impact-grid">
        <article v-for="row in [
            ['إصدار فاتورة زبون','ذمم العملاء','المبيعات + الضريبة إن كانت سارية'],
            ['قبض نقدي','الصندوق','ذمم العملاء'],
            ['تحويل إلكتروني مؤكد','البنك مباشرة','ذمم العملاء'],
            ['بدء التحضير','تكلفة البضاعة','المخزون'],
            ['فاتورة مورد','مخزون/مصروف + ضريبة المورد','ذمم الموردين'],
            ['سداد مورد أو مصروف','ذمم المورد/المصروف','الصندوق أو البنك'],
        ]" :key="row[0]"><strong>{{ row[0] }}</strong><span><small>مدين</small>{{ row[1] }}</span><i class="bi bi-arrow-left"></i><span><small>دائن</small>{{ row[2] }}</span></article>
    </div>
</FinancialWorkspace>

</template>

<style scoped>
.acc-help { margin-bottom: 12px; font-size: .875rem; color: var(--muted, #626B6D); }
.acc-help summary { cursor: pointer; }

.acc-hero { display:flex; align-items:center; gap:13px; margin-bottom:14px; padding:15px 17px; border:1px solid var(--line, #DFD7CB); border-radius:12px; background:var(--surface, #FFFDFA); }.acc-hero__icon { display:grid; flex:0 0 44px; width:44px; height:44px; place-items:center; border-radius:12px; color:#fff; background:var(--brand, #A3422B); font-size:1.15rem; }.acc-hero>div{flex:1}.acc-hero strong{color:var(--ink, #25343A);font-size:.9rem}.acc-hero p{max-width:780px;margin:3px 0 0;color:var(--muted, #626B6D);font-size:.875rem;line-height:1.6}.acc-hero>a{color:var(--ink, #25343A);font-size:.875rem;font-weight:850;white-space:nowrap}
.health-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.health-grid article{display:flex;align-items:center;gap:10px;min-height:88px;padding:12px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.health-grid article>span{display:grid;flex:0 0 38px;width:38px;height:38px;place-items:center;border-radius:11px;color:var(--muted, #626B6D);background:var(--surface, #FFFDFA)}.health-grid article[data-tone="good"]>span{color:#A3422B;background:#e4f3e9}.health-grid article[data-tone="warn"]>span{color:#a35b00;background:#fff0da}.health-grid article>div{display:grid;min-width:0}.health-grid small{color:var(--muted, #626B6D);font-size:.875rem}.health-grid strong{overflow:hidden;color:var(--ink, #25343A);font-size:.96rem;text-overflow:ellipsis;white-space:nowrap}.health-grid em{color:var(--muted, #626B6D);font-size:.875rem;font-style:normal}
.attention-center{margin-bottom:14px;overflow:hidden;border:1px solid #edc6c9;border-radius:12px;background:var(--surface, #FFFDFA)}.attention-center>header{display:flex;align-items:center;gap:11px;padding:13px 15px;background:var(--surface, #FFFDFA)}.attention-center>header>span{display:grid;flex:0 0 40px;width:40px;height:40px;place-items:center;border-radius:12px;color:#b52b36;background:var(--surface, #FFFDFA)}.attention-center>header>div{display:grid;flex:1}.attention-center>header small{color:#a64a51;font-size:.875rem;font-weight:850}.attention-center>header strong{color:#472528;font-size:.88rem}.attention-center>header p{margin:2px 0 0;color:var(--muted, #626B6D);font-size:.875rem}.attention-center>header>b{padding:5px 9px;border-radius:12px;color:#a32631;background:var(--surface, #FFFDFA);font-size:.875rem}.attention-center.ready{border-color:#F3E3D8}.attention-center.ready>header{background:#edf8f1}.attention-center.ready>header>span{color:#A3422B}.attention-center.ready>header small,.attention-center.ready>header>b{color:#A3422B}.attention-center.ready>header strong{color:#25343A}.attention-center.ready>header p{color:#A3422B}.attention-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px;border-top:1px solid #f2e5e6}.attention-list>a{display:grid;grid-template-columns:38px minmax(0,1fr) auto;align-items:center;gap:9px;min-height:74px;padding:10px;border:1px solid var(--line, #DFD7CB);border-inline-start:3px solid #d24a54;border-radius:12px;color:var(--ink, #25343A);background:var(--surface, #FFFDFA)}.attention-list>a[data-severity="warning"]{border-inline-start-color:#d98a15}.attention-list>a:hover{border-color:#caa3a6;background:var(--surface, #FFFDFA)}.attention-list>a[data-severity="warning"]:hover{border-color:#ddbf91;background:#fffdf8}.attention-icon{display:grid;width:36px;height:36px;place-items:center;border-radius:10px;color:#ae2b35;background:#fff0f1}.attention-list>a[data-severity="warning"] .attention-icon{color:#a86400;background:#fff3df}.attention-copy{display:grid;min-width:0}.attention-copy strong{font-size:.875rem}.attention-copy small{overflow:hidden;color:var(--muted, #626B6D);font-size:.875rem;line-height:1.5;text-overflow:ellipsis;white-space:nowrap}.attention-metric{display:grid;justify-items:end;gap:1px}.attention-metric small{color:var(--muted, #626B6D);font-size:.875rem}.attention-metric bdi{color:var(--ink, #25343A);font-size:.875rem;font-weight:900;white-space:nowrap}.attention-list>a>em{grid-column:2/-1;color:var(--ink, #25343A);font-size:.875rem;font-style:normal;font-weight:850}
.acc-home-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(300px,.75fr);align-items:start;gap:14px}.acc-home-main,.acc-home-side{display:grid;gap:14px}.task-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.task-grid>a{display:flex;align-items:center;gap:10px;min-height:82px;padding:11px;border:1px solid var(--line, #DFD7CB);border-radius:12px;color:var(--ink, #25343A);background:var(--surface, #FFFDFA);transition:.15s}.task-grid>a:hover{border-color:var(--line, #DFD7CB);transform:translateY(-1px)}.task-grid>a:focus-visible{outline:3px solid rgba(37,52,58,.17);outline-offset:2px}.task-grid>a>span{display:grid;flex:0 0 38px;width:38px;height:38px;place-items:center;border-radius:11px;color:var(--ink, #25343A);background:var(--brand-soft, #F3E3D8)}.task-grid>a[data-tone="amber"]>span{color:#9d5700;background:#fff0d9}.task-grid>a[data-tone="blue"]>span{color:#175f8d;background:#ebf5fb}.task-grid>a>div{display:grid;flex:1;gap:2px}.task-grid strong{font-size:.875rem}.task-grid small{color:var(--muted, #626B6D);font-size:.875rem;line-height:1.45}.task-grid>a>i{color:var(--muted, #626B6D);font-size:.875rem}
.entries-list{display:grid}.entries-list article{display:grid;grid-template-columns:36px minmax(0,1fr) 85px 105px 32px;align-items:center;gap:9px;padding:11px 14px;border-bottom:1px solid var(--line, #DFD7CB)}.entries-list article:last-child{border:0}.entry-event{display:grid;width:34px;height:34px;place-items:center;border-radius:10px;color:var(--ink, #25343A);background:var(--surface, #FFFDFA)}.entry-copy{display:grid;min-width:0}.entry-copy strong{overflow:hidden;font-size:.875rem;text-overflow:ellipsis;white-space:nowrap}.entry-copy small,.entries-list time{color:var(--muted, #626B6D);font-size:.875rem}.entries-list bdi{color:var(--ink, #25343A);font-size:.875rem;font-weight:850;text-align:left}.entries-list article>a{display:grid;width:32px;height:32px;place-items:center;border-radius:9px;color:#8a5a11;background:#fff5e6}.panel-link{color:var(--ink, #25343A);font-size:.875rem;font-weight:850}.acc-empty{display:grid;justify-items:center;gap:4px;padding:38px 15px;color:var(--muted, #626B6D);text-align:center}.acc-empty>i{font-size:1.5rem}.acc-empty strong{color:var(--muted, #626B6D);font-size:.875rem}.acc-empty span{font-size:.875rem}
.tax-summary{display:flex;align-items:center;gap:10px;padding:12px;border-radius:12px;background:#fff4e5}.tax-summary>span{color:#a45b00;font-size:1.25rem}.tax-summary.active{background:#edf8f1}.tax-summary.active>span{color:#A3422B}.tax-summary>div{display:grid}.tax-summary strong{font-size:.875rem}.tax-summary small{color:var(--muted, #626B6D);font-size:.875rem;line-height:1.5}.tax-next{display:flex;gap:7px;margin-top:8px;padding:8px 10px;border-radius:10px;color:#285c78;background:#eff8fc;font-size:.875rem}.tax-config-btn{width:100%;margin-top:10px;padding:9px;border:1px solid var(--line, #DFD7CB);border-radius:8px;color:var(--ink, #25343A);background:var(--surface, #FFFDFA);font-size:.875rem;font-weight:850}.tax-form{display:grid;gap:9px;margin-top:10px;padding-top:10px;border-top:1px dashed var(--line, #DFD7CB)}.tax-form>label:not(.tax-toggle){display:grid;gap:4px}.tax-form>label>span{font-size:.875rem;font-weight:800}.tax-toggle{display:flex;align-items:center;gap:8px}.tax-toggle input{width:18px;height:18px;accent-color:var(--ink, #25343A)}.tax-toggle span{display:grid}.tax-toggle small{color:var(--muted, #626B6D);font-size:.875rem}.tax-error{margin:0;color:#bd2d2d;font-size:.875rem}.tax-history{margin-top:10px;padding-top:10px;border-top:1px solid var(--line, #DFD7CB)}.tax-history summary{cursor:pointer;color:var(--muted, #626B6D);font-size:.875rem;font-weight:800}.tax-history>div{display:grid;margin-top:7px}.tax-history article{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--line, #DFD7CB)}.tax-history article>span{display:grid;flex:1}.tax-history strong{font-size:.875rem}.tax-history small,.tax-history em{color:var(--muted, #626B6D);font-size:.875rem;font-style:normal}.tax-history button{border:0;color:#ba2e2e;background:transparent}
.setup-checks{display:grid}.setup-checks>a{display:flex;align-items:center;gap:9px;padding:10px 0;border-bottom:1px solid var(--line, #DFD7CB);color:var(--ink, #25343A)}.setup-checks>a:last-child{border:0}.setup-checks>a>i:first-child{color:var(--brand, #A3422B)}.setup-checks>a>span{display:grid;flex:1}.setup-checks strong{font-size:.875rem}.setup-checks small{color:var(--muted, #626B6D);font-size:.875rem}.setup-checks>a>i:last-child{color:var(--muted, #626B6D);font-size:.875rem}
.impact-toggle{display:flex;width:100%;align-items:center;gap:10px;margin-top:14px;padding:13px 15px;border:1px solid var(--line, #DFD7CB);border-radius:12px;color:var(--ink, #25343A);background:var(--surface, #FFFDFA);text-align:start}.impact-toggle>span{display:flex;flex:1;align-items:center;gap:8px}.impact-toggle b{font-size:.875rem}.impact-toggle small{color:var(--muted, #626B6D);font-size:.875rem}.impact-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:8px}.impact-grid article{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:7px;padding:12px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.impact-grid article>strong{grid-column:1/-1;font-size:.875rem}.impact-grid article>span{display:grid;font-size:.875rem}.impact-grid article small{color:var(--muted, #626B6D);font-size:.875rem}.impact-grid article>i{color:var(--muted, #626B6D)}
@media(max-width:1100px){.health-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.attention-list{grid-template-columns:1fr}.acc-home-grid{grid-template-columns:1fr}.acc-home-side{grid-template-columns:repeat(2,minmax(0,1fr))}.acc-home-side>:last-child{align-self:start}}
@media(max-width:680px){.acc-hero{align-items:flex-start;flex-wrap:wrap}.acc-hero>a{width:100%;padding-inline-start:57px}.health-grid,.task-grid,.acc-home-side,.impact-grid{grid-template-columns:1fr}.attention-center>header{align-items:flex-start;flex-wrap:wrap}.attention-center>header>div{min-width:calc(100% - 55px)}.attention-center>header>b{margin-inline-start:51px}.attention-list>a{grid-template-columns:36px minmax(0,1fr)}.attention-metric{grid-column:2;justify-items:start}.attention-list>a>em{grid-column:2}.entries-list article{grid-template-columns:34px minmax(0,1fr) auto}.entries-list time{display:none}.entries-list bdi{grid-column:2}.entries-list article>a{grid-column:3;grid-row:1/3}.impact-toggle small{display:none}}


.health-grid article>span{align-self:flex-start;background:transparent!important;color:#A3422B!important;width:28px;flex-basis:28px}
.attention-center>header{padding:18px 20px}.attention-center.ready{border-color:#DFD7CB}.attention-center.ready>header{background:#FFFDFA}.attention-center.ready>header p{display:none}.attention-center.ready>header small{display:none}.attention-center.ready>header>span{background:#F7F3ED;color:#626B6D}.attention-center.ready>header strong{color:#25343A;font-size:16px}.attention-center.ready>header>b{background:#F7F3ED;color:#626B6D}
.task-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:0}.task-grid>a{min-height:86px;border:0;border-bottom:1px solid #DFD7CB;border-radius:0;padding:16px 10px;background:transparent}.task-grid>a:hover{transform:none;background:#F7F3ED}.task-grid>a>span{background:transparent!important;color:#A3422B!important}.task-grid strong{font-size:16px}.task-grid small{font-size:13px}.entries-list article{padding:18px 20px;grid-template-columns:36px minmax(0,1fr) 100px 140px 38px}.entry-copy strong{font-size:15px}.entries-list article>a{height:38px;width:38px;background:#F7F3ED;color:#A3422B}.acc-home-side{gap:24px}
@media(max-width:700px){.task-grid{grid-template-columns:1fr}.entries-list article{grid-template-columns:30px minmax(0,1fr) 38px}.entries-list bdi{grid-column:2}.entries-list time{display:none}}

</style>
