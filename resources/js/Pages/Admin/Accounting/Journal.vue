<script setup>
import FinancialWorkspace from '../../../Components/Accounting/FinancialWorkspace.vue'
import { computed, reactive, ref } from 'vue'
import { Link, Head, router } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import Pagination from '../../../Components/Ui/Pagination.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'

defineOptions({ layout: AdminLayout })
const props = defineProps({ can:Object, currency:Object, entries:Object, eventTypes:Array, filters:Object, urls:Object })
const filterOpen = ref(Boolean(props.filters.from || props.filters.eventType || props.filters.search))
const form = reactive({ ...props.filters })
const hasFilters = computed(() => Boolean(form.from || form.eventType || form.search))
function visit() { router.get(props.urls.index, { from:form.from||undefined, to:form.to||undefined, event_type:form.eventType||undefined, search:form.search||undefined }, { preserveState:true, preserveScroll:true }) }
function clearFilters() { form.from=''; form.to=''; form.eventType=''; form.search=''; visit() }
function exportCsv() { window.location.href = `${props.urls.export}?${new URLSearchParams({ from:props.filters.from||'', to:props.filters.to||'', event_type:props.filters.eventType||'', search:props.filters.search||'' })}` }
function money(value){ return `${new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value||0))} ${props.currency.symbol}` }
</script>

<template>
<Head title="دفتر القيود" />
<FinancialWorkspace area="المحاسبة" mode="records">
<template #heading>
<PageHeader title="دفتر القيود" icon="bi-journal-text" subtitle="كل حركة مالية مع مصدرها وتفاصيل المدين والدائن">
    <template #actions><Link v-if="urls.manualEntry" :href="urls.manualEntry" class="btn btn-primary"><i class="bi bi-plus-lg"></i> قيد يدوي</Link></template>
</PageHeader>
</template>
<template #navigation>
<AccountingNav :urls="urls" active="journal" />
</template>
<template #filters>
<form v-if="filterOpen" class="filter-panel" @submit.prevent="visit">
    <label><span>من</span><input v-model="form.from" type="date" class="form-control"></label>
    <label><span>إلى</span><input v-model="form.to" type="date" class="form-control"></label>
    <label><span>نوع العملية</span><select v-model="form.eventType" class="form-select"><option value="">كل العمليات</option><option v-for="type in eventTypes" :key="type.value" :value="type.value">{{type.label}}</option></select></label>
    <button class="btn btn-primary"><i class="bi bi-search"></i> تطبيق</button>
    <button v-if="hasFilters" type="button" class="btn btn-light" @click="clearFilters">مسح</button>
</form>
</template>
<div class="journal-tools">
    <label><i class="bi bi-search"></i><input v-model="form.search" type="search" placeholder="رقم القيد أو الوصف" @keyup.enter="visit"></label>
    <button type="button" :class="{active:filterOpen}" @click="filterOpen=!filterOpen"><i class="bi bi-funnel"></i> تصفية <span v-if="hasFilters"></span></button>
    <button v-if="can.export" type="button" @click="exportCsv"><i class="bi bi-download"></i> CSV</button>
</div>
<AccountingPanel title="القيود المرحّلة" :description="`${entries.total} قيد ضمن الفترة المختارة`" icon="bi-journal-check" compact>
    <div v-if="entries.data.length" class="journal-list">
        <article v-for="entry in entries.data" :key="entry.id" :class="{'is-reversed':entry.reversed}">
            <div class="entry-top">
                <span class="entry-no">{{entry.number}}</span><time>{{entry.date}}</time><bdi>{{money(entry.amount)}}</bdi>
                <Link v-if="entry.correctUrl" :href="entry.correctUrl" class="correct-btn"><i class="bi bi-arrow-counterclockwise"></i><span>تصحيح</span></Link>
                <span v-else-if="entry.closing" class="lock-badge"><i class="bi bi-lock-fill"></i> إقفال</span>
            </div>
            <div class="entry-main"><strong>{{entry.description}}</strong><div><span>{{entry.eventLabel}}</span><span v-if="entry.branch">{{entry.branch}}</span><span v-if="entry.reversed" class="warn">معكوس</span></div></div>
            <details><summary>تفاصيل المدين والدائن <i class="bi bi-chevron-down"></i></summary><div class="line-list"><div v-for="line in entry.lines" :key="line.id"><span><bdi>{{line.accountCode}}</bdi> — {{line.accountName}}<small v-if="line.description">{{line.description}}</small></span><span><bdi v-if="line.debit">مدين {{money(line.debit)}}</bdi><bdi v-if="line.credit">دائن {{money(line.credit)}}</bdi></span></div></div></details>
        </article>
    </div>
    <div v-else class="empty"><i class="bi bi-journal-x"></i><strong>لا توجد قيود</strong><span>غيّر الفترة أو الفلاتر، أو ابدأ أول عملية مالية.</span></div>
    <template #footer><Pagination v-if="entries.links?.length" :links="entries.links" /></template>
