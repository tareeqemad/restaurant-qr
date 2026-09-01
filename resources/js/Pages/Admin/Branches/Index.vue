<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useToast } from '../../../Composables/useToast';
import { formPost } from '../../../Support/formPost';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    branches: { type: Object, required: true },
    stats: { type: Object, required: true },
    sourceBranches: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const toast = useToast();
const form = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
const hasFilters = computed(() => Boolean(form.search || form.status));

const visit = () => router.get(props.urls.index, {
    search: form.search || undefined,
    status: form.status || undefined,
}, { preserveState: true, preserveScroll: true });

function setStatus(status) {
    form.status = status;
    visit();
}

function clear() {
    form.search = '';
    form.status = '';
    visit();
}

async function toggle(branch) {
    if (branch.isActive) {
        const accepted = await ask({
            title: `تعطيل فرع «${branch.name}»؟`,
            message: branch.isCurrent
                ? 'هذا فرعك الحالي. سيتوقف عن استقبال عمليات جديدة ويختفي من مبدّل الفروع.'
                : 'ستبقى بياناته وفواتيره محفوظة، لكنه لن يستقبل عمليات جديدة.',
            confirmLabel: 'تعطيل الفرع',
            danger: true,
        });
        if (! accepted) return;
    }
    formPost(branch.urls.toggle, { _method: 'PATCH' });
}

async function destroy(branch) {
    if (branch.blocksDelete) {
        toast.warning(`يوجد ${branch.usersCount} موظف في «${branch.name}». أزل تعييناتهم أو عطّل الفرع.`);
        return;
    }
    const accepted = await ask({
        title: `حذف فرع «${branch.name}» نهائياً؟`,
        message: 'الحذف لا يمكن التراجع عنه. إن أردت إيقاف العمل فقط استخدم تعطيل الفرع.',
        confirmLabel: 'حذف نهائي',
        danger: true,
    });
    if (accepted) formPost(branch.urls.destroy, { _method: 'DELETE' });
}

const copying = ref(null);
const sourceId = ref('');
const canCopyMenu = (branch) => branch.menuItems === 0 && props.sourceBranches.some((source) => source.id !== branch.id);

function openCopy(branch) {
    copying.value = branch;
    sourceId.value = '';
}

function submitCopy() {
    if (! sourceId.value) return;
    formPost(copying.value.urls.duplicateMenu, { source_id: sourceId.value });
}
</script>

