<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import UserAccountSheet from '../../../Components/Users/UserAccountSheet.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    users: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    showBranches: { type: Boolean, default: false },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
    editor: { type: Object, required: true },
});

const { ask } = useConfirm();
const editorOpen = ref(false);
const editorRecord = ref(null);
const filter = reactive({
    search: props.filters.search || '',
    role: props.filters.role || '',
    status: props.filters.status || '',
});

const hasFilters = computed(() => Object.values(filter).some(Boolean));
const resultLabel = computed(() => {
    if (!hasFilters.value) return `يعرض ${props.users.total} موظفاً ضمن نطاقك`;
    return `وجدنا ${props.users.total} نتيجة مطابقة`;
});

const roleIcons = {
    super_admin: 'bi-shield-lock-fill', partner: 'bi-briefcase-fill', admin: 'bi-person-gear',
    manager: 'bi-diagram-3-fill', accountant: 'bi-calculator-fill', cashier: 'bi-cash-stack',
    waiter: 'bi-person-raised-hand', chef: 'bi-fire', bartender: 'bi-cup-straw',
};

const statusMeta = {
    active: { label: 'فعّال', hint: 'يمكنه الدخول', icon: 'bi-check-circle-fill' },
    inactive: { label: 'غير فعّال', hint: 'الدخول متوقف', icon: 'bi-pause-circle' },
    suspended: { label: 'موقوف', hint: 'موقوف مؤقتاً', icon: 'bi-slash-circle' },
};

function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map((word) => word[0]).join('');
}

