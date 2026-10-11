<script setup>
import ManagementPage from '../../../Components/Inventory/ManagementPage.vue';
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';

defineOptions({ layout: AdminLayout });
const props = defineProps({ supplier:{type:Object,required:true},branches:{type:Array,default:()=>[]},currency:{type:String,default:'₪'},urls:{type:Object,required:true} });
const editing = computed(() => Boolean(props.supplier.id));
const days = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
const form = useForm({
    name:props.supplier.name,contact_person:props.supplier.contactPerson,phone:props.supplier.phone,email:props.supplier.email,
    address:props.supplier.address,notes:props.supplier.notes,active:props.supplier.active,lead_time_days:props.supplier.leadTimeDays,
    payment_terms_days:props.supplier.paymentTermsDays,minimum_order_amount:props.supplier.minimumOrderAmount,
    delivery_days:[...(props.supplier.deliveryDays??[])],branch_ids:[...(props.supplier.branchIds??[])],
});
function toggleDay(day){const i=form.delivery_days.indexOf(day);i===-1?form.delivery_days.push(day):form.delivery_days.splice(i,1)}
function toggleBranch(branch){if(!branch.editable)return;const i=form.branch_ids.indexOf(branch.id);i===-1?form.branch_ids.push(branch.id):form.branch_ids.splice(i,1)}
function submit(){const options={preserveScroll:true};editing.value?form.put(props.urls.submit,options):form.post(props.urls.submit,options)}
</script>

<template>
    <ManagementPage mode="form">
<Head :title="editing?`تعديل ${supplier.name}`:'مورد جديد'"/>
<PageHeader :title="editing?`تعديل ${supplier.name}`:'مورد جديد'" icon="bi-truck" subtitle="احفظ المعلومات التي تؤثر فعلاً على الشراء والاستحقاق" :crumbs="[{label:'الموردون',url:urls.index}]"/>
<form class="sup-form" @submit.prevent="submit">
<DataPanel title="بيانات الاتصال" icon="bi-person-vcard">
  <div class="form-grid">
    <label class="wide"><span>اسم المورد *</span><input v-model="form.name" class="form-control" required><small v-if="form.errors.name">{{form.errors.name}}</small></label>
    <label><span>الشخص المسؤول</span><input v-model="form.contact_person" class="form-control"></label>
    <label><span>الهاتف</span><input v-model="form.phone" class="form-control" placeholder="+970…"></label>
    <label><span>البريد الإلكتروني</span><input v-model="form.email" type="email" class="form-control"><small v-if="form.errors.email">{{form.errors.email}}</small></label>
    <label class="wide"><span>العنوان</span><textarea v-model="form.address" class="form-control" rows="2"></textarea></label>
  </div>
</DataPanel>
<DataPanel title="شروط التعامل" icon="bi-calendar2-week">
  <div class="form-grid terms">
    <label><span>مهلة التوريد</span><div class="suffix"><input v-model="form.lead_time_days" type="number" min="0" max="365"><b>يوم</b></div><em>من إرسال الأمر حتى وصول البضاعة.</em></label>
    <label><span>مهلة الدفع</span><div class="suffix"><input v-model="form.payment_terms_days" type="number" min="0" max="365"><b>يوم</b></div><em>0 يعني الدفع الفوري.</em></label>
    <label><span>أقل قيمة طلب</span><div class="suffix"><input v-model="form.minimum_order_amount" type="number" step="0.01" min="0"><b>{{currency}}</b></div><em>اتركها فارغة إذا لا يوجد حد.</em></label>
    <div class="wide"><span class="field-label">أيام التوصيل</span><div class="day-pills"><button v-for="(day,i) in days" :key="day" type="button" :class="{active:form.delivery_days.includes(i)}" @click="toggleDay(i)">{{day}}</button></div></div>
    <label class="wide"><span>ملاحظات التعامل</span><textarea v-model="form.notes" class="form-control" rows="2" placeholder="وقت الطلب، طريقة التواصل أو شرط خاص…"></textarea></label>
  </div>
</DataPanel>
<DataPanel v-if="branches.length" title="الفروع التي يخدمها" icon="bi-building">
  <p class="branch-note">اختيار فرع واحد على الأقل يضمن أن كل فرع يرى مورديه فقط.</p>
  <div class="branch-grid"><button v-for="branch in branches" :key="branch.id" type="button" :disabled="!branch.editable" :class="{active:form.branch_ids.includes(branch.id),locked:!branch.editable}" @click="toggleBranch(branch)"><i class="bi" :class="form.branch_ids.includes(branch.id)?'bi-check-circle-fill':'bi-circle'"></i><span>{{branch.name}}</span><i v-if="!branch.editable" class="bi bi-lock-fill"></i></button></div>
  <small v-if="form.errors.branch_ids" class="error">{{form.errors.branch_ids}}</small>
