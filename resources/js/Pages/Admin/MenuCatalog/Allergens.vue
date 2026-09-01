<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import CatalogWorkspacePage from '../../../Components/MenuAdmin/CatalogWorkspacePage.vue';
import MenuSheet from '../../../Components/MenuAdmin/MenuSheet.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });
const props=defineProps({navigation:Array,allergens:Object,stats:Object,editor:Object,can:Object,urls:Object});
const {ask}=useConfirm(); const editing=ref(null);
const form=useForm({code:'',name:'',icon:'',description:'',display_order:0});
function seed(item=null){form.clearErrors();form.code=item?.code??'';form.name=item?.name??'';form.icon=item?.icon??'';form.description=item?.description??'';form.display_order=item?.displayOrder??0}
function openCreate(){editing.value={mode:'create'};seed()}
function openEdit(item){editing.value={mode:'edit',item};seed(item)}
function close(){if(!form.processing)editing.value=null}
if(props.editor?.mode==='create')openCreate();if(props.editor?.mode==='edit'&&props.editor.allergen)openEdit(props.editor.allergen);
function submit(){const edit=editing.value?.mode==='edit';const url=edit?(editing.value.item.updateUrl??editing.value.item.urls?.update):props.urls.store;const done=()=>{editing.value=null};if(edit)form.put(url,{preserveScroll:true,onSuccess:done});else form.post(url,{preserveScroll:true,onSuccess:done})}
async function destroy(item){if(!item.can.delete)return;const yes=await ask({title:`حذف «${item.name}»؟`,message:'لن يمكن حذفه إذا كان مرتبطاً بأي صنف.',confirmLabel:'حذف التنبيه',danger:true});if(yes)router.delete(item.urls.destroy,{preserveScroll:true})}
</script>

<template>
<Head title="مسببات الحساسية"/>
<CatalogWorkspacePage :navigation="navigation" title="مسببات الحساسية" icon="bi-shield-exclamation"
                      subtitle="تنبيهات قصيرة وموحّدة يراها الزبون قبل إضافة الصنف"
                      panel-title="دليل الحساسية" panel-subtitle="استخدم فقط التنبيهات التي يحتاجها مطعمك"
                      panel-icon="bi-shield-exclamation" :count="allergens.total" :stats="[
                          {label:'كل التنبيهات',value:stats.total,icon:'bi-shield-exclamation'},
                          {label:'مستخدمة',value:stats.used,icon:'bi-egg-fried'},
                          {label:'غير مستخدمة',value:stats.unused,icon:'bi-inbox',tone:'muted'},
                      ]">
 <template #actions><button v-if="can.create" type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg"></i> تنبيه جديد</button></template>
 <div class="ma-grid">
  <article v-for="item in allergens.data" :key="item.id" class="ma-card">
   <span class="ma-symbol">{{item.icon||'⚠️'}}</span>
   <div class="ma-copy"><h3>{{item.name}}</h3><small>{{item.code}}</small><p v-if="item.description">{{item.description}}</p><span><i class="bi bi-egg-fried"></i> {{item.itemsCount}} صنف</span></div>
   <div class="ma-actions"><button v-if="item.can.update" type="button" @click="openEdit(item)" title="تعديل"><i class="bi bi-pencil"></i></button><button v-if="item.can.delete" type="button" class="danger" @click="destroy(item)" title="حذف"><i class="bi bi-trash3"></i></button><i v-else-if="item.itemsCount" class="bi bi-lock" title="مستخدم على أصناف"></i></div>
  </article>
  <EmptyState v-if="!allergens.data.length" icon="bi-shield-exclamation" title="لا توجد تنبيهات حساسية" message="أضف التنبيهات التي يحتاجها منيو المطعم فقط."/>
 </div>
 <template #footer><Pagination :links="allergens.links"/></template>
