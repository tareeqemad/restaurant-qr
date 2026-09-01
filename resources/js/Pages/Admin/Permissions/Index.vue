<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PermissionTree from '../../../Components/Permissions/PermissionTree.vue';
import UserAccountSheet from '../../../Components/Users/UserAccountSheet.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    tree: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    initialTab: { type: String, default: 'roles' },
    focus: { type: Object, default: () => ({}) },
    rules: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
    editor: { type: Object, required: true },
});

const tab = ref(props.initialTab);
const search = ref('');
const userSearch = ref('');
const userRoleFilter = ref('');
const accountEditorOpen = ref(false);
const accountEditorRecord = ref(null);
const { ask } = useConfirm();
const initialRole = props.roles.find((role) => role.id === props.focus.role)
    ?? props.roles.find((role) => role.name === 'cashier')
    ?? props.roles[0];
const initialUser = props.users.find((user) => user.id === props.focus.user)
    ?? props.users.find((user) => user.role === 'cashier')
    ?? props.users[0];
const selectedRoleId = ref(initialRole?.id ?? null);
const selectedUserId = ref(initialUser?.id ?? null);
const roleForm = useForm({ permissions: [] });
const userForm = useForm({ permissions: [] });

const selectedRole = computed(() => props.roles.find((role) => role.id === selectedRoleId.value) ?? null);
const selectedUser = computed(() => props.users.find((user) => user.id === selectedUserId.value) ?? null);
const totalPermissions = computed(() => props.tree.reduce((total, group) => total + group.permissions.length, 0));
const filteredUsers = computed(() => {
    const needle = userSearch.value.trim().toLocaleLowerCase('ar');
    return props.users.filter((user) => {
        if (userRoleFilter.value && user.role !== userRoleFilter.value) return false;
        if (!needle) return true;
        return `${user.name} ${user.username} ${user.roleLabel} ${user.branches.join(' ')}`
            .toLocaleLowerCase('ar')
            .includes(needle);
    });
});

function sorted(ids) {
    return [...ids].map(Number).sort((a, b) => a - b);
}

const roleDirty = computed(() => JSON.stringify(sorted(roleForm.permissions)) !== JSON.stringify(sorted(selectedRole.value?.permissionIds ?? [])));
const userDirty = computed(() => JSON.stringify(sorted(userForm.permissions)) !== JSON.stringify(sorted(selectedUser.value?.effectivePermissionIds ?? [])));
const userDeviationCount = computed(() => (selectedUser.value?.grantedPermissionIds.length ?? 0) + (selectedUser.value?.revokedPermissionIds.length ?? 0));
const userGrantCount = computed(() => selectedUser.value?.grantedPermissionIds.length ?? 0);
const userRevokeCount = computed(() => selectedUser.value?.revokedPermissionIds.length ?? 0);
const permissionById = computed(() => new Map(props.tree.flatMap((group) => group.permissions).map((permission) => [Number(permission.id), permission])));
const roleAddedIds = computed(() => sorted(roleForm.permissions).filter((id) => !(selectedRole.value?.permissionIds ?? []).map(Number).includes(id)));
const roleRemovedIds = computed(() => (selectedRole.value?.permissionIds ?? []).map(Number).filter((id) => !roleForm.permissions.map(Number).includes(id)));
const userAddedIds = computed(() => sorted(userForm.permissions).filter((id) => !(selectedUser.value?.effectivePermissionIds ?? []).map(Number).includes(id)));
const userRemovedIds = computed(() => (selectedUser.value?.effectivePermissionIds ?? []).map(Number).filter((id) => !userForm.permissions.map(Number).includes(id)));

function permissionLabels(ids) {
    return ids.map((id) => permissionById.value.get(Number(id))?.label).filter(Boolean).slice(0, 4);
}

watch(selectedRole, (role) => {
    roleForm.permissions = [...(role?.permissionIds ?? [])];
    roleForm.clearErrors();
}, { immediate: true });

watch(selectedUser, (user) => {
    userForm.permissions = [...(user?.effectivePermissionIds ?? [])];
    userForm.clearErrors();
}, { immediate: true });

