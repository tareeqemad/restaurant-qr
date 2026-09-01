<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    user: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    ownerRoles: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    canManagePermissions: { type: Boolean, default: false },
    accountGuard: { type: Object, default: () => ({ lockRole: false, lockStatus: false, message: '' }) },
    urls: { type: Object, required: true },
});

const editing = computed(() => Boolean(props.user.id));
const showPassword = ref(false);
const initialAccessibleBranches = props.user.branchIds.filter((id) =>
    props.branches.some((branch) => branch.id === id && branch.accessible)
);
const initialPrimary = props.branches.some((branch) =>
    branch.id === props.user.primaryBranchId && branch.accessible
) ? props.user.primaryBranchId : null;

const form = useForm({
    name: props.user.name,
    username: props.user.username,
    email: props.user.email,
    phone: props.user.phone,
    role: props.user.role,
    station_id: props.user.stationId ?? '',
    status: props.user.status,
    password: '',
    password_confirmation: '',
    branches: [...initialAccessibleBranches],
    primary_branch_id: initialPrimary,
});

const roleMeta = {
    super_admin: { icon: 'bi-shield-lock-fill', text: 'وصول تقني كامل للنظام وكل الفروع', tone: 'owner' },
    partner: { icon: 'bi-briefcase-fill', text: 'مالك يرى العمل والمال في كل الفروع', tone: 'owner' },
    admin: { icon: 'bi-person-gear', text: 'إدارة عامة وتشغيل يومي واسع', tone: 'lead' },
    manager: { icon: 'bi-diagram-3-fill', text: 'إدارة الفروع المعيّن إليها وفريقها', tone: 'lead' },
    accountant: { icon: 'bi-calculator-fill', text: 'الحسابات والقيود والتقارير المالية', tone: 'finance' },
    cashier: { icon: 'bi-cash-stack', text: 'الفواتير والتحصيل وإجراءات الكاشير', tone: 'service' },
    waiter: { icon: 'bi-person-raised-hand', text: 'الطاولات والطلبات وخدمة الزبائن', tone: 'service' },
    chef: { icon: 'bi-fire', text: 'طلبات المطبخ وتحديث جاهزية الأصناف', tone: 'station' },
    bartender: { icon: 'bi-cup-straw', text: 'طلبات البار والمشروبات', tone: 'station' },
};

const selectedRole = computed(() => props.roles.find((role) => role.value === form.role));
const selectedRoleMeta = computed(() => roleMeta[form.role] ?? { icon: 'bi-person', text: 'اختر دور المستخدم', tone: '' });
const isOwnerRole = computed(() => props.ownerRoles.includes(form.role));
const needsStation = computed(() => ['chef', 'bartender'].includes(form.role));
const selectedBranches = computed(() => props.branches.filter((branch) =>
    form.branches.includes(branch.id) || (! branch.accessible && branch.assigned)
));
const primaryBranch = computed(() => props.branches.find((branch) => branch.id === form.primary_branch_id));
const initials = computed(() => (form.name || 'م').trim().split(/\s+/).slice(0, 2).map((word) => word[0]).join(''));
const branchError = computed(() => form.errors.branches || Object.entries(form.errors)
    .find(([key]) => key.startsWith('branches.'))?.[1]);

watch(() => form.role, () => {
    if (! needsStation.value) form.station_id = '';
});

function chooseRole(role) {
    if (props.accountGuard.lockRole) return;
    form.role = role.value;
    form.clearErrors('role');
}

function toggleBranch(branch) {
    if (! branch.accessible) return;
    const index = form.branches.indexOf(branch.id);
    if (index === -1) {
        form.branches.push(branch.id);
        if (! form.primary_branch_id) form.primary_branch_id = branch.id;
    } else {
        form.branches.splice(index, 1);
        if (form.primary_branch_id === branch.id) {
            form.primary_branch_id = form.branches[0] ?? null;
        }
    }
    form.clearErrors('branches', 'primary_branch_id');
}

function selectPrimary(branch) {
    if (! branch.accessible || ! form.branches.includes(branch.id)) return;
    form.primary_branch_id = branch.id;
}

