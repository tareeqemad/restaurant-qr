<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
defineOptions({layout:AdminLayout});
const props=defineProps({ingredientCount:{type:Number,required:true},locations:{type:Array,default:()=>[]},defaults:{type:Object,required:true},urls:{type:Object,required:true}});
const form=useForm({count_date:props.defaults.date,storage_location_id:'',notes:''});
const picked=computed(()=>props.locations.find(x=>x.id===Number(form.storage_location_id))??null);
const submit=()=>form.post(props.urls.store);
</script>
<template>
<Head title="بدء جرد"/>
<PageHeader title="بدء جرد فعلي" icon="bi-clipboard-plus-fill" subtitle="حدد النطاق فقط؛ إدخال الكميات ومراجعتها يأتي في الشاشة التالية" :crumbs="[{label:'الجرد',url:urls.index}]"/>
<form @submit.prevent="submit">
 <div class="count-create">
  <DataPanel title="نطاق الجرد" icon="bi-bullseye">
   <div class="scope-grid">
    <button type="button" :class="{active:form.storage_location_id===''}" @click="form.storage_location_id=''"><i class="bi bi-building"></i><span><strong>إجمالي الفرع</strong><small>العد الشامل لكل المخزون في الفرع.</small></span><i class="bi" :class="form.storage_location_id===''?'bi-check-circle-fill':'bi-circle'"></i></button>
    <button v-for="location in locations" :key="location.id" type="button" :class="{active:Number(form.storage_location_id)===location.id}" @click="form.storage_location_id=location.id"><i class="bi bi-geo-alt"></i><span><strong>{{location.name}}</strong><small>{{location.code||'موقع تخزين محدد'}}<template v-if="location.default"> · الافتراضي</template></small></span><i class="bi" :class="Number(form.storage_location_id)===location.id?'bi-check-circle-fill':'bi-circle'"></i></button>
   </div>
  </DataPanel>
  <DataPanel title="بيانات العملية" icon="bi-calendar3">
   <div class="count-fields"><label><span>تاريخ الجرد *</span><input v-model="form.count_date" type="date" class="form-control" required><small v-if="form.errors.count_date">{{form.errors.count_date}}</small></label><label class="wide"><span>ملاحظة اختيارية</span><textarea v-model="form.notes" rows="3" class="form-control" placeholder="مثلاً: جرد شهري أو مراجعة مخزن رئيسي"></textarea></label></div>
  </DataPanel>
  <aside class="count-summary"><div><i class="bi bi-clipboard-data"></i><span><small>سيُنشأ جرد لـ</small><strong>{{ingredientCount}} مكوّن متتبع</strong></span></div><p><i class="bi bi-geo-alt"></i>{{picked?.name??'إجمالي الفرع'}}</p><p><i class="bi bi-shield-check"></i>إنشاء الجرد لا يغيّر الرصيد. التغيير يحصل فقط بعد إدخال الكميات والضغط على «اعتماد».</p></aside>
 </div>
 <div class="save-bar"><a :href="urls.index" class="btn btn-light">إلغاء</a><span></span><button class="btn btn-primary" :disabled="form.processing"><i class="bi bi-play-circle-fill"></i> {{form.processing?'جارٍ الإنشاء…':'ابدأ العد'}}</button></div>
</form>
</template>
<style scoped>
.count-create{display:grid;grid-template-columns:minmax(0,1fr) 300px;align-items:start;gap:10px}.count-create>:first-child,.count-create>:nth-child(2){grid-column:1}.count-create aside{grid-column:2;grid-row:1/3}.scope-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.scope-grid button{display:flex;align-items:center;gap:10px;min-height:64px;padding:11px;border:1px solid #dfe7e2;border-radius:13px;color:#526158;background:#fff;text-align:start}.scope-grid button>i:first-child{display:grid;width:38px;height:38px;place-items:center;border-radius:10px;color:#176b39;background:#edf6ef}.scope-grid button span{display:grid;flex:1}.scope-grid strong{font-size:.69rem}.scope-grid small{color:#829087;font-size:.57rem}.scope-grid button>i:last-child{color:#9ba69f}.scope-grid button.active{border-color:#96c6a4;color:#176b39;background:#f0f8f2}.scope-grid button.active>i:last-child{color:#176b39}.count-fields{display:grid;grid-template-columns:200px 1fr;gap:10px}.count-fields label{display:grid;gap:5px}.count-fields label>span{font-size:.65rem;font-weight:850}.count-fields label>small{color:#b42318;font-size:.58rem}.count-summary{position:sticky;top:180px;display:grid;gap:10px;padding:16px;border:1px solid #bdd8c5;border-radius:15px;background:#f1f8f3}.count-summary>div{display:flex;align-items:center;gap:10px}.count-summary>div>i{display:grid;width:42px;height:42px;place-items:center;border-radius:11px;color:#176b39;background:#dfeee3;font-size:1.1rem}.count-summary span{display:grid}.count-summary small{color:#718078;font-size:.58rem}.count-summary strong{font-size:.75rem}.count-summary p{display:flex;gap:8px;margin:0;padding-top:9px;border-top:1px solid #dce9df;color:#5d6d63;font-size:.62rem;line-height:1.65}.count-summary p i{color:#176b39}.save-bar{position:sticky;z-index:8;bottom:8px;display:flex;gap:8px;margin-top:10px;padding:11px;border:1px solid #cbded1;border-radius:14px;background:rgba(255,255,255,.97);box-shadow:0 12px 35px rgba(20,50,31,.12)}.save-bar span{flex:1}
@media(max-width:850px){.count-create{grid-template-columns:1fr}.count-create>:first-child,.count-create>:nth-child(2),.count-create aside{grid-column:1;grid-row:auto}.count-summary{position:static;order:-1}.count-fields{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.scope-grid,.count-fields{grid-template-columns:1fr}.save-bar .btn-primary{flex:1}}
</style>
