<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useFormUx } from '../../../Composables/useFormUx';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    branch: { type: Object, required: true },
    availableOwners: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

function blankOwner() {
    return {
        id: null, owner_type: 'person', name: '', national_id: '', tax_number: '',
        commercial_registration_number: '', phone: '', email: '', address: '', notes: '',
        ownership_percentage: '', title: '', is_primary: false, is_authorized_signatory: false,
        starts_on: '', ends_on: '',
    };
}

const editing = computed(() => Boolean(props.branch.id));
const form = useForm({
    code: props.branch.code,
    name: props.branch.name,
    city: props.branch.city,
    phone: props.branch.phone,
    email: props.branch.email,
    address: props.branch.address,
    display_order: props.branch.displayOrder,
    is_active: props.branch.isActive,
    customer_tax_display: props.branch.customerTaxDisplay,
    legal: { ...props.branch.legal },
    owners: props.branch.owners?.length
        ? props.branch.owners.map((owner) => ({ ...owner }))
        : [{ ...blankOwner(), is_primary: true, is_authorized_signatory: true, ownership_percentage: 100 }],
});
const formRoot = ref(null);
const { focusFirstError } = useFormUx(form, { root: formRoot });

const selectedOwnerId = ref('');
const ownerChoices = computed(() => props.availableOwners.filter((owner) => !form.owners.some((row) => Number(row.id) === Number(owner.id))));
const ownershipTotal = computed(() => form.owners.reduce((total, owner) => total + (Number(owner.ownership_percentage) || 0), 0));
const legalReady = computed(() => Boolean(form.legal.registered_name || form.legal.tax_number || form.legal.commercial_registration_number));

function ownerBranches(ownerId) {
    return props.availableOwners.find((owner) => Number(owner.id) === Number(ownerId))?.branchNames ?? [];
}

function addExistingOwner() {
    const source = props.availableOwners.find((owner) => Number(owner.id) === Number(selectedOwnerId.value));
    if (!source) return;
    const isOnlyBlankRow = form.owners.length === 1 && !form.owners[0].id && !form.owners[0].name.trim();
    const row = {
        ...source,
        ownership_percentage: isOnlyBlankRow ? 100 : '',
        title: '',
        is_primary: isOnlyBlankRow,
        is_authorized_signatory: isOnlyBlankRow,
        starts_on: '',
        ends_on: '',
    };
    if (isOnlyBlankRow) form.owners.splice(0, 1, row);
    else form.owners.push(row);
    selectedOwnerId.value = '';
}

function addNewOwner() { form.owners.push(blankOwner()); }

function removeOwner(index) {
    if (form.owners.length === 1) return;
    const wasPrimary = form.owners[index].is_primary;
    form.owners.splice(index, 1);
    if (wasPrimary && form.owners.length) form.owners[0].is_primary = true;
}

function setPrimary(index) {
    form.owners.forEach((owner, row) => { owner.is_primary = row === index; });
}

const title = computed(() => editing.value ? `تعديل ${props.branch.name}` : 'إضافة فرع');
const previewName = computed(() => form.name.trim() || 'اسم الفرع');
const previewCode = computed(() => form.code.trim() || 'branch-code');

function normalizeCode() {
    form.code = form.code.trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/-+/g, '-');
}

