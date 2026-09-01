<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import CatalogWorkspacePage from '../../../Components/MenuAdmin/CatalogWorkspacePage.vue';
import MenuSheet from '../../../Components/MenuAdmin/MenuSheet.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });
const props = defineProps({ navigation:Array, groups:Object, stats:Object, editor:Object, can:Object, urls:Object });
const { ask } = useConfirm();
const editing = ref(null);
const blankOption = () => ({ id: null, name: '', price_delta: 0, display_order: 0, active: true });
const form = useForm({ name:'', min_select:0, max_select:1, required:false, display_order:0, active:true, modifiers:[] });
const invalidRange = computed(() => Number(form.max_select) < Number(form.min_select));

function seed(group=null){
    form.clearErrors(); form.name=group?.name??'';
    form.min_select=group?.minSelect??0; form.max_select=group?.maxSelect??1;
    form.required=Boolean(group?.required); form.display_order=group?.displayOrder??0; form.active=group?Boolean(group.active):true;
    form.modifiers=(group?.options??[]).map(option=>({ id:option.id, name:option.name, price_delta:option.priceDelta??0, display_order:option.displayOrder??0, active:Boolean(option.active) }));
    if(!form.modifiers.length) form.modifiers=[blankOption()];
}
function openCreate(){editing.value={mode:'create'};seed()}
function openEdit(group){editing.value={mode:'edit',group};seed(group)}
function close(){if(!form.processing)editing.value=null}
if(props.editor?.mode==='create')openCreate();
if(props.editor?.mode==='edit'&&props.editor.group)openEdit(props.editor.group);
function addOption(){form.modifiers.push(blankOption())}
function removeOption(index){form.modifiers.splice(index,1);if(!form.modifiers.length)addOption()}
function submit(){
    if(invalidRange.value)return;
    const edit=editing.value?.mode==='edit'; const url=edit?(editing.value.group.updateUrl??editing.value.group.urls?.update):props.urls.store;
    const done=()=>{editing.value=null};
    if(edit)form.put(url,{preserveScroll:true,onSuccess:done});else form.post(url,{preserveScroll:true,onSuccess:done});
}
async function destroy(group){
    if(!group.can.delete)return;
    const yes=await ask({title:`حذف مجموعة «${group.name}»؟`,message:'سيتم حذف خياراتها أيضاً. المجموعة المرتبطة بأصناف لا يمكن حذفها.',confirmLabel:'حذف المجموعة',danger:true});
    if(yes)router.delete(group.urls.destroy,{preserveScroll:true});
}
function ruleLabel(group){if(group.minSelect===group.maxSelect)return`اختر ${group.minSelect}`;if(group.minSelect>0)return`من ${group.minSelect} إلى ${group.maxSelect}`;return`حتى ${group.maxSelect}`}
</script>

<template>
<Head title="إضافات المنيو"/>
<CatalogWorkspacePage :navigation="navigation" title="مجموعات الإضافات" icon="bi-sliders2"
                      subtitle="الأحجام والإضافات والتخصيصات التي يختارها الزبون مع الصنف"
                      panel-title="الإضافات" panel-subtitle="قاعدة الاختيار وخياراتها وأسعارها في مكان واحد"
                      panel-icon="bi-sliders2" :count="groups.total" :stats="[
                          {label:'المجموعات',value:stats.total_groups,icon:'bi-sliders2'},
                          {label:'إلزامية',value:stats.required,icon:'bi-asterisk',tone:'warning'},
                          {label:'اختيارية',value:stats.optional,icon:'bi-check2-square'},
                          {label:'كل الخيارات',value:stats.total_options,icon:'bi-tags-fill',tone:'muted'},
                      ]">
 <template #actions><button v-if="can.create" class="btn btn-primary" type="button" @click="openCreate"><i class="bi bi-plus-lg"></i> مجموعة جديدة</button></template>
 <div class="mm-grid">
  <article v-for="group in groups.data" :key="group.id" class="mm-card" :class="{required:group.required,off:!group.active}">
   <header><span class="mm-icon"><i class="bi bi-sliders2"></i></span><div><h3>{{group.name}}</h3><small>{{ruleLabel(group)}}<template v-if="group.required"> · إلزامي</template></small></div><span :class="group.active?'on':'off'">{{group.active?'نشطة':'متوقفة'}}</span></header>
   <div class="mm-options">
    <span v-for="option in group.options" :key="option.id" :class="{off:!option.active}"><b>{{option.name}}</b><em>{{Number(option.priceDelta)===0?'بدون زيادة':`${Number(option.priceDelta)>0?'+':''}${Number(option.priceDelta).toFixed(2)}`}}</em></span>
    <p v-if="!group.options.length"><i class="bi bi-exclamation-circle"></i> لا توجد خيارات داخل المجموعة.</p>
   </div>
   <div class="mm-usage"><i class="bi bi-egg-fried"></i> مرتبطة بـ {{group.itemsCount}} صنف</div>
   <footer><button v-if="group.can.update" type="button" class="btn btn-sm btn-light" @click="openEdit(group)"><i class="bi bi-pencil"></i> تحرير المجموعة</button><button v-if="group.can.delete" type="button" class="btn btn-sm btn-outline-danger ms-auto" @click="destroy(group)"><i class="bi bi-trash3"></i></button><span v-else-if="group.itemsCount" class="mm-lock"><i class="bi bi-lock"></i> مستخدمة</span></footer>
  </article>
  <EmptyState v-if="!groups.data.length" icon="bi-sliders2" title="لا توجد مجموعات إضافات" message="أنشئ مجموعة مثل الحجم أو الإضافات ثم اربطها بالأصناف."/>
 </div>
 <template #footer><Pagination :links="groups.links"/></template>