function submit() {
    const options = { preserveScroll: true };
    editing.value ? form.put(props.urls.submit, options) : form.post(props.urls.submit, options);
}
</script>

<template>
    <Head :title="editing ? `تعديل ${user.name}` : 'إضافة مستخدم'" />

    <PageHeader
        :title="editing ? `تعديل ${user.name}` : 'إضافة مستخدم'"
        icon="bi-person-plus-fill"
        subtitle="حساب واحد، دور واضح، والفروع التي يعمل فيها فقط"
        :crumbs="[{ label: 'المستخدمون', url: urls.index }]"
    />

    <form class="user-form" @submit.prevent="submit">
        <main class="user-main">
            <div v-if="accountGuard.lockRole || accountGuard.lockStatus" class="account-guard" role="note">
                <i class="bi bi-shield-lock-fill"></i>
                <div><strong>حماية الحساب من الإغلاق</strong><span>{{ accountGuard.message }}</span></div>
            </div>
            <section class="form-section">
                <header class="section-head">
                    <span class="step">1</span>
                    <div><h2>بيانات الدخول</h2><p>المعلومات التي يحتاجها الموظف ليتعرّف عليه النظام.</p></div>
                </header>
                <div class="field-grid">
                    <label class="field wide">
                        <span>الاسم الكامل *</span>
                        <input v-model="form.name" class="form-control" required autofocus placeholder="مثال: أحمد محمد" />
                        <small v-if="form.errors.name" class="error">{{ form.errors.name }}</small>
                    </label>
                    <label class="field">
                        <span>اسم المستخدم *</span>
                        <input v-model="form.username" class="form-control" required autocomplete="username" placeholder="ahmad" />
                        <small v-if="form.errors.username" class="error">{{ form.errors.username }}</small>
                    </label>
                    <label class="field">
                        <span>رقم الجوال <small>اختياري — يمكن استخدامه للدخول</small></span>
                        <input v-model="form.phone" class="form-control" inputmode="numeric" maxlength="10" pattern="0[0-9]{9}" placeholder="0592632026" />
                        <small v-if="form.errors.phone" class="error">{{ form.errors.phone }}</small>
                    </label>
                    <label class="field">
                        <span>البريد الإلكتروني</span>
                        <input v-model="form.email" class="form-control" type="email" autocomplete="email" placeholder="name@example.com" />
                        <small v-if="form.errors.email" class="error">{{ form.errors.email }}</small>
                    </label>
                </div>
            </section>

            <section class="form-section">
                <header class="section-head">
                    <span class="step">2</span>
                    <div><h2>دوره في المطعم</h2><p>اختر وظيفة واحدة؛ صلاحياتها الافتراضية تطبّق تلقائياً.</p></div>
                </header>
                <div class="role-grid" role="radiogroup" aria-label="دور المستخدم">
                    <button v-for="role in roles" :key="role.value" type="button" class="role-card"
                            :class="[{ selected: form.role === role.value, locked: accountGuard.lockRole }, `tone-${roleMeta[role.value]?.tone ?? ''}`]"
                            :disabled="accountGuard.lockRole"
                            role="radio" :aria-checked="form.role === role.value" @click="chooseRole(role)">
                        <i class="bi" :class="roleMeta[role.value]?.icon ?? 'bi-person'"></i>
                        <span><strong>{{ role.label }}</strong><small>{{ roleMeta[role.value]?.text }}</small></span>
                        <i class="bi role-check" :class="form.role === role.value ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                    </button>
                </div>
                <small v-if="form.errors.role" class="error section-error">{{ form.errors.role }}</small>

                <div v-if="needsStation" class="station-row">
                    <div><strong>محطة العمل</strong><small>تحدد الشاشة التي يستقبل عليها الطلبات.</small></div>
                    <select v-model="form.station_id" class="form-select">
                        <option value="">— اختر المحطة —</option>
                        <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
                    </select>
                    <small v-if="form.errors.station_id" class="error">{{ form.errors.station_id }}</small>
                </div>
            </section>

            <section class="form-section" :class="{ 'has-error': branchError }">
                <header class="section-head section-head--split">
                    <div class="section-title"><span class="step">3</span><div><h2>فروع العمل</h2><p>{{ isOwnerRole ? 'اختيار الفرع اختياري لهذا الدور.' : 'اختر فرعاً واحداً على الأقل، ثم حدّد الأساسي.' }}</p></div></div>
                    <Link v-if="urls.createBranch" :href="urls.createBranch" class="text-action"><i class="bi bi-plus-lg"></i> فرع جديد</Link>
                </header>

                <div v-if="branches.length" class="branch-grid">
                    <article v-for="branch in branches" :key="branch.id" class="branch-card"
                             :class="{ selected: form.branches.includes(branch.id) || (!branch.accessible && branch.assigned), locked: !branch.accessible }">
                        <button type="button" class="branch-select" :disabled="!branch.accessible" @click="toggleBranch(branch)">
                            <i class="bi" :class="form.branches.includes(branch.id) || (!branch.accessible && branch.assigned) ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                            <span><strong>{{ branch.name }}</strong><small>{{ branch.code }}<template v-if="branch.city"> · {{ branch.city }}</template></small></span>
                            <i v-if="!branch.accessible" class="bi bi-lock-fill lock"></i>
                        </button>
                        <button v-if="branch.accessible && form.branches.includes(branch.id)" type="button" class="primary-pick"
                                :class="{ active: form.primary_branch_id === branch.id }" @click="selectPrimary(branch)">
                            <i class="bi" :class="form.primary_branch_id === branch.id ? 'bi-star-fill' : 'bi-star'"></i>
                            {{ form.primary_branch_id === branch.id ? 'الفرع الأساسي' : 'اجعله الأساسي' }}
                        </button>
                        <span v-else-if="!branch.accessible && branch.assigned" class="locked-note">تعيين محفوظ خارج نطاقك</span>
                    </article>
                </div>
                <div v-else class="empty-branches"><i class="bi bi-building-exclamation"></i><span>لا توجد فروع مفعّلة. أنشئ فرعاً قبل إضافة الموظفين.</span></div>
                <small v-if="branchError" class="error section-error">{{ branchError }}</small>
            </section>

            <section class="form-section">
                <header class="section-head">
                    <span class="step">4</span>
                    <div><h2>{{ editing ? 'كلمة المرور والحالة' : 'كلمة المرور' }}</h2><p>{{ editing ? 'اترك كلمة المرور فارغة إذا لم ترد تغييرها.' : 'ستُستخدم عند أول دخول للموظف.' }}</p></div>
                </header>
                <div class="field-grid">
                    <label class="field">
                        <span>كلمة المرور {{ editing ? '' : '*' }}</span>
                        <span class="password-input"><input v-model="form.password" class="form-control" :type="showPassword ? 'text' : 'password'" :required="!editing" autocomplete="new-password" /><button type="button" :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'" @click="showPassword = !showPassword"><i class="bi" :class="showPassword ? 'bi-eye-slash' : 'bi-eye'"></i></button></span>
                        <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
                    </label>
                    <label class="field">
                        <span>تأكيد كلمة المرور</span>
                        <input v-model="form.password_confirmation" class="form-control" :type="showPassword ? 'text' : 'password'" :required="!editing" autocomplete="new-password" />
                    </label>
                </div>

                <div class="status-options" role="radiogroup" aria-label="حالة المستخدم">
                    <button v-for="status in [{value:'active',label:'فعّال',hint:'يمكنه الدخول الآن',icon:'bi-check-circle-fill'},{value:'inactive',label:'غير فعّال',hint:'محفوظ بدون دخول',icon:'bi-pause-circle'},{value:'suspended',label:'موقوف',hint:'إيقاف مؤقت للحساب',icon:'bi-slash-circle'}]"
                            :key="status.value" type="button" :disabled="accountGuard.lockStatus" :class="{ selected: form.status === status.value, locked: accountGuard.lockStatus }" @click="form.status = status.value">
                        <i class="bi" :class="status.icon"></i><span><strong>{{ status.label }}</strong><small>{{ status.hint }}</small></span>
                    </button>
                </div>
                <small v-if="form.errors.status" class="error section-error">{{ form.errors.status }}</small>
            </section>

            <details class="optional-card">
                <summary><span><i class="bi bi-cup-hot-fill"></i><strong>بدل وجبات الموظف</strong><small>اختياري — لا يلزم لإضافة الحساب</small></span><i class="bi bi-chevron-down"></i></summary>
                <div class="optional-body">
                </div>
            </details>
        </main>

        <aside class="user-summary">
            <div class="profile-preview">
                <span class="avatar">{{ initials }}</span>
                <div><strong>{{ form.name || 'مستخدم جديد' }}</strong><small>{{ selectedRole?.label || 'لم يُحدد الدور' }}</small></div>
                <span class="status-dot" :class="form.status"></span>
            </div>
            <dl>
                <div><dt>الدور</dt><dd><i class="bi" :class="selectedRoleMeta.icon"></i> {{ selectedRole?.label || '—' }}</dd></div>
                <div><dt>فروع العمل</dt><dd>{{ selectedBranches.length || (isOwnerRole ? 'كل الفروع' : '—') }}</dd></div>
                <div><dt>يفتح على</dt><dd>{{ primaryBranch?.name || (isOwnerRole ? 'وضع كل الفروع' : '—') }}</dd></div>
                <div v-if="needsStation"><dt>المحطة</dt><dd>{{ stations.find(s => s.id === form.station_id)?.name || 'لم تحدد' }}</dd></div>
            </dl>
            <div class="permission-note">
                <i class="bi bi-shield-check"></i>
                <div><strong>الصلاحيات بدون تعقيد</strong><p>الدور يمنح الصلاحيات المعتادة تلقائياً. الاستثناءات الفردية تُدار من مركز الصلاحيات بعد الحفظ.</p></div>
            </div>
            <Link v-if="canManagePermissions && urls.permissions" :href="urls.permissions" class="permission-link"><i class="bi bi-diagram-3"></i> فتح مركز الصلاحيات</Link>
        </aside>

        <footer class="save-bar">
            <Link :href="urls.index" class="btn btn-light">إلغاء</Link>
            <span><i class="bi bi-shield-lock"></i> لا يمكن منح دور أعلى من صلاحيتك.</span>
            <button class="btn btn-primary" :disabled="form.processing || (!isOwnerRole && form.branches.length === 0)">
                <i class="bi bi-check2-circle"></i> {{ form.processing ? 'جارٍ الحفظ…' : editing ? 'حفظ التعديلات' : 'إضافة المستخدم' }}
            </button>
        </footer>
    </form>
