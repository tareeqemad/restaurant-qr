<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
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
const resultCopy = computed(() => hasFilters.value
    ? `${props.branches.total} نتيجة مطابقة للبحث`
    : `${props.branches.total} فرعاً ضمن المنشأة`);

const visit = () => router.get(props.urls.index, {
    search: form.search || undefined,
    status: form.status || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

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
const canCopyMenu = (branch) => branch.menuItems === 0
    && props.sourceBranches.some((source) => source.id !== branch.id);

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

    <PageHeader title="الفروع" icon="bi-diagram-3-fill"
                subtitle="راقب جاهزية كل فرع وافتح إدارته بدون خلط الموظفين أو المخزون أو الفواتير">
        <template v-if="can.create" #actions>
            <a :href="urls.create" class="branch-create"><i class="bi bi-plus-lg"></i> إضافة فرع جديد</a>
        </template>
    </PageHeader>

    <section class="branch-command" aria-label="ملخص الفروع">
        <div class="command-copy">
            <span><i class="bi bi-shop"></i></span>
            <div>
                <small>هيكلة المنشأة</small>
                <h2>كل فرع مساحة تشغيل ودفتر مستقل</h2>
                <p>الفريق، المنيو، المخزون والمبيعات تبقى مرتبطة بفرعها؛ الإدارة هنا لا تخلط الأرصدة أو الصلاحيات.</p>
            </div>
        </div>
        <div class="command-stats">
            <article>
                <i class="bi bi-building"></i>
                <span><strong>{{ stats.total }}</strong><small>إجمالي الفروع</small></span>
            </article>
            <article class="is-good">
                <i class="bi bi-check-circle-fill"></i>
                <span><strong>{{ stats.active }}</strong><small>تعمل الآن</small></span>
            </article>
            <article :class="{ 'is-warning': stats.inactive }">
                <i class="bi bi-pause-circle"></i>
                <span><strong>{{ stats.inactive }}</strong><small>متوقفة</small></span>
            </article>
            <article>
                <i class="bi bi-people-fill"></i>
                <span><strong>{{ stats.staff }}</strong><small>موظف مرتبط</small></span>
            </article>
        </div>
    </section>

    <section class="branch-workspace">
        <header class="workspace-head">
            <div class="workspace-title">
                <span><i class="bi bi-building"></i></span>
                <div><h2>دليل الفروع</h2><p>{{ resultCopy }}</p></div>
            </div>
            <button v-if="hasFilters" type="button" class="clear-btn" @click="clear">
                <i class="bi bi-arrow-counterclockwise"></i> مسح البحث والفلاتر
            </button>
        </header>

        <div class="branch-toolbar">
            <form class="search-box" @submit.prevent="visit">
                <i class="bi bi-search"></i>
                <input v-model.trim="form.search" type="search" aria-label="البحث في الفروع"
                       placeholder="ابحث باسم الفرع، الرمز، المدينة أو المالك…" />
                <button v-if="form.search" type="button" aria-label="مسح البحث" class="search-clear"
                        @click="form.search = ''; visit()"><i class="bi bi-x-lg"></i></button>
                <button type="submit" class="search-submit"><i class="bi bi-search"></i><span>بحث</span></button>
            </form>
            <div class="status-tabs" role="group" aria-label="تصفية الفروع حسب الحالة">
                <button type="button" :class="{ active: form.status === '' }" @click="setStatus('')">
                    الكل <span>{{ stats.total }}</span>
                </button>
                <button type="button" :class="{ active: form.status === 'active' }" @click="setStatus('active')">
                    تعمل <span>{{ stats.active }}</span>
                </button>
                <button type="button" :class="{ active: form.status === 'inactive' }" @click="setStatus('inactive')">
                    متوقفة <span>{{ stats.inactive }}</span>
                </button>
            </div>
        </div>

        <div v-if="branches.data.length" class="branch-grid">
            <article v-for="branch in branches.data" :key="branch.id" class="branch-card"
                     :class="{ 'is-inactive': ! branch.isActive, 'is-current': branch.isCurrent }">
                <header class="branch-card-head">
                    <span class="branch-mark"><i class="bi" :class="branch.isCurrent ? 'bi-shop' : 'bi-building'"></i></span>
                    <div class="branch-identity">
                        <div>
                            <h3>{{ branch.name }}</h3>
                            <b v-if="branch.isCurrent"><i class="bi bi-geo-alt-fill"></i> فرعك الحالي</b>
                        </div>
                        <p><bdi>{{ branch.code }}</bdi><template v-if="branch.city"> · {{ branch.city }}</template></p>
                    </div>
                    <span class="state-pill" :class="{ active: branch.isActive }">
                        <i></i>{{ branch.isActive ? 'يعمل' : 'متوقف' }}
                    </span>
                </header>

                <div class="branch-metrics" aria-label="أرقام الفرع">
                    <div><i class="bi bi-people-fill"></i><span><strong>{{ branch.usersCount }}</strong><small>موظف</small></span></div>
                    <div><i class="bi bi-journal-text"></i><span><strong>{{ branch.menuItems }}</strong><small>صنف منيو</small></span></div>
                    <div><i class="bi bi-grid"></i><span><strong>{{ branch.menuCategories }}</strong><small>تصنيف</small></span></div>
                    <div><i class="bi bi-person-badge"></i><span><strong>{{ branch.rolesCount }}</strong><small>دور مخصص</small></span></div>
                </div>

                <div class="branch-facts">
                    <div class="fact-location">
                        <i class="bi bi-geo-alt"></i>
                        <span><small>الموقع والتواصل</small><strong>{{ branch.city || 'المدينة غير مسجلة' }}</strong></span>
                        <bdi v-if="branch.phone">{{ branch.phone }}</bdi>
                    </div>
                    <div class="fact-legal" :class="{ missing: ! branch.ownersCount }">
                        <i class="bi" :class="branch.ownersCount ? 'bi-person-badge' : 'bi-exclamation-circle'"></i>
                        <span v-if="branch.ownersCount">
                            <small>الملكية القانونية</small>
                            <strong>{{ branch.ownerNames.join('، ') }}</strong>
                            <em>{{ branch.legalName || (branch.ownersCount > 1 ? `${branch.ownersCount} ملاك مرتبطون` : 'مالك مسجل') }}</em>
                        </span>
                        <span v-else>
                            <small>الملكية القانونية</small>
                            <strong>تحتاج استكمال</strong>
                            <em>أضف المالك والاسم القانوني من تعديل الفرع.</em>
                        </span>
                        <b v-if="branch.taxNumber"><i class="bi bi-shield-check"></i> ضريبي</b>
                    </div>
                </div>

                <div v-if="branch.menuItems === 0" class="setup-note">
                    <i class="bi bi-info-circle-fill"></i>
                    <span><strong>المنيو فارغ</strong><small>ابدأ من الصفر أو انسخه من فرع جاهز.</small></span>
                    <button v-if="canCopyMenu(branch) && branch.can.update" type="button" @click="openCopy(branch)">
                        <i class="bi bi-files"></i> نسخ منيو
                    </button>
                </div>

                <footer class="branch-actions">
                    <a v-if="branch.can.update" :href="branch.urls.edit" class="edit-link">
                        <i class="bi bi-pencil-square"></i><span>فتح وإدارة بيانات الفرع</span>
                    </a>
                    <button v-if="canCopyMenu(branch) && branch.can.update && branch.menuItems !== 0" type="button"
                            class="copy-link" @click="openCopy(branch)"><i class="bi bi-files"></i> نسخ المنيو</button>
                    <details v-if="branch.can.update || branch.can.delete" class="more-menu">
                        <summary aria-label="إجراءات أخرى"><i class="bi bi-three-dots"></i></summary>
                        <div>
                            <button v-if="branch.can.update" type="button" @click="toggle(branch)">
                                <i class="bi" :class="branch.isActive ? 'bi-pause-circle' : 'bi-play-circle'"></i>
                                {{ branch.isActive ? 'تعطيل الفرع' : 'تفعيل الفرع' }}
                            </button>
                            <button v-if="branch.can.delete" type="button" class="danger"
                                    :class="{ blocked: branch.blocksDelete }" @click="destroy(branch)">
                                <i class="bi" :class="branch.blocksDelete ? 'bi-lock-fill' : 'bi-trash3'"></i>
                                {{ branch.blocksDelete ? 'الحذف مقفل لوجود موظفين' : 'حذف الفرع' }}
                            </button>
                        </div>
                    </details>
                </footer>
            </article>
        </div>

        <EmptyState v-else icon="bi-building" title="لا توجد فروع مطابقة"
                    :message="hasFilters ? 'غيّر البحث أو امسح الفلاتر لرؤية بقية الفروع.' : 'ابدأ بإضافة الفرع الأول.'">
            <template v-if="hasFilters" #cta>
                <button type="button" class="empty-clear" @click="clear"><i class="bi bi-x-circle"></i> مسح الفلاتر</button>
            </template>
        </EmptyState>

        <footer v-if="branches.links?.length > 3" class="workspace-pagination">
            <Pagination :links="branches.links" />
        </footer>
    </section>

    <Teleport to="body">
        <Transition name="sheet">
            <div v-if="copying" class="sheet-backdrop" @click.self="copying = null">
                <form class="copy-sheet" role="dialog" aria-modal="true" @submit.prevent="submitCopy">
                    <header>
                        <span><i class="bi bi-files"></i></span>
                        <div><h3>نسخ المنيو إلى «{{ copying.name }}»</h3><p>اختر فرعاً جاهزاً؛ لن تُمس أي قائمة موجودة.</p></div>
                        <button type="button" aria-label="إغلاق" @click="copying = null"><i class="bi bi-x-lg"></i></button>
                    </header>
                    <label>
                        <span>الفرع المصدر</span>
                        <select v-model="sourceId" class="form-select" required>
                            <option value="">— اختر الفرع —</option>
                            <option v-for="source in sourceBranches.filter(source => source.id !== copying.id)"
                                    :key="source.id" :value="source.id">{{ source.name }}</option>
                        </select>
                    </label>
                    <div class="sheet-actions">
                        <button type="button" @click="copying = null">تراجع</button>
                        <button type="submit" class="primary" :disabled="! sourceId"><i class="bi bi-files"></i> ابدأ النسخ</button>
                    </div>
                </form>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.branch-create { display:inline-flex;min-height:44px;align-items:center;justify-content:center;gap:.45rem;padding-inline:1rem;border-radius:11px;background:rgb(var(--primary-rgb));color:#fff;text-decoration:none;font-size:.72rem;font-weight:850;box-shadow:0 10px 22px -15px rgba(20,105,63,.9) }
.branch-command { display:grid;grid-template-columns:minmax(320px,1.25fr) minmax(520px,1fr);gap:16px;margin-bottom:14px;padding:16px;border:1px solid #d9e7dd;border-radius:18px;background:linear-gradient(125deg,#f2f9f4 0%,#fff 58%,#f7faf8 100%);box-shadow:0 10px 30px rgba(22,61,37,.045) }
.command-copy { display:flex;align-items:center;gap:13px;min-width:0 }
.command-copy>span { display:grid;flex:0 0 52px;height:52px;place-items:center;border-radius:15px;background:#dff1e5;color:#126b3b;font-size:1.25rem;box-shadow:inset 0 0 0 1px rgba(21,112,63,.07) }
.command-copy>div { display:grid;gap:2px;min-width:0 }
.command-copy small { color:#2b7a4e;font-size:.56rem;font-weight:850 }
.command-copy h2 { margin:0;color:#1d3325;font-size:.88rem;font-weight:900 }
.command-copy p { margin:0;color:#718078;font-size:.61rem;line-height:1.75 }
.command-stats { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));overflow:hidden;border:1px solid #e0e9e3;border-radius:14px;background:rgba(255,255,255,.82) }
.command-stats article { display:flex;align-items:center;justify-content:center;gap:8px;min-width:0;padding:10px;border-inline-start:1px solid #e7ede9 }
.command-stats article:first-child { border-inline-start:0 }
.command-stats article>i { display:grid;flex:0 0 31px;height:31px;place-items:center;border-radius:9px;background:#eef3f0;color:#617169 }
.command-stats article>span { display:grid;min-width:0 }
.command-stats strong { color:#273b2f;font-size:.84rem;font-variant-numeric:tabular-nums }
.command-stats small { overflow:hidden;color:#859188;font-size:.5rem;text-overflow:ellipsis;white-space:nowrap }
.command-stats .is-good i,.command-stats .is-good strong { color:#167945 }.command-stats .is-good i { background:#e8f6ec }
.command-stats .is-warning i,.command-stats .is-warning strong { color:#a86a0d }.command-stats .is-warning i { background:#fff5df }

.branch-workspace { overflow:hidden;border:1px solid #dce6e0;border-radius:18px;background:#fff;box-shadow:0 12px 36px rgba(25,58,38,.05) }
.workspace-head { display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #e8eeea }
.workspace-title { display:flex;align-items:center;gap:10px }
.workspace-title>span { display:grid;width:40px;height:40px;place-items:center;border-radius:12px;background:#eaf5ed;color:#176c3d;font-size:.95rem }
.workspace-title>div { display:grid;gap:1px }.workspace-title h2 { margin:0;color:#1f3326;font-size:.82rem;font-weight:900 }.workspace-title p { margin:0;color:#829087;font-size:.56rem }
.clear-btn,.empty-clear { display:inline-flex;min-height:38px;align-items:center;gap:6px;padding-inline:11px;border:1px solid #dfe7e2;border-radius:10px;background:#fff;color:#68776e;font:inherit;font-size:.59rem;font-weight:800 }
.branch-toolbar { display:grid;grid-template-columns:minmax(300px,1fr) auto;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid #edf2ef;background:#f8faf9 }
.search-box { display:grid;grid-template-columns:24px minmax(0,1fr) 38px auto;align-items:center;min-height:48px;padding-inline:12px 5px;border:1px solid #d7e3dc;border-radius:13px;background:#fff;color:#788980 }
.search-box input { min-width:0;height:44px;border:0;outline:0;background:transparent;font:inherit;font-size:.68rem }
.search-box button { border:0;font:inherit }.search-clear { display:grid;width:34px;height:34px;place-items:center;border-radius:9px;background:#f2f5f3;color:#718078 }
.search-submit { display:inline-flex;min-width:78px;height:38px;align-items:center;justify-content:center;gap:5px;padding-inline:12px;border-radius:9px;background:#1a7048;color:#fff;font-size:.62rem;font-weight:850 }
.status-tabs { display:flex;gap:4px;padding:4px;border-radius:12px;background:#edf2ef }
.status-tabs button { display:flex;min-height:40px;align-items:center;justify-content:center;gap:6px;padding-inline:13px;border:0;border-radius:9px;background:transparent;color:#697970;font:inherit;font-size:.62rem;font-weight:800 }
.status-tabs button span { display:grid;min-width:21px;height:21px;place-items:center;border-radius:999px;background:#fff;color:#6c7972;font-size:.52rem }
.status-tabs button.active { background:#fff;color:#176c3d;box-shadow:0 3px 10px rgba(25,63,39,.08) }

.branch-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(min(430px,100%),1fr));gap:12px;padding:14px;background:#f7faf8 }
.branch-card { position:relative;display:flex;min-width:0;flex-direction:column;gap:12px;padding:15px;border:1px solid #dfe8e2;border-radius:16px;background:#fff;box-shadow:0 4px 16px rgba(24,59,37,.025);transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease }
.branch-card:hover { border-color:#afd0bb;box-shadow:0 12px 30px rgba(27,64,40,.075);transform:translateY(-1px) }
.branch-card.is-current { border-color:#81bd96;box-shadow:inset 0 3px #238452,0 8px 24px rgba(27,90,53,.06) }
.branch-card.is-inactive { border-style:dashed;background:#fafbfa }.branch-card.is-inactive .branch-mark { background:#eef1ef;color:#7d8982 }
.branch-card-head { display:flex;align-items:center;gap:10px;min-width:0 }
.branch-mark { display:grid;flex:0 0 46px;height:46px;place-items:center;border-radius:14px;background:#e7f4eb;color:#156d3d;font-size:1.05rem }
.branch-identity { display:grid;flex:1;gap:2px;min-width:0 }.branch-identity>div { display:flex;align-items:center;gap:7px;min-width:0 }
.branch-identity h3 { overflow:hidden;margin:0;color:#1c3023;font-size:.82rem;font-weight:900;text-overflow:ellipsis;white-space:nowrap }
.branch-identity b { display:inline-flex;flex:0 0 auto;align-items:center;gap:3px;padding:3px 6px;border-radius:999px;background:#e9f6ed;color:#147542;font-size:.48rem }
.branch-identity p { margin:0;color:#87938c;font-size:.55rem }.branch-identity p bdi { font-variant-numeric:tabular-nums }
.state-pill { display:inline-flex;flex:0 0 auto;align-items:center;gap:5px;padding:5px 8px;border-radius:999px;background:#f0f2f1;color:#77837c;font-size:.52rem;font-weight:850 }
.state-pill i { width:7px;height:7px;border-radius:50%;background:#9ba59f }.state-pill.active { background:#e7f6ec;color:#137a43 }.state-pill.active i { background:#1da157;box-shadow:0 0 0 3px rgba(29,161,87,.12) }
.branch-metrics { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));overflow:hidden;border:1px solid #e7ede9;border-radius:12px;background:#fafcfb }
.branch-metrics>div { display:flex;align-items:center;justify-content:center;gap:7px;min-width:0;padding:10px 6px;border-inline-start:1px solid #e7ede9 }.branch-metrics>div:first-child { border-inline-start:0 }
.branch-metrics i { color:#718078;font-size:.78rem }.branch-metrics span { display:grid;min-width:0 }.branch-metrics strong { color:#263a2e;font-size:.68rem;font-variant-numeric:tabular-nums }.branch-metrics small { overflow:hidden;color:#89958e;font-size:.48rem;text-overflow:ellipsis;white-space:nowrap }
.branch-facts { display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.3fr);gap:8px }
.fact-location,.fact-legal { display:flex;align-items:center;gap:8px;min-width:0;padding:10px;border-radius:11px;background:#f7faf8;color:#53675b }
.fact-location>i,.fact-legal>i { display:grid;flex:0 0 31px;height:31px;place-items:center;border-radius:9px;background:#eaf2ed;color:#276b46 }
.fact-location>span,.fact-legal>span { display:grid;min-width:0;flex:1 }.fact-location small,.fact-legal small { color:#8a978f;font-size:.48rem }.fact-location strong,.fact-legal strong { overflow:hidden;color:#30463a;font-size:.59rem;text-overflow:ellipsis;white-space:nowrap }
.fact-location>bdi { color:#68786f;font-size:.52rem;white-space:nowrap }.fact-legal em { overflow:hidden;color:#75847b;font-size:.49rem;font-style:normal;text-overflow:ellipsis;white-space:nowrap }
.fact-legal>b { display:inline-flex;align-items:center;gap:3px;padding:3px 6px;border-radius:999px;background:#fff;color:#197045;font-size:.46rem;white-space:nowrap }.fact-legal.missing { background:#fff8e8;color:#9b650c }.fact-legal.missing>i { background:#fff0c9;color:#a66b08 }.fact-legal.missing strong { color:#8c5908 }
.setup-note { display:flex;align-items:center;gap:8px;padding:9px 10px;border:1px solid #f3dfb4;border-radius:11px;background:#fff9ec;color:#976006 }.setup-note>span { display:grid;flex:1 }.setup-note strong { font-size:.58rem }.setup-note small { color:#9b7b40;font-size:.5rem }.setup-note button { display:inline-flex;min-height:32px;align-items:center;gap:4px;padding-inline:9px;border:1px solid #ecd496;border-radius:8px;background:#fff;color:#8e5d0b;font:inherit;font-size:.52rem;font-weight:850 }
.branch-actions { position:relative;display:flex;align-items:center;gap:7px;margin-top:auto;padding-top:11px;border-top:1px solid #edf2ef }
.edit-link,.copy-link { display:inline-flex;min-height:42px;align-items:center;justify-content:center;gap:6px;padding-inline:12px;border:1px solid #c9dfd1;border-radius:10px;background:#eef7f1;color:#176d3f;text-decoration:none;font:inherit;font-size:.59rem;font-weight:850 }.edit-link { flex:1 }.copy-link { background:#fff }
.more-menu { position:relative }.more-menu summary { display:grid;width:42px;height:42px;place-items:center;border:1px solid #dfe7e2;border-radius:10px;background:#fff;color:#64736a;cursor:pointer;list-style:none }.more-menu summary::-webkit-details-marker { display:none }
.more-menu>div { position:absolute;z-index:15;bottom:48px;inset-inline-end:0;width:205px;padding:5px;border:1px solid #dfe7e2;border-radius:11px;background:#fff;box-shadow:0 16px 40px rgba(24,46,31,.17) }
.more-menu button { display:flex;width:100%;min-height:40px;align-items:center;gap:7px;padding-inline:10px;border:0;border-radius:8px;background:#fff;color:#536158;font:inherit;font-size:.58rem;font-weight:800;text-align:start }.more-menu button:hover { background:#f3f6f4 }.more-menu button.danger { color:#b42318 }.more-menu button.blocked { color:#8b9690 }
.workspace-pagination { padding:8px 14px;border-top:1px solid #e8eeea }.empty-clear { background:#eef7f1;color:#176d3f }

.sheet-backdrop { position:fixed;inset:0;z-index:18000;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.5) }.copy-sheet { width:min(450px,100%);padding:16px;border-radius:18px;background:#fff;box-shadow:0 25px 70px rgba(0,0,0,.22) }
.copy-sheet header { display:flex;align-items:flex-start;gap:10px }.copy-sheet header>span { display:grid;flex:0 0 40px;height:40px;place-items:center;border-radius:12px;background:#eaf5ed;color:#176b39 }.copy-sheet header>div { display:grid;flex:1 }.copy-sheet h3 { margin:0;color:#1d3024;font-size:.8rem;font-weight:900 }.copy-sheet header p { margin:3px 0 0;color:#79877e;font-size:.58rem }.copy-sheet header>button { display:grid;width:36px;height:36px;place-items:center;border:0;border-radius:10px;background:#f2f5f3;color:#68776e }
.copy-sheet label { display:grid;gap:5px;margin:15px 0 }.copy-sheet label>span { font-size:.63rem;font-weight:850 }.copy-sheet .form-select { min-height:44px;border-radius:11px;font-size:.67rem }.sheet-actions { display:flex;gap:8px }.sheet-actions button { flex:1;min-height:44px;border:0;border-radius:11px;background:#f1f4f2;color:#5e6c63;font:inherit;font-size:.63rem;font-weight:850 }.sheet-actions button.primary { background:rgb(var(--primary-rgb));color:#fff }.sheet-actions button:disabled { opacity:.5 }.sheet-enter-active,.sheet-leave-active { transition:opacity .15s }.sheet-enter-from,.sheet-leave-to { opacity:0 }

@media(max-width:1050px) {
    .branch-command { grid-template-columns:1fr }.branch-toolbar { grid-template-columns:1fr }.status-tabs { justify-self:start }.branch-grid { grid-template-columns:repeat(auto-fit,minmax(min(370px,100%),1fr)) }
}
@media(max-width:680px) {
    .branch-command { padding:13px }.command-copy { align-items:flex-start }.command-copy p { line-height:1.65 }.command-stats { grid-template-columns:repeat(2,1fr) }.command-stats article:nth-child(3) { border-top:1px solid #e7ede9 }.command-stats article:nth-child(4) { border-top:1px solid #e7ede9 }
    .workspace-head { align-items:flex-start }.clear-btn { width:40px;padding:0;font-size:0;justify-content:center }.clear-btn i { font-size:.8rem }.branch-toolbar { padding:10px }.status-tabs { width:100%;justify-self:stretch }.status-tabs button { flex:1;padding-inline:6px }.branch-grid { grid-template-columns:1fr;padding:10px }.branch-facts { grid-template-columns:1fr }.branch-card { padding:13px }.branch-metrics { grid-template-columns:repeat(2,1fr) }.branch-metrics>div:nth-child(3),.branch-metrics>div:nth-child(4) { border-top:1px solid #e7ede9 }.branch-metrics>div:nth-child(3) { border-inline-start:0 }.search-submit { min-width:42px }.search-submit span { display:none }
}
@media(max-width:420px) {
    .command-copy>span { flex-basis:44px;height:44px }.command-copy h2 { font-size:.77rem }.command-stats article { justify-content:flex-start }.branch-card-head { align-items:flex-start }.branch-identity>div { align-items:flex-start;flex-direction:column }.state-pill { margin-inline-start:auto }.setup-note { align-items:flex-start;flex-wrap:wrap }.setup-note button { margin-inline-start:39px }.edit-link span { overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
}
@media(prefers-reduced-motion:reduce) { .branch-card { transition:none } }
</style>
