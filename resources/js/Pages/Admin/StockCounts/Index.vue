<script setup>
import ManagementPage from '../../../Components/Inventory/ManagementPage.vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
defineOptions({layout:AdminLayout});
const props=defineProps({counts:{type:Object,required:true},stats:{type:Object,required:true},filters:{type:Object,required:true},can:{type:Object,required:true},urls:{type:Object,required:true}});
const setStatus=(status)=>router.get(props.urls.index,{status:status||undefined},{preserveState:true,replace:true});
</script>
<template>
    <ManagementPage mode="records">
<Head title="الجرد الفعلي"/>
<PageHeader title="الجرد الفعلي" icon="bi-clipboard-check-fill" subtitle="عدّ الواقع أولاً، راجع الفرق، ثم اعتمد التسوية مرة واحدة"><template #actions><a v-if="can.create" :href="urls.create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> بدء جرد</a></template></PageHeader>
<StatRail :stats="[{label:'مسودات تحتاج إكمالاً',value:stats.draftCount,icon:'bi-pencil-square',color:stats.draftCount?'accent':'muted'},{label:'عمليات هذا الشهر',value:stats.monthCount,icon:'bi-calendar-check',color:'primary'},{label:'آخر اعتماد',value:stats.lastFinalized,icon:'bi-clock-history',color:'muted'},{label:'صافي فروقات الشهر',value:stats.monthVariance,icon:'bi-plus-slash-minus',color:Math.abs(stats.monthVarianceRaw)>100?'danger':'success'}]"/>
<div class="count-lenses"><button :class="{active:!filters.status}" @click="setStatus('')"><i class="bi bi-layers"></i> الكل</button><button :class="{active:filters.status==='draft'}" @click="setStatus('draft')"><i class="bi bi-pencil-square"></i> قيد العد <b>{{stats.draftCount}}</b></button><button :class="{active:filters.status==='finalized'}" @click="setStatus('finalized')"><i class="bi bi-check2-circle"></i> معتمد</button><button :class="{active:filters.status==='cancelled'}" @click="setStatus('cancelled')"><i class="bi bi-x-circle"></i> ملغي</button></div>
<DataPanel title="سجل الجرد" :count="counts.total" icon="bi-clipboard-data">
 <div v-if="counts.data.length" class="count-list"><a v-for="count in counts.data" :key="count.id" :href="count.url" class="count-card" :class="`is-${count.status}`"><div class="count-state"><i class="bi" :class="count.status==='draft'?'bi-pencil':count.status==='finalized'?'bi-check2':'bi-x'"></i></div><div class="count-main"><div><strong>{{count.number}}</strong><span :class="`bg-${count.statusColor}-transparent text-${count.statusColor}`">{{count.statusLabel}}</span></div><small><i class="bi bi-calendar3"></i>{{count.date}}<i class="bi bi-geo-alt"></i>{{count.location}}</small></div><span class="count-items"><small>المكونات</small><b>{{count.itemsCount}}</b></span><span class="count-people"><small>أنشأه {{count.creator}}</small><small v-if="count.finalizer">اعتمده {{count.finalizer}} · {{count.finalizedAgo}}</small></span><span class="count-action">{{count.editable?'متابعة العد':'عرض'}} <i class="bi bi-chevron-left"></i></span></a></div>
 <EmptyState v-else icon="bi-clipboard-check" title="لا توجد عمليات في هذه الحالة" message="ابدأ جرداً عند الحاجة؛ لن يتغير الرصيد قبل الاعتماد."><template #cta><a v-if="can.create" :href="urls.create" class="btn btn-primary">بدء جرد</a></template></EmptyState>
 <template #footer><Pagination :links="counts.links"/></template>
</DataPanel>
    </ManagementPage>
</template>
<style scoped>
.count-lenses{display:flex;gap:6px;overflow-x:auto;margin-bottom:10px;padding:5px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--canvas, #F7F3ED)}.count-lenses button{display:flex;flex:0 0 auto;align-items:center;gap:6px;min-height:38px;padding:0 12px;border:0;border-radius:9px;color:var(--muted, #626B6D);background:transparent;font-size:.875rem;font-weight:800}.count-lenses button.active{color:var(--ink, #25343A);background:var(--surface, #FFFDFA);box-shadow:none}.count-lenses b{display:grid;min-width:19px;height:19px;place-items:center;border-radius:12px;background:var(--canvas, #F7F3ED);font-size:.875rem}.count-list{display:grid;gap:7px}.count-card{display:grid;grid-template-columns:40px minmax(170px,1.2fr) 80px minmax(150px,.8fr) auto;align-items:center;gap:11px;padding:11px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;color:var(--ink, #25343A);background:var(--surface, #FFFDFA)}.count-card.is-draft{border-inline-start:4px solid #d88914}.count-state{display:grid;width:38px;height:38px;place-items:center;border-radius:10px;color:var(--ink, #25343A);background:var(--canvas, #F7F3ED)}.count-main{display:grid;gap:5px}.count-main>div{display:flex;align-items:center;gap:7px}.count-main strong{font-size:.875rem}.count-main span{padding:3px 6px;border-radius:12px;font-size:.875rem;font-weight:800}.count-main small{display:flex;flex-wrap:wrap;gap:5px;color:var(--muted, #626B6D);font-size:.875rem}.count-items,.count-people{display:grid}.count-items{text-align:center}.count-items small,.count-people small{color:var(--muted, #626B6D);font-size:.875rem}.count-items b{color:var(--ink, #25343A)}.count-action{display:flex;align-items:center;gap:8px;color:var(--ink, #25343A);font-size:.875rem;font-weight:850}
@media(max-width:800px){.count-card{grid-template-columns:38px 1fr auto}.count-items{grid-column:2;text-align:start;display:flex;align-items:center;gap:6px}.count-people{grid-column:2}.count-action{grid-column:3;grid-row:1/4}}
@media(max-width:500px){.count-card{grid-template-columns:36px 1fr}.count-action{grid-column:1/-1;grid-row:auto;justify-content:center;min-height:35px;border-radius:8px;background:var(--canvas, #F7F3ED)}.count-main>div{align-items:flex-start;flex-direction:column}}

/* Dar Al Sarw management layout */
.count-lenses { padding: 6px; gap: 8px; margin-bottom: 18px; border-radius: 10px; background: var(--surface); } .count-lenses button { min-height: 44px; border-radius: 8px; } .count-card { padding: 18px; border-radius: 10px; }
</style>