</template>

<style scoped>
.user-form{display:grid;grid-template-columns:minmax(0,1fr) 286px;gap:12px;align-items:start}.user-main{display:grid;gap:10px}.account-guard{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid #ead29e;border-radius:14px;background:#fff8e9;color:#81530a}.account-guard>i{font-size:1rem}.account-guard>div{display:grid}.account-guard strong{font-size:.7rem}.account-guard span{color:#947144;font-size:.6rem}.form-section,.optional-card,.user-summary{border:1px solid #dfe7e2;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(24,57,36,.035)}.form-section{padding:16px}.section-head,.section-title{display:flex;align-items:flex-start;gap:10px}.section-head{margin-bottom:14px}.section-head--split{align-items:center;justify-content:space-between}.step{display:grid;flex:0 0 30px;height:30px;place-items:center;border-radius:10px;background:#eaf5ed;color:rgb(var(--primary-rgb));font-size:.72rem;font-weight:900}.section-head h2{margin:0;color:#16261c;font-size:.88rem;font-weight:900}.section-head p{margin:3px 0 0;color:#77857c;font-size:.63rem}.text-action{display:inline-flex;align-items:center;gap:5px;color:#176b39;font-size:.64rem;font-weight:850;text-decoration:none;white-space:nowrap}.field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.field{display:grid;align-content:start;gap:5px;margin:0}.field.wide{grid-column:1/-1}.field>span{color:#34483a;font-size:.66rem;font-weight:850}.field>span>small{margin-inline-start:4px;color:#87938b;font-size:.55rem;font-weight:650}.field .form-control,.station-row .form-select{min-height:44px;border-color:#dce5df;border-radius:11px;font-size:.72rem}.field .form-control:focus,.station-row .form-select:focus{border-color:#82b593;box-shadow:0 0 0 3px rgba(var(--primary-rgb),.08)}.password-input{position:relative;display:block}.password-input .form-control{width:100%;padding-inline-end:44px}.password-input button{position:absolute;inset-block:3px;inset-inline-end:3px;width:38px;border:0;border-radius:9px;background:transparent;color:#66776d}.error{color:#b42318;font-size:.61rem}.section-error{display:block;margin-top:9px}.form-section.has-error{border-color:#f2a8a3}.role-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.role-card{display:flex;min-height:67px;align-items:center;gap:9px;padding:10px;border:1px solid #e1e8e3;border-radius:13px;background:#fbfcfb;color:#69776e;text-align:start;transition:.15s}.role-card>i:first-child{display:grid;flex:0 0 36px;height:36px;place-items:center;border-radius:10px;background:#f0f4f1;font-size:1rem}.role-card span{display:grid;flex:1;min-width:0}.role-card strong{color:#25372b;font-size:.69rem}.role-card small{margin-top:2px;color:#829087;font-size:.55rem;line-height:1.45}.role-card .role-check{font-size:.78rem}.role-card:hover{border-color:#b7cebe}.role-card.selected{border-color:#8bb99a;background:#eef8f1;color:#176b39;box-shadow:0 0 0 2px rgba(var(--primary-rgb),.05)}.role-card.selected>i:first-child{background:#dcefe2;color:#176b39}.role-card.tone-owner.selected{border-color:#b9a06a;background:#fffaee;color:#8a5b08}.role-card.tone-finance.selected{border-color:#88abbf;background:#f0f8fb;color:#17627f}.role-card.locked,.status-options button.locked{cursor:not-allowed;opacity:.68}.station-row{display:grid;grid-template-columns:minmax(140px,1fr) minmax(180px,1fr);align-items:center;gap:9px;margin-top:11px;padding:11px;border-radius:12px;background:#f7faf8}.station-row>div{display:grid}.station-row strong{font-size:.69rem}.station-row small{color:#829087;font-size:.57rem}.station-row>.error{grid-column:2}.branch-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.branch-card{overflow:hidden;border:1px solid #e1e8e3;border-radius:13px;background:#fbfcfb}.branch-card.selected{border-color:#8bb99a;background:#f1f9f3}.branch-card.locked{opacity:.62;background:#f3f5f4}.branch-select{display:flex;width:100%;min-height:58px;align-items:center;gap:9px;padding:10px;border:0;background:transparent;color:#65746b;text-align:start}.branch-select>span{display:grid;flex:1}.branch-select strong{color:#273a2e;font-size:.7rem}.branch-select small{color:#829087;font-size:.57rem;text-align:end}.branch-select>i:first-child{color:#16804b}.lock{color:#8a9690}.primary-pick{display:flex;width:100%;min-height:36px;align-items:center;justify-content:center;gap:6px;border:0;border-top:1px solid #dfeae2;background:rgba(255,255,255,.55);color:#718078;font-size:.59rem;font-weight:800}.primary-pick.active{color:#9a6b0b;background:#fff9e8}.locked-note{display:block;padding:8px;border-top:1px solid #e1e5e2;color:#7b8981;font-size:.56rem;text-align:center}.empty-branches{display:flex;align-items:center;gap:9px;padding:14px;border-radius:12px;background:#fff8e7;color:#8a5a05;font-size:.66rem}.status-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}.status-options button{display:flex;min-height:52px;align-items:center;gap:8px;padding:9px 11px;border:1px solid #e0e7e2;border-radius:12px;background:#fbfcfb;color:#718078;text-align:start}.status-options button>span{display:grid}.status-options strong{color:#304338;font-size:.68rem}.status-options small{font-size:.56rem}.status-options button.selected{border-color:#8fbd9c;background:#eef8f1;color:#15723e}.status-options button:nth-child(3).selected{border-color:#e7a6a1;background:#fff2f1;color:#b42318}.optional-card{overflow:hidden}.optional-card summary{display:flex;min-height:62px;align-items:center;justify-content:space-between;padding:13px 16px;cursor:pointer;list-style:none}.optional-card summary::-webkit-details-marker{display:none}.optional-card summary>span{display:grid;grid-template-columns:auto 1fr;column-gap:9px;align-items:center}.optional-card summary>span>i{grid-row:1/3;color:#9a6b0b}.optional-card summary strong{font-size:.71rem}.optional-card summary small{color:#829087;font-size:.57rem}.optional-card[open] summary>.bi{transform:rotate(180deg)}.optional-body{display:grid;grid-template-columns:1fr 1fr;gap:11px;padding:0 16px 16px;border-top:1px solid #eef2ef;padding-top:13px}.user-summary{position:sticky;top:82px;overflow:hidden;padding:14px}.profile-preview{display:flex;align-items:center;gap:10px;padding-bottom:13px;border-bottom:1px solid #edf1ee}.avatar{display:grid;flex:0 0 44px;height:44px;place-items:center;border-radius:14px;background:linear-gradient(145deg,rgb(var(--primary-rgb)),#23945b);color:#fff;font-size:.82rem;font-weight:900}.profile-preview>div{display:grid;flex:1;min-width:0}.profile-preview strong{overflow:hidden;color:#1d3024;font-size:.74rem;text-overflow:ellipsis;white-space:nowrap}.profile-preview small{color:#7d8b82;font-size:.59rem}.status-dot{width:9px;height:9px;border-radius:50%;background:#94a3b8}.status-dot.active{background:#22a75a}.status-dot.suspended{background:#dd4c45}.user-summary dl{display:grid;gap:0;margin:10px 0}.user-summary dl>div{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 2px;border-bottom:1px solid #f0f3f1}.user-summary dt{color:#829087;font-size:.59rem;font-weight:700}.user-summary dd{margin:0;color:#2d4034;font-size:.64rem;font-weight:850;text-align:end}.permission-note{display:flex;gap:9px;padding:11px;border-radius:12px;background:#f0f7f2;color:#2c5c3b}.permission-note>i{font-size:1rem}.permission-note div{display:grid}.permission-note strong{font-size:.64rem}.permission-note p{margin:2px 0 0;color:#6e8074;font-size:.56rem;line-height:1.65}.permission-link{display:flex;min-height:42px;align-items:center;justify-content:center;gap:6px;margin-top:8px;border:1px solid #cfe0d4;border-radius:11px;color:#176b39;font-size:.62rem;font-weight:850;text-decoration:none}.save-bar{position:sticky;z-index:8;bottom:8px;grid-column:1/-1;display:flex;align-items:center;gap:9px;padding:11px;border:1px solid #cbddd1;border-radius:14px;background:rgba(255,255,255,.97);box-shadow:0 12px 35px rgba(20,50,31,.12)}.save-bar>span{display:flex;flex:1;gap:6px;color:#7a8980;font-size:.61rem}
@media(max-width:1050px){.user-form{grid-template-columns:1fr}.user-summary{position:static;grid-row:1}.role-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.form-section{padding:13px}.field-grid,.branch-grid,.optional-body{grid-template-columns:1fr}.field.wide{grid-column:auto}.role-grid{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:3px}.role-card{flex:0 0 215px;scroll-snap-align:start}.station-row{grid-template-columns:1fr}.station-row>.error{grid-column:auto}.status-options{grid-template-columns:1fr}.save-bar>span{display:none}.save-bar .btn-primary{flex:1}.section-head--split{align-items:flex-start}.user-summary{display:none}}
</style>