async function saveRole() {
    if (!selectedRole.value || !props.canManage || selectedRole.value.locked) return;
    const approved = await ask({
        title: `تطبيق التغيير على دور ${selectedRole.value.label}؟`,
        message: `سيُطبق فوراً على ${selectedRole.value.members} موظف: ${roleAddedIds.value.length} منح و${roleRemovedIds.value.length} سحب.`,
        confirmLabel: 'حفظ وتطبيق',
        danger: roleRemovedIds.value.length > 0,
    });
    if (!approved) return;
    roleForm.put(selectedRole.value.syncUrl, { preserveScroll: true });
}

async function saveUser() {
    if (!selectedUser.value || !props.canManage || selectedUser.value.owner) return;
    const approved = await ask({
        title: `حفظ استثناءات ${selectedUser.value.name}؟`,
        message: `سيبقى دوره ${selectedUser.value.roleLabel}، ويتغير وصول هذا الحساب وحده: ${userAddedIds.value.length} منح و${userRemovedIds.value.length} سحب.`,
        confirmLabel: 'حفظ الاستثناءات',
        danger: userRemovedIds.value.length > 0,
    });
    if (!approved) return;
    userForm.put(selectedUser.value.syncUrl, { preserveScroll: true });
}

function resetUserToRole() {
    userForm.permissions = [...(selectedUser.value?.rolePermissionIds ?? [])];
}

function revertRoleChanges() {
    roleForm.permissions = [...(selectedRole.value?.permissionIds ?? [])];
}

function revertUserChanges() {
    userForm.permissions = [...(selectedUser.value?.effectivePermissionIds ?? [])];
}

async function confirmDiscard(message) {
    return ask({
        title: 'توجد تغييرات غير محفوظة',
        message,
        confirmLabel: 'اترك التغييرات',
        danger: true,
    });
}

async function selectRole(id) {
    if (id === selectedRoleId.value) return;
    if (roleDirty.value && ! await confirmDiscard('إذا انتقلت إلى دور آخر ستفقد التعديلات الحالية.')) return;
    selectedRoleId.value = id;
    search.value = '';
}

async function selectUser(id) {
    if (id === selectedUserId.value) return;
    if (userDirty.value && ! await confirmDiscard('إذا انتقلت إلى موظف آخر ستفقد الاستثناءات غير المحفوظة.')) return;
    selectedUserId.value = id;
    search.value = '';
}

async function switchTab(next) {
    if (next === tab.value) return;
    const dirty = tab.value === 'roles' ? roleDirty.value : userDirty.value;
    if (dirty && ! await confirmDiscard('إذا غادرت هذا القسم ستفقد التعديلات غير المحفوظة.')) return;
    tab.value = next;
    search.value = '';
}

async function openAccountEditor(user = null) {
    if (userDirty.value && !await confirmDiscard('فتح بيانات الحساب سيعيد تحميل الموظف بعد الحفظ؛ احفظ استثناءات الصلاحيات أولاً أو اتركها الآن.')) return;
    if (userDirty.value) revertUserChanges();
    accountEditorRecord.value = user?.account ?? null;
    accountEditorOpen.value = true;
}

function roleIcon(role) {
    return {
        super_admin: 'bi-shield-fill-check', partner: 'bi-gem', admin: 'bi-person-gear',
        manager: 'bi-person-badge', accountant: 'bi-calculator', cashier: 'bi-cash-stack',
        waiter: 'bi-person-standing', chef: 'bi-fire', bartender: 'bi-cup-straw',
    }[role] ?? 'bi-person';
}
</script>

