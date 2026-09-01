<script setup>
/**
 * Daily expense desk. Pending items are visually first-class because they
 * need a decision; approved history stays readable without looking like an
 * accounting spreadsheet, and every action remains on the same screen.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    expenses: { type: Object, required: true },
    stats: { type: Object, required: true },
    filteredStats: { type: Object, default: null },
    filters: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const rejecting = ref(null);
const rejectionReason = ref('');

const form = reactive({
    search: props.filters.search ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    categoryId: props.filters.categoryId ?? '',
    status: props.filters.status ?? '',
    paymentMethod: props.filters.paymentMethod ?? '',
});

const hasFilters = computed(() => Object.values(form).some(Boolean));
const query = computed(() => ({
    search: form.search || undefined,
    from: form.from || undefined,
    to: form.to || undefined,
    category_id: form.categoryId || undefined,
    status: form.status || undefined,
    payment_method: form.paymentMethod || undefined,
}));
const exportUrl = computed(() => {
    const params = new URLSearchParams();
    Object.entries({ ...query.value, export: 'xlsx' }).forEach(([key, value]) => {
        if (value) params.set(key, value);
    });
    return `${props.urls.index}?${params.toString()}`;
});

function visit(patch = {}) {
    Object.assign(form, patch);
    router.get(props.urls.index, query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clear() {
    visit({ search: '', from: '', to: '', categoryId: '', status: '', paymentMethod: '' });
}

async function approve(expense) {
    const yes = await ask({
        title: `اعتماد ${expense.number}؟`,
        message: `${expense.description} — ${expense.amount}. سيُسجّل الأثر المحاسبي فوراً.`,
        confirmLabel: 'اعتماد وتسجيل القيد',
    });
    if (yes) router.post(expense.urls.approve, {}, { preserveScroll: true });
}

function openReject(expense) {
    rejecting.value = expense;
    rejectionReason.value = '';
}

function reject() {
    if (!rejectionReason.value.trim()) return;
    router.post(rejecting.value.urls.reject, { rejection_reason: rejectionReason.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => { rejecting.value = null; },
    });
}

async function destroy(expense) {
    const yes = await ask({
        title: `حذف ${expense.number}؟`,
        message: 'المصروف المعتمد لا يقبل الحذف؛ السجلات غير المعتمدة فقط يمكن إخفاؤها.',
        confirmLabel: 'حذف المصروف',
        danger: true,
    });
    if (yes) router.delete(expense.urls.destroy, { preserveScroll: true });
}

const categoryStyle = (category) => category.color
    ? { background: `${category.color}18`, color: category.color, borderColor: `${category.color}45` }
    : {};
</script>

<template>
    <Head title="المصروفات التشغيلية" />

    <PageHeader title="المصروفات التشغيلية" icon="bi-cash-coin"
                subtitle="سجّل، راجع واعتمد مصروفات الفرع من شاشة واحدة">
        <template #actions>
            <a v-if="can.create" :href="urls.create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> مصروف جديد
            </a>
        </template>
    </PageHeader>

    <div class="ex-stats">
        <StatRail :stats="[
            { label: 'بانتظار القرار', value: stats.pending, icon: 'bi-hourglass-split', color: 'warning' },
            { label: 'مصروفات اليوم', value: stats.todayTotal, icon: 'bi-calendar-day', color: 'primary' },
            { label: 'إجمالي الشهر', value: stats.monthTotal, icon: 'bi-bar-chart-fill', color: 'accent' },
        ]" />
    </div>

    <div class="ex-lenses">
        <button type="button" :class="{ active: !form.status }" @click="visit({ status: '' })">
            <i class="bi bi-list-ul"></i> الكل
        </button>
        <button type="button" class="pending" :class="{ active: form.status === 'pending_approval' }"
                @click="visit({ status: form.status === 'pending_approval' ? '' : 'pending_approval' })">
            <i class="bi bi-hourglass-split"></i> ينتظر الاعتماد <b>{{ stats.pending }}</b>
        </button>
        <button type="button" :class="{ active: form.status === 'approved' }"
                @click="visit({ status: form.status === 'approved' ? '' : 'approved' })">
            <i class="bi bi-check2-circle"></i> المعتمدة
        </button>
        <button type="button" class="rejected" :class="{ active: form.status === 'rejected' }"
                @click="visit({ status: form.status === 'rejected' ? '' : 'rejected' })">
            <i class="bi bi-x-circle"></i> المرفوضة
        </button>
    </div>

    <DataPanel title="سجل المصروفات" :count="expenses.total" icon="bi-receipt">
        <template #actions>
            <a :href="exportUrl" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel-fill"></i> Excel</a>
            <a v-if="can.manageCategories" :href="urls.categories" class="btn btn-light"><i class="bi bi-tags"></i> التصنيفات</a>
        </template>

        <template #filters>
            <form class="ex-filter" @submit.prevent="visit()">
                <label class="ex-filter__search"><i class="bi bi-search"></i><input v-model="form.search" placeholder="رقم المصروف، الوصف، المورد…"></label>
                <select v-model="form.categoryId" aria-label="تصنيف المصروف" @change="visit()">
                    <option value="">كل التصنيفات</option>
                    <option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.label }}</option>
                </select>
                <select v-model="form.paymentMethod" aria-label="طريقة الدفع" @change="visit()">
                    <option value="">كل طرق الدفع</option>
                    <option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ method.label }}</option>
                </select>
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
            </form>
            <div class="ex-dates">
                <label><span>من</span><input v-model="form.from" type="date" @change="visit()"></label>
                <label><span>إلى</span><input v-model="form.to" type="date" @change="visit()"></label>
                <button v-if="hasFilters" type="button" @click="clear"><i class="bi bi-x-circle"></i> مسح الفلاتر</button>
            </div>
        </template>

        <div v-if="filteredStats" class="ex-result-summary">
            <span><small>النتائج</small><b>{{ filteredStats.count }}</b></span>
            <span><small>الإجمالي</small><b>{{ filteredStats.totalFormatted }}</b></span>
            <span><small>بانتظار القرار</small><b>{{ filteredStats.pending }}</b></span>
            <span><small>معتمد</small><b>{{ filteredStats.approved }}</b></span>
        </div>

        <div v-if="!categories.length" class="ex-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>لا توجد تصنيفات مصروفات مفعّلة.</span>
            <a v-if="can.manageCategories" :href="urls.categories">أضف تصنيفاً الآن</a>
        </div>

        <div v-if="expenses.data.length" class="ex-list">
            <article v-for="expense in expenses.data" :key="expense.id" class="ex-card" :class="`is-${expense.status}`">
                <div class="ex-state-line"></div>
                <div class="ex-main">
                    <div class="ex-title">
                        <code>{{ expense.number }}</code>
                        <span class="ex-status" :class="`is-${expense.statusColor}`">{{ expense.statusLabel }}</span>
                        <a v-if="expense.attachmentUrl" :href="expense.attachmentUrl" target="_blank" title="فتح المرفق"><i class="bi bi-paperclip"></i></a>
                    </div>
                    <strong>{{ expense.description }}</strong>
                    <div class="ex-meta">
                        <span v-if="expense.vendor"><i class="bi bi-shop"></i>{{ expense.vendor }}</span>
                        <span><i class="bi bi-credit-card"></i>{{ expense.paymentMethod }}</span>
                        <span v-if="expense.paymentReference"><i class="bi bi-hash"></i>{{ expense.paymentReference }}</span>
                        <span v-if="expense.branch" class="ex-branch" :style="{ '--hue': expense.branch.hue }"><i class="bi bi-building"></i>{{ expense.branch.name }}</span>
                    </div>
                    <p v-if="expense.notes" class="ex-notes">{{ expense.notes }}</p>
                    <p v-if="expense.rejectionReason" class="ex-reason"><i class="bi bi-info-circle"></i>{{ expense.rejectionReason }}</p>
                </div>

                <div class="ex-category" :style="categoryStyle(expense.category)">{{ expense.category.label }}</div>

                <div class="ex-money">
                    <strong>{{ expense.amount }}</strong>
                    <small v-if="expense.baseAmount">≈ {{ expense.baseAmount }} بالدفاتر</small>
                    <span><i class="bi bi-calendar3"></i>{{ expense.date }}</span>
                </div>

                <div class="ex-actions">
                    <button v-if="expense.can.approve" type="button" class="approve" @click="approve(expense)"><i class="bi bi-check2"></i><span>اعتماد</span></button>
                    <button v-if="expense.can.reject" type="button" class="reject" @click="openReject(expense)"><i class="bi bi-x-lg"></i><span>رفض</span></button>
                    <a v-if="expense.can.update" :href="expense.urls.edit"><i class="bi bi-pencil"></i><span>تعديل</span></a>
                    <button v-if="expense.can.delete" type="button" class="delete" @click="destroy(expense)"><i class="bi bi-trash3"></i><span>حذف</span></button>
                </div>
            </article>
        </div>

        <EmptyState v-else icon="bi-cash-coin" title="لا توجد مصروفات هنا"
                    :message="hasFilters ? 'جرّب مسح الفلاتر أو توسيع الفترة.' : 'ابدأ بتسجيل أول مصروف للفرع.'" />

        <template #footer><Pagination :links="expenses.links" /></template>
    </DataPanel>

    <Teleport to="body">
        <Transition name="ex-modal">
            <div v-if="rejecting" class="ex-backdrop" @click.self="rejecting = null">
                <form class="ex-reject-card" @submit.prevent="reject">
                    <header><div><small>قرار يحتاج سبباً</small><h3>رفض {{ rejecting.number }}</h3></div><button type="button" @click="rejecting = null"><i class="bi bi-x-lg"></i></button></header>
                    <p>{{ rejecting.description }} — {{ rejecting.amount }}</p>
                    <label><span>سبب الرفض *</span><textarea v-model="rejectionReason" rows="4" maxlength="255" required placeholder="مثلاً: الإيصال غير واضح أو المبلغ غير مطابق…"></textarea></label>
                    <footer><button type="button" class="btn btn-light" @click="rejecting = null">تراجع</button><button class="btn btn-danger" :disabled="!rejectionReason.trim()"><i class="bi bi-x-circle"></i> تأكيد الرفض</button></footer>
                </form>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.ex-lenses { display: flex; gap: .5rem; margin-bottom: .85rem; overflow-x: auto; padding-bottom: .1rem; }
.ex-lenses button { flex: 1; min-width: 145px; min-height: 46px; padding: .45rem .75rem; border: 1px solid #dfe8e2; border-radius: 12px; background: #fff; color: #53685c; font-weight: 750; white-space: nowrap; }
.ex-lenses button i { margin-inline-end: .35rem; color: #167343; }
.ex-lenses button b { margin-inline-start: .35rem; padding: .1rem .4rem; border-radius: 99px; background: #edf5f0; }
.ex-lenses button.active { border-color: #1a7b49; background: #f0f8f3; color: #0e6b3b; }
.ex-lenses .pending i { color: #ba7a13; }
.ex-lenses .pending.active { border-color: #e4bd76; background: #fff9ed; color: #8a5e14; }
.ex-lenses .rejected i { color: #bd3b3b; }
.ex-lenses .rejected.active { border-color: #e4abab; background: #fff5f5; color: #a33333; }
.ex-filter { display: grid; grid-template-columns: minmax(260px, 1fr) 190px 180px 48px; gap: .55rem; }
.ex-filter__search { display: flex; align-items: center; gap: .55rem; padding: 0 .8rem; border: 1px solid #dfe6e2; border-radius: 10px; background: #fff; }
.ex-filter__search:focus-within { border-color: #1b7c49; box-shadow: 0 0 0 3px rgba(27,124,73,.08); }
.ex-filter__search input { min-height: 44px; width: 100%; border: 0; outline: 0; background: transparent; }
.ex-filter select, .ex-dates input { min-height: 44px; padding: .55rem .7rem; border: 1px solid #dfe6e2; border-radius: 10px; background: #fff; }
.ex-dates { display: flex; gap: .5rem; align-items: flex-end; margin-top: .55rem; }
.ex-dates label { display: flex; align-items: center; gap: .35rem; color: #75857d; font-size: .68rem; }
.ex-dates button { min-height: 42px; margin-inline-start: auto; border: 0; background: transparent; color: #8a9890; font-size: .7rem; }
.ex-result-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: .45rem; margin-bottom: .75rem; }
.ex-result-summary span { padding: .55rem .7rem; border-radius: 10px; background: #f5f8f6; display: flex; align-items: center; justify-content: space-between; }
.ex-result-summary small { color: #7c8b83; }
.ex-result-summary b { color: #19382a; }
.ex-warning { display: flex; align-items: center; gap: .5rem; padding: .7rem .8rem; margin-bottom: .75rem; border: 1px solid #efd39b; border-radius: 11px; background: #fff9ec; color: #865c13; font-size: .75rem; }
.ex-warning a { margin-inline-start: auto; color: inherit; font-weight: 800; }
.ex-list { display: grid; gap: .65rem; }
.ex-card { position: relative; min-height: 98px; display: grid; grid-template-columns: minmax(260px, 1.5fr) minmax(110px, .55fr) minmax(145px, .65fr) auto; gap: .8rem; align-items: center; padding: .8rem .9rem .8rem 1rem; border: 1px solid #e3ebe6; border-radius: 14px; background: #fff; overflow: hidden; transition: .15s ease; }
.ex-card:hover { border-color: #b4d1bf; box-shadow: 0 7px 20px rgba(23,79,46,.07); }
.ex-state-line { position: absolute; inset-block: 0; inset-inline-start: 0; width: 4px; background: #8da097; }
.ex-card.is-pending_approval .ex-state-line { background: #d59626; }
.ex-card.is-approved .ex-state-line { background: #1a8a4d; }
.ex-card.is-rejected .ex-state-line { background: #ce4343; }
.ex-title { display: flex; align-items: center; gap: .4rem; margin-bottom: .25rem; }
.ex-title code { padding: .15rem .4rem; border-radius: 6px; background: #f2f5f3; color: #42574c; font-size: .68rem; font-weight: 800; }
.ex-title a { color: #687d72; }
.ex-main > strong { color: #1a3427; font-size: .9rem; }
.ex-status { padding: .15rem .42rem; border-radius: 99px; font-size: .62rem; font-weight: 800; }
.ex-status.is-warning { color: #966511; background: #fff3d9; }
.ex-status.is-success { color: #0f743d; background: #e7f6ed; }
.ex-status.is-danger { color: #b33232; background: #feecec; }
.ex-meta { display: flex; flex-wrap: wrap; gap: .25rem .7rem; margin-top: .35rem; color: #7d8b84; font-size: .68rem; }
.ex-meta i { margin-inline-end: .25rem; }
.ex-branch { padding: .1rem .35rem; border-radius: 6px; background: hsl(var(--hue) 55% 95%); color: hsl(var(--hue) 45% 30%); }
.ex-notes, .ex-reason { margin: .3rem 0 0; max-width: 520px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #829088; font-size: .67rem; }
.ex-reason { color: #b43b3b; }
.ex-category { justify-self: start; padding: .28rem .55rem; border: 1px solid #e2e8e4; border-radius: 8px; background: #f5f7f6; color: #596a61; font-size: .68rem; font-weight: 750; }
.ex-money { display: flex; flex-direction: column; align-items: flex-end; }
.ex-money strong { color: #173827; font-size: .95rem; font-family: ui-monospace, monospace; }
.ex-money small, .ex-money span { color: #849189; font-size: .63rem; }
.ex-money span { margin-top: .25rem; }
.ex-actions { display: flex; gap: .3rem; justify-content: flex-end; }
.ex-actions button, .ex-actions a { min-width: 38px; min-height: 38px; padding: 0 .55rem; border: 1px solid #dfe7e2; border-radius: 9px; background: #fff; color: #51665a; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
.ex-actions span { display: none; }
.ex-actions .approve { color: #0f7540; border-color: #a9d7bd; background: #eff9f3; }
.ex-actions .reject, .ex-actions .delete { color: #b43939; border-color: #ecc2c2; }
.ex-backdrop { position: fixed; inset: 0; z-index: 1090; display: grid; place-items: center; padding: 1rem; background: rgba(13,29,20,.45); backdrop-filter: blur(2px); }
.ex-reject-card { width: min(480px, 96vw); padding: 1rem; border-radius: 16px; background: #fff; box-shadow: 0 24px 65px rgba(0,0,0,.22); }
.ex-reject-card header { display: flex; justify-content: space-between; align-items: center; }
.ex-reject-card header small { color: #8b9891; }
.ex-reject-card h3 { margin: .1rem 0 0; font-size: 1.05rem; }
.ex-reject-card header button { width: 38px; height: 38px; border: 0; border-radius: 10px; background: #f3f5f4; }
.ex-reject-card p { color: #6e7e76; font-size: .74rem; }
.ex-reject-card label { display: flex; flex-direction: column; gap: .35rem; color: #4f6358; font-size: .74rem; font-weight: 700; }
.ex-reject-card textarea { padding: .65rem; border: 1px solid #dfe6e2; border-radius: 10px; resize: vertical; }
.ex-reject-card footer { display: flex; justify-content: flex-end; gap: .5rem; margin-top: .9rem; padding-top: .8rem; border-top: 1px solid #e8edea; }
.ex-modal-enter-active, .ex-modal-leave-active { transition: opacity .16s ease; }
.ex-modal-enter-from, .ex-modal-leave-to { opacity: 0; }
@media (max-width: 1000px) {
    .ex-card { grid-template-columns: minmax(240px, 1fr) minmax(130px, auto) auto; }
    .ex-category { display: none; }
}
@media (max-width: 767.98px) {
    .ex-stats :deep(.stat-rail) { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
    .ex-stats :deep(.stat-rail-item) { min-height: 70px; padding: .6rem; }
    .ex-stats :deep(.stat-rail-item:last-child) { grid-column: 1 / -1; }
    .ex-filter { grid-template-columns: 1fr; }
    .ex-dates { display: grid; grid-template-columns: 1fr 1fr; }
    .ex-dates label { display: grid; }
    .ex-dates button { grid-column: 1 / -1; margin: 0; }
    .ex-result-summary { grid-template-columns: repeat(2, 1fr); }
    .ex-card { grid-template-columns: minmax(0, 1fr) auto; padding: .75rem; }
    .ex-category { display: block; grid-column: 1; }
    .ex-money { grid-column: 2; grid-row: 1 / 3; }
    .ex-actions { grid-column: 1 / -1; border-top: 1px solid #edf1ee; padding-top: .55rem; }
    .ex-actions button, .ex-actions a { flex: 1; }
    .ex-actions span { display: inline; margin-inline-start: .3rem; font-size: .68rem; }
}
@media (max-width: 430px) {
    .ex-stats :deep(.stat-rail) { grid-template-columns: 1fr; }
    .ex-stats :deep(.stat-rail-item:last-child) { grid-column: auto; }
    .ex-card { grid-template-columns: 1fr; }
    .ex-money { grid-column: 1; grid-row: auto; align-items: flex-start; }
}
</style>
