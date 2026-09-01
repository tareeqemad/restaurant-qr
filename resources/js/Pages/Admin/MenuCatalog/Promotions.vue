<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import CatalogWorkspacePage from '../../../Components/MenuAdmin/CatalogWorkspacePage.vue';
import MenuSheet from '../../../Components/MenuAdmin/MenuSheet.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({layout:AdminLayout});
const props=defineProps({navigation:Array,promotions:Object,stats:Object,filters:Object,editor:Object,formOptions:Object,can:Object,urls:Object});
const {ask}=useConfirm();const editing=ref(null);
const filters=reactive({search:props.filters?.search??'',status:props.filters?.status??''});
const days=[{id:0,label:'الأحد'},{id:1,label:'الإثنين'},{id:2,label:'الثلاثاء'},{id:3,label:'الأربعاء'},{id:4,label:'الخميس'},{id:5,label:'الجمعة'},{id:6,label:'السبت'}];
const channels=[{id:'qr',label:'QR الزبون',icon:'bi-qr-code'},{id:'cashier',label:'الكاشير',icon:'bi-shop'},{id:'waiter',label:'النادل',icon:'bi-person-workspace'},{id:'phone',label:'الهاتف',icon:'bi-telephone'},{id:'delivery',label:'التوصيل',icon:'bi-bicycle'},{id:'other',label:'أخرى',icon:'bi-three-dots'}];
const form=useForm({branch_id:'',name:'',description:'',type:'percent',value:0,min_subtotal:'',usage_limit:'',audience:'everyone',free_modifier_ids:[],bxgy_buy_qty:'',bxgy_get_qty:'',target_type:'menu_item',target_id:'',starts_at:'',ends_at:'',time_from:'',time_to:'',days_of_week:[],channels:[],excluded_item_ids:[],active:true,priority:0});
const targetOptions=computed(()=>form.target_type==='category'?props.formOptions.categories:props.formOptions.menuItems);
const typeHint=computed(()=>({percent:'نسبة تُخصم من السعر الأصلي.',sale_price:'السعر النهائي الذي يدفعه الزبون.',fixed_off:'مبلغ ثابت يُخصم من السعر.'}[form.type]));
const invalidWindow=computed(()=>Boolean(form.starts_at&&form.ends_at&&form.ends_at<form.starts_at));
const invalidTime=computed(()=>Boolean((form.time_from&&!form.time_to)||(!form.time_from&&form.time_to)));
function toggle(list,value){const i=list.findIndex(v=>String(v)===String(value));if(i>=0)list.splice(i,1);else list.push(value)}
function checked(list,value){return list.some(v=>String(v)===String(value))}
function seed(item=null){
 form.clearErrors();form.branch_id=item?.branchId??(props.formOptions.canChainWide?'':(props.formOptions.activeBranchId??''));form.name=item?.name??'';form.description=item?.description??'';form.type=item?.type??'percent';form.value=item?.value??0;form.min_subtotal=item?.minSubtotal??'';form.usage_limit=item?.usageLimit??'';form.audience=item?.audience??'everyone';form.free_modifier_ids=[...(item?.freeModifierIds??[])];form.bxgy_buy_qty=item?.bxgyBuyQty??'';form.bxgy_get_qty=item?.bxgyGetQty??'';form.target_type=item?.targetType??'menu_item';form.target_id=item?.targetId??'';form.starts_at=item?.startsAt??'';form.ends_at=item?.endsAt??'';form.time_from=item?.timeFrom??'';form.time_to=item?.timeTo??'';form.days_of_week=[...(item?.daysOfWeek??[])];form.channels=[...(item?.channels??[])];form.excluded_item_ids=[...(item?.excludedItemIds??[])];form.active=item?Boolean(item.active):true;form.priority=item?.priority??0;
}
function openCreate(item=null){editing.value={mode:'create'};seed(item)}
function openEdit(item){editing.value={mode:'edit',item};seed(item)}
function close(){if(!form.processing)editing.value=null}
if(props.editor?.mode==='create')openCreate(props.editor.promotion);if(props.editor?.mode==='edit'&&props.editor.promotion)openEdit(props.editor.promotion);
function changeTarget(){form.target_id='';form.excluded_item_ids=[]}
function visit(){router.get(props.urls.index,{search:filters.search||undefined,status:filters.status||undefined},{preserveState:true,preserveScroll:true,replace:true})}
function clearFilters(){filters.search='';filters.status='';visit()}
function submit(){if(invalidWindow.value||invalidTime.value)return;const edit=editing.value?.mode==='edit';const url=edit?(editing.value.item.updateUrl??editing.value.item.urls?.update):props.urls.store;const done=()=>{editing.value=null};if(edit)form.put(url,{preserveScroll:true,onSuccess:done});else form.post(url,{preserveScroll:true,onSuccess:done})}
async function changeState(item){const action=item.active?'إيقاف':'تشغيل';if(item.active){const yes=await ask({title:`إيقاف «${item.name}» مؤقتاً؟`,message:'يتوقف تطبيق الخصم فوراً على الطلبات الجديدة فقط.',confirmLabel:'إيقاف العرض'});if(!yes)return}router.patch(item.urls.toggle,{}, {preserveScroll:true})}
async function destroy(item){const yes=await ask({title:`حذف عرض «${item.name}»؟`,message:'لن تتغير الطلبات السابقة لأنها تحتفظ بسعرها المسجّل.',confirmLabel:'حذف العرض',danger:true});if(yes)router.delete(item.urls.destroy,{preserveScroll:true})}
const statusMeta=status=>({live:['نشط الآن','live','bi-broadcast'],paused:['متوقف','paused','bi-pause-fill'],upcoming:['قادم','upcoming','bi-hourglass-top'],expired:['منتهي','expired','bi-calendar-x'],outside:['خارج الوقت','outside','bi-clock']}[status]??[status,'outside','bi-clock']);
</script>

