<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import CatalogWorkspacePage from '../../../Components/MenuAdmin/CatalogWorkspacePage.vue';
import MenuSheet from '../../../Components/MenuAdmin/MenuSheet.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({layout:AdminLayout});
const props=defineProps({navigation:Array,stations:Array,stats:Object,storageLocations:Array,editor:Object,can:Object,urls:Object});
const {ask}=useConfirm();const editing=ref(null);
const form=useForm({code:'',name:'',color:'#1f6b50',icon:'bi-fire',storage_location_id:'',display_order:0,active:true});
function seed(item=null){form.clearErrors();form.code=item?.code??'';form.name=item?.name??'';form.color=item?.color??'#1f6b50';form.icon=item?.icon??'bi-fire';form.storage_location_id=item?.storageLocationId??'';form.display_order=item?.displayOrder??0;form.active=item?Boolean(item.active):true}
function openCreate(){editing.value={mode:'create'};seed()}
function openEdit(item){editing.value={mode:'edit',item};seed(item)}
function close(){if(!form.processing)editing.value=null}
if(props.editor?.mode==='create')openCreate();if(props.editor?.mode==='edit'&&props.editor.station)openEdit(props.editor.station);
function submit(){const edit=editing.value?.mode==='edit';const url=edit?(editing.value.item.updateUrl??editing.value.item.urls?.update):props.urls.store;const done=()=>{editing.value=null};if(edit)form.put(url,{preserveScroll:true,onSuccess:done});else form.post(url,{preserveScroll:true,onSuccess:done})}
async function destroy(item){if(!item.can.delete)return;const yes=await ask({title:`حذف محطة «${item.name}»؟`,message:'الحذف متاح فقط للمحطة الجديدة التي لم ترتبط بأصناف أو موظفين أو طلبات.',confirmLabel:'حذف المحطة',danger:true});if(yes)router.delete(item.urls.destroy,{preserveScroll:true})}
</script>

<template>
<Head title="محطات التحضير"/>
<CatalogWorkspacePage :navigation="navigation" title="محطات التحضير" icon="bi-fire"
                      subtitle="حدّد أين يصل كل صنف: المطبخ، البار أو أي نقطة إنتاج أخرى"
                      panel-title="مسار الإنتاج" panel-subtitle="المحطة ومخزون الصرف والفريق المرتبط بها"
                      panel-icon="bi-diagram-3-fill" :count="stations.length" :stats="[
                          {label:'المحطات',value:stats.total,icon:'bi-fire'},
                          {label:'نشطة',value:stats.active,icon:'bi-broadcast'},
                          {label:'أصناف موجهة',value:stats.items,icon:'bi-egg-fried'},
                          {label:'فريق المحطات',value:stats.staff,icon:'bi-people-fill',tone:'muted'},
                      ]">
 <template #actions><button v-if="can.create" type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg"></i> محطة جديدة</button></template>
 <template #beforePanel><div class="ms-tip"><i class="bi bi-lightbulb-fill"></i><div><b>قاعدة ربط بسيطة</b><span>اربط القسم بمحطة افتراضية، وحدّد محطة على الصنف فقط عندما يكون استثناءً.</span></div></div></template>
 <div class="ms-grid">
  <article v-for="item in stations" :key="item.id" class="ms-card" :class="{off:!item.active}" :style="{'--station':item.color}">
   <header><span><i class="bi" :class="item.icon||'bi-fire'"></i></span><div><h3>{{item.name}}</h3><small>{{item.code}}</small></div><b :class="item.active?'on':'off'">{{item.active?'تستقبل الطلبات':'متوقفة'}}</b></header>
   <div class="ms-route"><span><i class="bi bi-box-seam"></i> مخزون الصرف</span><strong>{{item.storageLocation||'غير محدد'}}</strong></div>
   <div class="ms-facts"><span><b>{{item.itemsCount}}</b><small>صنف</small></span><span><b>{{item.usersCount}}</b><small>موظف</small></span><span><b>{{item.historyCount}}</b><small>سطر تاريخي</small></span></div>
   <footer><button v-if="item.can.update" type="button" class="btn btn-sm btn-light" @click="openEdit(item)"><i class="bi bi-pencil"></i> ضبط المحطة</button><button v-if="item.can.delete" type="button" class="btn btn-sm btn-outline-danger ms-auto" @click="destroy(item)"><i class="bi bi-trash3"></i></button><span v-else class="ms-lock"><i class="bi bi-lock"></i> تُعطّل ولا تُحذف</span></footer>
  </article>
  <EmptyState v-if="!stations.length" icon="bi-fire" title="لا توجد محطات تحضير" message="ابدأ بالمطبخ أو البار ثم اربط الأقسام والأصناف بها."/>
 </div>
</CatalogWorkspacePage>

