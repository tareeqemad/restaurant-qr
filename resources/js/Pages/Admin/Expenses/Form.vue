<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useFormUx } from '../../../Composables/useFormUx';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    expense: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    baseCurrencyCode: { type: String, required: true },
    canManageCategories: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const editing = computed(() => Boolean(props.expense.id));
const detailsOpen = ref(Boolean(
    props.expense.paymentReference || props.expense.vendorName || props.expense.supplierId
    || props.expense.notes || props.expense.attachmentUrl
));
const form = useForm({
    description: props.expense.description,
    amount: props.expense.amount,
    currency_code: props.expense.currencyCode,
    exchange_rate: props.expense.exchangeRate,
    expense_category_id: props.expense.categoryId ?? '',
    payment_method: props.expense.paymentMethod,
    expense_date: props.expense.date,
    payment_reference: props.expense.paymentReference,
    vendor_name: props.expense.vendorName,
    supplier_id: props.expense.supplierId ?? '',
    notes: props.expense.notes,
    attachment: null,
});
const formRoot = ref(null);
const { focusFirstError } = useFormUx(form, { root: formRoot });

const selectedCurrency = computed(() => props.currencies.find((currency) => currency.code === form.currency_code));
const isBaseCurrency = computed(() => form.currency_code === props.baseCurrencyCode);
const baseAmount = computed(() => {
    const value = Number(form.amount || 0) * Number(form.exchange_rate || 0);
    return Number.isFinite(value) ? value.toFixed(2) : '0.00';
});
const selectedCategory = computed(() => props.categories.find((category) => String(category.id) === String(form.expense_category_id)));
const selectedPayment = computed(() => props.paymentMethods.find((method) => method.value === form.payment_method));

watch(() => form.currency_code, () => {
    form.exchange_rate = isBaseCurrency.value ? 1 : (selectedCurrency.value?.rate || '');
});

function submit() {
    const payload = editing.value ? { _method: 'put' } : {};
    form.transform((data) => ({ ...data, ...payload })).post(props.urls.submit, {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => {
            if (['supplier_id', 'vendor_name', 'payment_reference', 'attachment', 'notes'].some((key) => errors[key])) {
                detailsOpen.value = true;
            }
            focusFirstError(errors);
        },
    });
}
</script>

