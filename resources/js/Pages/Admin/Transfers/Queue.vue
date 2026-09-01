<script setup>
/**
 * Bank confirmation queue. One compact card equals one decision, while the
 * refresh pauses during typing or a decision sheet to protect in-progress work.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import CollectionNav from '../../../Components/Collections/CollectionNav.vue';
import CollectionSheet from '../../../Components/Collections/CollectionSheet.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useLiveRefresh } from '../../../Composables/useLiveRefresh';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    pending: { type: Array, default: () => [] },
    recentlyClosed: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    collectionNav: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const filter = reactive({ search: props.filters.search ?? '' });
const verifying = ref(null);
const rejecting = ref(null);
const verifyForm = useForm({ verified_amount: '', verification_notes: '' });
const rejectForm = useForm({ reason: '' });
const hasSearch = computed(() => Boolean(filter.search.trim()));
const amountChanged = computed(() => verifying.value
    && Math.abs(Number(verifyForm.verified_amount || 0) - Number(verifying.value.amount || 0)) > .001);

function search() {
    router.get(props.urls.index, { q: filter.search.trim() || undefined }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearSearch() {
    filter.search = '';
    search();
}

function openVerify(transfer) {
    verifying.value = transfer;
    verifyForm.clearErrors();
    verifyForm.verified_amount = transfer.amount.toFixed(2);
    verifyForm.verification_notes = '';
}

function verify() {
    verifyForm.post(verifying.value.urls.verify, {
        preserveScroll: true,
        onSuccess: () => { verifying.value = null; },
    });
}

function openReject(transfer) {
    rejecting.value = transfer;
    rejectForm.clearErrors();
    rejectForm.reason = '';
}

function reject() {
    rejectForm.post(rejecting.value.urls.reject, {
        preserveScroll: true,
        onSuccess: () => { rejecting.value = null; },
    });
}

async function reopen(transfer) {
    const yes = await ask({
        title: 'إعادة التحويل للانتظار؟',
        message: `تحويل ${transfer.senderName} بقيمة ${transfer.amountFormatted} سيعود إلى طابور التحقق.`,
        confirmLabel: 'إعادة فتح التحويل',
    });
    if (yes) router.post(transfer.urls.reopen, {}, { preserveScroll: true });
}

useLiveRefresh({
    pollMs: 15000,
    onPing: () => {
        const field = document.activeElement?.matches?.('input, textarea, select');
        if (!verifying.value && !rejecting.value && !field) {
            router.reload({ only: ['pending', 'recentlyClosed', 'stats'], preserveState: true, preserveScroll: true });
        }
    },
});
</script>

<template>
    <Head title="تحويلات تنتظر التأكيد" />

    <PageHeader title="تأكيد التحويلات" icon="bi-bank"
                subtitle="طابق الاسم والمبلغ مع تطبيق البنك، ثم سجّل الدفعة من نفس البطاقة">
        <template #actions><a :href="urls.cashier" class="btn btn-light"><i class="bi bi-cash-register"></i> الكاشير</a></template>
    </PageHeader>

    <CollectionNav :items="collectionNav" active="transfers" />

    <StatRail :stats="[
        { label: 'بانتظارك الآن', value: stats.pendingCount, icon: 'bi-hourglass-split', color: 'warning' },
        { label: 'مبلغ قيد التحقق', value: stats.pendingAmountFormatted, icon: 'bi-bank', color: 'primary' },
        { label: 'أقدم انتظار', value: stats.oldestWaiting || 'لا يوجد', icon: 'bi-clock-history', color: 'danger' },
        { label: 'أُغلق اليوم', value: stats.closedToday, icon: 'bi-check2-circle', color: 'success' },
    ]" />

    <div class="transfer-toolbar">
        <form @submit.prevent="search"><label><i class="bi bi-search"></i><input v-model="filter.search" placeholder="المحوّل، الزبون، الطاولة أو المبلغ"></label><button class="btn btn-primary">بحث</button><button v-if="hasSearch" type="button" class="btn btn-light" @click="clearSearch"><i class="bi bi-x-lg"></i></button></form>
        <span><i class="bi bi-arrow-clockwise"></i> تحديث تلقائي كل 15 ثانية</span>
    </div>

    <div v-if="pending.length" class="transfer-grid">
        <article v-for="transfer in pending" :key="transfer.id" class="transfer-card">
            <header>
                <span class="table-badge"><i class="bi bi-grid-3x3-gap"></i>{{ transfer.tableNumber ? `طاولة ${transfer.tableNumber}` : 'بدون طاولة' }}</span>
                <span class="wait-time"><i class="bi bi-clock"></i>{{ transfer.age }}</span>
            </header>
            <div class="transfer-main">
                <div><small>اسم المحوّل</small><strong>{{ transfer.senderName }}</strong></div>
                <div class="transfer-amount"><small>المبلغ المُدّعى</small><strong>{{ transfer.amountFormatted }}</strong></div>
            </div>
            <div class="transfer-meta">
                <span><i class="bi bi-person"></i>{{ transfer.customerName }}</span>
                <span v-if="transfer.customerPhone"><i class="bi bi-telephone"></i>{{ transfer.customerPhone }}</span>
                <span><i class="bi bi-pencil-square"></i>سجّله {{ transfer.recordedBy }}</span>
            </div>
            <p v-if="transfer.notes" class="transfer-note"><i class="bi bi-chat-left-text"></i>{{ transfer.notes }}</p>
            <div class="transfer-proof"><a v-if="transfer.urls.proof" :href="transfer.urls.proof" target="_blank"><i class="bi bi-image"></i> عرض وصل التحويل</a><span v-else><i class="bi bi-image"></i> بدون صورة وصل</span></div>
            <footer><button type="button" class="verify" @click="openVerify(transfer)"><i class="bi bi-check2-circle"></i> موجود في البنك</button><button type="button" class="reject" @click="openReject(transfer)"><i class="bi bi-x-circle"></i> غير موجود</button></footer>
        </article>
    </div>

    <EmptyState v-else icon="bi-check2-circle" title="كل التحويلات معالجة"
                :message="hasSearch ? 'لا يوجد تحويل مطابق للبحث.' : 'لا يوجد تحويل بانتظار تأكيد البنك الآن.'" />

    <details v-if="recentlyClosed.length" class="closed-panel">
        <summary><span><i class="bi bi-clock-history"></i> ما عولج اليوم</span><b>{{ recentlyClosed.length }}</b><i class="bi bi-chevron-down"></i></summary>
        <div class="closed-list">
            <article v-for="transfer in recentlyClosed" :key="transfer.id" :class="`is-${transfer.status}`">
                <span class="closed-state"><i class="bi" :class="transfer.status === 'verified' ? 'bi-check2' : 'bi-x-lg'"></i></span>
                <div><strong>{{ transfer.senderName }}</strong><small>{{ transfer.tableNumber ? `طاولة ${transfer.tableNumber}` : 'بدون طاولة' }} · {{ transfer.handledAt }}</small></div>
                <div class="closed-amount"><strong>{{ transfer.actualAmountFormatted || transfer.amountFormatted }}</strong><small v-if="transfer.hasAmountDifference"><s>{{ transfer.amountFormatted }}</s> مُدّعى</small></div>
                <span class="closed-label">{{ transfer.statusLabel }}</span>
                <button v-if="transfer.status === 'rejected'" type="button" @click="reopen(transfer)"><i class="bi bi-arrow-counterclockwise"></i> إعادة فتح</button>
            </article>
        </div>
    </details>

    <CollectionSheet :open="Boolean(verifying)" title="تأكيد وصول التحويل" :eyebrow="verifying ? verifying.senderName : ''" icon="bi-bank" @close="verifying = null">
        <form v-if="verifying" id="verifyTransferForm" class="decision-form" @submit.prevent="verify">
            <div class="decision-summary"><span>{{ verifying.tableNumber ? `طاولة ${verifying.tableNumber}` : 'بدون طاولة' }}</span><strong>{{ verifying.amountFormatted }}</strong><small>المبلغ الذي أبلغه الزبون</small></div>
            <label><span>المبلغ الفعلي في البنك *</span><input v-model="verifyForm.verified_amount" type="number" min="0.01" step="0.01" required><small v-if="verifyForm.errors.verified_amount" class="error">{{ verifyForm.errors.verified_amount }}</small></label>
            <p v-if="amountChanged" class="amount-warning"><i class="bi bi-exclamation-triangle"></i> المبلغ مختلف عن الادعاء. سيُسجّل الفعلي فقط، وأي باقي سيظل مطلوباً على الفاتورة.</p>
            <label><span>ملاحظة التحقق <small>اختياري</small></span><textarea v-model="verifyForm.verification_notes" rows="3" maxlength="500" placeholder="مثلاً: وصل من حساب باسم مختلف"></textarea></label>
        </form>
        <template #footer><button type="button" class="btn btn-light" @click="verifying = null">تراجع</button><button type="submit" form="verifyTransferForm" class="btn btn-success" :disabled="verifyForm.processing"><i class="bi bi-check2-circle"></i> تأكيد وتسجيل الدفعة</button></template>
    </CollectionSheet>

    <CollectionSheet :open="Boolean(rejecting)" title="التحويل غير موجود" :eyebrow="rejecting ? rejecting.senderName : ''" icon="bi-x-octagon" danger @close="rejecting = null">
        <form v-if="rejecting" id="rejectTransferForm" class="decision-form" @submit.prevent="reject">
            <p class="reject-note">سجّل سبباً واضحاً؛ يمكن إعادة فتح التحويل لاحقاً إذا ظهر في كشف البنك.</p>
            <label><span>سبب الرفض *</span><textarea v-model="rejectForm.reason" rows="4" maxlength="500" required placeholder="مثلاً: لا توجد عملية بهذا المبلغ أو الاسم"></textarea><small v-if="rejectForm.errors.reason" class="error">{{ rejectForm.errors.reason }}</small></label>
        </form>
        <template #footer><button type="button" class="btn btn-light" @click="rejecting = null">تراجع</button><button type="submit" form="rejectTransferForm" class="btn btn-danger" :disabled="rejectForm.processing || !rejectForm.reason.trim()"><i class="bi bi-x-circle"></i> تسجيل الرفض</button></template>
    </CollectionSheet>
</template>

<style scoped>
.transfer-toolbar { display: flex; align-items: center; justify-content: space-between; gap: .7rem; margin-bottom: .8rem; }
.transfer-toolbar form { display: grid; grid-template-columns: minmax(260px, 420px) auto auto; gap: .45rem; }
.transfer-toolbar label { min-height: 44px; display: flex; align-items: center; gap: .5rem; padding: 0 .7rem; border: 1px solid #dce6e0; border-radius: 10px; background: #fff; }
.transfer-toolbar input { width: 100%; border: 0; outline: 0; background: transparent; }
.transfer-toolbar > span { color: #86948c; font-size: .65rem; }
.transfer-toolbar > span i { margin-inline-end: .3rem; color: #177746; }
.transfer-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .7rem; }
.transfer-card { display: flex; flex-direction: column; padding: .8rem; border: 1px solid #e1e9e4; border-top: 4px solid #d49a29; border-radius: 14px; background: #fff; box-shadow: 0 5px 16px rgba(29,78,49,.04); }
.transfer-card > header { display: flex; justify-content: space-between; align-items: center; }
.table-badge { padding: .25rem .45rem; border-radius: 7px; background: #edf6f0; color: #136e3c; font-size: .66rem; font-weight: 800; }
.table-badge i, .wait-time i { margin-inline-end: .25rem; }
.wait-time { color: #8b968f; font-size: .6rem; }
.transfer-main { display: grid; grid-template-columns: 1fr auto; gap: .6rem; align-items: end; margin-top: .7rem; }
.transfer-main small, .transfer-main strong { display: block; }
.transfer-main small { color: #89958e; font-size: .6rem; }
.transfer-main strong { color: #1c392c; font-size: .9rem; }
.transfer-amount { text-align: end; }
.transfer-amount strong { color: #0f7140; font-family: ui-monospace, monospace; font-size: 1.05rem; }
.transfer-meta { display: flex; flex-wrap: wrap; gap: .25rem .55rem; margin-top: .6rem; color: #74837b; font-size: .62rem; }
.transfer-meta i { margin-inline-end: .22rem; }
.transfer-note { margin: .55rem 0 0; padding: .45rem; border-radius: 8px; background: #f6f8f7; color: #687971; font-size: .63rem; }
.transfer-note i { margin-inline-end: .3rem; }
.transfer-proof { margin-top: .55rem; }
.transfer-proof a, .transfer-proof span { display: inline-flex; align-items: center; gap: .3rem; color: #597066; font-size: .63rem; text-decoration: none; }
.transfer-proof a { color: #166f41; font-weight: 750; }
.transfer-card > footer { display: grid; grid-template-columns: 1.25fr .75fr; gap: .4rem; margin-top: auto; padding-top: .7rem; }
.transfer-card footer button { min-height: 44px; border-radius: 10px; font-size: .69rem; font-weight: 800; }
.transfer-card .verify { border: 1px solid #168149; background: #168149; color: #fff; }
.transfer-card .reject { border: 1px solid #e5bcbc; background: #fff; color: #b43535; }
.closed-panel { margin-top: .85rem; border: 1px solid #e1e8e4; border-radius: 14px; background: #fff; overflow: hidden; }
.closed-panel summary { min-height: 50px; display: flex; align-items: center; gap: .55rem; padding: .6rem .8rem; color: #486054; cursor: pointer; list-style: none; font-size: .73rem; font-weight: 800; }
.closed-panel summary span { flex: 1; }
.closed-panel summary span i { margin-inline-end: .35rem; color: #167746; }
.closed-panel summary b { min-width: 27px; padding: .12rem .4rem; border-radius: 99px; background: #edf3ef; text-align: center; }
.closed-list { border-top: 1px solid #edf1ef; }
.closed-list article { display: grid; grid-template-columns: auto minmax(160px, 1fr) minmax(120px, auto) auto auto; gap: .65rem; align-items: center; padding: .6rem .8rem; border-bottom: 1px solid #edf1ef; }
.closed-state { width: 32px; height: 32px; display: grid; place-items: center; border-radius: 9px; background: #eaf6ee; color: #12723e; }
.is-rejected .closed-state { background: #fff0f0; color: #b33232; }
.closed-list strong, .closed-list small { display: block; }
.closed-list small { color: #85928b; font-size: .59rem; }
.closed-amount { text-align: end; font-family: ui-monospace, monospace; }
.closed-label { padding: .2rem .4rem; border-radius: 7px; background: #eaf6ee; color: #12723e; font-size: .62rem; font-weight: 800; }
.is-rejected .closed-label { background: #fff0f0; color: #b33232; }
.closed-list button { min-height: 40px; border: 1px solid #e0bd72; border-radius: 9px; background: #fffaf0; color: #8c6014; font-size: .63rem; }
.decision-form { display: grid; gap: .75rem; }
.decision-summary { display: grid; grid-template-columns: 1fr auto; padding: .7rem; border-radius: 11px; background: #f1f8f4; }
.decision-summary strong { color: #0e7040; font-family: ui-monospace, monospace; }
.decision-summary small { grid-column: 1 / -1; color: #839087; font-size: .61rem; }
.decision-form label { display: grid; gap: .3rem; color: #4e6257; font-size: .72rem; font-weight: 750; }
.decision-form input, .decision-form textarea { min-height: 44px; padding: .6rem .7rem; border: 1px solid #dce5e0; border-radius: 10px; outline: 0; }
.decision-form textarea { min-height: 82px; resize: vertical; }
.amount-warning, .reject-note { margin: 0; padding: .6rem; border-radius: 9px; background: #fff7e8; color: #8a6118; font-size: .65rem; line-height: 1.7; }
.reject-note { background: #fff1f1; color: #a93737; }
.error { color: #b62f2f; }
@media (max-width: 1100px) { .transfer-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) {
    .transfer-toolbar { align-items: stretch; flex-direction: column; }
    .transfer-toolbar form { grid-template-columns: 1fr auto auto; }
    .transfer-grid { grid-template-columns: 1fr; }
    .closed-list article { grid-template-columns: auto 1fr auto; }
    .closed-amount { grid-column: 2; text-align: start; }
    .closed-label { grid-column: 3; grid-row: 1; }
    .closed-list button { grid-column: 1 / -1; }
}
</style>