</CatalogWorkspacePage>

<MenuSheet :open="Boolean(editing)" :busy="form.processing" wide :title="editing?.mode==='edit'?'تحرير مجموعة الإضافات':'مجموعة إضافات جديدة'" icon="bi-sliders2" subtitle="اضبط قاعدة الاختيار ثم الخيارات وأسعارها" @close="close">
 <form id="modifier-form" class="mm-form" @submit.prevent="submit">
  <div class="row g-3">
   <div class="col-md-6"><label class="form-label">اسم المجموعة *</label><input v-model="form.name" class="form-control" required><small v-if="form.errors.name" class="mm-error">{{form.errors.name}}</small></div>
   <div class="col-md-3"><label class="form-label">أقل اختيار</label><input v-model.number="form.min_select" type="number" min="0" class="form-control" required></div>
   <div class="col-md-3"><label class="form-label">أقصى اختيار</label><input v-model.number="form.max_select" type="number" min="1" class="form-control" required><small v-if="invalidRange" class="mm-error">يجب أن يكون أكبر من أو يساوي الحد الأدنى.</small></div>
   <div class="col-md-2"><label class="form-label">الترتيب</label><input v-model.number="form.display_order" type="number" class="form-control"></div>
   <div class="col-md-4 mm-switches"><label><input v-model="form.required" type="checkbox"><span><b>اختيار إلزامي</b><small>لا يرسل الطلب بدونه.</small></span></label><label><input v-model="form.active" type="checkbox"><span><b>المجموعة نشطة</b><small>متاحة للزبائن.</small></span></label></div>
  </div>
  <div class="mm-builder">
   <div class="mm-builder-head"><div><h4>خيارات المجموعة</h4><p>مثال: صغير، وسط، كبير أو جبنة إضافية.</p></div><button type="button" @click="addOption"><i class="bi bi-plus-lg"></i> خيار</button></div>
   <div class="mm-row-head"><span>الاسم</span><span>فرق السعر</span><span>الترتيب</span><span>نشط</span><span></span></div>
   <div class="mm-rows">
    <div v-for="(option,index) in form.modifiers" :key="option.id??`new-${index}`" class="mm-row">
     <input v-model="option.name" class="form-control" placeholder="اسم الخيار">
     <input v-model.number="option.price_delta" type="number" step="0.01" class="form-control" placeholder="0.00">
     <input v-model.number="option.display_order" type="number" class="form-control">
     <label class="mm-option-active"><input v-model="option.active" type="checkbox"><span>{{option.active?'نعم':'لا'}}</span></label>
     <button type="button" class="mm-remove" @click="removeOption(index)"><i class="bi bi-x-lg"></i></button>
     <small v-if="form.errors[`modifiers.${index}.name`]" class="mm-row-error">{{form.errors[`modifiers.${index}.name`]}}</small>
    </div>
   </div>
  </div>
 </form>
 <template #footer><button type="button" class="btn btn-light" @click="close">تراجع</button><button type="submit" form="modifier-form" class="btn btn-primary" :disabled="form.processing||invalidRange"><span v-if="form.processing" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-check-circle-fill"></i> حفظ</button></template>
</MenuSheet>
</template>

