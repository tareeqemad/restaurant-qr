<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useConfirm } from '../../Composables/useConfirm';

const props = defineProps({
    open: { type: Boolean, default: false },
    record: { type: Object, default: null },
    catalogue: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);
const { ask } = useConfirm();
const firstInput = ref(null);
const showPassword = ref(false);
const editing = computed(() => Boolean(props.record?.id));
const title = computed(() => editing.value ? `تعديل ${props.record.name}` : 'إضافة مستخدم');
const roleMeta = {
    super_admin: ['bi-shield-lock-fill', 'إدارة تقنية كاملة'], partner: ['bi-briefcase-fill', 'مالك كل الفروع'],
    admin: ['bi-person-gear', 'إدارة عامة'], manager: ['bi-diagram-3-fill', 'إدارة الفروع'],
    accountant: ['bi-calculator-fill', 'الحسابات والتقارير'], cashier: ['bi-cash-stack', 'الفواتير والتحصيل'],
    waiter: ['bi-person-raised-hand', 'خدمة الصالة'], chef: ['bi-fire', 'محطة المطبخ'], bartender: ['bi-cup-straw', 'محطة البار'],
};

const blank = () => ({
    _inline: true, name: '', username: '', email: '', phone: '',
    role: props.catalogue.roles?.find((role) => role.value === 'waiter')?.value ?? props.catalogue.roles?.[0]?.value ?? '',
    station_id: '', status: 'active', password: '', password_confirmation: '',
    branches: props.catalogue.defaultBranchId ? [props.catalogue.defaultBranchId] : [],
    primary_branch_id: props.catalogue.defaultBranchId ?? null,
});
const form = useForm(blank());
const availableRoles = computed(() => {
    const roles = [...(props.catalogue.roles ?? [])];
    if (props.record?.role && !roles.some((role) => role.value === props.record.role)) roles.unshift({ value: props.record.role, label: props.record.roleLabel });
    return roles;
});
const isOwnerRole = computed(() => (props.catalogue.ownerRoles ?? []).includes(form.role));
const needsStation = computed(() => ['chef', 'bartender'].includes(form.role));
const lockedBranchIds = computed(() => props.record?.lockedBranchIds ?? []);
const lockedPrimaryBranch = computed(() => props.catalogue.branches?.find((branch) => branch.id === props.record?.lockedPrimaryBranchId));
const branchError = computed(() => form.errors.branches ?? Object.entries(form.errors).find(([key]) => key.startsWith('branches.'))?.[1]);
const selectedRole = computed(() => availableRoles.value.find((role) => role.value === form.role));
const canSubmit = computed(() => !form.processing && (editing.value ? props.record?.canUpdate : props.catalogue.canCreate) && (isOwnerRole.value || form.branches.length > 0));

function payloadFor(record) {
    if (!record) return blank();
    return {
        _inline: true, name: record.name ?? '', username: record.username ?? '',
        email: record.email ?? '', phone: record.phone ?? '', role: record.role ?? '', station_id: record.stationId ?? '',
        status: record.status ?? 'active', password: '', password_confirmation: '',
        branches: [...(record.branchIds ?? [])], primary_branch_id: record.primaryBranchId ?? null,
    };
}
function hydrate() {
    const values = payloadFor(props.record);
    form.defaults(values); form.reset(); form.clearErrors(); showPassword.value = false;
    nextTick(() => firstInput.value?.focus({ preventScroll: true }));
}
watch(() => props.open, (open) => {
    document.body.classList.toggle('user-sheet-open', open);
    if (open) hydrate();
});
watch(() => form.role, () => { if (!needsStation.value) form.station_id = ''; });

async function requestClose() {
    if (form.processing) return;
    if (form.isDirty && !await ask({ title: 'إغلاق دون حفظ؟', message: 'التعديلات المكتوبة على حساب الموظف ستفقد.', confirmLabel: 'إغلاق', danger: true })) return;
    emit('close');
}
function toggleBranch(branch) {
    if (!branch.accessible) return;
    const index = form.branches.indexOf(branch.id);
    if (index === -1) {
        form.branches.push(branch.id);
        if (!form.primary_branch_id && !props.record?.lockedPrimaryBranchId) form.primary_branch_id = branch.id;
    } else {
        form.branches.splice(index, 1);
        if (form.primary_branch_id === branch.id) form.primary_branch_id = form.branches[0] ?? null;
    }
    form.clearErrors('branches', 'primary_branch_id');
}
function submit() {
    if (!canSubmit.value) return;
    const options = {
        preserveScroll: true, preserveState: true,
        onSuccess: () => {
            form.defaults({ ...form.data(), password: '', password_confirmation: '' });
            emit('saved'); emit('close');
        },
    };
    editing.value ? form.put(props.record.urls.submit, options) : form.post(props.catalogue.urls.create, options);
}
function onKeydown(event) { if (event.key === 'Escape' && props.open) requestClose(); }
onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => { document.removeEventListener('keydown', onKeydown); document.body.classList.remove('user-sheet-open'); });
</script>