<template>
    <Head title="مركز الصلاحيات" />

    <main class="access-page">
        <header class="access-hero">
            <div class="hero-copy">
                <span class="hero-icon"><i class="bi bi-shield-lock-fill"></i></span>
                <div>
                    <small>إدارة الوصول</small>
                    <h1>مركز الصلاحيات</h1>
                    <p>دور واضح لكل موظف، واستثناءات محددة عند الحاجة فقط.</p>
                </div>
            </div>
            <div class="hero-stats">
                <span><strong>{{ roles.length }}</strong><small>أدوار</small></span>
                <span><strong>{{ users.length }}</strong><small>موظفون</small></span>
                <span><strong>{{ totalPermissions }}</strong><small>صلاحية</small></span>
            </div>
            <Link :href="urls.users" class="users-link"><i class="bi bi-people"></i> إدارة المستخدمين</Link>
        </header>

        <div v-if="!canManage" class="read-only-note">
            <i class="bi bi-eye"></i>
            <div><strong>عرض فقط</strong><span>السوبر أدمن وحده يستطيع تغيير الأدوار أو استثناءات الموظفين.</span></div>
        </div>

        <details class="rules-guide">
            <summary>
                <span><i class="bi bi-info-circle-fill"></i><strong>متى أعطي الكاشير أو المحاسب صلاحية مالية؟</strong><small>دليل سريع للفصل بين التشغيل والتصحيح المحاسبي</small></span>
                <i class="bi bi-chevron-down"></i>
            </summary>
            <section class="boundary-grid">
                <article v-for="(rule, key) in rules" :key="key" :class="`is-${key}`">
                    <span><i class="bi" :class="key === 'cashier' ? 'bi-cash-register' : 'bi-journal-check'"></i></span>
                    <div><strong>{{ rule.title }}</strong><ul><li v-for="item in rule.items" :key="item">{{ item }}</li></ul></div>
                </article>
            </section>
        </details>

        <nav class="access-tabs" aria-label="أقسام مركز الصلاحيات">
            <button type="button" :class="{ active: tab === 'roles' }" @click="switchTab('roles')">
                <i class="bi bi-people-fill"></i><span><strong>صلاحيات الأدوار</strong><small>القاعدة الافتراضية لكل وظيفة</small></span>
            </button>
            <button type="button" :class="{ active: tab === 'users' }" @click="switchTab('users')">
                <i class="bi bi-person-check-fill"></i><span><strong>استثناءات الموظفين</strong><small>منح أو سحب لشخص محدد</small></span>
            </button>
        </nav>

        <div class="effect-rule">
            <i class="bi bi-lightning-charge-fill"></i>
            <div><strong>{{ tab === 'roles' ? 'تعديل الدور يغيّر الوصول لكل حامليه' : 'الاستثناء يغيّر حساباً واحداً فقط' }}</strong><span>{{ tab === 'roles' ? 'استخدمه عندما تكون الصلاحية جزءاً طبيعياً من الوظيفة.' : 'استخدمه للحاجة الخاصة، واترك الموظف مطابقاً لدوره كلما أمكن.' }}</span></div>
        </div>

        <section v-if="tab === 'roles'" class="access-workspace">
            <aside class="entity-rail">
                <div class="rail-heading"><strong>اختر الدور</strong><span>التعديل هنا يطبق على كل حاملي الدور.</span></div>
                <div class="role-list">
                    <button
                        v-for="role in roles"
                        :key="role.id"
                        type="button"
                        :class="{ active: role.id === selectedRoleId }"
                        @click="selectRole(role.id)"
                    >
                        <span><i class="bi" :class="roleIcon(role.name)"></i></span>
                        <div><strong>{{ role.label }}</strong><small>{{ role.activeMembers }} فعّال · {{ role.members }} إجمالي</small></div>
                        <i class="bi bi-chevron-left"></i>
                    </button>
                </div>
            </aside>

            <div v-if="selectedRole" class="permission-editor">
                <header class="editor-header">
                    <div><small>صلاحيات الدور</small><h2>{{ selectedRole.label }}</h2><p>{{ selectedRole.description || 'اختر فقط ما يحتاجه هذا الدور في عمله اليومي.' }}</p></div>
                    <span class="permission-count"><strong>{{ roleForm.permissions.length }}</strong><small>مفعّلة</small></span>
                </header>

                <div v-if="selectedRole.locked" class="owner-access">
                    <i class="bi bi-infinity"></i><div><strong>صلاحيات شاملة تلقائياً</strong><span>أدوار الملكية لا تُدار بصناديق اختيار حتى لا يظهر أنها قابلة للتقييد جزئياً.</span></div>
                </div>
                <template v-else>
                    <div v-if="roleDirty" class="change-preview">
                        <div><i class="bi bi-eye-fill"></i><span><strong>الأثر قبل الحفظ</strong><small>سيصل التغيير إلى {{ selectedRole.members }} موظف فوراً.</small></span></div>
                        <ul>
                            <li v-if="roleAddedIds.length" class="added"><b>+{{ roleAddedIds.length }}</b><span>{{ permissionLabels(roleAddedIds).join('، ') }}<template v-if="roleAddedIds.length > 4">…</template></span></li>
                            <li v-if="roleRemovedIds.length" class="removed"><b>-{{ roleRemovedIds.length }}</b><span>{{ permissionLabels(roleRemovedIds).join('، ') }}<template v-if="roleRemovedIds.length > 4">…</template></span></li>
                        </ul>
                    </div>
                    <label class="permission-search"><i class="bi bi-search"></i><input v-model="search" type="search" placeholder="ابحث باسم الشاشة أو الإجراء..."></label>
                    <PermissionTree v-model="roleForm.permissions" :groups="tree" :disabled="!canManage" :search="search" mode="role" />
                    <div class="save-bar" :class="{ dirty: roleDirty }">
                        <div><i class="bi" :class="roleDirty ? 'bi-pencil-square' : 'bi-check2-circle'"></i><span><strong>{{ roleDirty ? 'تغييرات غير محفوظة' : 'الدور محفوظ' }}</strong><small>أي تعديل يؤثر على حاملي الدور فور الحفظ.</small></span></div>
                        <div class="save-actions">
                            <button v-if="roleDirty" type="button" class="undo" @click="revertRoleChanges"><i class="bi bi-arrow-counterclockwise"></i> تراجع</button>
                            <button type="button" :disabled="!canManage || !roleDirty || roleForm.processing" @click="saveRole"><i class="bi bi-check2"></i> حفظ الدور</button>
                        </div>
                    </div>
                    <p v-if="roleForm.errors.permissions || roleForm.errors.role" class="form-error">{{ roleForm.errors.permissions || roleForm.errors.role }}</p>
                </template>
            </div>
        </section>

        <section v-else class="access-workspace">
            <aside class="entity-rail user-rail">
                <div class="rail-heading"><strong>اختر الموظف</strong><span>لا تستعمل الاستثناء إلا لحاجة واضحة.</span></div>
                <button v-if="editor.canCreate" type="button" class="rail-create" @click="openAccountEditor()"><i class="bi bi-person-plus-fill"></i> مستخدم جديد</button>
                <label class="rail-search"><i class="bi bi-search"></i><input v-model="userSearch" type="search" placeholder="اسم أو دور أو فرع..."></label>
                <select v-model="userRoleFilter" class="rail-role-filter" aria-label="تصفية الموظفين حسب الدور">
                    <option value="">كل الأدوار</option>
                    <option v-for="role in roles" :key="role.name" :value="role.name">{{ role.label }}</option>
                </select>
                <div class="user-list">
                    <button
                        v-for="user in filteredUsers"
                        :key="user.id"
                        type="button"
                        :class="{ active: user.id === selectedUserId }"
                        @click="selectUser(user.id)"
                    >
                        <span class="avatar">{{ user.name.slice(0, 1) }}</span>
                        <div><strong>{{ user.name }}</strong><small>{{ user.roleLabel }} · {{ user.status === 'active' ? 'فعّال' : 'الدخول متوقف' }}</small></div>
                        <b v-if="user.grantedPermissionIds.length + user.revokedPermissionIds.length">{{ user.grantedPermissionIds.length + user.revokedPermissionIds.length }}</b>
                    </button>
                    <div v-if="filteredUsers.length === 0" class="no-users">لا يوجد موظف مطابق</div>
                </div>
            </aside>

            <div v-if="selectedUser" class="permission-editor">
                <header class="editor-header">
                    <div><small>استثناءات موظف</small><h2>{{ selectedUser.name }}</h2><p>دوره الأساسي: {{ selectedUser.roleLabel }}. الأخضر منح إضافي والأحمر صلاحية مسحوبة من الدور.</p></div>
                    <span class="permission-count is-deviation"><strong>{{ userDeviationCount }}</strong><small>استثناء</small></span>
                </header>

                <div v-if="selectedUser.owner" class="owner-access">
                    <i class="bi bi-infinity"></i><div><strong>صلاحيات شاملة تلقائياً</strong><span>لا تُحفظ استثناءات على حسابات الملكية.</span></div>
                </div>
                <template v-else>
                    <div class="exception-summary">
                        <div><span class="extra"><i class="bi bi-plus-circle-fill"></i><strong>{{ userGrantCount }}</strong> منح إضافي</span><span class="removed"><i class="bi bi-dash-circle-fill"></i><strong>{{ userRevokeCount }}</strong> مسحوبة</span></div>
                        <button type="button" :disabled="!canManage || userDeviationCount === 0" @click="resetUserToRole"><i class="bi bi-arrow-repeat"></i> إلغاء كل الاستثناءات</button>
                    </div>
                    <div v-if="userDirty" class="change-preview is-user">
                        <div><i class="bi bi-person-check-fill"></i><span><strong>ما سيتغير لهذا الحساب</strong><small>لا يتأثر أي موظف آخر.</small></span></div>
                        <ul>
                            <li v-if="userAddedIds.length" class="added"><b>+{{ userAddedIds.length }}</b><span>{{ permissionLabels(userAddedIds).join('، ') }}<template v-if="userAddedIds.length > 4">…</template></span></li>
                            <li v-if="userRemovedIds.length" class="removed"><b>-{{ userRemovedIds.length }}</b><span>{{ permissionLabels(userRemovedIds).join('، ') }}<template v-if="userRemovedIds.length > 4">…</template></span></li>
                        </ul>
                    </div>
                    <div class="user-tools">
                        <label class="permission-search"><i class="bi bi-search"></i><input v-model="search" type="search" placeholder="ابحث داخل الصلاحيات..."></label>
                    </div>
                    <PermissionTree v-model="userForm.permissions" :groups="tree" :baseline="selectedUser.rolePermissionIds" :disabled="!canManage" :search="search" mode="user" default-filter="all" />
                    <div class="save-bar" :class="{ dirty: userDirty }">
                        <div><i class="bi" :class="userDirty ? 'bi-pencil-square' : 'bi-check2-circle'"></i><span><strong>{{ userDirty ? 'استثناءات غير محفوظة' : 'الاستثناءات محفوظة' }}</strong><small>هذه التغييرات تخص {{ selectedUser.name }} فقط.</small></span></div>
                        <div class="save-actions">
                            <button v-if="userDirty" type="button" class="undo" @click="revertUserChanges"><i class="bi bi-arrow-counterclockwise"></i> تراجع</button>
                            <button type="button" :disabled="!canManage || !userDirty || userForm.processing" @click="saveUser"><i class="bi bi-check2"></i> حفظ الاستثناءات</button>
                        </div>
                    </div>
                    <p v-if="userForm.errors.permissions" class="form-error">{{ userForm.errors.permissions }}</p>
                </template>
                <button v-if="selectedUser.account?.canUpdate" type="button" class="edit-user-link" @click="openAccountEditor(selectedUser)"><i class="bi bi-person-gear"></i> تعديل بيانات المستخدم وفروعه هنا</button>
            </div>
        </section>
    </main>

    <UserAccountSheet :open="accountEditorOpen" :record="accountEditorRecord" :catalogue="editor" @close="accountEditorOpen = false" />