<template>
<Head title="عروض المنيو"/>
<CatalogWorkspacePage :navigation="navigation" title="عروض وخصومات المنيو" icon="bi-tag-fill"
                      subtitle="حملة دائمة أو مجدولة تظهر فوراً في QR والكاشير والنادل"
                      panel-title="العروض" panel-subtitle="الحالة والقيمة والنطاق والجدولة في بطاقة واضحة"
                      panel-icon="bi-tags-fill" :count="promotions.total" :stats="[
                          {label:'كل العروض',value:stats.total,icon:'bi-tags-fill'},
                          {label:'مفعّلة',value:stats.active,icon:'bi-play-circle-fill'},
                          {label:'متوقفة',value:stats.paused,icon:'bi-pause-circle-fill',tone:'muted'},
                          {label:'قادمة',value:stats.upcoming,icon:'bi-calendar-event',tone:'info'},
                      ]">
 <template #actions><button v-if="can.create" type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg"></i> عرض جديد</button></template>
 <template #beforePanel><div class="mp-rule"><i class="bi bi-lightbulb-fill"></i><div><b>عند تطابق أكثر من عرض</b><span>الأعلى أولوية يفوز، ثم عرض الصنف المحدد قبل عرض القسم. الطلب القديم يحتفظ بسعره.</span></div></div></template>
 <template v-if="filters.search||filters.status" #panelActions><button type="button" class="btn btn-light" @click="clearFilters"><i class="bi bi-arrow-counterclockwise"></i> مسح الفلاتر</button></template>
 <template #filters><form class="mp-filter" @submit.prevent="visit"><label><i class="bi bi-search"></i><input v-model="filters.search" placeholder="اسم العرض"></label><select v-model="filters.status" class="form-select" @change="visit"><option value="">كل الحالات</option><option value="active">مفعلة</option><option value="paused">متوقفة</option><option value="upcoming">قادمة</option><option value="expired">منتهية</option></select><button class="btn btn-primary"><i class="bi bi-search"></i> بحث</button></form></template>
 <div class="mp-grid">
  <article v-for="item in promotions.data" :key="item.id" class="mp-card" :class="`is-${item.status}`">
   <header><div><h3>{{item.name}}</h3><small>{{item.description||'بدون وصف داخلي'}}</small></div><span :class="statusMeta(item.status)[1]"><i class="bi" :class="statusMeta(item.status)[2]"></i>{{statusMeta(item.status)[0]}}</span></header>
   <div class="mp-value"><strong>{{item.valueLabel}}</strong><span>{{item.typeLabel}}</span></div>
   <div class="mp-target"><span><i class="bi" :class="item.targetType==='menu_item'?'bi-egg-fried':'bi-grid-fill'"></i>{{item.targetType==='menu_item'?'صنف':'قسم'}}</span><b>{{item.targetName}}</b></div>
   <div class="mp-meta"><span><i class="bi bi-calendar3"></i>{{item.scheduleLabel}}</span><span><i class="bi bi-building"></i>{{item.branchName}}</span><span><i class="bi bi-sort-up"></i>أولوية {{item.priority}}</span><span v-if="item.usageLimit"><i class="bi bi-people"></i>{{item.usageCount}} / {{item.usageLimit}} استخدام</span></div>
   <footer><button v-if="item.can.update" type="button" class="btn btn-sm" :class="item.active?'btn-outline-warning':'btn-outline-success'" @click="changeState(item)"><i class="bi" :class="item.active?'bi-pause-fill':'bi-play-fill'"></i>{{item.active?'إيقاف':'تشغيل'}}</button><button v-if="item.can.update" type="button" class="btn btn-sm btn-light" @click="openEdit(item)"><i class="bi bi-pencil"></i> تعديل</button><button v-if="item.can.delete" type="button" class="btn btn-sm btn-outline-danger ms-auto" @click="destroy(item)"><i class="bi bi-trash3"></i></button></footer>
  </article>
  <EmptyState v-if="!promotions.data.length" icon="bi-tag-fill" title="لا توجد عروض مطابقة" message="أنشئ عرضاً بسيطاً أو غيّر الفلاتر."/>
 </div>
 <template #footer><Pagination :links="promotions.links"/></template>
