<script setup>
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

    <PageHeader title="مركز المحاسبة" icon="bi-calculator-fill" subtitle="راجع ما سجله النظام، صحّح باستقلالية، ثم أقفل الفترة بثقة">
        <template #actions>
            <Link v-if="urls.manualEntry" :href="urls.manualEntry" class="btn btn-primary"><i class="bi bi-plus-lg"></i> قيد يدوي</Link>
        </template>
    </PageHeader>

    <AccountingNav :urls="urls" active="home" />

    <section class="acc-hero">
        <span class="acc-hero__icon"><i class="bi bi-lightning-charge-fill"></i></span>
        <div>
            <strong>التسجيل اليومي تلقائي</strong>
            <p>المبيعات والتحصيل والتحويل المؤكد والمخزون والمشتريات والمصاريف تنشئ قيودها من مصدرها. دور المحاسب هنا هو المراجعة والضبط والإقفال، لا إعادة إدخال العمل.</p>
        </div>
        <Link :href="urls.guide">افهم مسار كل قيد <i class="bi bi-arrow-left"></i></Link>
    </section>

    <div class="health-grid">
        <article v-for="card in healthCards" :key="card.label" :data-tone="card.tone">
            <span><i class="bi" :class="card.icon"></i></span>
            <div><small>{{ card.label }}</small><strong>{{ card.value }}</strong><em>{{ card.detail }}</em></div>
        </article>
    </div>

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
            <AccountingPanel title="ماذا تريد أن تنجز الآن؟" description="ابدأ بالعمل المطلوب مباشرة؛ بقية الأدوات موجودة في الشريط العلوي." icon="bi-check2-square">
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
</template>