</template>

<style scoped>
.access-page { max-width: 1540px; margin-inline: auto; padding: 14px 0 28px; color: #26382d; }
.access-hero { display: flex; align-items: center; gap: 20px; margin-bottom: 12px; padding: 17px 19px; border: 1px solid #dce7df; border-radius: 18px; background: linear-gradient(120deg, #eef7f1, #fff 64%); }
.hero-copy { display: flex; flex: 1; align-items: center; gap: 12px; }.hero-icon { display: grid; flex: 0 0 48px; width: 48px; height: 48px; place-items: center; border-radius: 14px; color: #fff; background: #176f3a; font-size: 1.1rem; }.hero-copy > div { display: grid; gap: 1px; }.hero-copy small { color: #6f8276; font-size: .65rem; font-weight: 800; }.hero-copy h1 { margin: 0; color: #14291d; font-size: 1.25rem; font-weight: 950; }.hero-copy p { margin: 0; color: #7c8a81; font-size: .74rem; }
.hero-stats { display: flex; gap: 7px; }.hero-stats span { display: grid; min-width: 72px; padding: 8px 10px; border: 1px solid #dfe8e1; border-radius: 11px; text-align: center; background: rgba(255,255,255,.8); }.hero-stats strong { color: #176f3a; font-size: .92rem; }.hero-stats small { color: #819087; font-size: .59rem; }
.read-only-note { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding: 11px 13px; border: 1px solid #cfe0ea; border-radius: 13px; color: #2a657e; background: #f3f9fc; }.read-only-note > i { font-size: 1.1rem; }.read-only-note > div { display: grid; }.read-only-note strong { font-size: .78rem; }.read-only-note span { font-size: .68rem; }
.rules-guide{overflow:hidden;margin-bottom:10px;border:1px solid #dfe7e2;border-radius:14px;background:#fff}.rules-guide>summary{display:flex;min-height:50px;align-items:center;justify-content:space-between;padding:9px 13px;cursor:pointer;list-style:none}.rules-guide>summary::-webkit-details-marker{display:none}.rules-guide>summary>span{display:grid;grid-template-columns:auto 1fr;column-gap:8px;align-items:center}.rules-guide>summary>span>i{grid-row:1/3;color:#176f3a}.rules-guide>summary strong{color:#31453a;font-size:.67rem}.rules-guide>summary small{color:#86938b;font-size:.55rem}.rules-guide[open]>summary>.bi{transform:rotate(180deg)}
.boundary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; padding:0 10px 10px;border-top:1px solid #edf1ee;padding-top:10px }.boundary-grid article { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border: 1px solid #dfe7e1; border-radius: 14px; background: #fff; }.boundary-grid article > span { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border-radius: 11px; color: #176f3a; background: #eaf5ed; }.boundary-grid article > div { display: grid; gap: 4px; }.boundary-grid strong { color: #273a2f; font-size: .78rem; }.boundary-grid ul { display: flex; flex-wrap: wrap; gap: 4px 15px; margin: 0; padding-inline-start: 16px; color: #748178; font-size: .65rem; line-height: 1.55; }.boundary-grid .is-accountant > span { color: #75520b; background: #fff3dc; }
.access-tabs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; margin-bottom: 10px; padding: 5px; border: 1px solid #dfe7e2; border-radius: 15px; background: #f4f7f5; }.access-tabs button { display: flex; min-height: 55px; align-items: center; gap: 9px; padding: 8px 12px; border: 0; border-radius: 11px; color: #637168; background: transparent; font: inherit; text-align: start; }.access-tabs button > i { font-size: .95rem; }.access-tabs button span { display: grid; }.access-tabs strong { font-size: .76rem; }.access-tabs small { color: #8a968e; font-size: .61rem; }.access-tabs button.active { color: #176f3a; background: #fff; box-shadow: 0 5px 15px rgba(20,55,34,.07); }
.access-workspace { display: grid; grid-template-columns: 250px minmax(0, 1fr); align-items: start; gap: 12px; }.entity-rail { position: sticky; top: 78px; overflow: hidden; max-height: calc(100dvh - 100px); border: 1px solid #dfe7e2; border-radius: 16px; background: #fff; }.rail-heading { display: grid; gap: 2px; padding: 14px; border-bottom: 1px solid #edf1ee; }.rail-heading strong { font-size: .82rem; }.rail-heading span { color: #839087; font-size: .65rem; }.role-list, .user-list { display: grid; gap: 3px; padding: 7px; overflow-y: auto; }.role-list button, .user-list button { display: grid; width: 100%; min-height: 50px; align-items: center; gap: 8px; padding: 7px 8px; border: 0; border-radius: 11px; color: #526057; background: transparent; font: inherit; text-align: start; }.role-list button { grid-template-columns: 34px minmax(0, 1fr) auto; }.role-list button > span { display: grid; width: 32px; height: 32px; place-items: center; border-radius: 9px; background: #f0f4f1; }.role-list button > div, .user-list button > div { display: grid; min-width: 0; }.role-list strong, .user-list strong { overflow: hidden; color: #34463b; font-size: .72rem; text-overflow: ellipsis; white-space: nowrap; }.role-list small, .user-list small { overflow: hidden; color: #8a968e; font-size: .6rem; text-overflow: ellipsis; white-space: nowrap; }.role-list button.active, .user-list button.active { color: #176f3a; background: #ecf6ef; }.role-list button.active > span { color: #fff; background: #17713c; }
.rail-search { display: flex; align-items: center; gap: 7px; margin: 9px 9px 2px; padding: 8px 9px; border: 1px solid #dfe7e2; border-radius: 10px; color: #87938b; background: #fafcfb; }.rail-search input { min-width: 0; width: 100%; border: 0; outline: 0; background: transparent; font: inherit; font-size: .68rem; }.user-list { max-height: calc(100dvh - 245px); }.user-list button { grid-template-columns: 34px minmax(0, 1fr) auto; }.avatar { display: grid; width: 32px; height: 32px; place-items: center; border-radius: 50%; color: #176f3a; background: #e8f4ec; font-size: .75rem; font-weight: 900; }.user-list b { display: grid; min-width: 21px; height: 21px; place-items: center; border-radius: 999px; color: #96570a; background: #fff0d9; font-size: .58rem; }
.rail-role-filter{width:calc(100% - 18px);min-height:38px;margin:6px 9px 2px;padding:0 9px;border:1px solid #dfe7e2;border-radius:10px;background:#fff;color:#5d6b62;font:inherit;font-size:.62rem}.no-users{padding:18px 8px;color:#87938b;font-size:.62rem;text-align:center}
.permission-editor { min-width: 0; }.editor-header { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; padding: 13px 15px; border: 1px solid #dfe7e2; border-radius: 15px; background: #fff; }.editor-header > div { display: grid; flex: 1; gap: 1px; }.editor-header small { color: #829087; font-size: .62rem; }.editor-header h2 { margin: 0; color: #1c3325; font-size: 1rem; }.editor-header p { margin: 0; color: #829087; font-size: .68rem; }.permission-count { display: grid; min-width: 58px; padding: 7px 9px; border-radius: 10px; color: #176f3a; background: #eaf5ed; text-align: center; }.permission-count strong { font-size: .85rem; }.permission-count small { color: inherit; font-size: .56rem; }.permission-count.is-deviation { color: #95600a; background: #fff3dd; }
.permission-search { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; padding: 10px 12px; border: 1px solid #dfe7e2; border-radius: 12px; color: #849088; background: #fff; }.permission-search input { min-width: 0; width: 100%; border: 0; outline: 0; background: transparent; font: inherit; font-size: .72rem; }.user-tools{display:block}.exception-summary{display:flex;min-height:48px;align-items:center;justify-content:space-between;gap:9px;margin-bottom:8px;padding:8px 11px;border:1px solid #e2e9e4;border-radius:12px;background:#fafcfb}.exception-summary>div{display:flex;flex-wrap:wrap;gap:7px}.exception-summary span{display:flex;align-items:center;gap:5px;padding:5px 8px;border-radius:8px;font-size:.57rem}.exception-summary .extra{background:#eaf6ed;color:#176f3a}.exception-summary .removed{background:#fff0f1;color:#a32f38}.exception-summary button{min-height:34px;padding:0 10px;border:1px solid #dfc6c8;border-radius:9px;background:#fff;color:#a02e36;font:inherit;font-size:.56rem;font-weight:800}.exception-summary button:disabled{opacity:.45}
.owner-access { display: flex; min-height: 180px; align-items: center; justify-content: center; gap: 13px; padding: 24px; border: 1px solid #cfe4d6; border-radius: 16px; color: #176f3a; background: #f1f9f3; }.owner-access > i { font-size: 2rem; }.owner-access > div { display: grid; }.owner-access strong { font-size: .9rem; }.owner-access span { color: #6e8375; font-size: .7rem; }
.save-bar { position: sticky; z-index: 10; bottom: 8px; display: flex; align-items: center; gap: 12px; margin-top: 10px; padding: 10px 12px; border: 1px solid #dbe6de; border-radius: 14px; background: rgba(255,255,255,.96); box-shadow: 0 12px 34px rgba(17,52,31,.13); backdrop-filter: blur(10px); }.save-bar > div { display: flex; flex: 1; align-items: center; gap: 8px; }.save-bar > div > i { display: grid; width: 32px; height: 32px; place-items: center; border-radius: 9px; color: #176f3a; background: #eaf5ed; }.save-bar span { display: grid; }.save-bar strong { font-size: .72rem; }.save-bar small { color: #839087; font-size: .61rem; }.save-bar button { min-height: 44px; padding-inline: 16px; border: 0; border-radius: 10px; color: #fff; background: #17713c; font: inherit; font-size: .72rem; font-weight: 850; }.save-bar button:disabled { cursor: not-allowed; opacity: .45; }.save-bar.dirty { border-color: #e5cb9e; }.save-bar.dirty > div > i { color: #945900; background: #fff0d6; }.form-error { margin: 8px 0 0; color: #ac2f38; font-size: .7rem; }
.save-bar>.save-actions{flex:0 0 auto}.save-actions button.undo{border:1px solid #dfe7e2;background:#fff;color:#5f6d64}
.users-link{display:inline-flex;min-height:42px;align-items:center;justify-content:center;gap:6px;padding-inline:12px;border:1px solid #cdded3;border-radius:11px;background:#fff;color:#176f3a;text-decoration:none;font-size:.61rem;font-weight:850;white-space:nowrap}.effect-rule{display:flex;align-items:center;gap:10px;margin-bottom:10px;padding:10px 13px;border:1px solid #d8e5dc;border-radius:13px;background:#f7faf8;color:#23623d}.effect-rule>i{display:grid;flex:0 0 34px;height:34px;place-items:center;border-radius:10px;background:#e4f2e8}.effect-rule>div{display:grid}.effect-rule strong{font-size:.7rem}.effect-rule span{color:#75867b;font-size:.59rem}.change-preview{display:grid;grid-template-columns:minmax(190px,.65fr) minmax(0,1fr);align-items:center;gap:10px;margin-bottom:9px;padding:10px 12px;border:1px solid #e6cf9f;border-radius:13px;background:#fff9ed}.change-preview>div{display:flex;align-items:center;gap:8px}.change-preview>div>i{display:grid;flex:0 0 34px;height:34px;place-items:center;border-radius:10px;background:#ffedc9;color:#94600a}.change-preview>div>span{display:grid}.change-preview strong{font-size:.66rem}.change-preview small{color:#8a7b62;font-size:.54rem}.change-preview ul{display:grid;gap:4px;margin:0;padding:0;list-style:none}.change-preview li{display:grid;grid-template-columns:30px minmax(0,1fr);align-items:center;gap:6px;min-width:0;padding:5px 7px;border-radius:8px;font-size:.54rem}.change-preview li b{display:grid;min-width:27px;height:23px;place-items:center;border-radius:7px}.change-preview li span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.change-preview .added{color:#1b6f40;background:#edf8f0}.change-preview .added b{background:#d9f0df}.change-preview .removed{color:#a43740;background:#fff0f1}.change-preview .removed b{background:#fbdadd}.change-preview.is-user{border-color:#cddfd3;background:#f7fbf8}.edit-user-link,.rail-create{display:inline-flex;min-height:42px;align-items:center;justify-content:center;gap:6px;border:1px solid #d9e4dc;border-radius:11px;background:#fff;color:#53685c;font:inherit;font-size:.59rem;font-weight:800}.edit-user-link{width:100%;margin-top:8px;padding-inline:12px}.rail-create{width:calc(100% - 18px);margin:8px 9px 0;color:#176f3a;background:#f1f8f3}
@media (max-width: 940px) { .access-workspace { grid-template-columns: 210px minmax(0, 1fr); }.boundary-grid { grid-template-columns: 1fr; } }
@media (max-width: 720px) { .access-page { padding-inline: 8px; }.access-hero { align-items: flex-start; flex-direction: column; }.hero-stats,.users-link { width: 100%; }.hero-stats span { flex: 1; }.access-workspace { display: block; }.entity-rail { position: static; max-height: none; margin-bottom: 10px; }.role-list, .user-list { display: flex; overflow-x: auto; max-height: none; }.role-list button, .user-list button { flex: 0 0 180px; }.access-tabs small { display: none; }.editor-header p { display: none; }.rail-role-filter{width:calc(100% - 18px)}.change-preview{grid-template-columns:1fr}.effect-rule{align-items:flex-start} }
@media (max-width: 500px) { .hero-copy p { display: none; }.boundary-grid { grid-template-columns:1fr }.access-tabs button { justify-content: center; }.access-tabs button > i { display: none; }.exception-summary{align-items:stretch;flex-direction:column}.save-bar small { display: none; }.save-bar button { padding-inline: 10px; }.save-bar>.save-actions{gap:5px}.save-actions button.undo{padding-inline:8px} }
</style>
