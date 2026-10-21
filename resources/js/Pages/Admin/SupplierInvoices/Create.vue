<script setup>
import { useFormUx } from '../../../Composables/useFormUx';
import ManagementPage from '../../../Components/Inventory/ManagementPage.vue';
import { computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useToast } from '../../../Composables/useToast';
defineOptions({layout:AdminLayout});
const props=defineProps({suppliers:{type:Array,default:()=>[]},branches:{type:Array,default:()=>[]},selectedBranchId:{type:Number,required:true},purchaseOrders:{type:Array,default:()=>[]},selectedPo:{type:Object,default:null},lines:{type:Array,default:()=>[]},currencies:{type:Array,default:()=>[]},defaults:{type:Object,required:true},urls:{type:Object,required:true}});
const toast=useToast();
const form=useForm({number:'',branch_id:props.selectedPo?.branchId??props.selectedBranchId,supplier_id:props.selectedPo?.supplierId??'',purchase_order_id:props.selectedPo?.id??'',currency_code:props.selectedPo?.currencyCode??props.defaults.baseCurrency,exchange_rate:props.selectedPo?.exchangeRate??1,invoice_date:props.defaults.date,due_date:'',attachment:null,subtotal:props.selectedPo?.subtotal??0,tax_total:0,total:props.selectedPo?.total??0,notes:'',lines:props.lines.map(x=>({...x}))});
const hasLines=computed(()=>form.lines.length>0);
const branchSuppliers=computed(()=>props.suppliers.filter(x=>(x.branchIds??[]).includes(Number(form.branch_id))));
const branchPurchaseOrders=computed(()=>props.purchaseOrders.filter(x=>x.branchId===Number(form.branch_id)));
const supplier=computed(()=>props.suppliers.find(x=>x.id===Number(form.supplier_id))??null);
const currency=computed(()=>props.currencies.find(x=>x.code===form.currency_code)??null);
const foreign=computed(()=>form.currency_code!==props.defaults.baseCurrency);
const lineSubtotal=(line)=>Number(line.quantity||0)*Number(line.unit_price||0);
const subtotal=computed(()=>hasLines.value?form.lines.reduce((s,x)=>s+lineSubtotal(x),0):Number(form.subtotal||0));
const taxTotal=computed(()=>hasLines.value?form.lines.reduce((s,x)=>s+Number(x.tax_total||0),0):Number(form.tax_total||0));
const total=computed(()=>subtotal.value+taxTotal.value);
const fmt=(n)=>Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
watch(()=>form.branch_id,()=>{if(props.selectedPo)return;if(!branchSuppliers.value.some(x=>x.id===Number(form.supplier_id)))form.supplier_id='';if(!branchPurchaseOrders.value.some(x=>x.id===Number(form.purchase_order_id)))form.purchase_order_id=''});
function choosePo(){const nextPo=form.purchase_order_id;form.purchase_order_id=props.selectedPo?.id??'';router.get(props.urls.create,nextPo?{po:nextPo}:{})}
function chooseCurrency(){form.exchange_rate=form.currency_code===props.defaults.baseCurrency?1:(currency.value?.rate??'')}
function submit(){if(!form.number.trim()){toast.warning('اكتب رقم فاتورة المورد.');return}if(!form.supplier_id){toast.warning('اختر المورد.');return}form.subtotal=Number(subtotal.value.toFixed(2));form.tax_total=Number(taxTotal.value.toFixed(2));form.total=Number(total.value.toFixed(2));form.post(props.urls.store,{forceFormData:true,preserveScroll:true})}
useFormUx(form);
</script>
<template>
    <ManagementPage mode="form">