<template>
    <Teleport to="body"><Transition name="user-sheet">
        <div v-if="open" class="user-sheet-backdrop" @click.self="requestClose">
            <aside class="user-sheet" role="dialog" aria-modal="true" :aria-label="title">
                <header class="sheet-head">
                    <div class="sheet-title"><span><i class="bi" :class="editing ? 'bi-person-gear' : 'bi-person-plus-fill'"></i></span><div><small>{{ editing ? 'بيانات الموظف' : 'حساب جديد' }}</small><h2>{{ title }}</h2><p>الحساب، عمله وفروعه دون مغادرة الشاشة.</p></div></div>
                    <button type="button" aria-label="إغلاق محرر المستخدم" @click="requestClose"><i class="bi bi-x-lg"></i></button>
                </header>
                <form class="sheet-form" @submit.prevent="submit">
                    <div v-if="record?.guard?.message" class="guard-note"><i class="bi bi-shield-lock-fill"></i><span><strong>حماية الحساب</strong><small>{{ record.guard.message }}</small></span></div>
                    <section>
                        <header><span>1</span><div><h3>بيانات الدخول</h3><p>المعلومات التي يتعرف بها النظام على الموظف.</p></div></header>
                        <div class="fields">
                            <label class="wide"><span>الاسم الكامل *</span><input ref="firstInput" v-model="form.name" required class="form-control" placeholder="مثال: أحمد محمد"><small v-if="form.errors.name">{{ form.errors.name }}</small></label>
                            <label><span>اسم المستخدم *</span><input v-model="form.username" required class="form-control" autocomplete="username"><small v-if="form.errors.username">{{ form.errors.username }}</small></label>
                            <label><span>رقم الجوال <b>اختياري</b></span><input v-model="form.phone" class="form-control" inputmode="numeric" maxlength="10" pattern="0[0-9]{9}" placeholder="0592632026"><small v-if="form.errors.phone">{{ form.errors.phone }}</small></label>
                            <label><span>البريد الإلكتروني</span><input v-model="form.email" class="form-control" type="email"><small v-if="form.errors.email">{{ form.errors.email }}</small></label>
                        </div>
                    </section>
                    <section>
                        <header><span>2</span><div><h3>الدور ومكان العمل</h3><p>الدور يمنح صلاحيات الوظيفة المعتادة تلقائياً.</p></div></header>
                        <div class="roles" role="radiogroup" aria-label="دور المستخدم">
                            <button v-for="role in availableRoles" :key="role.value" type="button" role="radio" :aria-checked="form.role === role.value" :disabled="record?.guard?.lockRole" :class="{ selected: form.role === role.value }" @click="form.role = role.value"><i class="bi" :class="roleMeta[role.value]?.[0] ?? 'bi-person'"></i><span><strong>{{ role.label }}</strong><small>{{ roleMeta[role.value]?.[1] }}</small></span><i class="bi" :class="form.role === role.value ? 'bi-check-circle-fill' : 'bi-circle'"></i></button>
                        </div>
                        <small v-if="form.errors.role" class="section-error">{{ form.errors.role }}</small>
                        <label v-if="needsStation" class="station"><span><strong>محطة العمل</strong><small>الشاشة التي يستقبل عليها الطلبات.</small></span><select v-model="form.station_id" class="form-select"><option value="">اختر المحطة</option><option v-for="station in catalogue.stations" :key="station.id" :value="station.id">{{ station.name }}</option></select><small v-if="form.errors.station_id">{{ form.errors.station_id }}</small></label>
                    </section>
                    <section>
                        <header class="split"><span>3</span><div><h3>فروع العمل</h3><p>{{ isOwnerRole ? 'اختيار الفرع اختياري لدور الملكية.' : 'اختر فرعاً واحداً على الأقل وحدد الأساسي.' }}</p></div><Link v-if="catalogue.urls.createBranch" :href="catalogue.urls.createBranch"><i class="bi bi-plus-lg"></i> فرع جديد</Link></header>
                        <div v-if="lockedPrimaryBranch" class="locked-primary"><i class="bi bi-lock-fill"></i> الفرع الأساسي {{ lockedPrimaryBranch.name }} خارج نطاق إدارتك وسيبقى محفوظاً.</div>
                        <div class="branches">
                            <article v-for="branch in catalogue.branches" :key="branch.id" :class="{ selected: form.branches.includes(branch.id) || lockedBranchIds.includes(branch.id), locked: !branch.accessible }">
                                <button type="button" :disabled="!branch.accessible" @click="toggleBranch(branch)"><i class="bi" :class="form.branches.includes(branch.id) || lockedBranchIds.includes(branch.id) ? 'bi-check-circle-fill' : 'bi-circle'"></i><span><strong>{{ branch.name }}</strong><small>{{ branch.code }}<template v-if="branch.city"> · {{ branch.city }}</template></small></span><i v-if="!branch.accessible" class="bi bi-lock-fill"></i></button>
                                <button v-if="branch.accessible && form.branches.includes(branch.id)" type="button" class="primary" :disabled="Boolean(record?.lockedPrimaryBranchId)" :class="{ active: form.primary_branch_id === branch.id }" @click="form.primary_branch_id = branch.id"><i class="bi" :class="form.primary_branch_id === branch.id ? 'bi-star-fill' : 'bi-star'"></i>{{ form.primary_branch_id === branch.id ? 'الفرع الأساسي' : 'اجعله الأساسي' }}</button>
                            </article>
                        </div>
                        <small v-if="branchError" class="section-error">{{ branchError }}</small>
                    </section>
                    <section>
                        <header><span>4</span><div><h3>الدخول والحالة</h3><p>{{ editing ? 'اترك كلمة المرور فارغة للاحتفاظ بها.' : 'أنشئ كلمة المرور الأولى للموظف.' }}</p></div></header>
                        <div class="fields"><label><span>كلمة المرور {{ editing ? '' : '*' }}</span><span class="password"><input v-model="form.password" class="form-control" :type="showPassword ? 'text' : 'password'" :required="!editing" autocomplete="new-password"><button type="button" :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'" @click="showPassword = !showPassword"><i class="bi" :class="showPassword ? 'bi-eye-slash' : 'bi-eye'"></i></button></span><small v-if="form.errors.password">{{ form.errors.password }}</small></label><label><span>تأكيد كلمة المرور</span><input v-model="form.password_confirmation" class="form-control" :type="showPassword ? 'text' : 'password'" :required="!editing"></label></div>
                        <div class="statuses"><button v-for="status in [{value:'active',label:'فعّال',icon:'bi-check-circle-fill'},{value:'inactive',label:'غير فعّال',icon:'bi-pause-circle'},{value:'suspended',label:'موقوف',icon:'bi-slash-circle'}]" :key="status.value" type="button" :disabled="record?.guard?.lockStatus" :class="{ selected: form.status === status.value }" @click="form.status = status.value"><i class="bi" :class="status.icon"></i>{{ status.label }}</button></div>
                        <small v-if="form.errors.status" class="section-error">{{ form.errors.status }}</small>
                    </section>
                </form>
                <footer class="sheet-actions"><div><strong>{{ form.name || 'مستخدم جديد' }}</strong><small>{{ selectedRole?.label || 'اختر الدور' }} · {{ form.branches.length }} فرع</small></div><button type="button" class="cancel" @click="requestClose">إلغاء</button><button type="button" class="save" :disabled="!canSubmit" @click="submit"><i class="bi bi-check2-circle"></i>{{ form.processing ? 'جارٍ الحفظ…' : editing ? 'حفظ التعديلات' : 'إضافة المستخدم' }}</button></footer>
            </aside>
        </div>
    </Transition></Teleport>
