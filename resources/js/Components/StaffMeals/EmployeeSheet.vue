<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import DialogSurface from '../Ui/DialogSurface.vue';
import { useFormUx } from '../../Composables/useFormUx';

const props = defineProps({
    open: { type: Boolean, default: false },
    storeUrl: { type: String, required: true },
    options: { type: Object, default: () => ({ branches: [], users: [], canLinkLogin: false }) },
});
const emit = defineEmits(['close']);

const form = useForm({
    name: '', phone: '', job_title: '', monthly_meal_allowance: '',
    meal_debt_ceiling: '', user_id: '', branch_ids: [],
});
const sheetRoot = ref(null);
const guardOpen = computed(() => props.open);
const { confirmDiscard, focusFirstError, markSaved } = useFormUx(form, {
    root: sheetRoot,
    guard: guardOpen,
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    if (!form.branch_ids.length && props.options.branches?.length === 1) {
        form.branch_ids = [props.options.branches[0].id];
    }
    nextTick(markSaved);
});

async function requestClose() {
    if (form.processing || ! await confirmDiscard()) return;
    form.reset();
    form.clearErrors();
    markSaved();
    emit('close');
}

function submit() {
    form.post(props.storeUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            markSaved();
            emit('close');
        },
        onError: focusFirstError,
    });
}
</script>

