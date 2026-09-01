<script setup>
import { computed, reactive, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
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
function exportCsv() { window.location.href = `${props.urls.export}?${new URLSearchParams({ from:form.from||'', to:form.to||'', event_type:form.eventType||'' })}` }
function money(value){ return `${new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value||0))} ${props.currency.symbol}` }
</script>

<template>
<Head title="دفتر القيود" />
<PageHeader title="دفتر القيود" icon="bi-journal-text" subtitle="كل حركة مالية مع مصدرها وتفاصيل المدين والدائن">
    <template #actions><a v-if="urls.manualEntry" :href="urls.manualEntry" class="btn btn-primary"><i class="bi bi-plus-lg"></i> قيد يدوي</a></template>
</PageHeader>
<AccountingNav :urls="urls" active="journal" />

<div class="journal-tools">
    <label><i class="bi bi-search"></i><input v-model="form.search" type="search" placeholder="رقم القيد أو الوصف" @keyup.enter="visit"></label>
    <button type="button" :class="{active:filterOpen}" @click="filterOpen=!filterOpen"><i class="bi bi-funnel"></i> تصفية <span v-if="hasFilters"></span></button>
    <button v-if="can.export" type="button" @click="exportCsv"><i class="bi bi-download"></i> CSV</button>
</div>
<form v-if="filterOpen" class="filter-panel" @submit.prevent="visit">
    <label><span>من</span><input v-model="form.from" type="date" class="form-control"></label>
    <label><span>إلى</span><input v-model="form.to" type="date" class="form-control"></label>
    <label><span>نوع العملية</span><select v-model="form.eventType" class="form-select"><option value="">كل العمليات</option><option v-for="type in eventTypes" :key="type.value" :value="type.value">{{type.label}}</option></select></label>
    <button class="btn btn-primary"><i class="bi bi-search"></i> تطبيق</button>
    <button v-if="hasFilters" type="button" class="btn btn-light" @click="clearFilters">مسح</button>
</form>

<AccountingPanel title="القيود المرحّلة" :description="`${entries.total} قيد ضمن الفترة المختارة`" icon="bi-journal-check" compact>
    <div v-if="entries.data.length" class="journal-list">
        <article v-for="entry in entries.data" :key="entry.id" :class="{'is-reversed':entry.reversed}">
            <div class="entry-top">
                <span class="entry-no">{{entry.number}}</span><time>{{entry.date}}</time><bdi>{{money(entry.amount)}}</bdi>
                <a v-if="entry.correctUrl" :href="entry.correctUrl" class="correct-btn"><i class="bi bi-arrow-counterclockwise"></i><span>تصحيح</span></a>
                <span v-else-if="entry.closing" class="lock-badge"><i class="bi bi-lock-fill"></i> إقفال</span>
            </div>
            <div class="entry-main"><strong>{{entry.description}}</strong><div><span>{{entry.eventLabel}}</span><span v-if="entry.branch">{{entry.branch}}</span><span v-if="entry.reversed" class="warn">معكوس</span></div></div>
            <details><summary>تفاصيل المدين والدائن <i class="bi bi-chevron-down"></i></summary><div class="line-list"><div v-for="line in entry.lines" :key="line.id"><span><bdi>{{line.accountCode}}</bdi> — {{line.accountName}}<small v-if="line.description">{{line.description}}</small></span><span><bdi v-if="line.debit">مدين {{money(line.debit)}}</bdi><bdi v-if="line.credit">دائن {{money(line.credit)}}</bdi></span></div></div></details>
        </article>
    </div>
    <div v-else class="empty"><i class="bi bi-journal-x"></i><strong>لا توجد قيود</strong><span>غيّر الفترة أو الفلاتر، أو ابدأ أول عملية مالية.</span></div>
    <template #footer><Pagination v-if="entries.links?.length" :links="entries.links" /></template>
</AccountingPanel>
</template>

<style scoped>
.journal-tools{display:flex;gap:8px;margin-bottom:10px}.journal-tools>label{display:flex;flex:1;align-items:center;gap:8px;padding:10px 13px;border:1px solid #dfe7e2;border-radius:12px;background:#fff}.journal-tools>label:focus-within{border-color:#83b596;box-shadow:0 0 0 3px rgba(31,107,80,.09)}.journal-tools input{width:100%;border:0;outline:0;background:transparent;font-size:.82rem}.journal-tools>button{position:relative;min-height:42px;padding:9px 14px;border:1px solid #dfe7e2;border-radius:12px;color:#516057;background:#fff;font-size:.78rem;font-weight:800}.journal-tools>button.active{color:#1f6b50;border-color:#9bc6a8;background:#f1f8f3}.journal-tools>button span{position:absolute;top:6px;inset-inline-end:6px;width:7px;height:7px;border-radius:50%;background:#d97706}.filter-panel{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto auto;align-items:end;gap:9px;margin-bottom:10px;padding:13px;border:1px solid #dfe7e2;border-radius:13px;background:#fff}.filter-panel label{display:grid;gap:4px}.filter-panel label>span{font-size:.72rem;font-weight:800;color:#64746a}
.journal-list{display:grid}.journal-list>article{padding:15px 17px;border-bottom:1px solid #e9eeeb}.journal-list>article:last-child{border:0}.journal-list>article.is-reversed{background:#fffbf3}.entry-top{display:grid;grid-template-columns:155px 95px 1fr auto;align-items:center;gap:10px}.entry-no{color:#1f6b50;font-size:.76rem;font-weight:900}.entry-top time{color:#738178;font-size:.72rem}.entry-top>bdi{text-align:end;font-size:.82rem;font-weight:900}.correct-btn,.lock-badge{display:flex;min-height:34px;align-items:center;gap:5px;padding:6px 10px;border-radius:9px;font-size:.7rem;font-weight:800}.correct-btn{color:#9b5800;background:#fff1dc}.lock-badge{color:#748079;background:#f0f3f1}.entry-main{display:grid;gap:5px;margin-top:7px}.entry-main>strong{font-size:.84rem}.entry-main>div{display:flex;flex-wrap:wrap;gap:5px}.entry-main>div span{padding:3px 8px;border-radius:999px;color:#4c6254;background:#edf3ef;font-size:.65rem}.entry-main>div .warn{color:#9d5900;background:#fff0d9}.journal-list details{margin-top:9px}.journal-list summary{display:flex;width:max-content;align-items:center;gap:5px;color:#1f6b50;cursor:pointer;font-size:.71rem;font-weight:800}.line-list{display:grid;gap:5px;margin-top:7px;padding:9px;border-radius:11px;background:#f7f9f7}.line-list>div{display:flex;justify-content:space-between;gap:10px;padding:6px;border-bottom:1px solid #e7ece8;font-size:.72rem}.line-list>div:last-child{border:0}.line-list>div>span{display:grid}.line-list small{color:#7e8c84}.line-list>div>span:last-child{display:flex;gap:10px;font-weight:800}.empty{display:grid;justify-items:center;gap:4px;padding:55px 15px;color:#829087;text-align:center}.empty>i{font-size:1.7rem}.empty strong{color:#4c5b52}.empty span{font-size:.72rem}
@media(max-width:760px){.filter-panel{grid-template-columns:repeat(2,1fr)}.entry-top{grid-template-columns:1fr auto}.entry-top time{grid-column:1}.entry-top>bdi{grid-column:2;grid-row:1}.correct-btn,.lock-badge{grid-column:2;grid-row:2}.line-list>div{flex-direction:column}.journal-tools>button span:not(.bi){display:none}}
@media(max-width:500px){.journal-tools>button{padding-inline:10px}.journal-tools>button{font-size:0}.journal-tools>button i{font-size:.9rem}.filter-panel{grid-template-columns:1fr}.entry-top{grid-template-columns:1fr auto}.correct-btn span{display:none}}
</style>