</template>

<style scoped>
body.user-sheet-open{overflow:hidden;overscroll-behavior:none}.user-sheet-backdrop{position:fixed;inset:0;z-index:18000;display:flex;justify-content:flex-start;background:rgba(8,25,16,.52);backdrop-filter:blur(3px)}.user-sheet{display:flex;width:min(760px,calc(100vw - 24px));height:100dvh;flex-direction:column;overflow:hidden;background:#f7faf8;box-shadow:22px 0 65px rgba(5,24,14,.25)}.sheet-head{display:flex;min-height:78px;flex:0 0 auto;align-items:center;justify-content:space-between;gap:12px;padding:12px 15px;border-bottom:1px solid #dce7e0;background:#fff}.sheet-title{display:flex;min-width:0;align-items:center;gap:10px}.sheet-title>span{display:grid;width:44px;height:44px;flex:0 0 44px;place-items:center;border-radius:13px;color:#fff;background:#176f3a}.sheet-title>div{display:grid;min-width:0}.sheet-title small{color:#819087;font-size:.58rem;font-weight:800}.sheet-title h2{overflow:hidden;margin:0;color:#193023;font-size:.95rem;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.sheet-title p{margin:1px 0 0;color:#7c8b82;font-size:.58rem}.sheet-head>button{display:grid;width:44px;height:44px;place-items:center;border:1px solid #dce6df;border-radius:12px;background:#fff;color:#617168}.sheet-form{display:grid;gap:9px;min-height:0;flex:1;overflow-y:auto;padding:10px;overscroll-behavior:contain}.sheet-form>section,.allowance{padding:13px;border:1px solid #dfe8e2;border-radius:15px;background:#fff}.sheet-form section>header{display:grid;grid-template-columns:30px minmax(0,1fr);align-items:start;gap:9px;margin-bottom:11px}.sheet-form section>header.split{grid-template-columns:30px minmax(0,1fr) auto}.sheet-form section>header>span{display:grid;width:30px;height:30px;place-items:center;border-radius:9px;color:#176f3a;background:#e9f4ec;font-size:.67rem;font-weight:900}.sheet-form h3{margin:0;color:#1d3325;font-size:.75rem;font-weight:900}.sheet-form header p{margin:2px 0 0;color:#809087;font-size:.56rem}.sheet-form header>a{display:inline-flex;min-height:34px;align-items:center;gap:4px;color:#176b39;font-size:.56rem;font-weight:850;text-decoration:none}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.fields label{display:grid;gap:4px}.fields label.wide{grid-column:1/-1}.fields label>span:first-child{color:#3a4d41;font-size:.59rem;font-weight:850}.fields label b{color:#8a978f;font-size:.5rem}.fields input,.station select{min-height:42px;border-color:#dce6df;border-radius:10px;font-size:.67rem}.fields label>small,.station>small,.section-error{color:#b42318;font-size:.55rem}.roles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.roles button{display:grid;grid-template-columns:32px minmax(0,1fr) auto;align-items:center;gap:7px;min-height:54px;padding:7px;border:1px solid #e0e8e2;border-radius:11px;background:#fbfcfb;color:#68786e;text-align:start}.roles button>i:first-child{display:grid;width:31px;height:31px;place-items:center;border-radius:8px;background:#edf3ef}.roles button span{display:grid;min-width:0}.roles strong{overflow:hidden;color:#2a3e31;font-size:.6rem;text-overflow:ellipsis;white-space:nowrap}.roles small{color:#87948c;font-size:.49rem}.roles button.selected{border-color:#8fbd9d;color:#176f3a;background:#eef8f1}.roles button:disabled{cursor:not-allowed;opacity:.65}.station{display:grid;grid-template-columns:minmax(0,1fr) 220px;align-items:center;gap:8px;margin-top:9px;padding:9px;border-radius:11px;background:#f7faf8}.station>span{display:grid}.station strong{font-size:.61rem}.station span small{color:#829087;font-size:.51rem}.branches{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.branches article{overflow:hidden;border:1px solid #e1e8e3;border-radius:11px;background:#fbfcfb}.branches article.selected{border-color:#91bda0;background:#f0f8f2}.branches article.locked{opacity:.62}.branches article>button:first-child{display:flex;width:100%;min-height:48px;align-items:center;gap:7px;padding:8px;border:0;background:transparent;color:#66776d;text-align:start}.branches article>button span{display:grid;flex:1;min-width:0}.branches article strong{overflow:hidden;color:#2a3e31;font-size:.61rem;text-overflow:ellipsis;white-space:nowrap}.branches article small{color:#829087;font-size:.5rem}.branches .primary{width:100%;min-height:31px;border:0;border-top:1px solid #dfe9e2;background:#fff;color:#708077;font-size:.52rem;font-weight:800}.branches .primary.active{color:#92620c;background:#fff8e8}.locked-primary{display:flex;align-items:center;gap:5px;margin-bottom:7px;padding:7px 9px;border-radius:9px;color:#78550e;background:#fff5df;font-size:.53rem}.statuses{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin-top:9px}.statuses button{min-height:40px;border:1px solid #e0e7e2;border-radius:10px;background:#fafcfb;color:#718078;font-size:.58rem;font-weight:800}.statuses button.selected{border-color:#8dbb9b;color:#176f3a;background:#edf7f0}.statuses button:last-child.selected{border-color:#e2aaa6;color:#a9343d;background:#fff1f1}.password{position:relative}.password input{width:100%;padding-inline-end:42px}.password button{position:absolute;inset-block:3px;inset-inline-end:3px;width:35px;border:0;border-radius:8px;background:transparent;color:#67776e}.allowance{padding:0!important}.allowance summary{display:flex;min-height:54px;align-items:center;justify-content:space-between;padding:10px 13px;cursor:pointer;list-style:none}.allowance summary::-webkit-details-marker{display:none}.allowance summary>span{display:grid;grid-template-columns:auto 1fr;column-gap:8px;align-items:center}.allowance summary span>i{grid-row:1/3;color:#90620d}.allowance summary strong{font-size:.62rem}.allowance summary small{color:#89958e;font-size:.51rem}.allowance[open] summary>.bi{transform:rotate(180deg)}.allowance>.fields{padding:10px 13px 13px;border-top:1px solid #edf1ee}.guard-note{display:flex;align-items:center;gap:8px;padding:9px 11px;border:1px solid #ead19a;border-radius:12px;color:#7e560d;background:#fff8e9}.guard-note>span{display:grid}.guard-note strong{font-size:.61rem}.guard-note small{font-size:.52rem}.sheet-actions{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:7px;padding:10px 13px max(10px,env(safe-area-inset-bottom));border-top:1px solid #dce7e0;background:rgba(255,255,255,.97);box-shadow:0 -8px 24px rgba(16,48,29,.07)}.sheet-actions>div{display:grid;min-width:0}.sheet-actions strong{overflow:hidden;color:#253b2d;font-size:.64rem;text-overflow:ellipsis;white-space:nowrap}.sheet-actions small{color:#849188;font-size:.52rem}.sheet-actions button{min-height:43px;padding-inline:14px;border:1px solid #dce5df;border-radius:10px;font:inherit;font-size:.61rem;font-weight:850}.sheet-actions .cancel{color:#617168;background:#fff}.sheet-actions .save{border-color:#176f3a;color:#fff;background:#176f3a}.sheet-actions .save:disabled{cursor:not-allowed;opacity:.48}.user-sheet-enter-active,.user-sheet-leave-active{transition:opacity .2s}.user-sheet-enter-active .user-sheet,.user-sheet-leave-active .user-sheet{transition:transform .24s ease}.user-sheet-enter-from,.user-sheet-leave-to{opacity:0}.user-sheet-enter-from .user-sheet,.user-sheet-leave-to .user-sheet{transform:translateX(-102%)}
@media(max-width:700px){.user-sheet{width:100vw;max-width:none}.sheet-head{min-height:68px;padding:9px 10px}.sheet-title>span{width:39px;height:39px;flex-basis:39px}.sheet-title p{display:none}.sheet-form{padding:8px}.sheet-form>section{padding:11px}.fields,.branches{grid-template-columns:1fr}.fields label.wide{grid-column:auto}.roles{display:flex;overflow-x:auto;scroll-snap-type:x mandatory}.roles button{flex:0 0 190px;scroll-snap-align:start}.station{grid-template-columns:1fr}.sheet-form section>header.split{grid-template-columns:30px minmax(0,1fr)}.sheet-form header>a{grid-column:2}.sheet-actions{grid-template-columns:1fr auto}.sheet-actions>div{display:none}.sheet-actions .save{min-width:170px}.statuses button{min-height:44px}}
</style>
<style>
body.user-sheet-open {
    overflow: hidden;
    overscroll-behavior: none;
}
</style>