<Head title="فاتورة مورد جديدة"/>
<PageHeader title="تسجيل فاتورة مورد" icon="bi-receipt-cutoff" subtitle="اربطها بالاستلام إن وُجد؛ السعر الفعلي يبقى قابلاً للمراجعة قبل الترحيل" :crumbs="[{label:'فواتير الموردين',url:urls.index}]"/>
<form class="invoice-form" @submit.prevent="submit">
 <div class="workflow-note"><span class="done"><i class="bi bi-check2"></i> أمر شراء</span><i class="bi bi-chevron-left"></i><span class="done"><i class="bi bi-check2"></i> استلام</span><i class="bi bi-chevron-left"></i><span class="active"><i class="bi bi-receipt"></i> فاتورة المورد</span><i class="bi bi-chevron-left"></i><span><i class="bi bi-cash-coin"></i> دفع لاحقاً</span></div>
 <DataPanel title="مرجع الفاتورة" icon="bi-info-circle-fill">
  <div class="header-grid">
   <label><span>رقم فاتورة المورد *</span><input v-model="form.number" class="form-control" maxlength="60" required placeholder="INV-…"><small v-if="form.errors.number">{{form.errors.number}}</small></label>
   <label><span>الفرع المستفيد *</span><select v-model.number="form.branch_id" class="form-select" required :disabled="Boolean(selectedPo)"><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{branch.name}}</option></select><em>المخزون والقيد والدين سيُسجّلون على هذا الفرع فقط.</em><small v-if="form.errors.branch_id">{{form.errors.branch_id}}</small></label>
   <label><span>أمر شراء مرتبط</span><select v-model="form.purchase_order_id" class="form-select" @change="choosePo"><option value="">بدون أمر شراء</option><option v-for="po in branchPurchaseOrders" :key="po.id" :value="po.id">{{po.number}} · {{po.supplierName}} · {{po.totalLabel}}</option></select><em>اختياره يحمّل ما تم استلامه فعلياً.</em></label>
   <label><span>المورد *</span><select v-model="form.supplier_id" class="form-select" required :disabled="Boolean(selectedPo)"><option value="">اختر المورد</option><option v-for="item in branchSuppliers" :key="item.id" :value="item.id">{{item.name}}</option></select><small v-if="form.errors.supplier_id">{{form.errors.supplier_id}}</small></label>
   <label><span>تاريخ الفاتورة *</span><input v-model="form.invoice_date" type="date" class="form-control" required></label>
   <label><span>تاريخ الاستحقاق</span><input v-model="form.due_date" type="date" class="form-control"><em>{{supplier?.paymentTermsDays===null||supplier?.paymentTermsDays===undefined?'اختياري؛ لا توجد مهلة محفوظة للمورد.':`يمكن تركه فارغاً: مهلة المورد ${supplier.paymentTermsDays} يوم.`}}</em></label>
   <label><span>عملة الفاتورة *</span><select v-model="form.currency_code" class="form-select" required @change="chooseCurrency"><option v-for="item in currencies" :key="item.code" :value="item.code">{{item.label}}</option></select></label>
   <label v-if="foreign"><span>سعر الصرف إلى {{defaults.baseCurrency}} *</span><input v-model="form.exchange_rate" type="number" step="0.00000001" min="0.000001" class="form-control" required><small v-if="form.errors.exchange_rate">{{form.errors.exchange_rate}}</small></label>
   <label><span>صورة أو PDF</span><input type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" @change="form.attachment=$event.target.files[0]??null"><em>حتى 5MB.</em></label>
  </div>
 </DataPanel>
 <DataPanel v-if="hasLines" :title="`مطابقة الاستلام مع ${selectedPo.number}`" :count="form.lines.length" icon="bi-list-check">
  <div class="line-head"><span>راجع الكمية والسعر المكتوبين على فاتورة المورد. التلوين يعني فرقاً عن السعر الأصلي في أمر الشراء، وليس خطأً تلقائياً.</span></div>
  <div class="invoice-lines"><article v-for="(line,i) in form.lines" :key="line.purchase_order_item_id"><div class="line-name"><strong>{{line.description}}</strong><small>{{line.unitCode}}</small><span :class="line.fullyReceived?'ok':'warn'">مستلم {{fmt(line.receivedQty)}}</span></div><label><span>كمية الفاتورة</span><input v-model.number="line.quantity" type="number" step="0.0001" min="0"></label><label><span>سعر الفاتورة</span><input v-model.number="line.unit_price" type="number" step="0.0001" min="0"><small v-if="Math.abs(Number(line.unit_price)-Number(line.orderedPrice))>.001" :class="Number(line.unit_price)>Number(line.orderedPrice)?'up':'down'">أمر الشراء: {{fmt(line.orderedPrice)}}</small></label><label><span>ضريبة المورد</span><input v-model.number="line.tax_total" type="number" step="0.0001" min="0"></label><div class="line-total"><small>الإجمالي</small><strong>{{fmt(lineSubtotal(line)+Number(line.tax_total||0))}} {{form.currency_code}}</strong></div></article></div>
 </DataPanel>
 <div class="invoice-bottom">
  <DataPanel title="ملاحظات" icon="bi-journal-text"><textarea v-model="form.notes" class="form-control" rows="4" maxlength="2000" placeholder="ملاحظة داخلية أو وصف فرق السعر…"></textarea></DataPanel>
  <DataPanel title="ملخص المبالغ" icon="bi-calculator-fill"><div v-if="!hasLines" class="manual-totals"><label><span>الفرعي</span><input v-model.number="form.subtotal" type="number" step="0.01" min="0"></label><label><span>ضريبة فاتورة المورد</span><input v-model.number="form.tax_total" type="number" step="0.01" min="0"></label></div><div class="totals"><span><small>الفرعي</small><b>{{fmt(subtotal)}} {{form.currency_code}}</b></span><span v-if="taxTotal>0"><small>ضريبة المورد</small><b>{{fmt(taxTotal)}} {{form.currency_code}}</b></span><strong><small>الإجمالي</small><b>{{fmt(total)}} {{form.currency_code}}</b></strong></div><p><i class="bi bi-info-circle"></i> هذه ضريبة واردة كما كتبها المورد، مستقلة كلياً عن ضريبة فاتورة الزبون.</p></DataPanel>
 </div>
 <div v-if="Object.keys(form.errors).length" class="form-errors"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>راجع الحقول المعلّمة</strong><small>{{Object.values(form.errors)[0]}}</small></span></div>
 <div class="save-bar"><a :href="urls.index" class="btn btn-light">إلغاء</a><span>بعد الحفظ تُسجّل الفاتورة كالتزام على المورد ويمكن دفعها من ملفها.</span><button class="btn btn-primary" :disabled="form.processing"><i class="bi bi-check2-circle"></i> {{form.processing?'جارٍ الحفظ…':'حفظ الفاتورة'}}</button></div>
