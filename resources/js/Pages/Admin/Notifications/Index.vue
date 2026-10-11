<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useToast } from '../../../Composables/useToast';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    notifications: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const toast = useToast();
const busy = ref(null);
const loading = ref(false);
const actionError = ref('');
const filter = reactive({ ...props.filters });
const statuses = [
    { value: 'all', label: 'الكل' },
    { value: 'unread', label: 'غير المقروء' },
    { value: 'read', label: 'المقروء' },
];

const hasFilters = computed(() => filter.status !== 'all' || filter.type || filter.severity);
const emptyCopy = computed(() => hasFilters.value
    ? { title: 'لا توجد نتائج مطابقة', message: 'جرّب مسح الفلاتر لعرض بقية الإشعارات.' }
    : { title: 'صندوقك مرتب', message: 'لا يوجد ما يحتاج انتباهك الآن. ستظهر التنبيهات الجديدة هنا.' });
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const typeMeta = (key) => props.types.find((type) => type.value === key);

function setLoading(value) {
    loading.value = value;
    if (value) actionError.value = '';
}

function visit(patch = {}) {
    Object.assign(filter, patch);
    router.get(props.urls.index, {
        status: filter.status,
        type: filter.type || undefined,
        severity: filter.severity || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
    });
}

function clear() {
    visit({ status: 'all', type: '', severity: '' });
}

function reloadInbox() {
    router.reload({
        only: ['notifications', 'stats'],
        preserveScroll: true,
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
    });
}