</CatalogWorkspacePage>

<MenuSheet :open="Boolean(editing)" :busy="form.processing" wide :title="editing?.mode==='edit'?'تعديل العرض':'عرض جديد'" icon="bi-tag-fill" subtitle="ابدأ بالخصم والنطاق؛ الجدولة والقيود اختيارية" @close="close">
 <form id="promotion-form" class="mp-form" @submit.prevent="submit">
  <section><header><i class="bi bi-info-circle-fill"></i><div><h4>الأساسيات</h4><p>اسم داخلي واضح وحالة التطبيق.</p></div></header><div class="row g-3">
   <div class="col-md-8"><label class="form-label">اسم العرض *</label><input v-model="form.name" class="form-control" maxlength="200" required placeholder="مثلاً: خصم الغداء"><small v-if="form.errors.name" class="mp-error">{{form.errors.name}}</small></div>
   <div class="col-md-4"><label class="form-label">الأولوية</label><input v-model.number="form.priority" type="number" min="0" max="65535" class="form-control"><small>الأعلى يفوز.</small></div>
   <div class="col-12"><label class="form-label">وصف داخلي</label><textarea v-model="form.description" rows="2" maxlength="500" class="form-control"></textarea></div>
   <div class="col-md-7"><label class="form-label">الفرع</label><select v-model="form.branch_id" class="form-select" :disabled="!formOptions.canChainWide"><option v-if="formOptions.canChainWide" value="">كل الفروع</option><option v-for="branch in formOptions.branches" :key="branch.id" :value="branch.id">{{branch.name}}</option></select><small v-if="!formOptions.canChainWide">العرض مقيد بفرعك الحالي حمايةً لبقية الفروع.</small></div>
   <div class="col-md-5 d-flex align-items-end"><label class="mp-switch"><input v-model="form.active" type="checkbox"><span><b>العرض مفعّل</b><small>يطبق عند تحقق الشروط.</small></span></label></div>
  </div></section>
  <section><header><i class="bi bi-percent"></i><div><h4>قيمة الخصم</h4><p>اختر طريقة واحدة سهلة الفهم.</p></div></header><div class="row g-3">
   <div class="col-md-6"><label class="form-label">نوع الخصم *</label><select v-model="form.type" class="form-select" required><option value="percent">نسبة مئوية</option><option value="sale_price">سعر عرض ثابت</option><option value="fixed_off">خصم مبلغ ثابت</option></select></div>
   <div class="col-md-6"><label class="form-label">القيمة *</label><div class="input-group"><input v-model="form.value" type="number" min="0" step="0.01" class="form-control" required><span class="input-group-text">{{form.type==='percent'?'%':'ش.إ'}}</span></div><small>{{typeHint}}</small><small v-if="form.errors.value" class="mp-error">{{form.errors.value}}</small></div>
  </div></section>
  <section><header><i class="bi bi-bullseye"></i><div><h4>نطاق التطبيق</h4><p>صنف واحد أو قسم كامل مع استثناءات.</p></div></header><div class="row g-3">
   <div class="col-md-4"><label class="form-label">يطبق على *</label><select v-model="form.target_type" class="form-select" @change="changeTarget"><option value="menu_item">صنف محدد</option><option value="category">قسم كامل</option></select></div>
   <div class="col-md-8"><label class="form-label">الهدف *</label><select v-model="form.target_id" class="form-select" required><option value="">— اختر —</option><option v-for="target in targetOptions" :key="target.id" :value="target.id">{{target.name}}<template v-if="target.category"> — {{target.category}}</template></option></select><small v-if="form.errors.target_id" class="mp-error">{{form.errors.target_id}}</small></div>
   <div v-if="form.target_type==='category'" class="col-12"><label class="form-label">استثناء أصناف من خصم القسم</label><div class="mp-check-grid"><button v-for="menuItem in formOptions.menuItems" :key="menuItem.id" type="button" :class="{selected:checked(form.excluded_item_ids,menuItem.id)}" @click="toggle(form.excluded_item_ids,menuItem.id)"><i class="bi" :class="checked(form.excluded_item_ids,menuItem.id)?'bi-check-circle-fill':'bi-circle'"></i>{{menuItem.name}}</button></div></div>
  </div></section>
  <section><header><i class="bi bi-calendar-event"></i><div><h4>الجدولة</h4><p>اتركها فارغة ليعمل العرض دائماً.</p></div></header><div class="row g-3">
   <div class="col-md-6"><label class="form-label">يبدأ</label><input v-model="form.starts_at" type="datetime-local" class="form-control"></div><div class="col-md-6"><label class="form-label">ينتهي</label><input v-model="form.ends_at" type="datetime-local" class="form-control"><small v-if="invalidWindow" class="mp-error">النهاية يجب أن تكون بعد البداية.</small></div>
   <div class="col-md-3"><label class="form-label">من ساعة</label><input v-model="form.time_from" type="time" class="form-control"></div><div class="col-md-3"><label class="form-label">إلى ساعة</label><input v-model="form.time_to" type="time" class="form-control"><small v-if="invalidTime" class="mp-error">حدد الوقتين معاً.</small></div>
   <div class="col-md-6"><label class="form-label">أيام الأسبوع</label><div class="mp-days"><button v-for="day in days" :key="day.id" type="button" :class="{selected:checked(form.days_of_week,day.id)}" @click="toggle(form.days_of_week,day.id)">{{day.label}}</button></div><small>فارغة = كل الأيام.</small></div>
  </div></section>
  <section><header><i class="bi bi-broadcast"></i><div><h4>قنوات الطلب</h4><p>فارغة = كل القنوات.</p></div></header><div class="mp-channels"><button v-for="channel in channels" :key="channel.id" type="button" :class="{selected:checked(form.channels,channel.id)}" @click="toggle(form.channels,channel.id)"><i class="bi" :class="channel.icon"></i><span>{{channel.label}}</span><i class="bi" :class="checked(form.channels,channel.id)?'bi-check-circle-fill':'bi-circle'"></i></button></div></section>
  <details class="mp-advanced"><summary><i class="bi bi-sliders"></i><span><b>شروط متقدمة</b><small>حد الفاتورة، عدد الاستخدامات، اشترِ وخذ، أو إضافة مجانية.</small></span><i class="bi bi-chevron-down"></i></summary><div class="mp-advanced-body row g-3">
   <div class="col-md-4"><label class="form-label">أقل إجمالي فاتورة</label><input v-model="form.min_subtotal" type="number" min="0" step=".01" class="form-control"></div><div class="col-md-4"><label class="form-label">حد الاستخدامات</label><input v-model="form.usage_limit" type="number" min="1" class="form-control"></div><div class="col-md-4"><label class="form-label">الجمهور</label><select v-model="form.audience" class="form-select"><option value="everyone">كل الزبائن</option><option value="birthday_month">أعياد الميلاد هذا الشهر</option></select></div>
   <div class="col-md-6"><label class="form-label">اشترِ عدد</label><input v-model="form.bxgy_buy_qty" type="number" min="1" class="form-control" placeholder="2"></div><div class="col-md-6"><label class="form-label">وخذ مجاناً</label><input v-model="form.bxgy_get_qty" type="number" min="1" class="form-control" placeholder="1"></div>
   <div class="col-12"><label class="form-label">إضافات مجانية مع العرض</label><div class="mp-check-grid"><button v-for="modifier in formOptions.modifiers" :key="modifier.id" type="button" :class="{selected:checked(form.free_modifier_ids,modifier.id)}" @click="toggle(form.free_modifier_ids,modifier.id)"><i class="bi" :class="checked(form.free_modifier_ids,modifier.id)?'bi-check-circle-fill':'bi-circle'"></i>{{modifier.name}}<small>{{modifier.group}}</small></button><span v-if="!formOptions.modifiers.length">لا توجد إضافات معدّة.</span></div></div>
  </div></details>
 </form>
 <template #footer><button type="button" class="btn btn-light" @click="close">تراجع</button><button type="submit" form="promotion-form" class="btn btn-primary" :disabled="form.processing||invalidWindow||invalidTime"><span v-if="form.processing" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-check-circle-fill"></i> حفظ العرض</button></template>