<style scoped>
.mm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(340px, 100%), 1fr)); gap: .72rem; }
.mm-card { display: flex; flex-direction: column; gap: .65rem; padding: .8rem; border: 1px solid var(--catalog-line); border-radius: 15px; background: #fff; box-shadow: 0 6px 18px rgba(18, 63, 49, .03); }
.mm-card.required { border-top: 3px solid #c18a1d; }
.mm-card.off { background: #f8faf9; opacity: .8; }
.mm-card header { display: flex; align-items: center; gap: .55rem; }
.mm-icon { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 12px; color: var(--catalog-primary); background: var(--catalog-primary-soft); }
.mm-card header > div { flex: 1; min-width: 0; }
.mm-card h3 { margin: 0; color: var(--catalog-primary-deep); font-size: .88rem; font-weight: 950; }
.mm-card header small { color: #839189; font-size: .66rem; }
.mm-card header > span:last-child { padding: .16rem .44rem; border-radius: 999px; font-size: .61rem; font-weight: 900; }
.mm-card header .on { color: var(--catalog-primary); background: var(--catalog-primary-soft); }
.mm-card header .off { color: #6c7b73; background: #eef2ef; }
.mm-options { display: flex; flex-wrap: wrap; gap: .35rem; }
.mm-options span { display: inline-flex; gap: .35rem; padding: .3rem .48rem; border: 1px solid var(--catalog-line); border-radius: 8px; background: #f8fbf9; font-size: .67rem; }
.mm-options span.off { opacity: .45; text-decoration: line-through; }
.mm-options b { color: #455a4f; }
.mm-options em { color: var(--catalog-primary); font-style: normal; font-weight: 900; }
.mm-options p { margin: 0; padding: .45rem; border-radius: 9px; color: #94630e; background: #fff8e8; font-size: .67rem; }
.mm-usage { color: #728078; font-size: .66rem; }
.mm-card footer { display: flex; align-items: center; gap: .35rem; margin: auto -.8rem -.8rem; padding: .56rem .8rem; border-top: 1px solid #edf1ee; background: #fbfcfb; }
.mm-card footer .btn { display: inline-flex; align-items: center; gap: .35rem; min-height: 42px; border-radius: 10px; font-size: .7rem; font-weight: 850; }
.mm-lock { margin-inline-start: auto; color: #8c7a54; font-size: .64rem; }
.mm-error { display: block; margin-top: .2rem; color: #b42334; font-size: .67rem; }
.mm-switches { display: flex; gap: .45rem; align-items: end; }
.mm-switches label { display: flex; align-items: flex-start; gap: .38rem; flex: 1; min-height: 48px; padding: .48rem; border: 1px solid var(--catalog-line); border-radius: 9px; }
.mm-switches span { display: flex; flex-direction: column; }
.mm-switches b { color: var(--catalog-primary-deep); font-size: .67rem; }
.mm-switches small { color: #849188; font-size: .58rem; }
.mm-builder { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e6ece8; }
.mm-builder-head { display: flex; align-items: center; justify-content: space-between; gap: .6rem; margin-bottom: .65rem; }
.mm-builder-head h4 { margin: 0; color: var(--catalog-primary-deep); font-size: .86rem; font-weight: 950; }
.mm-builder-head p { margin: .12rem 0 0; color: #809087; font-size: .65rem; }
.mm-builder-head button { min-height: 42px; padding: .42rem .65rem; border: 1px solid rgba(var(--primary-rgb, 31, 107, 80), .32); border-radius: 9px; color: var(--catalog-primary); background: var(--catalog-primary-soft); font-size: .7rem; font-weight: 900; }
.mm-row-head,
.mm-row { display: grid; grid-template-columns: 1.5fr .75fr .65fr 64px 44px; gap: .4rem; align-items: center; }
.mm-row-head { padding: 0 .45rem .3rem; color: #7d8b83; font-size: .62rem; font-weight: 850; }
.mm-rows { display: flex; flex-direction: column; gap: .42rem; }
.mm-row { padding: .45rem; border: 1px solid var(--catalog-line); border-radius: 10px; background: #fafcfb; }
.mm-option-active { display: flex; flex-direction: column; align-items: center; font-size: .6rem; }
.mm-remove { width: 44px; height: 44px; border: 1px solid #efd1d5; border-radius: 9px; color: #ae2938; background: #fff; }
.mm-row-error { grid-column: 1 / -1; color: #b42334; font-size: .65rem; }
@media (max-width: 767.98px) {
    .mm-row-head { display: none; }
    .mm-row { grid-template-columns: 1fr 1fr; }
    .mm-switches { margin-top: .4rem; }
    .mm-row-error { grid-column: 1 / -1; }
}
@media (max-width: 480px) {
    .mm-row { grid-template-columns: 1fr; }
    .mm-row .mm-option-active,
    .mm-row .mm-remove { justify-self: start; }
    .mm-switches { align-items: stretch; flex-direction: column; }
}
</style>