function submit() {
    normalizeCode();
    const options = { preserveScroll: true, onError: focusFirstError };
    editing.value ? form.put(props.urls.submit, options) : form.post(props.urls.submit, options);
}
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" icon="bi-building-add"
                subtitle="كل فرع مساحة تشغيل مستقلة بمخزونه وموظفيه وطلباته"
                :crumbs="[{ label: 'الفروع', url: urls.index }]" />

    <form ref="formRoot" class="branch-form" @submit.prevent="submit">
        <main class="branch-main">
            <section class="form-card">
                <header class="card-head"><span class="step">1</span><div><h2>هوية الفرع</h2><p>اسم واضح للموظفين ورمز ثابت يستخدمه النظام داخلياً.</p></div></header>
                <div class="field-grid">
                    <label class="field wide">
                        <span>اسم الفرع بالعربية *</span>
                        <input v-model="form.name" name="name" class="form-control" required autofocus placeholder="مثال: الفرع الرئيسي - خان يونس" />
                        <small v-if="form.errors.name" class="error">{{ form.errors.name }}</small>
                    </label>
                    <label class="field">
                        <span>رمز الفرع *</span>
                        <input v-model="form.code" name="code" class="form-control" required maxlength="32" placeholder="khan-yunis" @blur="normalizeCode" />
                        <small v-if="form.errors.code" class="error">{{ form.errors.code }}</small>
                        <em>إنجليزي وأرقام وشرطات فقط، مثل main-gaza.</em>
                    </label>
                </div>
            </section>

            <section class="form-card">
                <header class="card-head"><span class="step">2</span><div><h2>الموقع والتواصل</h2><p>بيانات تشغيلية اختيارية تظهر للفريق وفي المستندات عند الحاجة.</p></div></header>
                <div class="field-grid">
                    <label class="field">
                        <span>المدينة</span>
                        <input v-model="form.city" name="city" class="form-control" placeholder="خان يونس" />
                        <small v-if="form.errors.city" class="error">{{ form.errors.city }}</small>
                    </label>
                    <label class="field">
                        <span>رقم الهاتف</span>
                        <input v-model="form.phone" name="phone" class="form-control" inputmode="tel" placeholder="05xxxxxxxx" />
                        <small v-if="form.errors.phone" class="error">{{ form.errors.phone }}</small>
                    </label>
                    <label class="field">
                        <span>البريد الإلكتروني</span>
                        <input v-model="form.email" name="email" class="form-control" type="email" placeholder="branch@example.com" />
                        <small v-if="form.errors.email" class="error">{{ form.errors.email }}</small>
                    </label>
                    <label class="field">
                        <span>ترتيب الظهور</span>
                        <input v-model="form.display_order" name="display_order" class="form-control" type="number" min="0" inputmode="numeric" />
                        <small v-if="form.errors.display_order" class="error">{{ form.errors.display_order }}</small>
                    </label>
                    <label class="field wide">
                        <span>العنوان</span>
                        <textarea v-model="form.address" name="address" class="form-control" rows="2" placeholder="الشارع، الحي، علامة مميزة…"></textarea>
                        <small v-if="form.errors.address" class="error">{{ form.errors.address }}</small>
                    </label>
                </div>
            </section>

            <section class="form-card">
                <header class="card-head"><span class="step">3</span><div><h2>الهوية القانونية والفاتورة</h2><p>هذه بيانات المنشأة التي تصدر الفاتورة، وليست إعداداً لتفعيل الضريبة.</p></div></header>
                <div class="legal-note"><i class="bi bi-shield-check"></i><span><strong>الرقم الضريبي اختياري</strong><small>إذا كانت الضريبة متوقفة فلن ينشئ النظام قيود ضريبة. يبقى الرقم محفوظاً لتستخدمه الفاتورة عند الحاجة.</small></span><b :class="{ ready: legalReady }">{{ legalReady ? 'مسجلة' : 'غير مكتملة' }}</b></div>
                <div class="field-grid legal-grid">
                    <label class="field wide"><span>الاسم القانوني المسجل</span><input v-model="form.legal.registered_name" class="form-control" placeholder="اسم المنشأة كما يظهر في المستندات" /><small v-if="form.errors['legal.registered_name']" class="error">{{ form.errors['legal.registered_name'] }}</small></label>
                    <label class="field"><span>الرقم الضريبي</span><input v-model="form.legal.tax_number" class="form-control" inputmode="numeric" placeholder="اختياري" /><small v-if="form.errors['legal.tax_number']" class="error">{{ form.errors['legal.tax_number'] }}</small></label>
                    <label class="field"><span>رقم السجل التجاري</span><input v-model="form.legal.commercial_registration_number" class="form-control" placeholder="اختياري" /></label>
                    <label class="field"><span>رقم رخصة البلدية / المهنة</span><input v-model="form.legal.municipal_license_number" class="form-control" placeholder="اختياري" /></label>
                    <label class="field"><span>هاتف الفاتورة</span><input v-model="form.legal.invoice_phone" class="form-control" inputmode="tel" :placeholder="form.phone || 'اختياري'" /></label>
                    <label class="field"><span>بريد الفاتورة</span><input v-model="form.legal.invoice_email" class="form-control" type="email" :placeholder="form.email || 'اختياري'" /></label>
                    <label class="field wide"><span>عنوان الفاتورة</span><textarea v-model="form.legal.invoice_address" class="form-control" rows="2" :placeholder="form.address || 'يُستخدم عنوان الفرع عند تركه فارغاً'"></textarea></label>
                </div>
            </section>

            <section class="form-card owners-card">
                <header class="card-head"><span class="step">4</span><div><h2>الملاك والشركاء</h2><p>المالك سجل مستقل: اربطه بهذا الفرع أو أعد استخدامه في فرع آخر بدون تكرار بياناته.</p></div></header>
                <div class="owner-picker">
                    <select v-model="selectedOwnerId" class="form-select" :disabled="!ownerChoices.length"><option value="">{{ ownerChoices.length ? 'اختر مالكاً مسجلاً…' : 'لا يوجد ملاك آخرون متاحون' }}</option><option v-for="owner in ownerChoices" :key="owner.id" :value="owner.id">{{ owner.name }} · {{ owner.owner_type === 'company' ? 'شركة' : 'شخص' }}</option></select>
                    <button type="button" :disabled="!selectedOwnerId" @click="addExistingOwner"><i class="bi bi-link-45deg"></i> ربط المسجل</button>
                    <button type="button" class="new-owner" @click="addNewOwner"><i class="bi bi-person-plus"></i> مالك جديد</button>
                </div>
                <small v-if="form.errors.owners" class="error section-error">{{ form.errors.owners }}</small>
                <div class="owners-list">
                    <article v-for="(owner, index) in form.owners" :key="owner.id || `new-${index}`" class="owner-row" :class="{ primary: owner.is_primary }">
                        <header>
                            <span class="owner-avatar"><i class="bi" :class="owner.owner_type === 'company' ? 'bi-buildings' : 'bi-person-vcard'"></i></span>
                            <label><span>نوع المالك</span><select v-model="owner.owner_type" class="form-select"><option value="person">شخص</option><option value="company">شركة / جهة اعتبارية</option></select></label>
                            <label class="owner-name"><span>{{ owner.owner_type === 'company' ? 'اسم الشركة *' : 'اسم المالك الكامل *' }}</span><input v-model="owner.name" :name="`owners.${index}.name`" class="form-control" required :placeholder="owner.owner_type === 'company' ? 'الاسم القانوني للشركة' : 'الاسم الرباعي'" /><small v-if="form.errors[`owners.${index}.name`]" class="error">{{ form.errors[`owners.${index}.name`] }}</small></label>
                            <button type="button" class="remove-owner" :disabled="form.owners.length === 1" aria-label="إزالة المالك من الفرع" @click="removeOwner(index)"><i class="bi bi-trash3"></i></button>
                        </header>
                        <div v-if="owner.id && ownerBranches(owner.id).length" class="shared-owner"><i class="bi bi-diagram-3"></i><span>سجل مشترك مع: {{ ownerBranches(owner.id).join('، ') }}</span></div>
                        <div class="ownership-grid">
                            <label class="field"><span>نسبة الملكية</span><div class="percent-input"><input v-model="owner.ownership_percentage" :name="`owners.${index}.ownership_percentage`" class="form-control" type="number" min="0.01" max="100" step="0.01" inputmode="decimal" /><b>%</b></div><small v-if="form.errors[`owners.${index}.ownership_percentage`]" class="error">{{ form.errors[`owners.${index}.ownership_percentage`] }}</small></label>
                            <label class="field"><span>الصفة في الفرع</span><input v-model="owner.title" class="form-control" placeholder="مالك، شريك، ممثل شركة…" /></label>
                            <button type="button" class="flag-button" :class="{ active: owner.is_primary }" @click="setPrimary(index)"><i class="bi" :class="owner.is_primary ? 'bi-star-fill' : 'bi-star'"></i><span><strong>المالك الرئيسي</strong><small>يظهر أولاً في الملخص</small></span></button>
                            <button type="button" class="flag-button" :class="{ active: owner.is_authorized_signatory }" @click="owner.is_authorized_signatory = !owner.is_authorized_signatory"><i class="bi" :class="owner.is_authorized_signatory ? 'bi-check-circle-fill' : 'bi-circle'"></i><span><strong>مفوّض بالتوقيع</strong><small>صفة قانونية فقط</small></span></button>
                        </div>
                        <details class="owner-details">
                            <summary><span><i class="bi bi-card-checklist"></i> الهوية والتواصل</span><i class="bi bi-chevron-down"></i></summary>
                            <div class="field-grid">
                                <label class="field"><span>{{ owner.owner_type === 'company' ? 'رقم تسجيل الجهة' : 'رقم الهوية' }}</span><input v-model="owner.national_id" class="form-control" /></label>
                                <label class="field"><span>الرقم الضريبي للمالك</span><input v-model="owner.tax_number" class="form-control" /></label>
                                <label v-if="owner.owner_type === 'company'" class="field"><span>السجل التجاري للمالك</span><input v-model="owner.commercial_registration_number" class="form-control" /></label>
                                <label class="field"><span>رقم الجوال</span><input v-model="owner.phone" class="form-control" inputmode="tel" placeholder="0592632026" /></label>
                                <label class="field"><span>البريد الإلكتروني</span><input v-model="owner.email" class="form-control" type="email" /></label>
                                <label class="field"><span>بداية الملكية</span><input v-model="owner.starts_on" class="form-control" type="date" /></label>
                                <label class="field"><span>نهاية الملكية</span><input v-model="owner.ends_on" class="form-control" type="date" /><small v-if="form.errors[`owners.${index}.ends_on`]" class="error">{{ form.errors[`owners.${index}.ends_on`] }}</small></label>
                                <label class="field wide"><span>العنوان</span><textarea v-model="owner.address" class="form-control" rows="2"></textarea></label>
                                <label class="field wide"><span>ملاحظات داخلية</span><textarea v-model="owner.notes" class="form-control" rows="2" placeholder="لا تظهر في الفاتورة"></textarea></label>
                            </div>
                        </details>
                    </article>
                </div>
                <div class="ownership-total" :class="{ danger: ownershipTotal > 100, complete: Math.abs(ownershipTotal - 100) < .01 }"><span>مجموع النسب المدخلة</span><strong>{{ ownershipTotal.toFixed(2) }}%</strong><small v-if="ownershipTotal === 0">يمكن ترك النسب فارغة إذا لم تُحدد بعد.</small><small v-else-if="ownershipTotal < 100">المتبقي {{ (100 - ownershipTotal).toFixed(2) }}%</small><small v-else-if="ownershipTotal > 100">تجاوز المجموع 100% — صحح النسب.</small><small v-else>توزيع الملكية مكتمل.</small></div>
            </section>

            <section class="form-card">
                <header class="card-head"><span class="step">5</span><div><h2>حالة التشغيل</h2><p>الفرع الموقوف يبقى محفوظاً لكنه لا يستقبل عمليات جديدة.</p></div></header>
                <button type="button" class="status-toggle" :class="{ active: form.is_active }" @click="form.is_active = !form.is_active">
                    <i class="bi" :class="form.is_active ? 'bi-check-circle-fill' : 'bi-pause-circle-fill'"></i>
                    <span><strong>{{ form.is_active ? 'الفرع مفعّل' : 'الفرع متوقف' }}</strong><small>{{ form.is_active ? 'يظهر في مبدّل الفروع ويمكن لفريقه العمل عليه.' : 'لن يظهر للاستخدام التشغيلي الجديد حتى تفعّله.' }}</small></span>
                    <span class="switch" aria-hidden="true"><i></i></span>
                </button>
                <small v-if="form.errors.is_active" class="error section-error">{{ form.errors.is_active }}</small>
            </section>

            <details class="advanced-card">
                <summary><span><i class="bi bi-receipt"></i><strong>عرض السعر والضريبة للزبون</strong><small>إعداد متقدم — لا يفعّل أي ضريبة بحد ذاته</small></span><i class="bi bi-chevron-down"></i></summary>
                <div class="advanced-body">
                    <p>يغيّر طريقة عرض الإجمالي في منيو QR لهذا الفرع فقط. تفعيل الضريبة أو نسبتها يتم من إعدادات المحاسبة.</p>
                    <div class="tax-options">
                        <button v-for="option in [{value:'inherit',label:'إعداد النظام',hint:'الخيار الموصى به'},{value:'exclusive',label:'قبل الضريبة',hint:'يعرضها منفصلة'},{value:'inclusive',label:'شامل الضريبة',hint:'السعر النهائي ظاهر'}]" :key="option.value" type="button" :class="{selected:form.customer_tax_display===option.value}" @click="form.customer_tax_display=option.value">
                            <i class="bi" :class="form.customer_tax_display===option.value?'bi-check-circle-fill':'bi-circle'"></i><span><strong>{{option.label}}</strong><small>{{option.hint}}</small></span>
                        </button>
                    </div>
                    <small v-if="form.errors.customer_tax_display" class="error">{{ form.errors.customer_tax_display }}</small>
                </div>
            </details>
        </main>

        <aside class="branch-summary">
            <div class="branch-preview">
                <span class="branch-icon"><i class="bi bi-building"></i></span>
                <div><strong>{{ previewName }}</strong><small>{{ previewCode }}</small></div>
                <span class="state" :class="{ on: form.is_active }">{{ form.is_active ? 'مفعّل' : 'متوقف' }}</span>
            </div>
            <div class="preview-line"><span>المدينة</span><strong>{{ form.city || 'غير محددة' }}</strong></div>
            <div class="preview-line"><span>الهاتف</span><strong>{{ form.phone || '—' }}</strong></div>
            <div class="preview-line"><span>الملاك</span><strong>{{ form.owners.length }}</strong></div>
            <div class="preview-line"><span>هوية الفاتورة</span><strong>{{ legalReady ? 'مسجلة' : 'غير مكتملة' }}</strong></div>

            <div v-if="!editing" class="provision-box">
                <strong><i class="bi bi-magic"></i> جاهز للعمل من أول يوم</strong>
                <p>بعد الحفظ سينشئ النظام تلقائياً:</p>
                <ul><li><i class="bi bi-box-seam"></i> المخزن الرئيسي</li><li><i class="bi bi-fire"></i> محطة المطبخ</li></ul>
            </div>
            <div v-else class="provision-box neutral">
                <strong><i class="bi bi-shield-check"></i> تعديل آمن</strong>
                <p>تغيير بيانات الفرع لا يخلط مخزونه أو فواتيره أو موظفيه مع أي فرع آخر.</p>
            </div>
        </aside>

        <footer class="save-bar">
            <a :href="urls.index" class="btn btn-light">إلغاء</a>
            <span><i class="bi bi-info-circle"></i> الحقول غير المعلّمة بنجمة اختيارية.</span>
            <button class="btn btn-primary" :disabled="form.processing">
                <i class="bi bi-check2-circle"></i> {{ form.processing ? 'جارٍ الحفظ…' : editing ? 'حفظ التعديلات' : 'إنشاء الفرع' }}
            </button>
        </footer>
    </form>
