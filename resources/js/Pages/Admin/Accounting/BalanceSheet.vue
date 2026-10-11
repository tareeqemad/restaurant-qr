<script setup>
import FinancialWorkspace from '../../../Components/Accounting/FinancialWorkspace.vue'
import { reactive } from 'vue'
import { Link, Head, router } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'
defineOptions({layout:AdminLayout})
const props=defineProps({asOf:String,sections:Object,metrics:Object,currency:Object,urls:Object})
const form=reactive({as_of:props.asOf})
const money=(v)=>`${new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))} ${props.currency.symbol}`
const visit=()=>router.get(props.urls.index,form,{preserveState:true,preserveScroll:true})
</script>
<template>
<Head title="الميزانية العمومية"/>
<FinancialWorkspace area="المحاسبة" mode="report">
<template #heading>
<PageHeader title="الميزانية العمومية" icon="bi-bank2" subtitle="ما يملكه المطعم وما عليه وحقوق أصحابه في تاريخ محدد"/>
</template>
<template #navigation>
<AccountingNav :urls="urls" active="balanceSheet"/>
</template>
<template #filters>
<form class="asof" @submit.prevent="visit"><label><span>كما في تاريخ</span><input v-model="form.as_of" type="date" class="form-control"></label><button class="btn btn-primary"><i class="bi bi-search"></i> تحديث</button></form>
</template>
<template #metrics>
<div class="summary"><article><small>إجمالي الأصول</small><strong>{{money(metrics.totalAssets)}}</strong></article><i class="bi bi-arrow-left-right"></i><article><small>الالتزامات + حقوق الملكية</small><strong>{{money(metrics.totalLiabilities+metrics.totalEquity)}}</strong></article></div>
</template>
<section class="equation" :class="metrics.balanced?'ok':'bad'"><span><i class="bi" :class="metrics.balanced?'bi-check-circle-fill':'bi-exclamation-triangle-fill'"></i></span><div><strong>{{metrics.balanced?'المعادلة المحاسبية متوازنة':'هناك فرق يحتاج مراجعة'}}</strong><small>الأصول = الالتزامات + حقوق الملكية</small></div><bdi v-if="!metrics.balanced">فرق {{money(Math.abs(metrics.difference))}}</bdi></section>
<div class="sheets">
<AccountingPanel title="الأصول" description="النقد والبنوك والذمم والمخزون والأصول الثابتة" icon="bi-safe2"><div class="rows"><Link v-for="row in sections.assets" :key="row.id" :href="row.ledgerUrl"><span><bdi>{{row.code}}</bdi><strong>{{row.name}}</strong></span><bdi>{{money(row.balance)}}</bdi></Link><p v-if="!sections.assets.length">لا توجد أرصدة أصول.</p><footer><strong>إجمالي الأصول</strong><bdi>{{money(metrics.totalAssets)}}</bdi></footer></div></AccountingPanel>
<div class="right"><AccountingPanel title="الالتزامات" description="الذمم والمبالغ المستحقة على المطعم" icon="bi-receipt-cutoff" tone="amber"><div class="rows"><Link v-for="row in sections.liabilities" :key="row.id" :href="row.ledgerUrl"><span><bdi>{{row.code}}</bdi><strong>{{row.name}}</strong></span><bdi>{{money(row.balance)}}</bdi></Link><footer><strong>إجمالي الالتزامات</strong><bdi>{{money(metrics.totalLiabilities)}}</bdi></footer></div></AccountingPanel><AccountingPanel title="حقوق الملكية" description="رأس المال والأرباح المحتجزة ونتيجة الفترة" icon="bi-people-fill" tone="blue"><div class="rows"><Link v-for="row in sections.equity" :key="row.id" :href="row.ledgerUrl"><span><bdi>{{row.code}}</bdi><strong>{{row.name}}</strong></span><bdi>{{money(row.balance)}}</bdi></Link><div class="earnings"><span><strong>نتيجة الفترة الحالية</strong><small>قبل الإقفال</small></span><bdi>{{money(metrics.currentEarnings)}}</bdi></div><footer><strong>إجمالي حقوق الملكية</strong><bdi>{{money(metrics.totalEquity)}}</bdi></footer></div></AccountingPanel></div>
</div>
</FinancialWorkspace>

</template>
<style scoped>.equation{display:flex;align-items:center;gap:10px;margin-bottom:10px;padding:12px 14px;border:1px solid;border-radius:12px}.equation.ok{color:var(--ink, #25343A);border-color:var(--line, #DFD7CB);background:var(--surface, #FFFDFA)}.equation.bad{color:#aa2828;border-color:#e7b5b5;background:#fff4f4}.equation>span{font-size:1.2rem}.equation>div{display:grid;flex:1}.equation strong{font-size:.875rem}.equation small{opacity:.72;font-size:.875rem}.equation>bdi{font-size:.875rem;font-weight:900}.asof{display:flex;align-items:end;gap:9px;margin-bottom:10px;padding:10px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.asof label{display:grid;gap:4px;width:240px}.asof label span{font-size:.875rem;font-weight:850}.summary{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;margin-bottom:12px}.summary article{display:grid;padding:13px 15px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.summary small{color:var(--muted, #626B6D);font-size:.875rem}.summary strong{font-size:.95rem}.summary>i{color:var(--muted, #626B6D)}.sheets{display:grid;grid-template-columns:1fr 1fr;align-items:start;gap:12px}.right{display:grid;gap:12px}.rows{display:grid}.rows>a,.rows footer,.earnings{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--line, #DFD7CB);color:var(--ink, #25343A)}.rows>a>span,.earnings>span{display:flex;flex:1;align-items:center;gap:7px}.rows a span bdi{color:var(--ink, #25343A);font-weight:900}.rows a strong,.earnings strong{font-size:.875rem}.rows>a>bdi,.rows footer bdi,.earnings>bdi{font-size:.875rem;font-weight:850}.rows footer{border:0;padding-top:13px}.rows footer strong{flex:1;font-size:.875rem}.rows p{margin:20px 0;color:var(--muted, #626B6D);font-size:.875rem;text-align:center}.earnings span{display:grid;gap:1px}.earnings small{color:var(--muted, #626B6D);font-size:.875rem}@media(max-width:850px){.sheets{grid-template-columns:1fr}}@media(max-width:520px){.asof{display:grid}.asof label{width:100%}.summary{grid-template-columns:1fr}.summary>i{display:none}}</style>