</form>
    </ManagementPage>
</template>
<style scoped>
.invoice-form{display:grid;gap:10px}.workflow-note{display:flex;align-items:center;justify-content:center;gap:9px;padding:10px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--surface, #FFFDFA)}.workflow-note span{display:flex;align-items:center;gap:5px;color:var(--muted, #626B6D);font-size:.875rem;font-weight:800}.workflow-note>i{color:var(--muted, #626B6D);font-size:.875rem}.workflow-note span.done{color:var(--muted, #626B6D)}.workflow-note span.active{padding:6px 9px;border-radius:8px;color:var(--ink, #25343A);background:var(--canvas, #F7F3ED)}.header-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.header-grid label{display:grid;align-content:start;gap:5px}.header-grid label>span,.invoice-lines label>span,.manual-totals label>span{font-size:.875rem;font-weight:850}.header-grid small{color:#b42318;font-size:.875rem}.header-grid em{color:var(--muted, #626B6D);font-size:.875rem;font-style:normal;line-height:1.5}.line-head{margin-bottom:8px;padding:8px 11px;border-radius:9px;color:var(--muted, #626B6D);background:var(--canvas, #F7F3ED);font-size:.875rem}.invoice-lines{display:grid;gap:7px}.invoice-lines article{display:grid;grid-template-columns:minmax(170px,1.2fr) repeat(3,minmax(105px,.65fr)) minmax(115px,.7fr);align-items:end;gap:9px;padding:10px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--surface, #FFFDFA)}.line-name{display:grid;align-self:center}.line-name strong{font-size:.875rem}.line-name small{color:var(--muted, #626B6D);font-size:.875rem}.line-name span{width:max-content;margin-top:4px;padding:2px 6px;border-radius:12px;font-size:.875rem}.line-name .ok{color:var(--ink, #25343A);background:var(--canvas, #F7F3ED)}.line-name .warn{color:#965900;background:#fff0d2}.invoice-lines label{display:grid;gap:4px}.invoice-lines label small{font-size:.875rem}.invoice-lines .up{color:#a45d00}.invoice-lines .down{color:#1c6b8d}.line-total{display:grid;align-self:center;text-align:end}.line-total small{color:var(--muted, #626B6D);font-size:.875rem}.line-total strong{color:var(--ink, #25343A);font-size:.875rem}.invoice-bottom{display:grid;grid-template-columns:1fr 1fr;gap:10px}.manual-totals{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:9px}.manual-totals label{display:grid;gap:4px}.totals{display:grid;gap:7px}.totals>span,.totals>strong{display:flex;justify-content:space-between;gap:8px}.totals small{color:var(--muted, #626B6D);font-size:.875rem}.totals b{font-size:.875rem}.totals>strong{padding-top:9px;border-top:2px solid var(--brand-soft, #F3E3D8);color:var(--ink, #25343A)}.totals>strong b{font-size:.875rem}.invoice-bottom p{display:flex;gap:6px;margin:9px 0 0;padding-top:8px;border-top:1px solid var(--canvas, #F7F3ED);color:var(--muted, #626B6D);font-size:.875rem}.form-errors{display:flex;gap:9px;padding:11px 13px;border:1px solid #e8aaaa;border-radius:12px;color:#a52828;background:#fff1f1}.form-errors span{display:grid}.form-errors strong{font-size:.875rem}.form-errors small{font-size:.875rem}.save-bar{position:sticky;z-index:9;bottom:8px;display:flex;align-items:center;gap:9px;padding:11px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:rgba(255,255,255,.97);box-shadow:none}.save-bar>span{flex:1;color:var(--muted, #626B6D);font-size:.875rem}
@media(max-width:1000px){.header-grid{grid-template-columns:1fr 1fr}.invoice-lines article{grid-template-columns:minmax(150px,1fr) repeat(2,1fr)}.line-total{grid-column:3}.invoice-lines article>label:nth-of-type(3){grid-column:2}}
@media(max-width:680px){.workflow-note{justify-content:flex-start;overflow-x:auto}.workflow-note span{flex:0 0 auto}.header-grid,.invoice-bottom{grid-template-columns:1fr}.invoice-lines article{grid-template-columns:1fr 1fr}.line-name{grid-column:1/-1}.line-total{grid-column:auto}.save-bar>span{display:none}.save-bar .btn-primary{flex:1}}

/* Dar Al Sarw management layout */
.invoice-form { gap: 20px; } .workflow-note { justify-content: flex-start; padding: 16px 20px; gap: 14px; border-radius: 10px; } .invoice-lines>article { padding: 18px; border-radius: 10px; } .totals { padding: 20px; background: var(--canvas); border-radius: 10px; }
</style>
