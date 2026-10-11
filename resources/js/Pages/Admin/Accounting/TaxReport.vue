<script setup>
import FinancialWorkspace from '../../../Components/Accounting/FinancialWorkspace.vue'
import { reactive } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'
defineOptions({layout:AdminLayout})
const props=defineProps({from:String,to:String,taxLabel:String,metrics:Object,outputCodes:Array,inputCodes:Array,currency:Object,urls:Object})
const form=reactive({from:props.from,to:props.to});const money=(v)=>`${new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))} ${props.currency.symbol}`;const visit=()=>router.get(props.urls.index,form,{preserveState:true,preserveScroll:true})
</script>
<template>
<Head :title="`تقرير ${taxLabel}`"/>
<FinancialWorkspace area="المحاسبة" mode="report">
<template #heading>
<PageHeader :title="`تقرير ${taxLabel}`" icon="bi-percent" subtitle="ملخص الحسابات الضريبية للفترة، مستقل عن رسوم الخدمة"/>
</template>
<template #navigation>
<AccountingNav :urls="urls" active="taxReport"/>
</template>
<template #filters>
<form class="filter" @submit.prevent="visit"><label><span>من</span><input v-model="form.from" type="date" class="form-control"></label><label><span>إلى</span><input v-model="form.to" type="date" class="form-control"></label><button class="btn btn-primary"><i class="bi bi-search"></i> تحديث</button></form>
</template>
<template #metrics>
<div class="tax-metrics"><article><span><i class="bi bi-arrow-down-circle"></i></span><small>ضريبة مخرجات من الزبائن</small><strong>{{money(metrics.outputTax)}}</strong><em>حسابات {{outputCodes.join('، ')}}</em></article><article><span><i class="bi bi-arrow-up-circle"></i></span><small>ضريبة مدخلات قابلة للخصم</small><strong>{{money(metrics.inputTax)}}</strong><em>حسابات {{inputCodes.join('، ')}}</em></article><article class="payable"><span><i class="bi bi-building-check"></i></span><small>الصافي المستحق</small><strong>{{money(metrics.payable)}}</strong><em>{{metrics.payable>0?'مبلغ مستحق حتى نهاية الفترة':metrics.payable<0?'رصيد دائن لصالح المطعم':'لا يوجد رصيد مستحق'}}</em></article></div>
</template>
<section class="tax-note"><i class="bi bi-info-circle-fill"></i><div><strong>هذا التقرير للضريبة الحكومية فقط</strong><small>رسوم الخدمة التي يحددها المطعم ليست ضريبة ولا تظهر هنا. تغيير النسبة يؤثر في الفواتير الجديدة من تاريخ السريان فقط.</small></div></section>
<AccountingPanel title="كيف تقرأ النتيجة؟" description="معادلة قصيرة تحفظ التقرير واضحاً وقابلاً للمراجعة." icon="bi-calculator"><div class="formula"><span><small>مخرجات الزبائن</small><bdi>{{money(metrics.outputTax)}}</bdi></span><i class="bi bi-dash-lg"></i><span><small>مدخلات الموردين</small><bdi>{{money(metrics.inputTax)}}</bdi></span><i class="bi bi-equals"></i><span class="result"><small>الصافي</small><bdi>{{money(metrics.payable)}}</bdi></span></div></AccountingPanel>
</FinancialWorkspace>

</template><style scoped>.tax-note{display:flex;gap:10px;margin-bottom:10px;padding:12px 14px;border:1px solid #b8d7e8;border-radius:12px;color:#205f82;background:#f0f8fc}.tax-note>div{display:grid}.tax-note strong{font-size:.875rem}.tax-note small{font-size:.875rem;line-height:1.55}.filter{display:flex;align-items:end;gap:9px;margin-bottom:11px;padding:10px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.filter label{display:grid;gap:4px;width:190px}.filter label span{font-size:.875rem;font-weight:850}.tax-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}.tax-metrics article{display:grid;grid-template-columns:auto 1fr;gap:2px 9px;padding:15px;border:1px solid var(--line, #DFD7CB);border-radius:12px;background:var(--surface, #FFFDFA)}.tax-metrics article>span{display:grid;grid-row:1/4;width:39px;height:39px;place-items:center;border-radius:11px;color:var(--ink, #25343A);background:var(--brand-soft, #F3E3D8)}.tax-metrics small{color:var(--muted, #626B6D);font-size:.875rem}.tax-metrics strong{font-size:1rem}.tax-metrics em{color:var(--muted, #626B6D);font-size:.875rem;font-style:normal}.tax-metrics .payable{border-color:var(--line, #DFD7CB);background:var(--surface, #FFFDFA)}.formula{display:flex;align-items:center;justify-content:center;gap:18px;padding:10px}.formula span{display:grid;min-width:150px;gap:3px;text-align:center}.formula small{color:var(--muted, #626B6D);font-size:.875rem}.formula bdi{font-size:.9rem;font-weight:900}.formula i{color:var(--muted, #626B6D)}.formula .result{padding:12px;border-radius:12px;color:var(--ink, #25343A);background:var(--brand-soft, #F3E3D8)}@media(max-width:720px){.tax-metrics{grid-template-columns:1fr}.formula{align-items:stretch;flex-direction:column;gap:7px}.formula>i{align-self:center}.filter{display:grid;grid-template-columns:1fr 1fr}.filter label{width:100%}.filter button{grid-column:1/-1}}</style>
