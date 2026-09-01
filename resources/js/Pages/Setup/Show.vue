<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    mode: { type: String, required: true },
    defaults: { type: Object, required: true },
    summary: { type: Object, required: true },
    roles: { type: Array, required: true },
    routes: { type: Object, required: true },
});

const step = ref(0);
const logoInput = ref(null);
const faviconInput = ref(null);
const isDemo = computed(() => props.mode === 'demo');
const steps = computed(() => [
    ...(isDemo.value ? [{ title: 'تنظيف التجربة', icon: 'bi-stars' }] : []),
    { title: 'هوية المطعم', icon: 'bi-shop' },
    { title: 'الفرع الأول', icon: 'bi-geo-alt' },
    { title: 'الملكية والحسابات', icon: 'bi-people' },
    { title: 'المراجعة', icon: 'bi-check2-circle' },
]);
const identityStep = computed(() => isDemo.value ? 1 : 0);
const branchStep = computed(() => identityStep.value + 1);
const teamStep = computed(() => identityStep.value + 2);
const reviewStep = computed(() => identityStep.value + 3);
const finalStep = computed(() => step.value === steps.value.length - 1);
const progress = computed(() => `${Math.round(((step.value + 1) / steps.value.length) * 100)}%`);

const form = useForm({
    restaurant_name: props.defaults.restaurant_name ?? '',
    legal_name: props.defaults.legal_name ?? '',
    tax_number: props.defaults.tax_number ?? '',
    commercial_registration_number: props.defaults.commercial_registration_number ?? '',
    municipal_license_number: props.defaults.municipal_license_number ?? '',
    receipt_footer: props.defaults.receipt_footer ?? 'شكراً لزيارتكم',
    brand_logo: null,
    brand_favicon: null,
    branch_code: props.defaults.branch_code ?? 'main',
    branch_name: props.defaults.branch_name ?? 'الفرع الرئيسي',
    branch_phone: props.defaults.branch_phone ?? '',
    branch_city: props.defaults.branch_city ?? '',
    branch_address: props.defaults.branch_address ?? '',
    business_owner_type: props.defaults.business_owner_type ?? 'person',
    business_owner_name: props.defaults.business_owner_name ?? '',
    business_owner_national_id: props.defaults.business_owner_national_id ?? '',
    business_owner_phone: props.defaults.business_owner_phone ?? '',
    business_owner_percentage: props.defaults.business_owner_percentage ?? 100,
    admin_name: props.defaults.admin_name ?? '',
    admin_username: props.defaults.admin_username ?? 'admin',
    admin_email: props.defaults.admin_email ?? '',
    admin_phone: props.defaults.admin_phone ?? '',
    admin_password: '',
    admin_password_confirmation: '',
    staff: [],
    confirm_reset: false,
});

const kept = computed(() => [
    [props.summary.categories, 'تصنيف'], [props.summary.items, 'صنف منيو'],
    [props.summary.ingredients, 'مكوّن'], [props.summary.recipes, 'سطر وصفة'],
]);
const removed = computed(() => [
    [props.summary.orders, 'طلب'], [props.summary.invoices, 'فاتورة'],
    [props.summary.customers, 'زبون'], [props.summary.staff, 'حساب تجريبي'],
]);

function pickFile(event, field) { form[field] = event.target.files?.[0] ?? null; }
function role(value) { return props.roles.find((item) => item.value === value) ?? props.roles[0]; }
function addStaff() {
    if (form.staff.length >= 12) return;
    form.staff.push({ name: '', phone: '', role: 'waiter' });
    nextTick(() => document.querySelector(`[data-staff="${form.staff.length - 1}"] input`)?.focus());
}
function removeStaff(index) { form.staff.splice(index, 1); }