</CatalogWorkspacePage>
<MenuSheet :open="Boolean(editing)" :busy="form.processing" :title="editing?.mode==='edit'?'تعديل تنبيه الحساسية':'تنبيه حساسية جديد'" icon="bi-shield-exclamation" subtitle="اجعله قصيراً ومفهوماً للزبون" @close="close">
 <form id="allergen-form" class="row g-3" @submit.prevent="submit">
  <div class="col-md-4"><label class="form-label">الرمز *</label><input v-model="form.code" class="form-control" placeholder="nuts" required><small v-if="form.errors.code" class="ma-error">{{form.errors.code}}</small></div>
  <div class="col-md-8"><label class="form-label">الاسم *</label><input v-model="form.name" class="form-control" placeholder="مكسرات" required><small v-if="form.errors.name" class="ma-error">{{form.errors.name}}</small></div>
  <div class="col-md-2"><label class="form-label">رمز بصري</label><input v-model="form.icon" class="form-control text-center" placeholder="🥜"></div>
  <div class="col-md-3"><label class="form-label">الترتيب</label><input v-model.number="form.display_order" type="number" class="form-control"></div>
  <div class="col-12"><label class="form-label">توضيح مختصر</label><textarea v-model="form.description" rows="4" class="form-control" placeholder="يحتوي أو قد يحتوي على..."></textarea></div>
  <div class="col-12 ma-preview"><span>{{form.icon||'⚠️'}}</span><div><b>{{form.name||'اسم التنبيه'}}</b><small>{{form.description||'سيظهر بهذه الصورة للزبون على بطاقة الصنف.'}}</small></div></div>
 </form>
 <template #footer><button type="button" class="btn btn-light" @click="close">تراجع</button><button type="submit" form="allergen-form" class="btn btn-primary" :disabled="form.processing"><span v-if="form.processing" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-check-circle-fill"></i> حفظ</button></template>
</MenuSheet>
</template>

<style scoped>
.ma-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(290px, 100%), 1fr)); gap: .65rem; }
.ma-card { display: grid; grid-template-columns: 58px minmax(0, 1fr) auto; gap: .68rem; align-items: start; min-height: 112px; padding: .75rem; border: 1px solid var(--catalog-line); border-radius: 14px; background: #fff; box-shadow: 0 6px 18px rgba(18, 63, 49, .03); }
.ma-symbol { display: grid; place-items: center; width: 58px; height: 58px; border-radius: 14px; color: #8e2b37; background: #fff2f3; font-size: 1.5rem; }
.ma-copy { min-width: 0; }
.ma-copy h3 { margin: 0; color: var(--catalog-primary-deep); font-size: .86rem; font-weight: 950; }
.ma-copy small { color: #8b9790; font-size: .63rem; }
.ma-copy p { display: -webkit-box; overflow: hidden; margin: .32rem 0; color: #718078; font-size: .68rem; line-height: 1.5; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.ma-copy > span { display: inline-flex; align-items: center; gap: .24rem; color: #697a70; font-size: .64rem; }
.ma-actions { display: flex; gap: .28rem; align-items: center; }
.ma-actions button { display: inline-grid; place-items: center; width: 44px; height: 44px; border: 1px solid var(--catalog-line); border-radius: 10px; color: var(--catalog-primary-deep); background: #fff; cursor: pointer; }
.ma-actions button:hover { color: var(--catalog-primary); background: var(--catalog-primary-soft); }
.ma-actions button.danger { color: #ad2938; border-color: #efd2d5; background: #fff8f8; }
.ma-actions > i { padding-top: .55rem; color: #9a8b68; }
.ma-error { display: block; color: #b42334; font-size: .67rem; }
.ma-preview { display: flex; align-items: center; gap: .7rem; padding: .7rem; border: 1px solid #f0d9dc; border-radius: 12px; background: #fff7f8; }
.ma-preview > span { font-size: 1.6rem; }
.ma-preview > div { display: flex; flex-direction: column; }
.ma-preview b { color: #6d2730; font-size: .78rem; }
.ma-preview small { color: #8b6970; font-size: .67rem; }
@media (max-width: 420px) {
    .ma-card { grid-template-columns: 52px minmax(0, 1fr) auto; padding: .65rem; }
    .ma-symbol { width: 52px; height: 52px; }
}
</style>