<template>
    <Head :title="editing ? `تعديل ${expense.number}` : 'مصروف جديد'" />
    <PageHeader :title="editing ? `تعديل ${expense.number}` : 'تسجيل مصروف'" icon="bi-receipt-cutoff"
                subtitle="بيانات واضحة الآن، وقيد محاسبي تلقائي بعد الاعتماد"
                :crumbs="[{ label: 'المصروفات', url: urls.index }]" />

    <form ref="formRoot" class="expense-workspace" @submit.prevent="submit">
        <main>
            <section class="form-card">
                <header><span>1</span><div><h2>المصروف</h2><p>ما الذي دُفع؟ كم؟ ومتى؟</p></div></header>
                <div class="field-grid">
                    <label class="field wide"><span>وصف واضح *</span><input v-model="form.description" class="form-control" maxlength="255" required autofocus placeholder="مثال: فاتورة الكهرباء لشهر آب"><small v-if="form.errors.description" class="error">{{ form.errors.description }}</small></label>
                    <label class="field"><span>المبلغ *</span><input v-model="form.amount" class="form-control money" type="number" min="0.01" step="0.01" inputmode="decimal" required><small v-if="form.errors.amount" class="error">{{ form.errors.amount }}</small></label>
                    <label class="field"><span>التصنيف *</span><select v-model="form.expense_category_id" class="form-select" required><option value="">اختر التصنيف</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.label }}</option></select><small v-if="form.errors.expense_category_id" class="error">{{ form.errors.expense_category_id }}</small></label>
                    <label class="field"><span>طريقة الدفع *</span><select v-model="form.payment_method" class="form-select" required><option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ method.label }}</option></select><small v-if="form.errors.payment_method" class="error">{{ form.errors.payment_method }}</small></label>
                    <label class="field"><span>تاريخ المصروف *</span><input v-model="form.expense_date" class="form-control" type="date" :max="new Date().toISOString().slice(0,10)" required><small v-if="form.errors.expense_date" class="error">{{ form.errors.expense_date }}</small></label>
                </div>
                <div v-if="!categories.length" class="warning"><i class="bi bi-exclamation-triangle-fill"></i><span>لا توجد تصنيفات مفعّلة، ولن يمكن الحفظ.</span><a v-if="canManageCategories" :href="urls.categories">إضافة تصنيف</a></div>
            </section>

            <section class="form-card">
                <header><span>2</span><div><h2>العملة والقيمة الدفترية</h2><p>العملة الأصلية محفوظة، والمحاسبة تستلم قيمتها بعملة الأساس.</p></div></header>
                <div class="currency-grid">
                    <label class="field"><span>العملة *</span><select v-model="form.currency_code" class="form-select" required><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} — {{ currency.name }}</option></select></label>
                    <label class="field"><span>سعر الصرف إلى {{ baseCurrencyCode }} *</span><input v-model="form.exchange_rate" class="form-control money" type="number" min="0.000001" step="0.000001" :readonly="isBaseCurrency" required><small v-if="form.errors.exchange_rate" class="error">{{ form.errors.exchange_rate }}</small></label>
                    <div class="base-value"><span>القيمة بالدفاتر</span><strong>{{ baseAmount }} {{ baseCurrencyCode }}</strong><small>المبلغ × سعر الصرف</small></div>
                </div>
            </section>

            <section class="form-card optional" :class="{ open: detailsOpen }">
                <button type="button" class="optional-toggle" @click="detailsOpen = !detailsOpen"><span><i class="bi bi-paperclip"></i><b>تفاصيل وإيصال</b><small>اختياري — المورد، المرجع والمرفق</small></span><i class="bi" :class="detailsOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i></button>
                <div v-show="detailsOpen" class="field-grid optional-body">
                    <label class="field"><span>مورّد مسجّل</span><select v-model="form.supplier_id" class="form-select"><option value="">بدون ربط</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></label>
                    <label class="field"><span>الجهة المستفيدة</span><input v-model="form.vendor_name" class="form-control" maxlength="150" placeholder="اسم الجهة إن لم تكن مورداً مسجلاً"></label>
                    <label class="field"><span>مرجع الدفع</span><input v-model="form.payment_reference" class="form-control" maxlength="100" placeholder="رقم تحويل أو شيك"></label>
                    <label class="field"><span>إيصال أو فاتورة</span><input class="form-control" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" @input="form.attachment = $event.target.files[0]"><small v-if="form.errors.attachment" class="error">{{ form.errors.attachment }}</small><a v-if="expense.attachmentUrl" :href="expense.attachmentUrl" target="_blank" class="current-file"><i class="bi bi-box-arrow-up-left"></i> فتح المرفق الحالي</a></label>
                    <label class="field full"><span>ملاحظات</span><textarea v-model="form.notes" class="form-control" rows="3" maxlength="1000" placeholder="أي تفاصيل تفيد المراجع أو المحاسب"></textarea></label>
                </div>
            </section>
        </main>

        <aside class="review-card">
            <div class="review-icon"><i class="bi bi-journal-check"></i></div>
            <small>مراجعة سريعة</small><h2>{{ form.description || 'مصروف جديد' }}</h2>
            <dl><div><dt>المبلغ</dt><dd>{{ form.amount || '0.00' }} {{ form.currency_code }}</dd></div><div><dt>بالدفاتر</dt><dd>{{ baseAmount }} {{ baseCurrencyCode }}</dd></div><div><dt>التصنيف</dt><dd>{{ selectedCategory?.label || 'لم يُحدد' }}</dd></div><div><dt>الدفع</dt><dd>{{ selectedPayment?.label || '—' }}</dd></div><div><dt>التاريخ</dt><dd>{{ form.expense_date }}</dd></div></dl>
            <div class="accounting-note"><i class="bi bi-info-circle"></i><span>الحفظ يسجل المصروف بانتظار الاعتماد. الأثر النقدي والقيد يُرحّلان عند الاعتماد فقط.</span></div>
            <button class="btn btn-primary save" :disabled="form.processing || !categories.length"><i class="bi bi-check2-circle"></i>{{ form.processing ? 'جارٍ الحفظ…' : editing ? 'حفظ التعديلات' : 'تسجيل للمراجعة' }}</button>
            <a :href="urls.index" class="cancel">إلغاء والعودة</a>
        </aside>
    </form>