</template>

<style scoped>
.branch-form{display:grid;grid-template-columns:minmax(0,1fr) 292px;gap:12px;align-items:start}.branch-main{display:grid;gap:10px}.form-card,.advanced-card,.branch-summary{border:1px solid #dfe7e2;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(24,57,36,.035)}.form-card{padding:16px}.card-head{display:flex;align-items:flex-start;gap:10px;margin-bottom:14px}.step{display:grid;flex:0 0 30px;height:30px;place-items:center;border-radius:10px;background:#eaf5ed;color:rgb(var(--primary-rgb));font-size:.72rem;font-weight:900}.card-head h2{margin:0;color:#17271d;font-size:.88rem;font-weight:900}.card-head p{margin:3px 0 0;color:#79877e;font-size:.63rem}.field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.field{display:grid;align-content:start;gap:5px;margin:0}.field.wide{grid-column:1/-1}.field>span{color:#34483a;font-size:.66rem;font-weight:850}.field .form-control{min-height:44px;border-color:#dce5df;border-radius:11px;font-size:.72rem}.field textarea.form-control{min-height:70px;resize:vertical}.field .form-control:focus{border-color:#82b593;box-shadow:0 0 0 3px rgba(var(--primary-rgb),.08)}.field em{color:#89958e;font-size:.56rem;font-style:normal}.error{color:#b42318;font-size:.61rem}.section-error{display:block;margin-top:8px}.status-toggle{display:flex;width:100%;min-height:66px;align-items:center;gap:11px;padding:12px 14px;border:1px solid #e0e7e2;border-radius:14px;background:#f8faf8;color:#758279;text-align:start}.status-toggle>i{font-size:1.3rem}.status-toggle>span:nth-child(2){display:grid;flex:1}.status-toggle strong{color:#35473c;font-size:.72rem}.status-toggle small{margin-top:2px;font-size:.58rem}.status-toggle.active{border-color:#9fc8aa;background:#eff8f2;color:#178249}.status-toggle.active strong{color:#176b39}.switch{position:relative;width:42px;height:24px;border-radius:999px;background:#c7d0ca}.switch i{position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.18s}.status-toggle.active .switch{background:#29925a}.status-toggle.active .switch i{transform:translateX(-18px)}.advanced-card{overflow:hidden}.advanced-card summary{display:flex;min-height:64px;align-items:center;justify-content:space-between;padding:13px 16px;cursor:pointer;list-style:none}.advanced-card summary::-webkit-details-marker{display:none}.advanced-card summary>span{display:grid;grid-template-columns:auto 1fr;column-gap:9px;align-items:center}.advanced-card summary>span>i{grid-row:1/3;color:#176b39}.advanced-card summary strong{font-size:.71rem}.advanced-card summary small{color:#829087;font-size:.57rem}.advanced-card[open] summary>.bi{transform:rotate(180deg)}.advanced-body{padding:13px 16px 16px;border-top:1px solid #eef2ef}.advanced-body>p{margin:0 0 10px;color:#738178;font-size:.61rem;line-height:1.7}.tax-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.tax-options button{display:flex;min-height:54px;align-items:center;gap:8px;padding:9px;border:1px solid #e0e7e2;border-radius:12px;background:#fbfcfb;color:#76837b;text-align:start}.tax-options button>span{display:grid}.tax-options strong{color:#34473a;font-size:.64rem}.tax-options small{font-size:.54rem}.tax-options button.selected{border-color:#91bd9d;background:#eef8f1;color:#167842}.branch-summary{position:sticky;top:82px;padding:14px}.branch-preview{display:flex;align-items:center;gap:10px;padding-bottom:13px;border-bottom:1px solid #edf1ee}.branch-icon{display:grid;flex:0 0 44px;height:44px;place-items:center;border-radius:14px;background:linear-gradient(145deg,rgb(var(--primary-rgb)),#269b60);color:#fff;font-size:1.05rem}.branch-preview>div{display:grid;flex:1;min-width:0}.branch-preview strong{overflow:hidden;color:#1d3024;font-size:.73rem;text-overflow:ellipsis;white-space:nowrap}.branch-preview small{color:#819087;font-size:.57rem;text-align:end}.state{border-radius:999px;padding:4px 8px;background:#f0f2f1;color:#7b8780;font-size:.54rem;font-weight:850}.state.on{background:#e5f6ea;color:#158048}.preview-line{display:flex;align-items:center;justify-content:space-between;gap:9px;padding:9px 2px;border-bottom:1px solid #f0f3f1}.preview-line span{color:#839087;font-size:.59rem}.preview-line strong{color:#304238;font-size:.63rem}.provision-box{margin-top:12px;padding:12px;border:1px solid #cce3d2;border-radius:13px;background:#f0f8f2;color:#28613b}.provision-box.neutral{border-color:#dce4df;background:#f7f9f7;color:#405548}.provision-box strong{display:flex;align-items:center;gap:6px;font-size:.66rem}.provision-box p{margin:4px 0 8px;color:#708077;font-size:.56rem;line-height:1.6}.provision-box ul{display:grid;gap:6px;margin:0;padding:0;list-style:none}.provision-box li{display:flex;align-items:center;gap:7px;font-size:.61rem;font-weight:800}.save-bar{position:sticky;z-index:8;bottom:8px;grid-column:1/-1;display:flex;align-items:center;gap:9px;padding:11px;border:1px solid #cbddd1;border-radius:14px;background:rgba(255,255,255,.97);box-shadow:0 12px 35px rgba(20,50,31,.12)}.save-bar>span{display:flex;flex:1;gap:6px;color:#7a8980;font-size:.61rem}
.legal-note{display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 12px;border:1px solid #d9e7dd;border-radius:12px;background:#f5faf7;color:#1c7040}.legal-note>span{display:grid;flex:1}.legal-note strong{font-size:.64rem}.legal-note small{color:#708078;font-size:.55rem;line-height:1.6}.legal-note>b{padding:4px 8px;border-radius:999px;color:#927123;background:#fff5d9;font-size:.52rem}.legal-note>b.ready{color:#167b43;background:#e5f5ea}.owner-picker{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:7px;padding-bottom:12px;border-bottom:1px solid #edf2ee}.owner-picker .form-select{min-height:42px;border-radius:10px;font-size:.64rem}.owner-picker button{display:inline-flex;min-height:42px;align-items:center;justify-content:center;gap:5px;padding:0 11px;border:1px solid #cfe0d4;border-radius:10px;color:#176b39;background:#f2f8f4;font-size:.59rem;font-weight:850}.owner-picker button.new-owner{color:#fff;background:rgb(var(--primary-rgb))}.owner-picker button:disabled{opacity:.45}.owners-list{display:grid;gap:9px;margin-top:11px}.owner-row{overflow:hidden;border:1px solid #dfe7e2;border-radius:14px;background:#fcfdfc}.owner-row.primary{border-color:#91bd9d;box-shadow:inset -3px 0 rgb(var(--primary-rgb))}.owner-row>header{display:grid;grid-template-columns:40px 150px minmax(0,1fr) 38px;align-items:end;gap:8px;padding:11px}.owner-avatar{display:grid;width:40px;height:40px;place-items:center;border-radius:11px;color:#176b39;background:#eaf5ed}.owner-row header label{display:grid;gap:4px}.owner-row header label>span{font-size:.57rem;font-weight:850}.owner-row header .form-control,.owner-row header .form-select{min-height:40px;border-radius:9px;font-size:.65rem}.remove-owner{display:grid;width:38px;height:38px;place-items:center;border:1px solid #efd7d7;border-radius:9px;color:#b42318;background:#fff6f6}.remove-owner:disabled{opacity:.3}.shared-owner{display:flex;align-items:center;gap:6px;margin:0 11px 9px;padding:7px 9px;border-radius:9px;color:#35624c;background:#edf7f1;font-size:.55rem}.ownership-grid{display:grid;grid-template-columns:140px minmax(150px,1fr) minmax(145px,1fr) minmax(145px,1fr);gap:8px;padding:0 11px 11px}.percent-input{position:relative}.percent-input input{padding-inline-end:30px}.percent-input b{position:absolute;top:50%;inset-inline-end:10px;transform:translateY(-50%);color:#718078;font-size:.63rem}.flag-button{display:flex;min-height:44px;align-items:center;gap:7px;padding:7px 9px;border:1px solid #e0e7e2;border-radius:10px;color:#78857d;background:#fff;text-align:start}.flag-button>span{display:grid}.flag-button strong{color:#394a40;font-size:.59rem}.flag-button small{font-size:.5rem}.flag-button.active{border-color:#9ac3a5;color:#187944;background:#eef8f1}.flag-button.active strong{color:#176b39}.owner-details{border-top:1px solid #edf1ee;background:#fafcfb}.owner-details summary{display:flex;min-height:42px;align-items:center;justify-content:space-between;padding:0 12px;cursor:pointer;list-style:none;color:#5f7066;font-size:.58rem;font-weight:850}.owner-details summary::-webkit-details-marker{display:none}.owner-details summary>span{display:flex;align-items:center;gap:6px}.owner-details[open] summary>.bi{transform:rotate(180deg)}.owner-details>.field-grid{padding:0 12px 12px}.ownership-total{display:grid;grid-template-columns:1fr auto;gap:2px 10px;margin-top:10px;padding:10px 12px;border:1px solid #dfe7e2;border-radius:11px;background:#fafcfb}.ownership-total span{color:#65756b;font-size:.59rem;font-weight:800}.ownership-total strong{grid-row:1/3;grid-column:2;color:#46574c;font-size:.78rem}.ownership-total small{color:#849087;font-size:.52rem}.ownership-total.complete{border-color:#a9ceb3;background:#f0f8f2}.ownership-total.complete strong{color:#177d45}.ownership-total.danger{border-color:#efb4b4;background:#fff5f5}.ownership-total.danger strong{color:#b42318}
@media(max-width:1100px){.ownership-grid{grid-template-columns:1fr 1fr}.owner-row>header{grid-template-columns:40px 130px minmax(0,1fr) 38px}}
@media(max-width:950px){.branch-form{grid-template-columns:1fr}.branch-summary{position:static;grid-row:1}}
@media(max-width:620px){.form-card{padding:13px}.field-grid,.tax-options,.ownership-grid{grid-template-columns:1fr}.field.wide{grid-column:auto}.branch-summary{display:none}.save-bar>span{display:none}.save-bar .btn-primary{flex:1}.owner-picker{grid-template-columns:1fr 1fr}.owner-picker .form-select{grid-column:1/-1}.owner-row>header{grid-template-columns:38px 1fr 36px}.owner-row header>label:nth-of-type(1){grid-column:2}.owner-row header .owner-name{grid-column:1/-1}.remove-owner{grid-column:3;grid-row:1}.legal-note{align-items:flex-start}.legal-note>b{white-space:nowrap}}
</style>
