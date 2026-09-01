<script setup>
/**
 * Refund review desk. Pending corrective actions stay prominent; completed
 * history remains compact and every decision happens without leaving the page.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import CollectionNav from '../../../Components/Collections/CollectionNav.vue';
import CollectionSheet from '../../../Components/Collections/CollectionSheet.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    refunds: { type: Object, required: true },
    stats: { type: Object, required: true },
    filteredStats: { type: Object, required: true },
    filters: { type: Object, required: true },
    methods: { type: Array, default: () => [] },
    showBranch: { type: Boolean, default: false },
    collectionNav: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });
const reversing = ref(null);
const reverseForm = useForm({ reason: '' });
const filter = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    method: props.filters.method ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const hasFilters = computed(() => Object.values(filter).some(Boolean));

function visit(patch = {}) {
    Object.assign(filter, patch);
    router.get(props.urls.index, {
        search: filter.search || undefined,
        status: filter.status || undefined,
        method: filter.method || undefined,
        from: filter.from || undefined,
        to: filter.to || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function clear() {
    visit({ search: '', status: '', method: '', from: '', to: '' });
}

async function complete(refund) {
    const yes = await ask({
        title: `إتمام ${refund.number}؟`,
        message: `${refund.amountFormatted} ستصبح مستردة فعلياً ويُسجّل أثرها المالي الآن.`,
        confirmLabel: 'إتمام الاسترداد',
    });
    if (yes) router.post(refund.urls.complete, {}, { preserveScroll: true });
}

function openCancel(refund) {
    cancelling.value = refund;
    cancelForm.clearErrors();
    cancelForm.reason = '';
}

function cancel() {
    cancelForm.post(cancelling.value.urls.cancel, {
        preserveScroll: true,
        onSuccess: () => { cancelling.value = null; },
    });
}

function openReverse(refund) {
    reversing.value = refund;
    reverseForm.clearErrors();
    reverseForm.reason = '';
}

function reverseRefund() {
    reverseForm.post(reversing.value.urls.reverse, {
        preserveScroll: true,
        onSuccess: () => { reversing.value = null; },
    });
}
</script>

<template>
    <Head title="الاستردادات" />

    <PageHeader title="الاستردادات" icon="bi-arrow-counterclockwise"
                subtitle="مراجعة المبالغ المرتجعة وإكمال المعلّق منها دون مغادرة السجل" />

    <CollectionNav :items="collectionNav" active="refunds" />

    <StatRail :stats="[
        { label: 'استردادات اليوم', value: stats.todayCount, icon: 'bi-receipt-cutoff', color: 'primary' },
        { label: 'قيمة اليوم', value: stats.todayAmountFormatted, icon: 'bi-cash-stack', color: 'danger' },
        { label: 'تحتاج قراراً', value: stats.pending, icon: 'bi-hourglass-split', color: 'warning' },
        { label: 'قيمة الشهر', value: stats.monthAmountFormatted, icon: 'bi-calendar3', color: 'accent' },
    ]" />

    <div class="refund-lenses">
        <button type="button" :class="{ active: !filter.status }" @click="visit({ status: '' })"><i class="bi bi-list-ul"></i> الكل</button>
        <button type="button" class="pending" :class="{ active: filter.status === 'pending' }" @click="visit({ status: filter.status === 'pending' ? '' : 'pending' })"><i class="bi bi-hourglass-split"></i> تحتاج قراراً <b>{{ stats.pending }}</b></button>
        <button type="button" :class="{ active: filter.status === 'completed' }" @click="visit({ status: filter.status === 'completed' ? '' : 'completed' })"><i class="bi bi-check2-circle"></i> مكتملة</button>
        <button type="button" class="cancelled" :class="{ active: filter.status === 'cancelled' }" @click="visit({ status: filter.status === 'cancelled' ? '' : 'cancelled' })"><i class="bi bi-x-circle"></i> ملغاة</button>
        <button type="button" class="cancelled" :class="{ active: filter.status === 'reversed' }" @click="visit({ status: filter.status === 'reversed' ? '' : 'reversed' })"><i class="bi bi-arrow-counterclockwise"></i> معكوسة</button>
    </div>

    <DataPanel title="سجل الاستردادات" :count="refunds.total" icon="bi-arrow-counterclockwise">
        <template #filters>
            <form class="refund-filter" @submit.prevent="visit()">
                <label class="refund-filter__search"><i class="bi bi-search"></i><input v-model="filter.search" placeholder="رقم الاسترداد، الفاتورة، المرجع أو السبب"></label>
                <select v-model="filter.method" aria-label="طريقة الاسترداد" @change="visit()"><option value="">كل طرق الدفع</option><option v-for="method in methods" :key="method.value" :value="method.value">{{ method.label }}</option></select>
                <button class="btn btn-primary"><i class="bi bi-search"></i></button>
            </form>
            <div class="refund-dates"><label><span>من</span><input v-model="filter.from" type="date" @change="visit()"></label><label><span>إلى</span><input v-model="filter.to" type="date" @change="visit()"></label><button v-if="hasFilters" type="button" @click="clear"><i class="bi bi-x-circle"></i> مسح الفلاتر</button></div>
        </template>

        <div v-if="hasFilters" class="refund-result"><span><small>النتائج</small><b>{{ filteredStats.count }}</b></span><span><small>الإجمالي</small><b>{{ filteredStats.amountFormatted }}</b></span><span><small>معلّق</small><b>{{ filteredStats.pending }}</b></span><span><small>مكتمل</small><b>{{ filteredStats.completed }}</b></span></div>

        <div v-if="refunds.data.length" class="refund-list">
            <article v-for="refund in refunds.data" :key="refund.id" :class="`is-${refund.status}`">
                <span class="refund-state"><i class="bi" :class="refund.status === 'completed' ? 'bi-check2' : (['cancelled', 'reversed'].includes(refund.status) ? 'bi-arrow-counterclockwise' : 'bi-hourglass-split')"></i></span>
                <div class="refund-main"><div><code>{{ refund.number }}</code><span class="refund-status">{{ refund.statusLabel }}</span></div><strong>{{ refund.reason }}</strong><p v-if="refund.notes">{{ refund.notes }}</p></div>
                <div class="refund-source"><small>فاتورة</small><code>{{ refund.invoiceNumber || '—' }}</code><span>{{ refund.customer || (refund.tableNumber ? `طاولة ${refund.tableNumber}` : 'بدون بيانات زبون') }}</span><span v-if="showBranch && refund.branch" class="branch" :style="{ '--hue': refund.branch.hue }"><i class="bi bi-building"></i>{{ refund.branch.name }}</span></div>
                <div class="refund-money"><strong>{{ refund.amountFormatted }}</strong><span>{{ refund.methodLabel }}</span><small v-if="refund.creditNote">{{ refund.creditNote }}</small><small v-for="allocation in refund.allocations" :key="`${allocation.method}-${allocation.amount}`">{{ allocation.methodLabel }}: {{ allocation.amountFormatted }}</small><small v-if="refund.reference">{{ refund.reference }}</small></div>
                <div class="refund-time"><span><i class="bi bi-person"></i>{{ refund.processor || '—' }}</span><time>{{ refund.refundedAt || '—' }}</time><small>{{ refund.refundedAtHuman }}</small></div>
                <div v-if="refund.can.complete || refund.can.cancel || refund.can.reverse" class="refund-actions"><button v-if="refund.can.complete" type="button" class="complete" @click="complete(refund)"><i class="bi bi-check2"></i><span>إتمام</span></button><button v-if="refund.can.cancel" type="button" class="cancel" @click="openCancel(refund)"><i class="bi bi-x-lg"></i><span>إلغاء</span></button><button v-if="refund.can.reverse" type="button" class="reverse" @click="openReverse(refund)"><i class="bi bi-arrow-counterclockwise"></i><span>عكس</span></button></div>
            </article>
        </div>

        <EmptyState v-else icon="bi-arrow-counterclockwise" title="لا توجد استردادات"
                    :message="hasFilters ? 'لا يوجد استرداد مطابق للفلاتر الحالية.' : 'تظهر هنا الاستردادات الصادرة من الكاشير.'" />

        <template #footer><Pagination :links="refunds.links" /></template>
    </DataPanel>

    <CollectionSheet :open="Boolean(cancelling)" title="إلغاء طلب الاسترداد" :eyebrow="cancelling ? cancelling.number : ''" icon="bi-x-octagon" danger @close="cancelling = null">
        <form v-if="cancelling" id="cancelRefundForm" class="cancel-form" @submit.prevent="cancel">
            <div><span>القيمة</span><strong>{{ cancelling.amountFormatted }}</strong><small>{{ cancelling.reason }}</small></div>
            <label><span>سبب الإلغاء *</span><textarea v-model="cancelForm.reason" rows="4" maxlength="500" required placeholder="مثلاً: تراجع الزبون أو رفض البنك العملية"></textarea><small v-if="cancelForm.errors.reason" class="error">{{ cancelForm.errors.reason }}</small></label>
        </form>
        <template #footer><button type="button" class="btn btn-light" @click="cancelling = null">تراجع</button><button type="submit" form="cancelRefundForm" class="btn btn-danger" :disabled="cancelForm.processing || !cancelForm.reason.trim()"><i class="bi bi-x-circle"></i> تأكيد الإلغاء</button></template>
    </CollectionSheet>

    <CollectionSheet :open="Boolean(reversing)" title="عكس استرداد مكتمل" :eyebrow="reversing ? reversing.number : ''" icon="bi-arrow-counterclockwise" danger @close="reversing = null">
        <form v-if="reversing" id="reverseRefundForm" class="cancel-form" @submit.prevent="reverseRefund">
            <div><span>سيعود للفاتورة</span><strong>{{ reversing.amountFormatted }}</strong><small>سيُعكس صرف المال والإشعار الدائن بقيود مقابلة، ولن يُحذف أي سجل.</small></div>
            <label><span>سبب العكس *</span><textarea v-model="reverseForm.reason" rows="4" maxlength="500" required placeholder="مثلاً: سُجل على فاتورة خاطئة"></textarea><small v-if="reverseForm.errors.reason" class="error">{{ reverseForm.errors.reason }}</small></label>
        </form>
        <template #footer><button type="button" class="btn btn-light" @click="reversing = null">تراجع</button><button type="submit" form="reverseRefundForm" class="btn btn-danger" :disabled="reverseForm.processing || !reverseForm.reason.trim()"><i class="bi bi-arrow-counterclockwise"></i> عكس المستندات والقيود</button></template>
    </CollectionSheet>
</template>

<style scoped>
.refund-lenses { display: flex; gap: .45rem; margin-bottom: .8rem; overflow-x: auto; }
.refund-lenses button { min-width: 135px; min-height: 44px; flex: 1; border: 1px solid #dfe8e2; border-radius: 11px; background: #fff; color: #53685c; font-size: .7rem; font-weight: 750; white-space: nowrap; }
.refund-lenses button i { margin-inline-end: .3rem; color: #167343; }
.refund-lenses button b { margin-inline-start: .3rem; padding: .1rem .35rem; border-radius: 99px; background: #edf5f0; }
.refund-lenses button.active { border-color: #1a7b49; background: #f0f8f3; color: #0e6b3b; }
.refund-lenses .pending.active { border-color: #e0b866; background: #fff8e8; color: #8b5f12; }
.refund-lenses .cancelled.active { border-color: #e2aaaa; background: #fff3f3; color: #aa3333; }
.refund-filter { display: grid; grid-template-columns: minmax(260px, 1fr) 180px 48px; gap: .5rem; }
.refund-filter__search { min-height: 44px; display: flex; align-items: center; gap: .5rem; padding: 0 .7rem; border: 1px solid #dce5e0; border-radius: 10px; background: #fff; }
.refund-filter input, .refund-filter select, .refund-dates input { min-height: 44px; border: 1px solid #dce5e0; border-radius: 10px; }
.refund-filter__search input { width: 100%; border: 0; outline: 0; background: transparent; }
.refund-filter select { padding: .5rem .65rem; background: #fff; }
.refund-dates { display: flex; gap: .5rem; align-items: end; margin-top: .5rem; }
.refund-dates label { display: grid; gap: .2rem; color: #77867e; font-size: .61rem; }
.refund-dates input { padding: .45rem .6rem; }
.refund-dates button { min-height: 40px; margin-inline-start: auto; border: 0; background: transparent; color: #7e8c84; font-size: .66rem; }
.refund-result { display: grid; grid-template-columns: repeat(4, 1fr); gap: .4rem; margin-bottom: .7rem; }
.refund-result span { display: flex; justify-content: space-between; padding: .5rem .6rem; border-radius: 9px; background: #f4f7f5; }
.refund-result small { color: #7f8d85; }
.refund-result b { color: #1b3b2b; }
.refund-list { display: grid; gap: .5rem; }
.refund-list article { position: relative; display: grid; grid-template-columns: auto minmax(240px, 1.4fr) minmax(140px, .65fr) minmax(130px, .55fr) minmax(130px, .55fr) auto; gap: .7rem; align-items: center; padding: .7rem .8rem; border: 1px solid #e1e9e4; border-radius: 13px; overflow: hidden; }
.refund-list article::before { content: ''; position: absolute; inset-block: 0; inset-inline-start: 0; width: 4px; background: #d69a28; }
.refund-list article.is-completed::before { background: #198149; }
.refund-list article.is-cancelled { opacity: .72; }
.refund-list article.is-cancelled::before { background: #929d97; }
.refund-list article.is-reversed { opacity: .78; }
.refund-list article.is-reversed::before { background: #64748b; }
.refund-state { width: 36px; height: 36px; display: grid; place-items: center; border-radius: 9px; background: #fff4dc; color: #956411; }
.is-completed .refund-state { background: #e9f7ee; color: #147540; }
.is-cancelled .refund-state { background: #f0f2f1; color: #68766f; }
.refund-main > div { display: flex; align-items: center; gap: .35rem; }
.refund-main code { color: #365346; font-size: .64rem; font-weight: 800; }
.refund-status { padding: .14rem .35rem; border-radius: 6px; background: #fff4dc; color: #916111; font-size: .57rem; font-weight: 800; }
.is-completed .refund-status { background: #e9f7ee; color: #13743f; }
.is-cancelled .refund-status { background: #eff1f0; color: #68756e; }
.refund-main > strong { display: block; margin-top: .18rem; color: #213d2f; font-size: .72rem; }
.refund-main p { margin: .18rem 0 0; overflow: hidden; color: #7c8a83; font-size: .59rem; text-overflow: ellipsis; white-space: nowrap; }
.refund-source small, .refund-source code, .refund-source span { display: block; }
.refund-source small { color: #8a968f; font-size: .55rem; }
.refund-source code { color: #3e574b; font-size: .63rem; }
.refund-source span { color: #7b8981; font-size: .59rem; }
.refund-source .branch { display: inline-block; margin-top: .2rem; padding: .12rem .3rem; border-radius: 6px; background: hsl(var(--hue) 55% 95%); color: hsl(var(--hue) 45% 30%); }
.refund-money { text-align: end; }
.refund-money > * { display: block; }
.refund-money strong { color: #b43232; font-family: ui-monospace, monospace; font-size: .83rem; }
.refund-money span, .refund-money small, .refund-time { color: #7d8a83; font-size: .59rem; }
.refund-time span, .refund-time time, .refund-time small { display: block; }
.refund-time i { margin-inline-end: .22rem; }
.refund-actions { display: flex; gap: .3rem; }
.refund-actions button { min-width: 40px; min-height: 40px; border-radius: 9px; font-size: .63rem; font-weight: 800; }
.refund-actions span { display: none; }
.refund-actions .complete { border: 1px solid #aad6bc; background: #eef9f2; color: #11733e; }
.refund-actions .cancel { border: 1px solid #ebc0c0; background: #fff; color: #b53535; }
.refund-actions .reverse { border: 1px solid #d8c990; background: #fffaf0; color: #7d6418; }
.cancel-form { display: grid; gap: .75rem; }
.cancel-form > div { display: grid; grid-template-columns: 1fr auto; padding: .7rem; border-radius: 11px; background: #fff1f1; }
.cancel-form > div strong { color: #a93232; font-family: ui-monospace, monospace; }
.cancel-form > div small { grid-column: 1 / -1; color: #8e6262; }
.cancel-form label { display: grid; gap: .3rem; color: #4e6257; font-size: .72rem; font-weight: 750; }
.cancel-form textarea { min-height: 88px; padding: .6rem .7rem; border: 1px solid #dce5e0; border-radius: 10px; resize: vertical; }
.error { color: #b62f2f; }
@media (max-width: 1100px) { .refund-list article { grid-template-columns: auto minmax(220px, 1fr) minmax(130px, .5fr) minmax(120px, .5fr) auto; } .refund-time { display: none; } }
@media (max-width: 760px) {
    .refund-filter { grid-template-columns: 1fr; }
    .refund-dates { display: grid; grid-template-columns: 1fr 1fr; }
    .refund-dates button { grid-column: 1 / -1; margin: 0; }
    .refund-result { grid-template-columns: repeat(2, 1fr); }
    .refund-list article { grid-template-columns: auto 1fr auto; }
    .refund-source, .refund-money { grid-column: 2; }
    .refund-money { text-align: start; }
    .refund-actions { grid-column: 1 / -1; }
    .refund-actions button { flex: 1; min-height: 44px; }
    .refund-actions span { display: inline; margin-inline-start: .25rem; }
}
</style>