<style scoped>
.acc-hero { display:flex; align-items:center; gap:13px; margin-bottom:14px; padding:15px 17px; border:1px solid #a9d2b6; border-radius:17px; background:linear-gradient(130deg,#edf8f1,#fff 72%); }.acc-hero__icon { display:grid; flex:0 0 44px; width:44px; height:44px; place-items:center; border-radius:13px; color:#fff; background:#1f6b50; font-size:1.15rem; }.acc-hero>div{flex:1}.acc-hero strong{color:#173522;font-size:.9rem}.acc-hero p{max-width:780px;margin:3px 0 0;color:#68776e;font-size:.76rem;line-height:1.6}.acc-hero>a{color:#1f6b50;font-size:.74rem;font-weight:850;white-space:nowrap}
.health-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.health-grid article{display:flex;align-items:center;gap:10px;min-height:88px;padding:12px;border:1px solid #dfe7e2;border-radius:15px;background:#fff}.health-grid article>span{display:grid;flex:0 0 38px;width:38px;height:38px;place-items:center;border-radius:11px;color:#567064;background:#eff3f0}.health-grid article[data-tone="good"]>span{color:#1f6b50;background:#e4f3e9}.health-grid article[data-tone="warn"]>span{color:#a35b00;background:#fff0da}.health-grid article>div{display:grid;min-width:0}.health-grid small{color:#718078;font-size:.7rem}.health-grid strong{overflow:hidden;color:#203329;font-size:.96rem;text-overflow:ellipsis;white-space:nowrap}.health-grid em{color:#7e8c84;font-size:.68rem;font-style:normal}
.attention-center{margin-bottom:14px;overflow:hidden;border:1px solid #edc6c9;border-radius:16px;background:#fff}.attention-center>header{display:flex;align-items:center;gap:11px;padding:13px 15px;background:linear-gradient(120deg,#fff3f3,#fff 72%)}.attention-center>header>span{display:grid;flex:0 0 40px;width:40px;height:40px;place-items:center;border-radius:12px;color:#b52b36;background:#fff}.attention-center>header>div{display:grid;flex:1}.attention-center>header small{color:#a64a51;font-size:.62rem;font-weight:850}.attention-center>header strong{color:#472528;font-size:.88rem}.attention-center>header p{margin:2px 0 0;color:#7f6b6d;font-size:.67rem}.attention-center>header>b{padding:5px 9px;border-radius:999px;color:#a32631;background:#fff;font-size:.65rem}.attention-center.ready{border-color:#b9dbc4}.attention-center.ready>header{background:linear-gradient(120deg,#edf8f1,#fff 72%)}.attention-center.ready>header>span{color:#197443}.attention-center.ready>header small,.attention-center.ready>header>b{color:#197443}.attention-center.ready>header strong{color:#173b27}.attention-center.ready>header p{color:#65786c}.attention-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px;border-top:1px solid #f2e5e6}.attention-list>a{display:grid;grid-template-columns:38px minmax(0,1fr) auto;align-items:center;gap:9px;min-height:74px;padding:10px;border:1px solid #e3e8e5;border-inline-start:3px solid #d24a54;border-radius:12px;color:#304238;background:#fff}.attention-list>a[data-severity="warning"]{border-inline-start-color:#d98a15}.attention-list>a:hover{border-color:#caa3a6;background:#fffafa}.attention-list>a[data-severity="warning"]:hover{border-color:#ddbf91;background:#fffdf8}.attention-icon{display:grid;width:36px;height:36px;place-items:center;border-radius:10px;color:#ae2b35;background:#fff0f1}.attention-list>a[data-severity="warning"] .attention-icon{color:#a86400;background:#fff3df}.attention-copy{display:grid;min-width:0}.attention-copy strong{font-size:.75rem}.attention-copy small{overflow:hidden;color:#7b8880;font-size:.63rem;line-height:1.5;text-overflow:ellipsis;white-space:nowrap}.attention-metric{display:grid;justify-items:end;gap:1px}.attention-metric small{color:#78867d;font-size:.58rem}.attention-metric bdi{color:#293f31;font-size:.7rem;font-weight:900;white-space:nowrap}.attention-list>a>em{grid-column:2/-1;color:#1f6b50;font-size:.62rem;font-style:normal;font-weight:850}
.acc-home-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(300px,.75fr);align-items:start;gap:14px}.acc-home-main,.acc-home-side{display:grid;gap:14px}.task-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.task-grid>a{display:flex;align-items:center;gap:10px;min-height:82px;padding:11px;border:1px solid #e0e7e2;border-radius:13px;color:#324238;background:#fff;transition:.15s}.task-grid>a:hover{border-color:#9bc6a8;transform:translateY(-1px)}.task-grid>a:focus-visible{outline:3px solid rgba(31,107,80,.17);outline-offset:2px}.task-grid>a>span{display:grid;flex:0 0 38px;width:38px;height:38px;place-items:center;border-radius:11px;color:#1f6b50;background:#e9f4ed}.task-grid>a[data-tone="amber"]>span{color:#9d5700;background:#fff0d9}.task-grid>a[data-tone="blue"]>span{color:#175f8d;background:#ebf5fb}.task-grid>a>div{display:grid;flex:1;gap:2px}.task-grid strong{font-size:.82rem}.task-grid small{color:#75847b;font-size:.7rem;line-height:1.45}.task-grid>a>i{color:#99a49d;font-size:.7rem}
.entries-list{display:grid}.entries-list article{display:grid;grid-template-columns:36px minmax(0,1fr) 85px 105px 32px;align-items:center;gap:9px;padding:11px 14px;border-bottom:1px solid #edf1ee}.entries-list article:last-child{border:0}.entry-event{display:grid;width:34px;height:34px;place-items:center;border-radius:10px;color:#1f6b50;background:#edf6f0}.entry-copy{display:grid;min-width:0}.entry-copy strong{overflow:hidden;font-size:.78rem;text-overflow:ellipsis;white-space:nowrap}.entry-copy small,.entries-list time{color:#78867d;font-size:.67rem}.entries-list bdi{color:#23392c;font-size:.76rem;font-weight:850;text-align:left}.entries-list article>a{display:grid;width:32px;height:32px;place-items:center;border-radius:9px;color:#8a5a11;background:#fff5e6}.panel-link{color:#1f6b50;font-size:.76rem;font-weight:850}.acc-empty{display:grid;justify-items:center;gap:4px;padding:38px 15px;color:#8b978f;text-align:center}.acc-empty>i{font-size:1.5rem}.acc-empty strong{color:#536158;font-size:.82rem}.acc-empty span{font-size:.7rem}
.tax-summary{display:flex;align-items:center;gap:10px;padding:12px;border-radius:13px;background:#fff4e5}.tax-summary>span{color:#a45b00;font-size:1.25rem}.tax-summary.active{background:#edf8f1}.tax-summary.active>span{color:#1f6b50}.tax-summary>div{display:grid}.tax-summary strong{font-size:.82rem}.tax-summary small{color:#7e8b83;font-size:.67rem;line-height:1.5}.tax-next{display:flex;gap:7px;margin-top:8px;padding:8px 10px;border-radius:10px;color:#285c78;background:#eff8fc;font-size:.68rem}.tax-config-btn{width:100%;margin-top:10px;padding:9px;border:1px solid #b6d7c1;border-radius:10px;color:#1f6b50;background:#fff;font-size:.72rem;font-weight:850}.tax-form{display:grid;gap:9px;margin-top:10px;padding-top:10px;border-top:1px dashed #dfe7e2}.tax-form>label:not(.tax-toggle){display:grid;gap:4px}.tax-form>label>span{font-size:.68rem;font-weight:800}.tax-toggle{display:flex;align-items:center;gap:8px}.tax-toggle input{width:18px;height:18px;accent-color:#1f6b50}.tax-toggle span{display:grid}.tax-toggle small{color:#829087;font-size:.62rem}.tax-error{margin:0;color:#bd2d2d;font-size:.68rem}.tax-history{margin-top:10px;padding-top:10px;border-top:1px solid #edf1ee}.tax-history summary{cursor:pointer;color:#59675e;font-size:.7rem;font-weight:800}.tax-history>div{display:grid;margin-top:7px}.tax-history article{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid #edf1ee}.tax-history article>span{display:grid;flex:1}.tax-history strong{font-size:.68rem}.tax-history small,.tax-history em{color:#86928a;font-size:.62rem;font-style:normal}.tax-history button{border:0;color:#ba2e2e;background:transparent}
.setup-checks{display:grid}.setup-checks>a{display:flex;align-items:center;gap:9px;padding:10px 0;border-bottom:1px solid #edf1ee;color:#34463b}.setup-checks>a:last-child{border:0}.setup-checks>a>i:first-child{color:#22914d}.setup-checks>a>span{display:grid;flex:1}.setup-checks strong{font-size:.73rem}.setup-checks small{color:#829087;font-size:.63rem}.setup-checks>a>i:last-child{color:#9aa59e;font-size:.6rem}
.impact-toggle{display:flex;width:100%;align-items:center;gap:10px;margin-top:14px;padding:13px 15px;border:1px solid #dfe7e2;border-radius:14px;color:#34463b;background:#fff;text-align:start}.impact-toggle>span{display:flex;flex:1;align-items:center;gap:8px}.impact-toggle b{font-size:.76rem}.impact-toggle small{color:#859188;font-size:.66rem}.impact-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:8px}.impact-grid article{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:7px;padding:12px;border:1px solid #e2e9e4;border-radius:12px;background:#fff}.impact-grid article>strong{grid-column:1/-1;font-size:.73rem}.impact-grid article>span{display:grid;font-size:.68rem}.impact-grid article small{color:#87938b;font-size:.6rem}.impact-grid article>i{color:#9aa59e}
@media(max-width:1100px){.health-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.attention-list{grid-template-columns:1fr}.acc-home-grid{grid-template-columns:1fr}.acc-home-side{grid-template-columns:repeat(2,minmax(0,1fr))}.acc-home-side>:last-child{align-self:start}}
@media(max-width:680px){.acc-hero{align-items:flex-start;flex-wrap:wrap}.acc-hero>a{width:100%;padding-inline-start:57px}.health-grid,.task-grid,.acc-home-side,.impact-grid{grid-template-columns:1fr}.attention-center>header{align-items:flex-start;flex-wrap:wrap}.attention-center>header>div{min-width:calc(100% - 55px)}.attention-center>header>b{margin-inline-start:51px}.attention-list>a{grid-template-columns:36px minmax(0,1fr)}.attention-metric{grid-column:2;justify-items:start}.attention-list>a>em{grid-column:2}.entries-list article{grid-template-columns:34px minmax(0,1fr) auto}.entries-list time{display:none}.entries-list bdi{grid-column:2}.entries-list article>a{grid-column:3;grid-row:1/3}.impact-toggle small{display:none}}
</style>