function validateStep() {
    form.clearErrors();
    const errors = {};
    if (step.value === identityStep.value && !form.restaurant_name.trim()) errors.restaurant_name = 'اكتب اسم المطعم.';
    if (step.value === branchStep.value) {
        if (!form.branch_name.trim()) errors.branch_name = 'اكتب اسم الفرع.';
        if (!form.branch_code.trim()) errors.branch_code = 'اكتب رمز الفرع.';
    }
    if (step.value === teamStep.value) {
        if (!form.business_owner_name.trim()) errors.business_owner_name = 'اكتب اسم المالك القانوني.';
        if (!form.admin_name.trim()) errors.admin_name = 'اكتب اسم مدير النظام.';
        if (!form.admin_username.trim()) errors.admin_username = 'اكتب اسم الدخول.';
        if (form.admin_password.length < 8) errors.admin_password = 'كلمة المرور 8 أحرف على الأقل.';
        if (form.admin_password !== form.admin_password_confirmation) errors.admin_password_confirmation = 'التأكيد غير مطابق.';
        form.staff.forEach((member, index) => {
            if (!member.name.trim()) errors[`staff.${index}.name`] = 'اكتب اسم الموظف أو احذف الصف.';
        });
    }
    Object.assign(form.errors, errors);
    return !Object.keys(errors).length;
}
function next() {
    if (!validateStep()) return;
    step.value += 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function previous() {
    step.value = Math.max(0, step.value - 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function submit() {
    if (form.processing || (isDemo.value && !form.confirm_reset)) return;
    form.post(props.routes.store, {
        forceFormData: true,
        preserveScroll: true,
        onError: () => {
            const keys = Object.keys(form.errors);
            if (keys.some((key) => key.startsWith('restaurant_') || ['legal_name', 'tax_number', 'commercial_registration_number', 'municipal_license_number', 'receipt_footer', 'brand_logo', 'brand_favicon'].includes(key))) step.value = identityStep.value;
            else if (keys.some((key) => key.startsWith('branch_'))) step.value = branchStep.value;
            else if (keys.some((key) => key.startsWith('business_owner_') || key.startsWith('admin_') || key.startsWith('staff'))) step.value = teamStep.value;
        },
    });
}
</script>

<template>
    <Head :title="`تجهيز ${brand.name}`" />
    <main class="setup-page">
        <header class="topbar">
            <a class="brand" :href="routes.continueDemo"><span><img :src="brand.logo" :alt="brand.name"></span><div><small>تجهيز المطعم</small><strong>{{ brand.name }}</strong></div></a>
            <a v-if="isDemo" class="back-demo" :href="routes.continueDemo"><i class="bi bi-arrow-return-right"></i> العودة للتجربة</a>
        </header>

        <div class="setup-shell">
            <aside class="stepper">
                <span class="mode"><i :class="isDemo ? 'bi bi-stars' : 'bi bi-rocket-takeoff'"></i>{{ isDemo ? 'تحويل التجربة إلى مطعمك' : 'إعداد جديد' }}</span>
                <h1>كل ما يلزم لبدء التشغيل.</h1>
                <p>نحفظ المنيو الذي أعجبك ونرتب الأساسيات. التفاصيل المتقدمة قابلة للتعديل لاحقاً.</p>
                <ol>
                    <li v-for="(item, index) in steps" :key="item.title" :class="{ active: index === step, done: index < step }">
                        <button type="button" :disabled="index > step" @click="index <= step && (step = index)"><span><i :class="index < step ? 'bi bi-check2' : `bi ${item.icon}`"></i></span><div><small>الخطوة {{ index + 1 }}</small><strong>{{ item.title }}</strong></div></button>
                    </li>
                </ol>
                <div class="safe"><i class="bi bi-shield-check"></i><span><strong>المنيو المختار محفوظ</strong><small>التنظيف يطال حركات التجربة فقط.</small></span></div>
            </aside>

            <section class="wizard" :aria-busy="form.processing">
                <div class="mobile-progress"><span>{{ steps[step].title }}</span><b>{{ step + 1 }} / {{ steps.length }}</b><i><em :style="{ width: progress }"></em></i></div>

                <section v-if="isDemo && step === 0" class="panel">
                    <header class="panel-title"><span><i class="bi bi-stars"></i></span><div><small>تسليم آمن</small><h2>احتفظ بما بنيته، واحذف ضجيج التجربة.</h2><p>فرع «{{ summary.branchName }}» سيبقى نفسه؛ لذلك لا نفقد الصور والوصفات وربط المطبخ والبار.</p></div></header>
                    <div class="handover">
                        <article class="keep"><header><i class="bi bi-shield-check"></i><div><strong>سيبقى تلقائياً</strong><small>القائمة المعتمدة والإنتاج</small></div></header><div class="metrics"><span v-for="item in kept" :key="item[1]"><b>{{ item[0] }}</b><small>{{ item[1] }}</small></span></div><ul><li>الصور والتصنيفات والأصناف</li><li>المكونات والوحدات والوصفات</li><li>محطات المطبخ والبار وهيكل المخزن</li></ul></article>
                        <article class="remove"><header><i class="bi bi-eraser"></i><div><strong>سيُنظف عند التأكيد</strong><small>كل ما لا يصلح كبداية حقيقية</small></div></header><div class="metrics"><span v-for="item in removed" :key="item[1]"><b>{{ item[0] }}</b><small>{{ item[1] }}</small></span></div><ul><li>الطلبات والفواتير والتحصيلات</li><li>العملاء والموردون والأرصدة</li><li>الحسابات والفروع والطاولات الوهمية</li></ul></article>
                    </div>
                    <div class="note"><i class="bi bi-info-circle"></i><div><strong>لن يُحذف شيء بفتح هذه الشاشة.</strong><span>يمكنك العودة للتجربة. المسح يحصل مرة واحدة فقط بعد مراجعة كل الخطوات.</span></div></div>
                </section>

                <section v-else-if="step === identityStep" class="panel">
                    <header class="panel-title"><span><i class="bi bi-shop"></i></span><div><small>هوية الزبون والفاتورة</small><h2>ضع اسم المطعم وشكله الرسمي.</h2><p>تظهر هذه البيانات في المنيو والفواتير والطباعة.</p></div></header>
                    <div class="form-grid two">
                        <label class="field"><span>اسم المطعم <b>*</b></span><input v-model="form.restaurant_name" type="text" autocomplete="organization"><em v-if="form.errors.restaurant_name">{{ form.errors.restaurant_name }}</em></label>
                        <label class="field"><span>الاسم القانوني <small>اختياري</small></span><input v-model="form.legal_name" type="text"></label>
                        <label class="field"><span>الرقم الضريبي <small>اختياري</small></span><input v-model="form.tax_number" type="text" inputmode="numeric"><em v-if="form.errors.tax_number">{{ form.errors.tax_number }}</em></label>
                        <label class="field"><span>السجل التجاري <small>اختياري</small></span><input v-model="form.commercial_registration_number" type="text"></label>
                        <label class="field"><span>رخصة البلدية / المهنة <small>اختياري</small></span><input v-model="form.municipal_license_number" type="text"></label>
                        <label class="field full"><span>عبارة أسفل الإيصال <small>اختياري</small></span><input v-model="form.receipt_footer" type="text" maxlength="500"></label>
                    </div>
                    <div class="uploads">
                        <button type="button" @click="logoInput.click()"><input ref="logoInput" hidden type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" @change="pickFile($event,'brand_logo')"><i class="bi bi-image"></i><span><strong>شعار المطعم</strong><small>{{ form.brand_logo?.name || 'PNG أو WebP أو SVG · حتى 2MB' }}</small></span><b>{{ form.brand_logo ? 'تغيير' : 'اختيار' }}</b></button>
                        <button type="button" @click="faviconInput.click()"><input ref="faviconInput" hidden type="file" accept="image/png,image/x-icon,image/jpeg,image/webp,image/svg+xml" @change="pickFile($event,'brand_favicon')"><i class="bi bi-browser-chrome"></i><span><strong>أيقونة المتصفح</strong><small>{{ form.brand_favicon?.name || 'صورة مربعة · حتى 512KB' }}</small></span><b>{{ form.brand_favicon ? 'تغيير' : 'اختيار' }}</b></button>
                    </div>
                </section>

                <section v-else-if="step === branchStep" class="panel">
                    <header class="panel-title"><span><i class="bi bi-geo-alt"></i></span><div><small>نقطة التشغيل الأولى</small><h2>عرّف الفرع الحقيقي.</h2><p>لكل فرع مخزنه وطاولاته وتشغيله. يمكن إضافة فروع أخرى لاحقاً.</p></div></header>
                    <div class="form-grid two">
                        <label class="field"><span>اسم الفرع <b>*</b></span><input v-model="form.branch_name" type="text"><em v-if="form.errors.branch_name">{{ form.errors.branch_name }}</em></label>
                        <label class="field"><span>رمز الفرع <b>*</b></span><input v-model.trim="form.branch_code" type="text" placeholder="main"><em v-if="form.errors.branch_code">{{ form.errors.branch_code }}</em></label>
                        <label class="field"><span>هاتف الفرع <small>اختياري</small></span><input v-model="form.branch_phone" type="tel"></label>
                        <label class="field"><span>المدينة <small>اختياري</small></span><input v-model="form.branch_city" type="text"></label>
                        <label class="field full"><span>العنوان <small>اختياري</small></span><textarea v-model="form.branch_address" rows="3"></textarea></label>
                    </div>
                    <div class="note"><i class="bi bi-magic"></i><div><strong>سيُجهز تلقائياً</strong><span>المخزن الرئيسي، المطبخ، البار، السنة المالية الحالية، الشيكل كعملة أساس والدولار للمشتريات.</span></div></div>
                </section>

                <section v-else-if="step === teamStep" class="panel">
                    <header class="panel-title"><span><i class="bi bi-people"></i></span><div><small>ملكية واضحة وحسابات حقيقية</small><h2>سجّل المالك ثم أنشئ حساب مدير النظام.</h2><p>الملكية قانونية ومستقلة عن تسجيل الدخول؛ قد يكون المدير هو المالك أو موظفاً مفوضاً.</p></div></header>
                    <div class="owner-box">
                        <h3><i class="bi bi-person-vcard"></i> المالك القانوني للفرع</h3>
                        <div class="form-grid two">
                            <label class="field"><span>نوع المالك <b>*</b></span><select v-model="form.business_owner_type"><option value="person">شخص</option><option value="company">شركة / جهة اعتبارية</option></select></label>
                            <label class="field"><span>{{ form.business_owner_type === 'company' ? 'اسم الشركة' : 'اسم المالك الكامل' }} <b>*</b></span><input v-model="form.business_owner_name" type="text"><em v-if="form.errors.business_owner_name">{{ form.errors.business_owner_name }}</em></label>
                            <label class="field"><span>{{ form.business_owner_type === 'company' ? 'رقم تسجيل الجهة' : 'رقم الهوية' }} <small>اختياري</small></span><input v-model="form.business_owner_national_id" type="text"></label>
                            <label class="field"><span>جوال المالك <small>اختياري</small></span><input v-model="form.business_owner_phone" type="tel" inputmode="numeric" maxlength="10" placeholder="0592632026"></label>
                            <label class="field"><span>نسبة الملكية</span><input v-model="form.business_owner_percentage" type="number" min="0.01" max="100" step="0.01" inputmode="decimal"></label>
                        </div>
                        <div class="note"><i class="bi bi-diagram-3"></i><div><strong>لن نكرر هذا المالك</strong><span>عند إضافة فرع ثانٍ ستختاره من سجل الملاك وتربطه بالفرع الجديد.</span></div></div>
                    </div>
                    <div class="owner-box admin-account">
                        <h3><i class="bi bi-shield-lock"></i> حساب مدير النظام</h3>
                        <div class="form-grid two">
                            <label class="field"><span>الاسم الحقيقي <b>*</b></span><input v-model="form.admin_name" type="text" autocomplete="name"><em v-if="form.errors.admin_name">{{ form.errors.admin_name }}</em></label>
                            <label class="field"><span>اسم الدخول <b>*</b></span><input v-model.trim="form.admin_username" type="text" autocomplete="username"><em v-if="form.errors.admin_username">{{ form.errors.admin_username }}</em></label>
                            <label class="field"><span>رقم الجوال <small>اختياري</small></span><input v-model="form.admin_phone" type="tel" inputmode="numeric" maxlength="10" placeholder="0592632026"><em v-if="form.errors.admin_phone">{{ form.errors.admin_phone }}</em></label>
                            <label class="field"><span>البريد <small>اختياري</small></span><input v-model="form.admin_email" type="email"></label>
                            <label class="field"><span>كلمة المرور <b>*</b></span><input v-model="form.admin_password" type="password" autocomplete="new-password"><em v-if="form.errors.admin_password">{{ form.errors.admin_password }}</em></label>
                            <label class="field"><span>تأكيد كلمة المرور <b>*</b></span><input v-model="form.admin_password_confirmation" type="password" autocomplete="new-password"><em v-if="form.errors.admin_password_confirmation">{{ form.errors.admin_password_confirmation }}</em></label>
                        </div>
                    </div>
                    <div class="staff-title"><div><h3>الفريق الأول</h3><p>سيُولد اسم دخول وكلمة مرور لكل موظف.</p></div><button type="button" :disabled="form.staff.length >= 12" @click="addStaff"><i class="bi bi-person-plus"></i> إضافة موظف</button></div>
                    <div v-if="form.staff.length" class="staff-list">
                        <article v-for="(member,index) in form.staff" :key="index" :data-staff="index">
                            <span class="role-icon"><i :class="`bi ${role(member.role)?.icon || 'bi-person'}`"></i></span>
                            <label class="field"><span>الاسم الحقيقي</span><input v-model="member.name" type="text"><em v-if="form.errors[`staff.${index}.name`]">{{ form.errors[`staff.${index}.name`] }}</em></label>
                            <label class="field"><span>الوظيفة</span><select v-model="member.role"><option v-for="item in roles" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
                            <label class="field"><span>الجوال <small>اختياري</small></span><input v-model="member.phone" type="tel" inputmode="numeric" maxlength="10" placeholder="0592632026"></label>
                            <button class="trash" type="button" aria-label="حذف الموظف" @click="removeStaff(index)"><i class="bi bi-trash3"></i></button>
                        </article>
                    </div>
                    <button v-else type="button" class="empty-team" @click="addStaff"><i class="bi bi-person-plus"></i><span><strong>لم تضف موظفين بعد</strong><small>ابدأ الآن أو أضفهم لاحقاً من إدارة المستخدمين.</small></span></button>
                </section>

                <section v-else class="panel">
                    <header class="panel-title"><span class="success"><i class="bi bi-check2-circle"></i></span><div><small>مراجعة أخيرة</small><h2>جاهز لتحويل النظام إلى مطعمك.</h2><p>بعد التنفيذ ستدخل إلى لوحة نظيفة بالمنيو الذي اخترته.</p></div></header>
                    <div class="review">
                        <article><i class="bi bi-shop"></i><div><small>المطعم</small><strong>{{ form.restaurant_name }}</strong><span>{{ form.legal_name || 'بدون اسم قانوني' }}</span></div><button @click="step=identityStep">تعديل</button></article>
                        <article><i class="bi bi-geo-alt"></i><div><small>الفرع</small><strong>{{ form.branch_name }}</strong><span>{{ form.branch_city || form.branch_code }}</span></div><button @click="step=branchStep">تعديل</button></article>
                        <article><i class="bi bi-person-vcard"></i><div><small>الملكية</small><strong>{{ form.business_owner_name }}</strong><span>{{ form.business_owner_percentage }}% · {{ form.business_owner_type === 'company' ? 'شركة' : 'شخص' }}</span></div><button @click="step=teamStep">تعديل</button></article>
                        <article><i class="bi bi-shield-lock"></i><div><small>مدير النظام</small><strong>{{ form.admin_name }}</strong><span>{{ form.admin_username }}</span></div><button @click="step=teamStep">تعديل</button></article>
                        <article><i class="bi bi-people"></i><div><small>الفريق</small><strong>{{ form.staff.length }} موظف</strong><span>{{ form.staff.length ? form.staff.map(m=>role(m.role)?.label).join('، ') : 'يُضاف لاحقاً' }}</span></div><button @click="step=teamStep">تعديل</button></article>
                    </div>
                    <label v-if="isDemo" class="confirm" :class="{ checked: form.confirm_reset }"><input v-model="form.confirm_reset" type="checkbox"><span><i class="bi bi-check2"></i></span><div><strong>أفهم أن بيانات التجربة التشغيلية ستُحذف نهائياً.</strong><small>سيبقى منيو «{{ summary.branchName }}» ومكوناته ووصفاته، وتبدأ باقي البيانات من الصفر.</small><em v-if="form.errors.confirm_reset">{{ form.errors.confirm_reset }}</em></div></label>
                    <div class="note"><i class="bi bi-sliders"></i><div><strong>إعدادات بداية منطقية ومرنة</strong><span>الضرائب والخدمة متوقفتان · خصم المخزون عند بدء التحضير · الشيكل أساس والدولار متاح · لا مزامنة خارجية.</span></div></div>
                </section>

                <footer class="actions"><button v-if="step>0" class="secondary" type="button" @click="previous"><i class="bi bi-arrow-right"></i> السابق</button><span v-else></span><button v-if="!finalStep" class="primary" type="button" @click="next">التالي <i class="bi bi-arrow-left"></i></button><button v-else class="primary launch" type="button" :disabled="form.processing||(isDemo&&!form.confirm_reset)" @click="submit"><i :class="form.processing?'bi bi-arrow-repeat spin':'bi bi-rocket-takeoff'"></i>{{ form.processing ? 'جاري تجهيز المطعم…' : 'اعتماد وبدء المطعم' }}</button></footer>
            </section>
        </div>
    </main>
</template>

<style scoped>
*{box-sizing:border-box}.setup-page{--g:#1f6b50;--d:#123f31;min-height:100dvh;color:#18271f;background:#f3f7f4}.topbar{position:sticky;z-index:20;top:0;display:flex;min-height:70px;align-items:center;justify-content:space-between;padding:.55rem clamp(1rem,4vw,3rem);border-bottom:1px solid #dbe5df;background:#fffffff2;backdrop-filter:blur(15px)}.brand{display:flex;align-items:center;gap:.6rem;color:inherit;text-decoration:none}.brand>span{display:grid;width:44px;height:44px;place-items:center;padding:.3rem;border:1px solid #dce7e0;border-radius:13px}.brand img{width:100%;height:100%;object-fit:contain}.brand div,.safe span{display:grid}.brand small{color:#75847b;font-size:.62rem}.brand strong{font-size:.88rem}.back-demo{display:inline-flex;min-height:44px;align-items:center;gap:.35rem;padding:0 .8rem;border:1px solid #d6e1da;border-radius:11px;color:#315c49;background:#fff;font-size:.68rem;font-weight:800;text-decoration:none}.setup-shell{display:grid;width:min(1220px,calc(100% - 2rem));min-height:calc(100dvh - 102px);margin:1rem auto;grid-template-columns:300px 1fr;overflow:hidden;border:1px solid #d8e3dc;border-radius:23px;background:#fff;box-shadow:0 28px 70px -48px #173d2d}.stepper{display:flex;flex-direction:column;padding:1.35rem;color:#fff;background:linear-gradient(155deg,#236f54,#123f31)}.mode{display:inline-flex;align-self:start;align-items:center;gap:.35rem;padding:.35rem .55rem;border:1px solid #ffffff2b;border-radius:999px;color:#ffe2ae;background:#ffffff13;font-size:.61rem;font-weight:800}.stepper h1{margin:1rem 0 .3rem;font-size:1.4rem}.stepper>p{margin:0;color:#ffffffa8;font-size:.68rem;line-height:1.8}.stepper ol{display:grid;gap:.2rem;margin:1.15rem 0;padding:0;list-style:none}.stepper button{display:flex;width:100%;min-height:56px;align-items:center;gap:.6rem;padding:.4rem;border:0;border-radius:12px;color:#ffffff9e;background:transparent;text-align:start}.stepper li.active button{color:#fff;background:#ffffff18}.stepper li.done button{color:#d9f4e5;cursor:pointer}.stepper button>span{display:grid;width:34px;height:34px;place-items:center;border:1px solid #ffffff27;border-radius:10px;background:#ffffff0f}.stepper li.active button>span,.stepper li.done button>span{color:var(--d);background:#e5f5ec}.stepper button div{display:grid}.stepper button small{font-size:.53rem;opacity:.68}.stepper button strong{font-size:.7rem}.safe{display:flex;gap:.55rem;margin-top:auto;padding:.7rem;border:1px solid #ffffff20;border-radius:13px;background:#0000001c}.safe>i{color:#f4cc88}.safe strong{font-size:.65rem}.safe small{color:#ffffff9e;font-size:.55rem}.wizard{display:flex;min-width:0;flex-direction:column;padding:clamp(1.1rem,3vw,2.1rem)}.mobile-progress{display:none}.panel{animation:in .2s ease}.panel-title{display:flex;gap:.75rem;padding-bottom:1.05rem;border-bottom:1px solid #e5ece8}.panel-title>span{display:grid;width:46px;height:46px;flex:0 0 auto;place-items:center;border-radius:13px;color:var(--g);background:#eaf5ef;font-size:1.1rem}.panel-title>span.success{color:#fff;background:var(--g)}.panel-title>div{min-width:0}.panel-title small{color:var(--g);font-size:.61rem;font-weight:850}.panel-title h2{margin:.18rem 0 .25rem;font-size:clamp(1.2rem,2.3vw,1.6rem)}.panel-title p{margin:0;color:#738179;font-size:.7rem;line-height:1.75}.handover{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:1rem}.handover>article{padding:.9rem;border:1px solid;border-radius:15px}.keep{border-color:#c6e4d2!important;background:#f3fbf6}.remove{border-color:#efd7b4!important;background:#fff9ef}.handover article>header{display:flex;align-items:center;gap:.5rem}.handover header>i{display:grid;width:36px;height:36px;place-items:center;border-radius:10px;background:#fff}.handover header div{display:grid}.handover header strong{font-size:.75rem}.handover header small{color:#75847b;font-size:.55rem}.metrics{display:grid;margin:.75rem 0;grid-template-columns:1fr 1fr;gap:.35rem}.metrics span{display:grid;padding:.5rem;border-radius:9px;background:#ffffffbd}.metrics b{font-size:1rem}.metrics small{color:#77837c;font-size:.54rem}.handover ul{margin:0;padding-inline-start:1rem;color:#506058;font-size:.62rem;line-height:1.9}.note{display:flex;gap:.6rem;margin-top:.9rem;padding:.75rem;border:1px solid #d9e5de;border-radius:12px;color:#315a47;background:#f7faf8}.note div{display:grid}.note strong{font-size:.68rem}.note span{margin-top:.12rem;color:#718078;font-size:.59rem;line-height:1.7}.form-grid{display:grid;gap:.75rem;margin-top:.95rem}.form-grid.two{grid-template-columns:1fr 1fr}.field{display:grid;align-content:start;gap:.3rem;min-width:0}.field.full{grid-column:1/-1}.field>span{font-size:.64rem;font-weight:850}.field>span b{color:#b23b3b}.field>span small{color:#8a9790;font-size:.53rem}.field input,.field select,.field textarea{width:100%;min-height:44px;padding:.6rem .7rem;border:1px solid #d5e0da;border-radius:10px;outline:0;color:#1e3026;background:#fff;font:inherit;font-size:.71rem}.field input:focus,.field select:focus,.field textarea:focus{border-color:#5c9b80;box-shadow:0 0 0 3px #e3f1ea}.field em,.confirm em{color:#b8323c;font-size:.55rem;font-style:normal}.uploads{display:grid;grid-template-columns:1fr 1fr;gap:.65rem;margin-top:.9rem}.uploads>button{display:flex;min-height:82px;align-items:center;gap:.6rem;padding:.7rem;border:1px dashed #b9cec2;border-radius:13px;color:inherit;background:#f9fbfa;text-align:start}.uploads button>i,.role-icon{display:grid;width:40px;height:40px;place-items:center;border-radius:11px;color:var(--g);background:#e8f3ed}.uploads button>span{display:grid;min-width:0;flex:1}.uploads strong{font-size:.68rem}.uploads small{overflow:hidden;color:#7d8982;font-size:.54rem;text-overflow:ellipsis;white-space:nowrap}.uploads button>b{color:var(--g);font-size:.58rem}.owner-box{margin-top:.9rem;padding:.85rem;border:1px solid #dbe6df;border-radius:14px;background:#fafcfb}.owner-box h3,.staff-title h3{margin:0;color:#254c39;font-size:.75rem}.staff-title{display:flex;align-items:center;justify-content:space-between;margin:.9rem 0 .55rem}.staff-title p{margin:.1rem 0;color:#7b8881;font-size:.56rem}.staff-title button{min-height:42px;padding:0 .7rem;border:1px solid #c9ded2;border-radius:10px;color:var(--g);background:#eef7f2;font:inherit;font-size:.62rem;font-weight:800}.staff-list{display:grid;gap:.5rem}.staff-list article{display:grid;grid-template-columns:40px 1.15fr .8fr 1fr 40px;align-items:start;gap:.5rem;padding:.65rem;border:1px solid #dce6e0;border-radius:13px}.trash{display:grid;width:40px;height:40px;place-items:center;border:1px solid #f0d5d5;border-radius:10px;color:#b83f45;background:#fff4f4}.empty-team{display:flex;width:100%;min-height:78px;align-items:center;justify-content:center;gap:.6rem;border:1px dashed #c5d7cd;border-radius:13px;color:#4a6657;background:#fafcfb}.empty-team span{display:grid;text-align:start}.empty-team strong{font-size:.66rem}.empty-team small{color:#839087;font-size:.55rem}.review{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.9rem}.review article{display:grid;grid-template-columns:40px 1fr auto;align-items:center;gap:.55rem;padding:.7rem;border:1px solid #dce6e0;border-radius:13px}.review article>i{display:grid;width:40px;height:40px;place-items:center;border-radius:10px;color:var(--g);background:#eaf5ef}.review article div{display:grid;min-width:0}.review small{color:#829087;font-size:.52rem}.review strong,.review span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.review strong{font-size:.69rem}.review span{color:#75827b;font-size:.55rem}.review button{min-height:36px;border:0;border-radius:8px;color:var(--g);background:#eef6f2;font:inherit;font-size:.56rem;font-weight:800}.confirm{display:flex;gap:.65rem;margin-top:.9rem;padding:.8rem;border:1px solid #e7c590;border-radius:13px;background:#fff9ef}.confirm input{position:absolute;opacity:0}.confirm>span{display:grid;width:27px;height:27px;flex:0 0 auto;place-items:center;border:1px solid #d2ad72;border-radius:8px;color:transparent;background:#fff}.confirm.checked>span{border-color:var(--g);color:#fff;background:var(--g)}.confirm div{display:grid}.confirm strong{font-size:.67rem}.confirm small{color:#746e62;font-size:.57rem;line-height:1.65}.actions{display:flex;justify-content:space-between;gap:.6rem;margin-top:auto;padding-top:1.15rem}.actions button{display:inline-flex;min-height:46px;align-items:center;justify-content:center;gap:.35rem;padding:0 .95rem;border-radius:11px;font:inherit;font-size:.68rem;font-weight:850}.secondary{border:1px solid #d3dfd8;color:#53645a;background:#fff}.primary{min-width:130px;border:1px solid var(--g);color:#fff;background:var(--g)}.launch{min-width:200px}.actions button:disabled{opacity:.5}.spin{animation:spin .8s linear infinite}@keyframes in{from{transform:translateY(5px);opacity:.4}}@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:930px){.setup-shell{grid-template-columns:235px 1fr}.staff-list article{grid-template-columns:40px 1fr 1fr}.staff-list article .field:nth-of-type(3){grid-column:2/-1}.trash{grid-row:2}}
@media(max-width:730px){.setup-shell{display:block;width:100%;min-height:calc(100dvh - 64px);margin:0;border:0;border-radius:0}.topbar{min-height:64px}.stepper{display:none}.wizard{min-height:calc(100dvh - 64px);padding:1rem}.mobile-progress{display:grid;grid-template-columns:1fr auto;gap:.35rem;margin-bottom:.9rem;color:#496255;font-size:.64rem;font-weight:800}.mobile-progress>i{height:4px;grid-column:1/-1;overflow:hidden;border-radius:99px;background:#e0e9e4}.mobile-progress em{display:block;height:100%;background:var(--g)}.handover,.form-grid.two,.uploads,.review{grid-template-columns:1fr}.staff-list article{grid-template-columns:38px 1fr 40px}.staff-list article .field{grid-column:2}.trash{grid-column:3;grid-row:1}.back-demo i{display:none}}
@media(max-width:420px){.topbar{padding:.45rem .7rem}.brand small{display:none}.wizard{padding:.8rem}.panel-title h2{font-size:1.1rem}.actions{position:sticky;z-index:5;bottom:0;margin:1rem -.8rem -.8rem;padding:.65rem .8rem;border-top:1px solid #dde6e1;background:#fffffff2}.launch{min-width:0;flex:1}}
</style>