</template>

<style scoped>
.expense-workspace{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:1rem;align-items:start}.expense-workspace main{display:grid;gap:.85rem}.form-card,.review-card{background:#fff;border:1px solid #dde7e1;border-radius:16px;box-shadow:0 8px 28px rgba(24,62,43,.05)}.form-card{padding:1rem}.form-card>header{display:flex;gap:.7rem;align-items:center;margin-bottom:1rem}.form-card>header>span{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:#e7f4ec;color:#126b3d;font-weight:850}.form-card h2,.form-card p{margin:0}.form-card h2{font-size:1rem}.form-card p{font-size:.74rem;color:#798b82;margin-top:.15rem}.field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}.field.wide,.field.full{grid-column:1/-1}.field{display:grid;gap:.35rem}.field>span{font-size:.76rem;font-weight:750;color:#40574b}.field input,.field select,.field textarea{border-color:#dbe5df;border-radius:10px;min-height:44px}.field textarea{resize:vertical}.money{font-weight:800}.error{color:#c33;font-size:.68rem}.currency-grid{display:grid;grid-template-columns:1fr 1fr 1.05fr;gap:.75rem}.base-value{padding:.7rem .85rem;border:1px solid #bddfc9;background:#f1faf4;border-radius:12px;display:grid;gap:.15rem}.base-value span,.base-value small{color:#668072;font-size:.7rem}.base-value strong{color:#0e6d3c;font-size:1.1rem}.warning{display:flex;gap:.5rem;align-items:center;margin-top:.75rem;padding:.7rem;border-radius:10px;background:#fff8e8;color:#8d6212;font-size:.75rem}.warning a{margin-inline-start:auto;color:inherit;font-weight:800}.optional{padding:0;overflow:hidden}.optional-toggle{width:100%;border:0;background:#fff;padding:1rem;display:flex;align-items:center;justify-content:space-between;text-align:start}.optional-toggle>span{display:grid;grid-template-columns:auto 1fr;column-gap:.55rem}.optional-toggle i{grid-row:1/3;color:#167344}.optional-toggle small{grid-column:2;color:#84928b;font-size:.7rem}.optional-body{padding:0 1rem 1rem;border-top:1px solid #edf1ef;padding-top:1rem}.current-file{font-size:.68rem;color:#157342;text-decoration:none}.review-card{position:sticky;top:88px;padding:1rem}.review-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:14px;background:#e9f5ed;color:#0e7240;font-size:1.25rem}.review-card>small{display:block;color:#819087;margin-top:.85rem}.review-card h2{font-size:1.05rem;margin:.2rem 0 .8rem;overflow-wrap:anywhere}.review-card dl{margin:0;display:grid}.review-card dl div{display:flex;justify-content:space-between;gap:.75rem;padding:.6rem 0;border-top:1px solid #edf1ef}.review-card dt{color:#7d8b84;font-size:.73rem}.review-card dd{margin:0;font-weight:800;font-size:.78rem;text-align:end}.accounting-note{display:flex;gap:.45rem;padding:.7rem;margin:.5rem 0 .85rem;border-radius:10px;background:#f5f8f6;color:#60756a;font-size:.7rem;line-height:1.7}.save{width:100%;min-height:48px;font-weight:850}.cancel{display:block;text-align:center;color:#6f7e76;text-decoration:none;font-size:.75rem;margin-top:.65rem}@media(max-width:900px){.expense-workspace{grid-template-columns:1fr}.review-card{position:static}.review-card dl{grid-template-columns:1fr 1fr;gap:0 .75rem}}@media(max-width:620px){.field-grid,.currency-grid{grid-template-columns:1fr}.field.wide,.field.full{grid-column:auto}.form-card,.review-card{border-radius:13px}.expense-workspace{gap:.65rem}.review-card dl{grid-template-columns:1fr}.review-card{padding:.85rem}}
</style>
