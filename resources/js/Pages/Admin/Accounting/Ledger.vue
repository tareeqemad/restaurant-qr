<script setup>
import FinancialWorkspace from '../../../Components/Accounting/FinancialWorkspace.vue'
import { reactive } from 'vue'
import { Link, Head, router } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import Pagination from '../../../Components/Ui/Pagination.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'
defineOptions({layout:AdminLayout})
const props=defineProps({currency:Object,accounts:Array,account:Object,lines:Object,filters:Object,summary:Object,urls:Object})
const form=reactive({...props.filters})
function visit(){router.get(props.urls.index,{account_id:form.accountId,from:form.from,to:form.to},{preserveState:true,preserveScroll:true})}
function money(v){return `${new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Math.abs(Number(v||0)))} ${props.currency.symbol}`}
function balance(v){const n=Number(v||0);return Math.abs(n)<.0001?`0.00 ${props.currency.symbol}`:`${money(n)} ${n>0?'مدين':'دائن'}`}
</script>
<template>
<Head title="دفتر الأستاذ"/>
<FinancialWorkspace area="المحاسبة" mode="report">
<template #heading>
<PageHeader title="دفتر الأستاذ" icon="bi-journal-bookmark-fill" subtitle="كشف حساب واحد مع رصيده السابق والجاري"/>
</template>
<template #navigation>
<AccountingNav :urls="urls" active="ledger"/>
</template>
<template #filters>
<form class="ledger-filter" @submit.prevent="visit"><label class="account-pick"><span>الحساب</span><select v-model="form.accountId" class="form-select" required><option v-for="item in accounts" :key="item.id" :value="item.id">{{item.code}} — {{item.name}}{{item.active?'':' (معطّل)'}}</option></select></label><label><span>من</span><input v-model="form.from" type="date" class="form-control"></label><label><span>إلى</span><input v-model="form.to" type="date" class="form-control"></label><button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button></form>
</template>
<template v-if="account">
<div class="ledger-summary"><article class="account"><small>الحساب</small><strong>{{account.code}} — {{account.name}}</strong></article><article><small>الرصيد السابق</small><strong>{{balance(summary.opening)}}</strong></article><article><small>حركة مدين</small><strong class="debit">{{money(summary.debit)}}</strong></article><article><small>حركة دائن</small><strong class="credit">{{money(summary.credit)}}</strong></article><article class="closing"><small>رصيد نهاية المدة</small><strong>{{balance(summary.closing)}}</strong></article></div>
<AccountingPanel title="حركات الحساب" :description="`${lines?.total||0} حركة في المدة`" icon="bi-list-columns-reverse" compact>
<div v-if="lines?.data?.length" class="ledger-list"><div class="ledger-head"><span>التاريخ</span><span>القيد</span><span>البيان</span><span>مدين</span><span>دائن</span><span>الرصيد</span></div><article v-if="lines.current_page===1" class="opening"><time>{{filters.from}}</time><span>—</span><strong>رصيد سابق</strong><span></span><span></span><bdi>{{balance(summary.opening)}}</bdi></article><article v-for="line in lines.data" :key="line.id"><time>{{line.date}}</time><Link :href="line.journalUrl">{{line.entryNumber}}</Link><div><strong>{{line.description}}</strong><small>{{line.entryDescription}}<template v-if="line.creator"> · بواسطة {{line.creator}}</template></small></div><bdi class="debit">{{line.debit?money(line.debit):'—'}}</bdi><bdi class="credit">{{line.credit?money(line.credit):'—'}}</bdi><bdi>{{balance(line.runningBalance)}}</bdi></article></div>
<div v-else class="empty"><i class="bi bi-journal-x"></i><strong>لا توجد حركة</strong><span>لا توجد قيود على هذا الحساب ضمن المدة.</span></div><template #footer><Pagination v-if="lines?.links" :links="lines.links"/></template></AccountingPanel>
</template>
<div v-else class="empty empty--page"><i class="bi bi-diagram-3"></i><strong>شجرة الحسابات فارغة</strong><Link :href="urls.accounts">أنشئ الحسابات أولاً</Link></div>
</FinancialWorkspace>

</template>
<style scoped>
.ledger-filter{display:grid;grid-template-columns:minmax(260px,1fr) 170px 170px auto;align-items:end;gap:9px;margin-bottom:10px;padding:12px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.ledger-filter label{display:grid;gap:4px}.ledger-filter label>span{color:var(--muted, #626B6D);font-size:.875rem;font-weight:800}.ledger-summary{display:grid;grid-template-columns:1.6fr repeat(4,1fr);gap:8px;margin-bottom:10px}.ledger-summary article{display:grid;align-content:center;min-height:78px;padding:11px 12px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.ledger-summary small{color:var(--muted, #626B6D);font-size:.875rem}.ledger-summary strong{font-size:.875rem}.ledger-summary .closing{border-color:var(--line, #DFD7CB);background:var(--surface, #FFFDFA)}.debit{color:#A3422B}.credit{color:#b64242}.ledger-list{overflow-x:auto}.ledger-head,.ledger-list article{display:grid;grid-template-columns:90px 145px minmax(260px,1fr) 105px 105px 135px;align-items:center;gap:9px;min-width:850px;padding:9px 14px}.ledger-head{color:var(--muted, #626B6D);background:var(--surface, #FFFDFA);font-size:.875rem;font-weight:850}.ledger-list article{border-bottom:1px solid var(--line, #DFD7CB);font-size:.875rem}.ledger-list article:last-child{border:0}.ledger-list article.opening{background:var(--surface, #FFFDFA)}.ledger-list time{color:var(--muted, #626B6D)}.ledger-list article>a{color:var(--ink, #25343A);font-weight:850}.ledger-list article>div{display:grid}.ledger-list article>div strong{font-size:.875rem}.ledger-list article>div small{color:var(--muted, #626B6D);font-size:.875rem}.ledger-list bdi{text-align:end;font-weight:800}.empty{display:grid;justify-items:center;gap:5px;padding:50px 15px;color:var(--muted, #626B6D);text-align:center}.empty i{font-size:1.6rem}.empty strong{color:var(--muted, #626B6D)}.empty span,.empty a{font-size:.875rem}.empty a{color:var(--ink, #25343A);font-weight:800}.empty--page{border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}
@media(max-width:1000px){.ledger-filter{grid-template-columns:1fr 1fr auto}.account-pick{grid-column:1/-1}.ledger-summary{grid-template-columns:repeat(2,1fr)}.ledger-summary .account,.ledger-summary .closing{grid-column:1/-1}}
@media(max-width:560px){.ledger-filter{grid-template-columns:1fr}.account-pick{grid-column:auto}.ledger-summary{grid-template-columns:1fr}.ledger-summary .account,.ledger-summary .closing{grid-column:auto}}

.ledger-summary{gap:1px;border:1px solid #DFD7CB;border-radius:16px;overflow:hidden;background:#DFD7CB}.ledger-summary>article{border:0;border-radius:0;padding:22px;background:#FFFDFA}.ledger-summary strong{font-size:26px}.ledger-table{overflow-x:auto}.ledger-table th,.ledger-table td{padding:16px}.account-banner{padding:22px;background:#25343A;color:#FFFDFA}
</style>