<MenuSheet :open="Boolean(editing)" :busy="form.processing" :title="editing?.mode==='edit'?'ضبط محطة التحضير':'محطة تحضير جديدة'" icon="bi-fire" subtitle="الكود ثابت وواضح، وموقع الصرف يجب أن يكون من نفس الفرع" @close="close">
 <form id="station-form" class="row g-3" @submit.prevent="submit">
  <div class="col-md-5"><label class="form-label">كود المحطة *</label><input v-model="form.code" class="form-control" placeholder="kitchen" required><small v-if="form.errors.code" class="ms-error">{{form.errors.code}}</small></div>
  <div class="col-md-7"><label class="form-label">الاسم *</label><input v-model="form.name" class="form-control" placeholder="المطبخ" required><small v-if="form.errors.name" class="ms-error">{{form.errors.name}}</small></div>
  <div class="col-md-6"><label class="form-label">موقع صرف المكونات</label><select v-model="form.storage_location_id" class="form-select"><option value="">— غير محدد —</option><option v-for="location in storageLocations" :key="location.id" :value="location.id">{{location.name}}{{location.isDefault?' (افتراضي)':''}}</option></select><small v-if="!storageLocations.length" class="ms-warning">لا توجد مواقع تخزين في الفرع. <a :href="urls.storageLocations">أنشئ موقعاً أولاً.</a></small></div>
  <div class="col-md-4"><label class="form-label">لون المحطة</label><input v-model="form.color" type="color" class="form-control form-control-color w-100"></div>
  <div class="col-md-4"><label class="form-label">الأيقونة</label><input v-model="form.icon" class="form-control" placeholder="bi-fire"></div>
  <div class="col-md-4"><label class="form-label">الترتيب</label><input v-model.number="form.display_order" type="number" class="form-control"></div>
  <div class="col-12"><label class="ms-toggle" :style="{'--station':form.color}"><input v-model="form.active" type="checkbox"><span class="ms-form-icon"><i class="bi" :class="form.icon||'bi-fire'"></i></span><span><b>{{form.name||'المحطة'}}</b><small>{{form.active?'نشطة وتستقبل الطلبات':'متوقفة ولا تستقبل طلبات جديدة'}}</small></span></label></div>
 </form>
 <template #footer><button type="button" class="btn btn-light" @click="close">تراجع</button><button type="submit" form="station-form" class="btn btn-primary" :disabled="form.processing"><span v-if="form.processing" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-check-circle-fill"></i> حفظ</button></template>
</MenuSheet>
</template>

<style scoped>
.ms-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(310px, 100%), 1fr)); gap: .72rem; }
.ms-card { display: flex; flex-direction: column; gap: .72rem; padding: .8rem; border: 1px solid color-mix(in srgb, var(--station) 24%, var(--catalog-line)); border-top: 3px solid var(--station); border-radius: 15px; background: #fff; box-shadow: 0 6px 18px rgba(18, 63, 49, .03); }
.ms-card.off { filter: saturate(.35); background: #f8faf9; opacity: .8; }
.ms-card header { display: flex; align-items: center; gap: .55rem; }
.ms-card header > span { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 12px; color: var(--station); background: color-mix(in srgb, var(--station) 14%, white); font-size: 1.12rem; }
.ms-card header > div { flex: 1; }
.ms-card h3 { margin: 0; color: var(--catalog-primary-deep); font-size: .9rem; font-weight: 950; }
.ms-card header small { color: #87948c; font-size: .63rem; }
.ms-card header > b { padding: .16rem .43rem; border-radius: 999px; font-size: .6rem; font-weight: 900; }
.ms-card header .on { color: var(--catalog-primary); background: var(--catalog-primary-soft); }
.ms-card header .off { color: #6c7b73; background: #eef2ef; }
.ms-route { display: flex; justify-content: space-between; gap: .5rem; padding: .5rem .6rem; border-radius: 10px; color: #6d7c74; background: #f5f8f6; font-size: .68rem; }
.ms-route span { display: inline-flex; align-items: center; gap: .24rem; }
.ms-route strong { color: #40564b; }
.ms-facts { display: grid; grid-template-columns: repeat(3, 1fr); gap: .35rem; }
.ms-facts span { display: flex; flex-direction: column; padding: .45rem; border-radius: 9px; background: #fafcfb; text-align: center; }
.ms-facts b { color: var(--station); font-size: .84rem; }
.ms-facts small { color: #849188; font-size: .6rem; }
.ms-card footer { display: flex; align-items: center; gap: .35rem; margin: auto -.8rem -.8rem; padding: .56rem .8rem; border-top: 1px solid #edf1ee; background: #fbfcfb; }
.ms-card footer .btn { display: inline-flex; align-items: center; gap: .35rem; min-height: 42px; border-radius: 10px; font-size: .7rem; font-weight: 850; }
.ms-lock { margin-inline-start: auto; color: #887854; font-size: .62rem; }
.ms-tip { display: flex; gap: .62rem; padding: .68rem .76rem; border: 1px solid #f0dfad; border-radius: 13px; color: #765a18; background: #fff9e9; }
.ms-tip > i { margin-top: .08rem; }
.ms-tip > div { display: flex; flex-direction: column; }
.ms-tip b { font-size: .76rem; }
.ms-tip span { font-size: .68rem; }
.ms-error { display: block; color: #b42334; font-size: .67rem; }
.ms-warning { display: block; margin-top: .2rem; color: #9a6810; font-size: .66rem; }
.ms-toggle { display: flex; align-items: center; gap: .65rem; min-height: 58px; padding: .7rem; border: 1px solid color-mix(in srgb, var(--station) 25%, #e0e8e2); border-radius: 12px; background: color-mix(in srgb, var(--station) 7%, white); }
.ms-form-icon { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 11px; color: var(--station); background: color-mix(in srgb, var(--station) 17%, white); }
.ms-toggle > span:last-child { display: flex; flex-direction: column; }
.ms-toggle b { font-size: .78rem; }
.ms-toggle small { color: #76857d; font-size: .66rem; }
</style>