</DataPanel>
<DataPanel title="الحالة" icon="bi-toggle-on">
 <button type="button" class="status-toggle" :class="{active:form.active}" @click="form.active=!form.active"><i class="bi" :class="form.active?'bi-check-circle-fill':'bi-pause-circle-fill'"></i><span><strong>{{form.active?'مورد فعّال':'مورد متوقف'}}</strong><small>{{form.active?'يظهر عند إنشاء أمر شراء وربط المكونات.':'يبقى سجله وفواتيره محفوظين، لكنه لا يظهر للاختيار الجديد.'}}</small></span></button>
</DataPanel>
<div class="save-bar"><a :href="urls.index" class="btn btn-light">إلغاء</a><span><i class="bi bi-info-circle"></i> التعديل لا يغيّر الفواتير أو أوامر الشراء السابقة.</span><button class="btn btn-primary" :disabled="form.processing"><i class="bi bi-check2"></i> {{form.processing?'جارٍ الحفظ…':'حفظ المورد'}}</button></div>
</form>
    </ManagementPage>
</template>

<style scoped>
.sup-form{display:grid;gap:10px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form-grid.terms{grid-template-columns:repeat(3,minmax(0,1fr))}.form-grid label{display:grid;align-content:start;gap:5px}.form-grid label>span,.field-label{font-size:.875rem;font-weight:850}.form-grid .wide{grid-column:1/-1}.form-grid label>small,.error{color:#b42318;font-size:.875rem}.form-grid em{color:var(--muted, #626B6D);font-size:.875rem;font-style:normal}.suffix{display:flex;overflow:hidden;border:1px solid var(--brand-soft, #F3E3D8);border-radius:10px;background:var(--surface, #FFFDFA)}.suffix input{min-width:0;width:100%;padding:9px;border:0;outline:0}.suffix b{display:grid;min-width:45px;place-items:center;color:var(--muted, #626B6D);background:var(--canvas, #F7F3ED);font-size:.875rem}.day-pills,.branch-grid{display:flex;flex-wrap:wrap;gap:7px}.day-pills button{min-height:39px;padding:0 13px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:10px;color:var(--muted, #626B6D);background:var(--surface, #FFFDFA);font-size:.875rem;font-weight:800}.day-pills button.active{color:var(--ink, #25343A);border-color:var(--brand, #A3422B);background:var(--canvas, #F7F3ED)}.branch-note{margin:0 0 10px;color:var(--muted, #626B6D);font-size:.875rem}.branch-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.branch-grid button{display:flex;align-items:center;gap:8px;min-height:45px;padding:9px 12px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:11px;color:var(--muted, #626B6D);background:var(--surface, #FFFDFA);text-align:start}.branch-grid button span{flex:1;font-size:.875rem;font-weight:800}.branch-grid button.active{color:var(--ink, #25343A);border-color:var(--brand, #A3422B);background:var(--canvas, #F7F3ED)}.branch-grid button.locked{opacity:.55}.status-toggle{display:flex;width:100%;align-items:center;gap:11px;padding:13px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;color:var(--muted, #626B6D);background:var(--canvas, #F7F3ED);text-align:start}.status-toggle>i{font-size:1.25rem}.status-toggle span{display:grid}.status-toggle strong{font-size:.875rem}.status-toggle small{color:var(--muted, #626B6D);font-size:.875rem}.status-toggle.active{color:var(--ink, #25343A);border-color:var(--brand, #A3422B);background:var(--canvas, #F7F3ED)}.save-bar{position:sticky;z-index:8;bottom:8px;display:flex;align-items:center;gap:9px;padding:11px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:rgba(255,255,255,.97);box-shadow:none}.save-bar span{display:flex;flex:1;gap:6px;color:var(--muted, #626B6D);font-size:.875rem}
@media(max-width:760px){.form-grid,.form-grid.terms{grid-template-columns:1fr}.form-grid .wide{grid-column:auto}.branch-grid{grid-template-columns:1fr 1fr}}
@media(max-width:500px){.branch-grid{grid-template-columns:1fr}.save-bar span{display:none}.save-bar .btn-primary{flex:1}}

/* Dar Al Sarw management layout */
.sup-form { gap: 20px; } .form-grid { gap: 20px; } .form-grid label>span,.field-label { font-size: .9375rem; } .form-grid input,.form-grid select { min-height: 46px; border-radius: 8px; }
</style>