<template>
    <DialogSurface
        :open="open"
        variant="sheet-start"
        title-id="employee-sheet-title"
        max-width="520px"
        initial-focus="[autofocus]"
        @close="requestClose"
    >
                <form ref="sheetRoot" class="sheet" @submit.prevent="submit">
                    <header>
                        <div><small>سجل تشغيلي مستقل</small><h2 id="employee-sheet-title">إضافة موظف</h2></div>
                        <button type="button" aria-label="إغلاق" @click="requestClose"><i class="bi bi-x-lg"></i></button>
                    </header>

                    <div class="explain">
                        <i class="bi bi-person-check"></i>
                        <p><strong>لا يلزم حساب دخول.</strong><span>أنشئ العامل هنا للوجبات والسجل المالي، واربط مستخدماً فقط إذا كان سيستعمل النظام.</span></p>
                    </div>

                    <div class="fields">
                        <label class="wide"><span>اسم الموظف *</span><input v-model.trim="form.name" name="name" class="form-control" required maxlength="191" autofocus><small v-if="form.errors.name">{{ form.errors.name }}</small></label>
                        <label><span>المسمى الوظيفي</span><input v-model.trim="form.job_title" name="job_title" class="form-control" maxlength="120" placeholder="مثال: عامل مطبخ"></label>
                        <label><span>رقم الجوال (اختياري)</span><input v-model.trim="form.phone" name="phone" class="form-control" inputmode="numeric" maxlength="10" placeholder="0592632026"><small v-if="form.errors.phone">{{ form.errors.phone }}</small></label>
                        <label><span>بدل الوجبات الشهري *</span><input v-model="form.monthly_meal_allowance" name="monthly_meal_allowance" class="form-control" type="number" min="0" max="99999" step="0.01" required><small v-if="form.errors.monthly_meal_allowance">{{ form.errors.monthly_meal_allowance }}</small></label>
                        <label><span>أقصى دين مسموح</span><input v-model="form.meal_debt_ceiling" name="meal_debt_ceiling" class="form-control" type="number" min="0" max="999999" step="0.01" placeholder="فارغ = بلا سقف إضافي"><small v-if="form.errors.meal_debt_ceiling">{{ form.errors.meal_debt_ceiling }}</small></label>
                    </div>

                    <fieldset>
                        <legend>يعمل في *</legend>
                        <label v-for="branch in options.branches" :key="branch.id" class="choice"><input v-model="form.branch_ids" name="branch_ids" type="checkbox" :value="branch.id"><span>{{ branch.name }}</span></label>
                        <small v-if="form.errors.branch_ids">{{ form.errors.branch_ids }}</small>
                    </fieldset>

                    <details v-if="options.canLinkLogin && options.users?.length" class="login-link">
                        <summary><span><i class="bi bi-key"></i><b>ربط حساب دخول موجود</b><small>اختياري</small></span><i class="bi bi-chevron-down"></i></summary>
                        <label><span>حساب المستخدم</span><select v-model="form.user_id" class="form-select"><option value="">بدون حساب دخول</option><option v-for="user in options.users" :key="user.id" :value="user.id">{{ user.name }}</option></select></label>
                    </details>

                    <footer><button type="button" class="btn btn-light" @click="requestClose">تراجع</button><button class="btn btn-primary" :disabled="form.processing"><i class="bi bi-check2"></i>{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ الموظف' }}</button></footer>
                </form>
    </DialogSurface>
</template>

<style scoped>
.sheet-backdrop{position:fixed;inset:0;z-index:2300;display:flex;justify-content:flex-start;background:rgba(9,28,18,.52);backdrop-filter:blur(2px)}.sheet{width:min(520px,100%);height:100%;overflow-y:auto;background:#fff;box-shadow:18px 0 70px rgba(0,0,0,.22);padding:1rem;display:flex;flex-direction:column;gap:.85rem}.sheet header{display:flex;align-items:center;gap:.75rem;padding-bottom:.8rem;border-bottom:1px solid #e5ece8}.sheet header>div{flex:1}.sheet h2,.sheet small{margin:0}.sheet h2{font-size:1.1rem}.sheet header small{font-size:.68rem;color:#718078}.sheet header button{width:44px;height:44px;border:1px solid #dce5df;border-radius:11px;background:#fff}.explain{display:flex;gap:.7rem;padding:.75rem;border:1px solid color-mix(in srgb,rgb(var(--primary-rgb)) 22%,#fff);border-radius:12px;background:color-mix(in srgb,rgb(var(--primary-rgb)) 6%,#fff)}.explain>i{width:38px;height:38px;display:grid;place-items:center;border-radius:10px;color:rgb(var(--primary-rgb));background:#fff}.explain p{display:grid;gap:.12rem;margin:0}.explain strong{font-size:.78rem}.explain span{font-size:.68rem;color:#66766d;line-height:1.6}.fields{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}.fields label,.login-link label{display:grid;gap:.3rem}.fields .wide{grid-column:1/-1}.fields label>span,.login-link label>span,legend{font-size:.7rem;font-weight:850}.fields small,fieldset>small{color:#b42318;font-size:.62rem}.form-control,.form-select{min-height:44px;border:1px solid #d8e2dc;border-radius:10px;padding:.55rem .7rem;background:#fff}fieldset{display:flex;flex-wrap:wrap;gap:.4rem;margin:0;padding:.7rem;border:1px solid #dfe7e2;border-radius:12px}legend{padding-inline:.3rem}.choice{display:flex;align-items:center;gap:.35rem;min-height:44px;padding:.4rem .65rem;border-radius:9px;background:#f4f8f5;font-size:.7rem}.login-link{border:1px solid #dfe7e2;border-radius:12px;overflow:hidden}.login-link summary{display:flex;align-items:center;justify-content:space-between;min-height:48px;padding:.6rem .75rem;cursor:pointer}.login-link summary>span{display:flex;align-items:center;gap:.45rem}.login-link summary small{padding:.12rem .35rem;border-radius:99px;background:#eef4f0}.login-link label{padding:.7rem;border-top:1px solid #edf1ef}.sheet footer{position:sticky;bottom:-1rem;display:flex;justify-content:flex-end;gap:.5rem;margin-top:auto;padding:1rem 0;background:#fff}.sheet footer .btn{min-height:46px}.employee-sheet-enter-active,.employee-sheet-leave-active{transition:.2s}.employee-sheet-enter-from,.employee-sheet-leave-to{opacity:0}.employee-sheet-enter-from .sheet,.employee-sheet-leave-to .sheet{transform:translateX(-24px)}@media(max-width:560px){.fields{grid-template-columns:1fr}.fields .wide{grid-column:auto}.sheet{padding:.8rem}.sheet-backdrop{align-items:flex-end}.sheet{height:min(94dvh,820px);border-radius:18px 18px 0 0}}
</style>