async function request(url, method = 'POST', payload = null) {
    const response = await fetch(url, {
        method,
        headers: {
            'X-CSRF-TOKEN': csrf(),
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: payload ? JSON.stringify(payload) : null,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'تعذر تنفيذ الإجراء. حاول مرة أخرى.');
    return data;
}

async function run(itemKey, callback, successMessage = '') {
    busy.value = itemKey;
    actionError.value = '';
    try {
        await callback();
        if (successMessage) toast.success(successMessage);
        return true;
    } catch (error) {
        actionError.value = error?.message || 'تعذر تنفيذ الإجراء. حاول مرة أخرى.';
        toast.error(actionError.value);
        return false;
    } finally {
        busy.value = null;
    }
}

async function markRead(item, navigate = false) {
    const completed = await run(item.id, async () => {
        if (!item.read) await request(`${props.urls.base}/${item.id}/read`);
    });
    if (!completed) return;
    if (navigate && item.action_url) {
        router.visit(item.action_url, {
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
        return;
    }
    reloadInbox();
}

async function markAll() {
    const completed = await run('all', () => request(props.urls.readAll), 'تم تعليم كل الإشعارات كمقروءة.');
    if (completed) reloadInbox();
}

async function dismiss(item) {
    const approved = await ask({
        title: 'حذف الإشعار؟',
        message: 'سيُزال من صندوقك فقط، ولن يتأثر الطلب أو الإجراء المرتبط به.',
        confirmLabel: 'حذف الإشعار',
        danger: true,
    });
    if (!approved) return;
    const completed = await run(item.id, () => request(`${props.urls.base}/${item.id}`, 'DELETE'), 'تم حذف الإشعار.');
    if (completed) reloadInbox();
}

async function quick(item) {
    const completed = await run(item.id, async () => {
        await request(item.quick_action.url, 'POST', item.quick_action.payload || {});
        if (!item.read) await request(`${props.urls.base}/${item.id}/read`);
    }, 'تم تنفيذ الإجراء بنجاح.');
    if (completed) reloadInbox();
}
</script>

<template>
    <Head title="الإشعارات" />
    <PageHeader title="الإشعارات" icon="bi-bell-fill" >
        <template #actions>
            <button v-if="stats.unread" class="btn btn-primary" type="button"
                    :disabled="busy === 'all' || loading" @click="markAll">
                <i class="bi" :class="busy === 'all' ? 'bi-arrow-repeat notification-spin' : 'bi-check2-all'"></i>
                قراءة الكل
            </button>
        </template>
    </PageHeader>

    <details class="ops-summary"><summary><i class="bi bi-bar-chart"></i> ملخص الإشعارات<i class="bi bi-chevron-down"></i></summary><StatRail :stats="[
        { label: 'غير مقروء', value: stats.unread, icon: 'bi-bell-fill', color: 'accent' },
        { label: 'وصل اليوم', value: stats.today, icon: 'bi-calendar-day-fill', color: 'success' },
        { label: 'طلبات جديدة', value: stats.newOrders, icon: 'bi-bag-plus-fill', color: 'primary' },
        { label: 'طلب فاتورة', value: stats.billRequests, icon: 'bi-receipt-cutoff', color: 'warning' },
        { label: 'مخزون منخفض', value: stats.lowStock, icon: 'bi-exclamation-triangle-fill', color: 'warning' },
    ]" /></details>

    <section class="inbox" :aria-busy="loading">
        <header class="inbox__head">
            <div>
                <span>صندوق المتابعة</span>
                <h2>{{ notifications.total }} إشعاراً</h2>
                <small v-if="stats.unread">{{ stats.unread }} منها لم يُقرأ بعد</small>
                <small v-else>اطلعت على كل الإشعارات</small>
            </div>
            <button v-if="hasFilters" type="button" class="clear-filters" @click="clear">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </button>
        </header>

        <div class="filters" aria-label="تصفية الإشعارات">
            <div class="status-tabs" role="group" aria-label="حالة القراءة">
                <button v-for="status in statuses" :key="status.value" type="button"
                        :class="{ active: filter.status === status.value }"
                        :aria-pressed="filter.status === status.value"
                        @click="visit({ status: status.value })">{{ status.label }}</button>
            </div>
            <label><span>النوع</span><select v-model="filter.type" @change="visit()">
                <option value="">كل الأنواع</option>
                <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
            </select></label>
            <label><span>الأولوية</span><select v-model="filter.severity" @change="visit()">
                <option value="">كل المستويات</option><option value="danger">حرج</option>
                <option value="warning">تحذير</option><option value="success">نجاح</option><option value="info">معلومة</option>
            </select></label>
        </div>

        <div v-if="actionError" class="inbox-error" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i><span>{{ actionError }}</span>
            <button type="button" aria-label="إغلاق الرسالة" @click="actionError = ''"><i class="bi bi-x-lg"></i></button>
        </div>
        <div v-if="loading" class="inbox-progress" role="status" aria-live="polite">
            <i class="bi bi-arrow-repeat notification-spin"></i> جارٍ تحديث الصندوق…
        </div>

        <div v-if="notifications.data.length" class="notification-list">
            <article v-for="item in notifications.data" :key="item.id"
                     :class="[{ read: item.read, busy: busy === item.id }, `is-${item.severity}`]">
                <span class="marker" aria-hidden="true"></span>
                <span class="avatar" aria-hidden="true"><i class="bi" :class="item.icon"></i></span>
                <div class="notification-body">
                    <div class="notification-title"><strong>{{ item.title }}</strong><time>{{ item.created_at }}</time></div>
                    <p v-if="item.body">{{ item.body }}</p>
                    <span class="type"><i class="bi" :class="typeMeta(item.type_key)?.icon || 'bi-bell'"></i>{{ typeMeta(item.type_key)?.label || 'إشعار نظام' }}</span>
                </div>
                <div class="notification-actions">
                    <button v-if="item.quick_action" type="button" class="quick" :disabled="busy === item.id" @click="quick(item)"><i class="bi bi-lightning-charge-fill"></i>{{ item.quick_action.label }}</button>
                    <button v-if="item.action_url" type="button" :disabled="busy === item.id" @click="markRead(item, true)"><i class="bi bi-box-arrow-up-left"></i>{{ item.action_label || 'فتح التفاصيل' }}</button>
                    <button v-if="!item.read" type="button" :disabled="busy === item.id" title="تعليم كمقروء" aria-label="تعليم كمقروء" @click="markRead(item)"><i class="bi bi-check2"></i></button>
                    <button type="button" class="delete" :disabled="busy === item.id" title="حذف" aria-label="حذف الإشعار" @click="dismiss(item)"><i class="bi" :class="busy === item.id ? 'bi-arrow-repeat notification-spin' : 'bi-trash3'"></i></button>
                </div>
            </article>
        </div>

        <EmptyState v-else :icon="hasFilters ? 'bi-funnel' : 'bi-bell-slash'" :title="emptyCopy.title" :message="emptyCopy.message">
            <template v-if="hasFilters" #cta><button type="button" class="btn btn-light" @click="clear">عرض كل الإشعارات</button></template>
        </EmptyState>
        <footer v-if="notifications.links?.length"><Pagination :links="notifications.links" /></footer>
    </section>
</template>

<style scoped>
.inbox{position:relative;margin-top:.85rem;overflow:hidden;border:1px solid var(--line);border-radius:12px;background:var(--surface);box-shadow:none}
.inbox__head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.1rem;border-bottom:1px solid var(--line)}.inbox__head>div{display:grid;gap:.1rem}.inbox__head span{color:var(--brand);font-size:.875rem;font-weight:800}.inbox__head h2{margin:0;font-size:1.05rem;font-weight:800}.inbox__head small{color:var(--muted);font-size:.875rem}.clear-filters{min-height:38px;padding:.45rem .7rem;border:0;border-radius:10px;color:var(--brand);background:#f1f5f3;font-size:.875rem;font-weight:800}
.filters{display:grid;grid-template-columns:minmax(300px,1fr) minmax(180px,230px) minmax(160px,190px);gap:.65rem;padding:.7rem 1rem;border-bottom:1px solid var(--line);background:var(--canvas)}.filters label{display:grid;gap:.22rem;margin:0}.filters label span{color:var(--brand);font-size:.875rem;font-weight:800}.filters select{min-height:42px;padding:.45rem .65rem;border:1px solid var(--line);border-radius:8px;color:var(--brand);background:var(--surface);font-size:.875rem}.status-tabs{display:flex;align-self:end;min-height:42px;padding:.25rem;border-radius:11px;background:#edf3ef}.status-tabs button{flex:1;border:0;border-radius:8px;color:var(--brand);background:transparent;font-size:.875rem}.status-tabs button.active{color:var(--brand);background:var(--surface);box-shadow:none;font-weight:800}
.inbox-error{display:flex;align-items:center;gap:.55rem;margin:.7rem 1rem 0;padding:.65rem .75rem;border:1px solid #fecaca;border-radius:10px;color:#991b1b;background:#fff7f7;font-size:.875rem}.inbox-error span{flex:1}.inbox-error button{border:0;color:inherit;background:transparent}.inbox-progress{display:flex;align-items:center;justify-content:center;gap:.45rem;min-height:38px;border-bottom:1px solid var(--line);color:var(--brand);background:#f0faf4;font-size:.875rem;font-weight:800}
.notification-list article{display:grid;grid-template-columns:4px 44px minmax(0,1fr) auto;gap:.75rem;align-items:center;min-height:86px;padding:.85rem 1rem;border-bottom:1px solid var(--line);transition:background .15s ease,opacity .15s ease}.notification-list article:last-child{border-bottom:0}.notification-list article:hover{background:#fbfdfc}.notification-list article.read{opacity:.68}.notification-list article.busy{opacity:.5;pointer-events:none}.marker{align-self:stretch;border-radius:6px;background:var(--brand)}.is-warning .marker{background:#d49323}.is-danger .marker{background:#c64343}.is-success .marker{background:#219564}.avatar{display:grid;width:42px;height:42px;place-items:center;border-radius:12px;color:var(--brand);background:#eef6f1;font-size:1rem}.is-warning .avatar{color:#aa6d0b;background:#fff6e5}.is-danger .avatar{color:#b83838;background:#fff0f0}
.notification-body{min-width:0}.notification-title{display:flex;justify-content:space-between;gap:.6rem}.notification-title strong{font-size:.875rem}.notification-title time{color:var(--brand);font-size:.875rem;white-space:nowrap}.notification-body p{margin:.2rem 0 .38rem;color:var(--brand);font-size:.875rem;line-height:1.55}.type{display:inline-flex;align-items:center;gap:.25rem;color:var(--brand);font-size:.875rem}.notification-actions{display:flex;gap:.3rem}.notification-actions button{min-height:36px;padding:.38rem .56rem;border:1px solid var(--line);border-radius:8px;color:var(--brand);background:var(--surface);font-size:.875rem;white-space:nowrap}.notification-actions button:hover:not(:disabled){border-color:var(--brand);background:#f2faf5}.notification-actions .quick{border-color:var(--brand);color:#FFFDFA;background:var(--brand)}.notification-actions .delete{color:#ba3b3b}.notification-actions button:disabled{opacity:.55;cursor:wait}.inbox>footer{padding:.8rem;border-top:1px solid var(--line)}.notification-spin{display:inline-block;animation:notification-rotate 1s linear infinite}@keyframes notification-rotate{to{transform:rotate(360deg)}}
@media(max-width:1180px){.filters{grid-template-columns:1fr 1fr}.status-tabs{grid-column:1/-1}.notification-list article{grid-template-columns:4px 40px minmax(0,1fr);align-items:start}.notification-actions{grid-column:3;flex-wrap:wrap}}
@media(max-width:680px){.inbox__head{align-items:flex-start}.filters{grid-template-columns:1fr}.status-tabs{grid-column:auto}.notification-list article{grid-template-columns:4px 36px minmax(0,1fr);padding:.75rem .65rem}.avatar{width:36px;height:36px}.notification-title{display:grid}.notification-title time{grid-row:1}.notification-actions button{flex:1 1 auto;min-height:42px}}
@media(prefers-reduced-motion:reduce){.notification-list article{transition:none}}

/* Operational layout and responsive hierarchy. */
.ops-summary{margin-bottom:16px;border:1px solid var(--line);border-radius:8px;background:var(--surface)}.ops-summary>summary{display:flex;align-items:center;gap:10px;min-height:48px;padding:12px 16px;cursor:pointer;list-style:none;font-size:14px;font-weight:700}.ops-summary>summary>i:last-child{margin-inline-start:auto}.ops-summary[open]>summary{border-bottom:1px solid var(--line)}.ops-summary>:deep(.stat-rail){margin:0;border:0;border-radius:0}.row.g-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin:0;gap:12px}.row.g-2>div{width:auto;max-width:none;padding:0;margin:0}.row.g-2 .form-control,.row.g-2 .form-select{min-height:46px;font-size:14px}.row.g-2 .btn{min-height:46px;width:100%}@media(max-width:560px){.row.g-2{grid-template-columns:1fr 1fr}.row.g-2>div:first-child{grid-column:1/-1}}

/* Operational layout and responsive hierarchy. */
.inbox{display:grid;grid-template-columns:210px minmax(0,1fr);border-radius:10px;overflow:hidden}.inbox__head{grid-column:1/-1;padding:18px;border-bottom:1px solid var(--line)}.filters{grid-column:1;grid-row:2/6;display:flex;flex-direction:column;align-items:stretch;border-inline-end:1px solid var(--line);padding:14px;background:var(--canvas)}.status-tabs{display:flex;flex-direction:column;gap:4px}.status-tabs button{min-height:46px;justify-content:space-between;border-radius:6px;text-align:start}.filters select{min-height:44px;width:100%;font-size:14px}.notification-list,.inbox>footer,.inbox-error,.inbox-progress{grid-column:2;min-width:0}.notification-list article{display:grid;grid-template-columns:32px minmax(0,1fr);padding:18px;gap:12px;border-radius:0;border-bottom:1px solid var(--line)}.notification-list .avatar{width:32px;height:32px;border-radius:6px}.notification-title{display:flex;flex-wrap:wrap;gap:6px 14px}.notification-title strong{font-size:16px}.notification-title time{font-size:14px;color:var(--muted)}.notification-actions{grid-column:2;display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-start}.notification-actions button{min-height:40px;border-radius:6px;font-size:14px}.notification-body p{font-size:14px;line-height:1.7}.marker{position:absolute;inset-inline-start:0;width:3px;border-radius:0}@media(max-width:800px){.inbox{grid-template-columns:1fr}.filters{grid-column:1;grid-row:auto;flex-direction:row;flex-wrap:wrap;border:0;border-bottom:1px solid var(--line)}.status-tabs{flex-direction:row;flex-basis:100%}.filters select{flex:1;width:auto;min-width:130px}.notification-list,.inbox>footer,.inbox-error,.inbox-progress{grid-column:1}}
.notification-list article{position:relative}.status-tabs button.active{background:var(--brand-soft);color:var(--brand);box-shadow:inset -3px 0 var(--brand)}.notification-actions .quick{background:var(--brand);border-color:var(--brand);color:#fff}.notification-list>:deep(.empty-state){grid-column:1/-1}
</style>
