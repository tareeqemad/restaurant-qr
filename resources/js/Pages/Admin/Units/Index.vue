<script setup>
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { useConfirm } from '../../../Composables/useConfirm';
defineOptions({layout:AdminLayout});
const props=defineProps({units:{type:Object,required:true},can:{type:Object,required:true},urls:{type:Object,required:true}});
const {ask}=useConfirm();
async function remove(unit){if(unit.used)return;if(!await ask({title:'حذف وحدة القياس؟',message:`سيتم حذف ${unit.name} (${unit.code}) نهائياً.`,danger:true,confirmLabel:'حذف'}))return;router.delete(unit.urls.destroy,{preserveScroll:true})}
const tone=(type)=>({weight:'primary',volume:'info',count:'success',length:'warning'}[type]??'secondary');
</script>
<template>
<Head title="وحدات القياس"/>
<PageHeader title="وحدات القياس" icon="bi-rulers" subtitle="تعريف مركزي بسيط؛ عبوات الشراء مثل الكرتونة تُدار من ملف المكوّن"><template #actions><a v-if="can.manage" :href="urls.create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> وحدة جديدة</a></template></PageHeader>
<div class="unit-note"><i class="bi bi-lightbulb-fill"></i><div><strong>الوحدة الأساسية هي لغة المخزون</strong><small>اختر غرام/مل/قطعة كوحدة أساس. كيلوغرام أو لتر أو دزينة هي تحويلات. أما «كرتونة 12 علبة» فتُضاف كعبوة داخل المكوّن، لا كوحدة عامة.</small></div></div>
<DataPanel title="الوحدات المعرفة" :count="units.total" icon="bi-rulers">
 <div v-if="units.data.length" class="unit-grid"><article v-for="unit in units.data" :key="unit.id"><div class="unit-code" :class="`tone-${tone(unit.type)}`"><b>{{unit.code}}</b><small>{{unit.typeLabel}}</small></div><div class="unit-name"><strong>{{unit.name}}</strong></div><div class="unit-factor"><small>معامل التحويل</small><b>× {{unit.factor}}</b></div><span v-if="unit.base" class="base"><i class="bi bi-check-circle-fill"></i> أساسية</span><span v-else-if="unit.used" class="used"><i class="bi bi-lock-fill"></i> مستخدمة</span><div v-if="unit.canEdit" class="unit-actions"><a :href="unit.urls.edit" class="btn btn-light btn-sm"><i class="bi bi-pencil"></i></a><button type="button" class="btn btn-outline-danger btn-sm" :disabled="unit.used" :title="unit.used?'لا يمكن حذف وحدة مستخدمة':'حذف'" @click="remove(unit)"><i class="bi bi-trash"></i></button></div></article></div>
 <EmptyState v-else icon="bi-rulers" title="لا توجد وحدات" message="أضف وحدات القياس الأساسية أولاً."/>
 <template #footer><Pagination :links="units.links"/></template>
</DataPanel>
</template>
<style scoped>
.unit-note{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;padding:12px 14px;border:1px solid #b7d7c0;border-radius:13px;color:#176b39;background:#eff8f2}.unit-note>i{font-size:1rem}.unit-note div{display:grid}.unit-note strong{font-size:.7rem}.unit-note small{font-size:.61rem;line-height:1.65}.unit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.unit-grid article{display:grid;grid-template-columns:70px minmax(0,1fr) 100px auto auto;align-items:center;gap:9px;padding:11px;border:1px solid #e1e8e3;border-radius:13px;background:#fff}.unit-code{display:grid;min-height:49px;place-items:center;border-radius:10px;background:#eef5f0}.unit-code b{font-size:.78rem}.unit-code small{font-size:.53rem}.tone-info{color:#0f6d83;background:#edf8fb}.tone-success{color:#176b39;background:#edf8f0}.tone-warning{color:#986000;background:#fff6e5}.unit-name{display:grid}.unit-name strong{font-size:.7rem}.unit-name small,.unit-factor small{color:#849188;font-size:.56rem}.unit-factor{display:grid}.unit-factor b{font-size:.65rem}.base,.used{display:flex;align-items:center;gap:4px;padding:4px 7px;border-radius:99px;font-size:.55rem;font-weight:800}.base{color:#176b39;background:#e6f4ea}.used{color:#68766e;background:#edf0ee}.unit-actions{display:flex;gap:4px}
@media(max-width:900px){.unit-grid{grid-template-columns:1fr}}
@media(max-width:560px){.unit-grid article{grid-template-columns:62px 1fr auto}.unit-factor{grid-column:2}.unit-actions{grid-column:3;grid-row:1/3;flex-direction:column}.base,.used{grid-column:2;width:max-content}}
</style>