<template>
    <Head title="الفروع" />

    <PageHeader title="الفروع" icon="bi-buildings"
                subtitle="إدارة مستقلة لكل فرع بدون خلط الموظفين أو المخزون أو الفواتير">
        <template #actions v-if="can.create">
            <a :href="urls.create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> إضافة فرع</a>
        </template>
    </PageHeader>

    <section class="branch-overview">
        <div class="overview-copy">
            <span class="overview-icon"><i class="bi bi-diagram-3-fill"></i></span>
            <div><strong>كل فرع مساحة تشغيل مستقلة</strong><p>الموظف يرى الفروع المعيّن إليها فقط، والتوريد والمخزون والمبيعات تبقى مرتبطة بفرعها.</p></div>
        </div>
        <div class="overview-stats">
            <div><strong>{{ stats.total }}</strong><small>فرع</small></div>
            <div class="success"><strong>{{ stats.active }}</strong><small>يعمل</small></div>
            <div :class="{ warning: stats.inactive }"><strong>{{ stats.inactive }}</strong><small>متوقف</small></div>
            <div><strong>{{ stats.staff }}</strong><small>موظف</small></div>
        </div>
    </section>

    <DataPanel title="قائمة الفروع" :count="branches.total" icon="bi-building">
        <template #actions>
            <button v-if="hasFilters" type="button" class="clear-btn" @click="clear"><i class="bi bi-x-circle"></i> مسح</button>
        </template>

        <div class="branch-toolbar">
            <form class="search-box" @submit.prevent="visit">
                <i class="bi bi-search"></i>
                <input v-model="form.search" aria-label="البحث في الفروع" placeholder="ابحث بالاسم أو الرمز أو المدينة…" />
                <button type="submit">بحث</button>
            </form>
            <div class="status-tabs" role="tablist" aria-label="حالة الفروع">
                <button type="button" :class="{ active: form.status === '' }" @click="setStatus('')">الكل <span>{{ stats.total }}</span></button>
                <button type="button" :class="{ active: form.status === 'active' }" @click="setStatus('active')">تعمل <span>{{ stats.active }}</span></button>
                <button type="button" :class="{ active: form.status === 'inactive' }" @click="setStatus('inactive')">متوقفة <span>{{ stats.inactive }}</span></button>
            </div>
        </div>

        <div v-if="branches.data.length" class="branch-grid">
            <article v-for="branch in branches.data" :key="branch.id" class="branch-card"
                     :class="{ inactive: !branch.isActive, current: branch.isCurrent }">
                <header class="branch-head">
                    <span class="branch-mark"><i class="bi bi-building"></i></span>
                    <div class="branch-title">
                        <div><strong>{{ branch.name }}</strong><span v-if="branch.isCurrent">فرعك الحالي</span></div>
                        <small>{{ branch.code }}<template v-if="branch.city"> · {{ branch.city }}</template></small>
                    </div>
                    <span class="state-pill" :class="{ on: branch.isActive }"><i></i>{{ branch.isActive ? 'مفعّل' : 'متوقف' }}</span>
                </header>

                <div class="branch-metrics">
                    <div><i class="bi bi-people-fill"></i><span><strong>{{ branch.usersCount }}</strong><small>موظف</small></span></div>
                    <div><i class="bi bi-journal-text"></i><span><strong>{{ branch.menuItems }}</strong><small>صنف منيو</small></span></div>
                    <div><i class="bi bi-grid"></i><span><strong>{{ branch.menuCategories }}</strong><small>قسم</small></span></div>
                </div>

                <div v-if="branch.phone || branch.city" class="branch-contact">
                    <span v-if="branch.city"><i class="bi bi-geo-alt"></i>{{ branch.city }}</span>
                    <span v-if="branch.phone"><i class="bi bi-telephone"></i>{{ branch.phone }}</span>
                </div>

                <div class="branch-legal" :class="{ missing: !branch.ownersCount }">
                    <i class="bi" :class="branch.ownersCount ? 'bi-person-vcard-fill' : 'bi-exclamation-circle'"></i>
                    <span v-if="branch.ownersCount"><strong>{{ branch.ownerNames.join('، ') }}</strong><small>{{ branch.ownersCount > 1 ? `${branch.ownersCount} ملاك مرتبطون` : 'مالك الفرع' }}<template v-if="branch.legalName"> · {{ branch.legalName }}</template></small></span>
                    <span v-else><strong>الملكية غير مسجلة</strong><small>افتح بيانات الفرع وأضف المالك القانوني.</small></span>
                    <b v-if="branch.taxNumber">رقم ضريبي مسجل</b>
                </div>

                <div v-if="branch.menuItems === 0" class="empty-menu">
                    <i class="bi bi-info-circle-fill"></i><span><strong>المنيو فارغ</strong><small>يمكن نسخه من فرع جاهز بكبسة واحدة.</small></span>
                </div>

                <footer class="branch-actions">
                    <a v-if="branch.can.update" :href="branch.urls.edit" class="edit-link"><i class="bi bi-pencil-square"></i> تعديل بيانات الفرع</a>
                    <button v-if="canCopyMenu(branch) && branch.can.update" type="button" class="copy-link" @click="openCopy(branch)"><i class="bi bi-files"></i> نسخ المنيو</button>
                    <details v-if="branch.can.update || branch.can.delete" class="more-menu">
                        <summary aria-label="إجراءات أخرى"><i class="bi bi-three-dots"></i></summary>
                        <div>
                            <button v-if="branch.can.update" type="button" @click="toggle(branch)"><i class="bi" :class="branch.isActive ? 'bi-pause-circle' : 'bi-play-circle'"></i>{{ branch.isActive ? 'تعطيل الفرع' : 'تفعيل الفرع' }}</button>
                            <button v-if="branch.can.delete" type="button" class="danger" :class="{ blocked: branch.blocksDelete }" @click="destroy(branch)"><i class="bi" :class="branch.blocksDelete ? 'bi-lock-fill' : 'bi-trash3'"></i>{{ branch.blocksDelete ? 'الحذف مقفل' : 'حذف الفرع' }}</button>
                        </div>
                    </details>
                </footer>
            </article>
        </div>

        <EmptyState v-else icon="bi-building" title="لا توجد فروع مطابقة"
                    :message="hasFilters ? 'غيّر البحث أو امسح الفلاتر.' : 'ابدأ بإضافة الفرع الأول.'" />

        <template #footer><Pagination :links="branches.links" /></template>
    </DataPanel>

    <Teleport to="body">
        <Transition name="sheet">
            <div v-if="copying" class="sheet-backdrop" @click.self="copying = null">
                <form class="copy-sheet" role="dialog" aria-modal="true" @submit.prevent="submitCopy">
                    <header><span><i class="bi bi-files"></i></span><div><h3>نسخ المنيو إلى «{{ copying.name }}»</h3><p>اختر فرعاً جاهزاً؛ لن تُمس أي قائمة موجودة.</p></div><button type="button" aria-label="إغلاق" @click="copying = null"><i class="bi bi-x-lg"></i></button></header>
                    <label><span>الفرع المصدر</span><select v-model="sourceId" class="form-select" required><option value="">— اختر الفرع —</option><option v-for="source in sourceBranches.filter(source => source.id !== copying.id)" :key="source.id" :value="source.id">{{ source.name }}</option></select></label>
                    <div class="sheet-actions"><button type="button" @click="copying = null">تراجع</button><button type="submit" class="primary" :disabled="!sourceId"><i class="bi bi-files"></i> ابدأ النسخ</button></div>
                </form>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.branch-overview{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:10px;padding:13px 15px;border:1px solid #d8e6dc;border-radius:16px;background:linear-gradient(120deg,#f0f8f2,#fff);box-shadow:0 8px 25px rgba(24,57,36,.035)}.overview-copy{display:flex;align-items:center;gap:11px}.overview-icon{display:grid;flex:0 0 42px;height:42px;place-items:center;border-radius:13px;background:#dff0e4;color:#176b39;font-size:1rem}.overview-copy>div{display:grid}.overview-copy strong{color:#203328;font-size:.74rem}.overview-copy p{margin:2px 0 0;color:#718077;font-size:.59rem}.overview-stats{display:flex;align-items:center}.overview-stats>div{display:grid;min-width:70px;padding:2px 14px;border-inline-start:1px solid #e2e9e4;text-align:center}.overview-stats strong{color:#24372b;font-size:.82rem}.overview-stats small{color:#829087;font-size:.54rem}.overview-stats .success strong{color:#16834a}.overview-stats .warning strong{color:#b36d08}.clear-btn{display:inline-flex;min-height:36px;align-items:center;gap:5px;padding:0 10px;border:1px solid #dfe7e2;border-radius:9px;background:#fff;color:#68776e;font-size:.6rem;font-weight:800}.branch-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.search-box{display:flex;flex:1;max-width:540px;min-height:44px;align-items:center;gap:8px;padding-inline-start:12px;border:1px solid #dfe7e2;border-radius:12px;background:#fff}.search-box>i{color:#809087}.search-box input{min-width:0;flex:1;border:0;outline:0;background:transparent;font:inherit;font-size:.66rem}.search-box button{align-self:stretch;padding:0 15px;border:0;border-radius:10px 0 0 10px;background:#eef6f0;color:#176b39;font-size:.61rem;font-weight:850}.status-tabs{display:flex;gap:4px;padding:4px;border-radius:12px;background:#f1f5f2}.status-tabs button{display:flex;min-height:36px;align-items:center;gap:6px;padding:0 11px;border:0;border-radius:9px;background:transparent;color:#6e7d73;font-size:.61rem;font-weight:800}.status-tabs button span{display:grid;min-width:20px;height:20px;place-items:center;border-radius:999px;background:#fff;color:#69776f;font-size:.52rem}.status-tabs button.active{background:#fff;color:#176b39;box-shadow:0 2px 8px rgba(30,66,43,.08)}.branch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(315px,100%),1fr));gap:10px}.branch-card{position:relative;display:flex;min-height:232px;flex-direction:column;gap:11px;padding:14px;border:1px solid #e0e7e2;border-radius:16px;background:#fff;transition:.15s}.branch-card:hover{border-color:#bcd2c3;box-shadow:0 9px 26px rgba(29,61,40,.07)}.branch-card.current{border-color:#8fbd9c;box-shadow:inset 0 3px #228650}.branch-card.inactive{background:#f8faf9}.branch-head{display:flex;align-items:center;gap:9px}.branch-mark{display:grid;flex:0 0 40px;height:40px;place-items:center;border-radius:12px;background:#eaf5ed;color:#176b39}.branch-card.inactive .branch-mark{background:#edf0ee;color:#7e8a83}.branch-title{display:grid;flex:1;min-width:0}.branch-title>div{display:flex;align-items:center;gap:6px}.branch-title strong{overflow:hidden;color:#1e3025;font-size:.74rem;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.branch-title>div span{border-radius:999px;padding:2px 6px;background:#edf7f0;color:#176b39;font-size:.48rem;font-weight:850;white-space:nowrap}.branch-title small{margin-top:2px;color:#85928a;font-size:.55rem;text-align:end}.state-pill{display:flex;align-items:center;gap:5px;padding:4px 7px;border-radius:999px;background:#f0f2f1;color:#77837c;font-size:.52rem;font-weight:850}.state-pill i{width:6px;height:6px;border-radius:50%;background:#9aa49e}.state-pill.on{background:#e8f7ec;color:#137b43}.state-pill.on i{background:#20a45a}.branch-metrics{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid #edf1ee;border-radius:12px;background:#fafcfb}.branch-metrics>div{display:flex;align-items:center;justify-content:center;gap:7px;padding:9px 5px;border-inline-start:1px solid #edf1ee}.branch-metrics>div:first-child{border-inline-start:0}.branch-metrics i{color:#7c8b82;font-size:.8rem}.branch-metrics span{display:grid}.branch-metrics strong{color:#2a3d31;font-size:.67rem}.branch-metrics small{color:#89958e;font-size:.49rem}.branch-contact{display:flex;flex-wrap:wrap;gap:12px;color:#748279;font-size:.56rem}.branch-contact span{display:flex;align-items:center;gap:5px}.empty-menu{display:flex;align-items:flex-start;gap:8px;padding:9px;border-radius:11px;background:#fff8e8;color:#986007}.empty-menu>span{display:grid}.empty-menu strong{font-size:.59rem}.empty-menu small{font-size:.52rem}.branch-actions{position:relative;display:flex;align-items:center;gap:6px;margin-top:auto}.edit-link,.copy-link{display:inline-flex;min-height:38px;align-items:center;justify-content:center;gap:6px;padding:0 11px;border:1px solid #cfe0d4;border-radius:10px;background:#f2f8f4;color:#176b39;font-size:.59rem;font-weight:850;text-decoration:none}.edit-link{flex:1}.copy-link{background:#fff}.more-menu{position:relative}.more-menu summary{display:grid;width:38px;height:38px;place-items:center;border:1px solid #dfe7e2;border-radius:10px;background:#fff;color:#64736a;cursor:pointer;list-style:none}.more-menu summary::-webkit-details-marker{display:none}.more-menu>div{position:absolute;z-index:15;bottom:44px;inset-inline-end:0;width:160px;padding:5px;border:1px solid #dfe7e2;border-radius:11px;background:#fff;box-shadow:0 14px 35px rgba(24,46,31,.16)}.more-menu button{display:flex;width:100%;min-height:38px;align-items:center;gap:7px;padding:0 9px;border:0;border-radius:8px;background:#fff;color:#536158;font-size:.59rem;font-weight:800;text-align:start}.more-menu button:hover{background:#f3f6f4}.more-menu button.danger{color:#b42318}.more-menu button.blocked{color:#8b9690}.sheet-backdrop{position:fixed;inset:0;z-index:18000;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.5)}.copy-sheet{width:min(450px,100%);padding:16px;border-radius:18px;background:#fff;box-shadow:0 25px 70px rgba(0,0,0,.22)}.copy-sheet header{display:flex;align-items:flex-start;gap:10px}.copy-sheet header>span{display:grid;flex:0 0 40px;height:40px;place-items:center;border-radius:12px;background:#eaf5ed;color:#176b39}.copy-sheet header>div{display:grid;flex:1}.copy-sheet h3{margin:0;color:#1d3024;font-size:.8rem;font-weight:900}.copy-sheet header p{margin:3px 0 0;color:#79877e;font-size:.58rem}.copy-sheet header>button{display:grid;width:36px;height:36px;place-items:center;border:0;border-radius:10px;background:#f2f5f3;color:#68776e}.copy-sheet label{display:grid;gap:5px;margin:15px 0}.copy-sheet label>span{font-size:.63rem;font-weight:850}.copy-sheet .form-select{min-height:44px;border-radius:11px;font-size:.67rem}.sheet-actions{display:flex;gap:8px}.sheet-actions button{flex:1;min-height:44px;border:0;border-radius:11px;background:#f1f4f2;color:#5e6c63;font-size:.63rem;font-weight:850}.sheet-actions button.primary{background:rgb(var(--primary-rgb));color:#fff}.sheet-actions button:disabled{opacity:.5}.sheet-enter-active,.sheet-leave-active{transition:opacity .15s}.sheet-enter-from,.sheet-leave-to{opacity:0}
.branch-legal{display:flex;align-items:center;gap:8px;padding:9px;border-radius:11px;color:#27613c;background:#eef7f1}.branch-legal>span{display:grid;min-width:0;flex:1}.branch-legal strong{overflow:hidden;font-size:.58rem;text-overflow:ellipsis;white-space:nowrap}.branch-legal small{color:#718078;font-size:.5rem}.branch-legal>b{padding:3px 6px;border-radius:999px;color:#176b39;background:#fff;font-size:.47rem;white-space:nowrap}.branch-legal.missing{color:#9a650b;background:#fff8e8}.branch-legal.missing strong{color:#8d5a06}
@media(max-width:800px){.branch-overview{align-items:flex-start;flex-direction:column}.overview-stats{width:100%}.overview-stats>div{flex:1}.branch-toolbar{align-items:stretch;flex-direction:column}.search-box{max-width:none}.status-tabs button{flex:1;justify-content:center}}
@media(max-width:520px){.overview-copy p{line-height:1.7}.overview-stats>div{min-width:0;padding-inline:7px}.branch-grid{grid-template-columns:1fr}.status-tabs button{padding-inline:5px}.branch-card{min-height:auto}.branch-metrics small{display:none}.search-box button{padding-inline:11px}}
</style>