</AccountingPanel>
</FinancialWorkspace>

</template>

<style scoped>
.journal-tools{display:flex;gap:8px;margin-bottom:10px}.journal-tools>label{display:flex;flex:1;align-items:center;gap:8px;padding:10px 13px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.journal-tools>label:focus-within{border-color:var(--line, #DFD7CB);box-shadow:0 0 0 3px rgba(37,52,58,.09)}.journal-tools input{width:100%;border:0;outline:0;background:transparent;font-size:.875rem}.journal-tools>button{position:relative;min-height:42px;padding:9px 14px;border:1px solid var(--line, #DFD7CB);border-radius:8px;color:var(--muted, #626B6D);background:var(--surface, #FFFDFA);font-size:.875rem;font-weight:800}.journal-tools>button.active{color:var(--ink, #25343A);border-color:var(--line, #DFD7CB);background:var(--surface, #FFFDFA)}.journal-tools>button span{position:absolute;top:6px;inset-inline-end:6px;width:7px;height:7px;border-radius:50%;background:#d97706}.filter-panel{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto auto;align-items:end;gap:9px;margin-bottom:10px;padding:13px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.filter-panel label{display:grid;gap:4px}.filter-panel label>span{font-size:.875rem;font-weight:800;color:var(--muted, #626B6D)}
.journal-list{display:grid}.journal-list>article{padding:15px 17px;border-bottom:1px solid var(--line, #DFD7CB)}.journal-list>article:last-child{border:0}.journal-list>article.is-reversed{background:#fffbf3}.entry-top{display:grid;grid-template-columns:155px 95px 1fr auto;align-items:center;gap:10px}.entry-no{color:var(--ink, #25343A);font-size:.875rem;font-weight:900}.entry-top time{color:var(--muted, #626B6D);font-size:.875rem}.entry-top>bdi{text-align:end;font-size:.875rem;font-weight:900}.correct-btn,.lock-badge{display:flex;min-height:34px;align-items:center;gap:5px;padding:6px 10px;border-radius:8px;font-size:.875rem;font-weight:800}.correct-btn{color:#9b5800;background:#fff1dc}.lock-badge{color:var(--muted, #626B6D);background:var(--surface, #FFFDFA)}.entry-main{display:grid;gap:5px;margin-top:7px}.entry-main>strong{font-size:.875rem}.entry-main>div{display:flex;flex-wrap:wrap;gap:5px}.entry-main>div span{padding:3px 8px;border-radius:12px;color:var(--muted, #626B6D);background:var(--surface, #FFFDFA);font-size:.875rem}.entry-main>div .warn{color:#9d5900;background:#fff0d9}.journal-list details{margin-top:9px}.journal-list summary{display:flex;width:max-content;align-items:center;gap:5px;color:var(--ink, #25343A);cursor:pointer;font-size:.875rem;font-weight:800}.line-list{display:grid;gap:5px;margin-top:7px;padding:9px;border-radius:11px;background:var(--surface, #FFFDFA)}.line-list>div{display:flex;justify-content:space-between;gap:10px;padding:6px;border-bottom:1px solid var(--line, #DFD7CB);font-size:.875rem}.line-list>div:last-child{border:0}.line-list>div>span{display:grid}.line-list small{color:var(--muted, #626B6D)}.line-list>div>span:last-child{display:flex;gap:10px;font-weight:800}.empty{display:grid;justify-items:center;gap:4px;padding:55px 15px;color:var(--muted, #626B6D);text-align:center}.empty>i{font-size:1.7rem}.empty strong{color:var(--muted, #626B6D)}.empty span{font-size:.875rem}
@media(max-width:760px){.filter-panel{grid-template-columns:repeat(2,1fr)}.entry-top{grid-template-columns:1fr auto}.entry-top time{grid-column:1}.entry-top>bdi{grid-column:2;grid-row:1}.correct-btn,.lock-badge{grid-column:2;grid-row:2}.line-list>div{flex-direction:column}.journal-tools>button span:not(.bi){display:none}}
@media(max-width:500px){.journal-tools>button{padding-inline:10px}.journal-tools>button{font-size:0}.journal-tools>button i{font-size:.9rem}.filter-panel{grid-template-columns:1fr}.entry-top{grid-template-columns:1fr auto}.correct-btn span{display:none}}

.journal-tools{display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px}.journal-table{overflow-x:auto}.entry-card{border-radius:12px;margin-bottom:14px}.entry-card>header{padding:18px 20px}.entry-lines{padding:14px 20px}
</style>