</MenuSheet>
</template>

<style scoped>
.mp-rule { display: flex; gap: .62rem; padding: .68rem .76rem; border: 1px solid #f0dfaa; border-radius: 13px; color: #775815; background: #fff9e9; }
.mp-rule > i { margin-top: .08rem; }
.mp-rule > div { display: flex; flex-direction: column; }
.mp-rule b { font-size: .75rem; }
.mp-rule span { font-size: .68rem; }
.mp-filter { display: grid; grid-template-columns: minmax(240px, 1fr) 220px auto; gap: .5rem; }
.mp-filter label { display: flex; align-items: center; gap: .45rem; min-height: 44px; padding-inline: .7rem; border: 1px solid var(--catalog-line); border-radius: 10px; background: #fff; }
.mp-filter label:focus-within { border-color: rgba(var(--primary-rgb, 31, 107, 80), .6); box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 31, 107, 80), .08); }
.mp-filter input { width: 100%; border: 0; outline: 0; background: transparent; }
.mp-filter .form-select,
.mp-filter .btn { min-height: 44px; border-radius: 10px; }
.mp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(350px, 100%), 1fr)); gap: .72rem; }
.mp-card { display: flex; flex-direction: column; gap: .62rem; padding: .8rem; border: 1px solid var(--catalog-line); border-top: 3px solid #aeb9b2; border-radius: 15px; background: #fff; box-shadow: 0 6px 18px rgba(18, 63, 49, .03); }
.mp-card.is-live { border-top-color: var(--catalog-primary); }
.mp-card.is-upcoming { border-top-color: #3583a2; }
.mp-card.is-paused { background: #f8faf9; opacity: .78; }
.mp-card.is-expired { filter: saturate(.4); }
.mp-card header { display: flex; align-items: flex-start; gap: .6rem; }
.mp-card header > div { flex: 1; min-width: 0; }
.mp-card h3 { margin: 0; color: var(--catalog-primary-deep); font-size: .9rem; font-weight: 950; }
.mp-card header small { display: -webkit-box; overflow: hidden; color: #849088; font-size: .65rem; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.mp-card header > span { display: inline-flex; align-items: center; gap: .28rem; padding: .17rem .46rem; border-radius: 999px; font-size: .6rem; font-weight: 900; white-space: nowrap; }
.mp-card header .live { color: var(--catalog-primary); background: var(--catalog-primary-soft); }
.mp-card header .paused { color: #68776f; background: #eef2ef; }
.mp-card header .upcoming { color: #236782; background: #eaf5fa; }
.mp-card header .expired { color: #596167; background: #eef0f1; }
.mp-card header .outside { color: #84600b; background: #fff5db; }
.mp-value { display: flex; align-items: flex-end; gap: .4rem; }
.mp-value strong { color: var(--catalog-primary); font-size: 1.18rem; font-weight: 950; }
.mp-value span { margin-bottom: .2rem; color: #7e8c84; font-size: .64rem; }
.mp-target { display: flex; align-items: center; gap: .5rem; padding: .5rem .6rem; border-radius: 10px; background: #f3f7f4; }
.mp-target span { display: flex; gap: .25rem; color: #738279; font-size: .64rem; }
.mp-target b { color: #3b5045; font-size: .72rem; }
.mp-meta { display: flex; flex-wrap: wrap; gap: .35rem .7rem; color: #697a70; font-size: .64rem; }
.mp-meta span { display: inline-flex; align-items: center; gap: .25rem; }
.mp-card footer { display: flex; gap: .35rem; margin: auto -.8rem -.8rem; padding: .56rem .8rem; border-top: 1px solid #edf1ee; background: #fbfcfb; }
.mp-card footer .btn { display: inline-flex; align-items: center; gap: .32rem; min-height: 42px; border-radius: 10px; font-size: .7rem; font-weight: 850; }
.mp-error { display: block; margin-top: .2rem; color: #b42334 !important; font-size: .66rem !important; }
.mp-form { display: flex; flex-direction: column; gap: .72rem; }
.mp-form > section { padding: .8rem; border: 1px solid #e4ebe6; border-radius: 13px; }
.mp-form > section > header { display: flex; align-items: center; gap: .5rem; margin-bottom: .7rem; }
.mp-form > section > header > i { display: grid; place-items: center; width: 37px; height: 37px; border-radius: 10px; color: rgb(var(--primary-rgb, 31, 107, 80)); background: rgba(var(--primary-rgb, 31, 107, 80), .08); }
.mp-form > section > header h4 { margin: 0; color: #263f33; font-size: .8rem; font-weight: 950; }
.mp-form > section > header p { margin: .1rem 0 0; color: #829087; font-size: .63rem; }
.mp-form small { display: block; margin-top: .2rem; color: #7f8d85; font-size: .63rem; }
.mp-switch { display: flex; align-items: center; gap: .5rem; width: 100%; min-height: 48px; padding: .52rem; border: 1px solid #e0e8e2; border-radius: 10px; background: #f6faf7; }
.mp-switch span { display: flex; flex-direction: column; }
.mp-switch b { font-size: .7rem; }
.mp-switch small { margin: 0; font-size: .6rem; }
.mp-check-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: .35rem; overflow: auto; max-height: 180px; padding: .35rem; border: 1px solid #e5ece7; border-radius: 10px; }
.mp-check-grid button { display: flex; align-items: center; gap: .35rem; min-height: 44px; padding: .35rem .48rem; border: 1px solid #e3e9e5; border-radius: 9px; color: #586a60; background: #fff; font-size: .67rem; text-align: start; }
.mp-check-grid button.selected { color: rgb(var(--primary-rgb, 31, 107, 80)); border-color: rgba(var(--primary-rgb, 31, 107, 80), .35); background: rgba(var(--primary-rgb, 31, 107, 80), .07); }
.mp-check-grid button small { margin-inline-start: auto; }
.mp-days { display: flex; flex-wrap: wrap; gap: .25rem; }
.mp-days button { min-height: 40px; padding: .3rem .48rem; border: 1px solid #dfe7e2; border-radius: 8px; color: #64756b; background: #fff; font-size: .63rem; }
.mp-days button.selected { color: #fff; border-color: rgb(var(--primary-rgb, 31, 107, 80)); background: rgb(var(--primary-rgb, 31, 107, 80)); }
.mp-channels { display: grid; grid-template-columns: repeat(3, 1fr); gap: .4rem; }
.mp-channels button { display: flex; align-items: center; gap: .4rem; min-height: 44px; padding: .5rem; border: 1px solid #e1e9e3; border-radius: 9px; color: #5b6d63; background: #fff; font-size: .67rem; }
.mp-channels button span { flex: 1; text-align: start; }
.mp-channels button.selected { color: rgb(var(--primary-rgb, 31, 107, 80)); border-color: rgba(var(--primary-rgb, 31, 107, 80), .35); background: rgba(var(--primary-rgb, 31, 107, 80), .07); }
.mp-advanced { border: 1px solid #dfe8e2; border-radius: 13px; background: #fafcfb; }
.mp-advanced summary { display: flex; align-items: center; gap: .55rem; min-height: 48px; padding: .7rem; cursor: pointer; }
.mp-advanced summary > span { display: flex; flex: 1; flex-direction: column; }
.mp-advanced summary b { color: #3f5549; font-size: .72rem; }
.mp-advanced summary small { margin: 0; color: #839188; font-size: .61rem; }
.mp-advanced-body { padding: .2rem .8rem .8rem; border-top: 1px solid #e6ece8; }
@media (max-width: 767.98px) {
    .mp-filter { grid-template-columns: 1fr; }
    .mp-channels,
    .mp-check-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 440px) {
    .mp-channels,
    .mp-check-grid { grid-template-columns: 1fr; }
}
</style>