function visit(patch = {}) {
    Object.assign(filter, patch);
    router.get(props.urls.index, {
        search: filter.search || undefined,
        role: filter.role || undefined,
        status: filter.status || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clear() {
    visit({ search: '', role: '', status: '' });
}

function createUser() {
    editorRecord.value = null;
    editorOpen.value = true;
}

function editUser(user) {
    editorRecord.value = user.account;
    editorOpen.value = true;
}

async function toggle(user) {
    const activating = user.status !== 'active';
    const verb = activating ? 'تفعيل' : 'إيقاف';
    const yes = await ask({
        title: `${verb} حساب ${user.name}؟`,
        message: activating
            ? 'سيستطيع الموظف الدخول والعمل فوراً ضمن فروعه وصلاحياته.'
            : 'سيتوقف دخوله فوراً، وتبقى كل حركاته السابقة محفوظة.',
        confirmLabel: verb,
        danger: !activating,
    });
    if (yes) router.patch(user.urls.toggle, {}, { preserveScroll: true });
}

async function destroyUser(user) {
    const yes = await ask({
        title: `حذف ${user.name}؟`,
        message: 'سيختفي الحساب من الفريق، بينما تبقى حركاته التاريخية محفوظة للتدقيق.',
        confirmLabel: 'حذف المستخدم',
        danger: true,
    });
    if (yes) router.delete(user.urls.destroy, { preserveScroll: true });
}
</script>

<template>
    <Head title="إدارة المستخدمين" />

    <PageHeader
        title="إدارة المستخدمين"
        icon="bi-people-fill"
        subtitle="الحساب، دوره، فروعه وأثر صلاحياته في مكان واحد"
    >
        <template #actions>
            <Link v-if="can.managePermissions && urls.permissions" :href="urls.permissions" class="btn btn-light">
                <i class="bi bi-shield-lock-fill"></i> مركز الصلاحيات
            </Link>
            <button v-if="can.create" type="button" class="btn btn-primary" @click="createUser">
                <i class="bi bi-person-plus-fill"></i> مستخدم جديد
            </button>
        </template>
    </PageHeader>

    <StatRail :stats="[
        { label: 'كل الفريق', value: stats.total, icon: 'bi-people-fill', color: 'primary' },
        { label: 'حسابات فعّالة', value: stats.active, icon: 'bi-check-circle-fill', color: 'success' },
        { label: 'الإدارة', value: stats.admins, icon: 'bi-shield-lock-fill', color: 'accent' },
        { label: 'دخول متوقف', value: stats.inactive, icon: 'bi-person-dash', color: 'muted' },
    ]" />

    <section class="team-workspace">
        <header class="team-toolbar">
            <form class="team-search" @submit.prevent="visit()">
                <i class="bi bi-search"></i>
                <input v-model.trim="filter.search" type="search" placeholder="ابحث بالاسم، اسم الدخول أو الجوال…" />
                <button v-if="filter.search" type="button" aria-label="مسح البحث" @click="visit({ search: '' })"><i class="bi bi-x-lg"></i></button>
                <button type="submit" class="search-submit">بحث</button>
            </form>

            <div class="status-switch" role="group" aria-label="تصفية حالة الحساب">
                <button type="button" :class="{ active: filter.status === '' }" @click="visit({ status: '' })">الكل</button>
                <button type="button" :class="{ active: filter.status === 'active' }" @click="visit({ status: 'active' })">فعّال</button>
                <button type="button" :class="{ active: filter.status === 'suspended' }" @click="visit({ status: 'suspended' })">موقوف</button>
                <button type="button" :class="{ active: filter.status === 'inactive' }" @click="visit({ status: 'inactive' })">غير فعّال</button>
            </div>

            <label class="role-filter">
                <i class="bi bi-person-badge"></i>
                <select v-model="filter.role" aria-label="تصفية حسب الدور" @change="visit()">
                    <option value="">كل الأدوار</option>
                    <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                </select>
            </label>
        </header>

        <div class="results-head">
            <div><strong>{{ resultLabel }}</strong><span>كل تعديل في الدور أو الحالة يطبّق عند الحفظ مباشرة.</span></div>
            <button v-if="hasFilters" type="button" @click="clear"><i class="bi bi-arrow-counterclockwise"></i> مسح التصفية</button>
        </div>

        <div v-if="users.data.length" class="team-list">
            <article v-for="user in users.data" :key="user.id" class="staff-card" :class="`is-${user.status}`">
                <div class="staff-identity">
                    <span class="avatar">{{ initials(user.name) }}</span>
                    <div>
                        <span class="name-line"><strong>{{ user.name }}</strong><b v-if="user.guard.self">حسابك</b></span>
                        <small><bdi>@{{ user.username }}</bdi><template v-if="user.phone"> · <bdi>{{ user.phone }}</bdi></template></small>
                    </div>
                </div>

                <div class="staff-facts">
                    <div class="fact role-fact">
                        <i class="bi" :class="roleIcons[user.role] || 'bi-person'"></i>
                        <span><small>الدور التشغيلي</small><strong>{{ user.roleLabel }}</strong><em>{{ user.station || 'بدون محطة ثابتة' }}</em></span>
                    </div>

                    <div class="fact access-fact">
                        <i class="bi bi-shield-check"></i>
                        <span v-if="user.access.owner"><small>الوصول الفعلي</small><strong>شامل لكل النظام</strong><em>لا يحتاج استثناءات فردية</em></span>
                        <span v-else>
                            <small>الوصول الفعلي</small>
                            <strong>{{ user.access.effectiveCount }} صلاحية</strong>
                            <em v-if="user.access.overridesCount" class="deviations">
                                <b v-if="user.access.grantedCount" class="grant">+{{ user.access.grantedCount }} منح</b>
                                <b v-if="user.access.revokedCount" class="revoke">-{{ user.access.revokedCount }} سحب</b>
                            </em>
                            <em v-else>مطابق لدور {{ user.roleLabel }}</em>
                        </span>
                    </div>

                    <div class="fact activity-fact">
                        <i class="bi bi-clock-history"></i>
                        <span><small>آخر دخول</small><strong>{{ user.lastLoginAt || 'لم يدخل بعد' }}</strong><em>{{ showBranches ? `${user.branches.length} فرع` : 'ضمن الفرع المحدد' }}</em></span>
                    </div>
                </div>

                <div v-if="showBranches" class="branch-chips">
                    <span v-for="branch in user.branches" :key="branch.id" :class="{ primary: branch.primary }">
                        <i class="bi bi-building"></i>{{ branch.name }}<i v-if="branch.primary" class="bi bi-star-fill"></i>
                    </span>
                    <small v-if="!user.branches.length">لا يوجد فرع معيّن</small>
                </div>

                <footer class="staff-footer">
                    <span class="account-status" :class="`is-${user.status}`">
                        <i class="bi" :class="statusMeta[user.status]?.icon"></i>
                        <span><strong>{{ statusMeta[user.status]?.label || user.status }}</strong><small>{{ statusMeta[user.status]?.hint }}</small></span>
                    </span>
                    <span v-if="user.guard.lastActiveSuperAdmin" class="protected-account"><i class="bi bi-shield-lock-fill"></i> آخر مدير نظام فعّال</span>
                    <div class="staff-actions">
                        <Link v-if="user.can.permissions && user.urls.permissions" :href="user.urls.permissions" class="access-action"><i class="bi bi-shield-check"></i> الصلاحيات</Link>
                        <button v-if="user.can.update" type="button" @click="editUser(user)"><i class="bi bi-pencil-square"></i> تعديل</button>
                        <button v-if="user.can.toggle" type="button" @click="toggle(user)"><i class="bi bi-power"></i> {{ user.status === 'active' ? 'إيقاف' : 'تفعيل' }}</button>
                        <button v-if="user.can.delete" type="button" class="danger" @click="destroyUser(user)"><i class="bi bi-trash3"></i><span>حذف</span></button>
                    </div>
                </footer>
            </article>
        </div>

        <EmptyState
            v-else
            icon="bi-people"
            title="لا يوجد مستخدمون بهذه المواصفات"
            :message="hasFilters ? 'غيّر الفلاتر أو امسحها لرؤية الفريق.' : 'أضف أول موظف وحدد دوره وفروعه.'"
        />

        <footer v-if="users.links?.length > 3" class="pagination-wrap"><Pagination :links="users.links" /></footer>
    </section>

    <UserAccountSheet
        :open="editorOpen"
        :record="editorRecord"
        :catalogue="editor"
        @close="editorOpen = false"
    />
</template>

<style scoped>
.team-workspace{margin-top:12px;overflow:hidden;border:1px solid #dce6e0;border-radius:18px;background:#fff;box-shadow:0 10px 32px rgba(18,59,37,.05)}
.team-toolbar{display:grid;grid-template-columns:minmax(320px,1fr) auto 190px;gap:10px;padding:12px;border-bottom:1px solid #e7eeea;background:#f8faf9}.team-search{display:grid;grid-template-columns:24px minmax(0,1fr) 38px auto;align-items:center;min-height:48px;padding-inline:12px 5px;border:1px solid #d7e3dc;border-radius:13px;background:#fff;color:#75867c}.team-search input{min-width:0;height:44px;border:0;outline:0;background:transparent;font:inherit;font-size:.75rem}.team-search>button{min-width:36px;height:38px;border:0;border-radius:9px;background:transparent;color:#708078;font:inherit}.team-search .search-submit{min-width:62px;padding-inline:12px;color:#fff;background:rgb(var(--primary-rgb));font-size:.68rem;font-weight:850}.status-switch{display:flex;gap:3px;padding:4px;border-radius:12px;background:#edf2ef}.status-switch button{min-height:40px;padding-inline:12px;border:0;border-radius:9px;background:transparent;color:#6c7b72;font:inherit;font-size:.64rem;font-weight:800}.status-switch button.active{color:rgb(var(--primary-rgb));background:#fff;box-shadow:0 3px 10px rgba(25,60,39,.08)}.role-filter{display:grid;grid-template-columns:25px minmax(0,1fr);align-items:center;min-height:48px;padding-inline:10px;border:1px solid #d7e3dc;border-radius:13px;background:#fff;color:#718279}.role-filter select{height:100%;border:0;outline:0;background:transparent;color:#42564a;font:inherit;font-size:.68rem;font-weight:750}
.results-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 15px;border-bottom:1px solid #edf1ef}.results-head>div{display:grid;gap:2px}.results-head strong{color:#263a2e;font-size:.76rem}.results-head span{color:#829087;font-size:.61rem}.results-head button{min-height:38px;padding-inline:11px;border:1px solid #dce6e0;border-radius:10px;background:#fff;color:#607168;font:inherit;font-size:.62rem;font-weight:800}
.team-list{display:grid;gap:8px;padding:10px;background:#f7faf8}.staff-card{position:relative;display:grid;grid-template-columns:minmax(210px,.8fr) minmax(0,2fr);gap:10px;padding:13px;border:1px solid #dfe8e2;border-radius:15px;background:#fff;box-shadow:0 4px 15px rgba(24,58,38,.025)}.staff-card:before{position:absolute;inset-block:12px;inset-inline-start:0;width:3px;border-radius:4px;background:#23935b;content:''}.staff-card.is-inactive:before{background:#9aa8a0}.staff-card.is-suspended:before{background:#cf4b50}.staff-identity{display:flex;align-items:center;gap:11px;min-width:0}.avatar{display:grid;flex:0 0 48px;height:48px;place-items:center;border-radius:14px;background:linear-gradient(145deg,#e6f4ea,#f4faf6);color:rgb(var(--primary-rgb));font-size:.8rem;font-weight:950}.staff-identity>div{display:grid;min-width:0;gap:2px}.name-line{display:flex;align-items:center;gap:6px;min-width:0}.name-line strong{overflow:hidden;color:#1f3326;font-size:.8rem;text-overflow:ellipsis;white-space:nowrap}.name-line b{flex:0 0 auto;padding:2px 6px;border-radius:99px;background:#eaf4ed;color:#297047;font-size:.5rem}.staff-identity small{overflow:hidden;color:#7e8c83;font-size:.62rem;text-overflow:ellipsis;white-space:nowrap}.staff-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}.fact{display:flex;align-items:center;gap:8px;min-width:0;padding:9px 10px;border-radius:11px;background:#f8faf9}.fact>i{display:grid;flex:0 0 34px;height:34px;place-items:center;border-radius:9px;background:#eaf3ed;color:rgb(var(--primary-rgb))}.fact>span{display:grid;min-width:0}.fact small{color:#89958e;font-size:.52rem}.fact strong{overflow:hidden;color:#31463a;font-size:.66rem;text-overflow:ellipsis;white-space:nowrap}.fact em{overflow:hidden;color:#7d8c83;font-size:.54rem;font-style:normal;text-overflow:ellipsis;white-space:nowrap}.deviations{display:flex!important;gap:4px}.deviations b{padding:1px 5px;border-radius:99px;font-size:.49rem}.deviations .grant{color:#19733f;background:#e5f5ea}.deviations .revoke{color:#ad3440;background:#fff0f1}.branch-chips{display:flex;grid-column:1/-1;flex-wrap:wrap;gap:5px;padding-top:2px}.branch-chips span{display:inline-flex;align-items:center;gap:4px;padding:4px 7px;border:1px solid #e0e8e3;border-radius:99px;color:#66766d;font-size:.56rem}.branch-chips span.primary{border-color:#cce1d3;color:#196e3e;background:#f0f8f2}.branch-chips small{color:#97a29b;font-size:.57rem}
.staff-footer{display:flex;grid-column:1/-1;align-items:center;gap:9px;padding-top:10px;border-top:1px solid #edf2ef}.account-status{display:flex;align-items:center;gap:7px;min-width:125px;color:#187341}.account-status>span{display:grid}.account-status strong{font-size:.63rem}.account-status small{color:#829087;font-size:.51rem}.account-status.is-inactive{color:#6f7d75}.account-status.is-suspended{color:#b73842}.protected-account{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:9px;color:#8a5b08;background:#fff5df;font-size:.55rem;font-weight:800}.staff-actions{display:flex;flex:1;justify-content:flex-end;gap:6px}.staff-actions a,.staff-actions button{display:inline-flex;min-height:40px;align-items:center;justify-content:center;gap:5px;padding-inline:11px;border:1px solid #dce6e0;border-radius:10px;background:#fff;color:#50645a;text-decoration:none;font:inherit;font-size:.6rem;font-weight:800}.staff-actions .access-action{border-color:#c9dfd1;color:#176d3c;background:#f1f8f3}.staff-actions .danger{color:#b53842}.pagination-wrap{padding:11px;border-top:1px solid #e9efeb}
@media(max-width:1100px){.team-toolbar{grid-template-columns:1fr 190px}.status-switch{grid-column:1/-1;grid-row:2}.staff-card{grid-template-columns:1fr}.staff-facts{grid-column:1}.staff-footer,.branch-chips{grid-column:1}}
@media(max-width:720px){.team-workspace{border-radius:14px}.team-toolbar{grid-template-columns:1fr;padding:9px}.status-switch{grid-column:auto;grid-row:auto;overflow-x:auto}.status-switch button{flex:1;min-width:max-content}.role-filter{min-height:44px}.results-head{align-items:flex-start}.results-head span{display:none}.staff-card{padding:11px}.staff-facts{grid-template-columns:1fr 1fr}.activity-fact{grid-column:1/-1}.staff-footer{align-items:stretch;flex-direction:column}.account-status{min-width:0}.staff-actions{width:100%;justify-content:stretch}.staff-actions a,.staff-actions button{flex:1}.staff-actions .danger{flex:0 0 42px;padding:0}.staff-actions .danger span{display:none}}
@media(max-width:460px){.team-search{grid-template-columns:22px minmax(0,1fr) 36px}.team-search .search-submit{display:none}.staff-facts{grid-template-columns:1fr}.activity-fact{grid-column:auto}.staff-actions{display:grid;grid-template-columns:1fr 1fr auto}.staff-actions a,.staff-actions button{min-height:44px}.staff-identity small{max-width:230px}}
</style>
