<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import CollectionNav from '../../../Components/Collections/CollectionNav.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    rows: { type: Array, default: () => [] },
    date: { type: String, required: true },
    summary: { type: Object, required: true },
    totals: { type: Object, required: true },
    collectionNav: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const filter = reactive({ date: props.date });

function visit() {
    router.get(props.urls.index, { date: filter.date }, { preserveState: true, preserveScroll: true, replace: true });
}

async function reopen(transfer) {
    const yes = await ask({
        title: 'إعادة التحويل للانتظار؟',
        message: `سيعود تحويل ${transfer.senderName} إلى شاشة الكاشير للتأكد منه مجدداً.`,
        confirmLabel: 'إعادة فتح التحويل',
    });
    if (yes) router.post(transfer.urls.reopen, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`مطابقة تحويلات ${date}`" />

    <PageHeader title="مطابقة التحويلات البنكية" icon="bi-clipboard-data"
                subtitle="المبلغ المؤكد هو ما دخل البنك فعلياً، وليس المبلغ الذي أبلغه الزبون"
                :crumbs="[{ label: 'تأكيد التحويلات', url: urls.queue }]" />

    <CollectionNav :items="collectionNav" active="transfer-report" />

    <div class="report-date"><form @submit.prevent="visit"><label><span>يوم المطابقة</span><input v-model="filter.date" type="date" required></label><button class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> عرض اليوم</button></form><div><span>إجمالي الادعاءات</span><strong>{{ totals.claimsFormatted }}</strong><small>دخل البنك المؤكد: {{ totals.verifiedActualFormatted }}</small></div></div>

    <StatRail :stats="[
        { label: `مؤكد · ${summary.verified.count}`, value: summary.verified.amountFormatted, icon: 'bi-check2-circle', color: 'success' },
        { label: `بانتظار · ${summary.pending.count}`, value: summary.pending.amountFormatted, icon: 'bi-hourglass-split', color: 'warning' },
        { label: `مرفوض · ${summary.rejected.count}`, value: summary.rejected.amountFormatted, icon: 'bi-x-octagon', color: 'danger' },
    ]" />

    <DataPanel title="حركة اليوم" :count="rows.length" icon="bi-list-check">
        <div v-if="rows.length" class="report-list">
            <article v-for="transfer in rows" :key="transfer.id" :class="`is-${transfer.status}`">
                <span class="state-icon"><i class="bi" :class="transfer.status === 'verified' ? 'bi-check2' : (transfer.status === 'rejected' ? 'bi-x-lg' : 'bi-hourglass-split')"></i></span>
                <div class="report-id"><time>{{ transfer.createdTime }}</time><strong>{{ transfer.senderName }}</strong><small>{{ transfer.tableNumber ? `طاولة ${transfer.tableNumber}` : 'بدون طاولة' }}</small></div>
                <div class="report-person"><span><i class="bi bi-person"></i>{{ transfer.recordedBy }}</span><small v-if="transfer.verifiedBy"><i class="bi bi-shield-check"></i>{{ transfer.verifiedBy }}</small></div>
                <div class="report-money"><small>المُدّعى</small><span>{{ transfer.amountFormatted }}</span><template v-if="transfer.actualAmountFormatted"><small>المؤكد</small><strong :class="{ changed: transfer.hasAmountDifference }">{{ transfer.actualAmountFormatted }}</strong></template></div>
                <div class="report-decision"><span>{{ transfer.statusLabel }}</span><p>{{ transfer.decisionNote || 'بدون ملاحظة' }}</p></div>
                <div class="report-actions"><a v-if="transfer.urls.proof" :href="transfer.urls.proof" target="_blank" title="عرض الوصل"><i class="bi bi-image"></i></a><button v-if="transfer.status === 'rejected'" type="button" title="إعادة فتح" @click="reopen(transfer)"><i class="bi bi-arrow-counterclockwise"></i></button></div>
            </article>
        </div>
        <EmptyState v-else icon="bi-inbox" title="لا تحويلات في هذا اليوم" message="اختر يوماً آخر أو ارجع إلى طابور التأكيد." />
    </DataPanel>
</template>

<style scoped>
.report-date { display: grid; grid-template-columns: minmax(330px, .8fr) minmax(260px, 1fr); gap: .7rem; margin-bottom: .8rem; }
.report-date > form, .report-date > div { min-height: 70px; display: flex; align-items: end; gap: .55rem; padding: .65rem .75rem; border: 1px solid #e0e8e3; border-radius: 13px; background: #fff; }
.report-date label { flex: 1; display: grid; gap: .2rem; color: #78877f; font-size: .62rem; }
.report-date input { min-height: 42px; padding: .5rem .65rem; border: 1px solid #dce5e0; border-radius: 9px; }
.report-date > div { display: grid; grid-template-columns: 1fr auto; align-content: center; align-items: center; background: #f2f8f4; }
.report-date > div span, .report-date > div small { color: #77867e; font-size: .64rem; }
.report-date > div strong { color: #173d2a; font-family: ui-monospace, monospace; font-size: 1rem; }
.report-date > div small { grid-column: 1 / -1; }
.report-list { display: grid; gap: .45rem; }
.report-list article { position: relative; display: grid; grid-template-columns: auto minmax(145px, .8fr) minmax(130px, .7fr) minmax(150px, .7fr) minmax(180px, 1fr) auto; gap: .65rem; align-items: center; padding: .65rem .7rem; border: 1px solid #e2e9e5; border-radius: 12px; overflow: hidden; }
.report-list article::before { content: ''; position: absolute; inset-block: 0; inset-inline-start: 0; width: 4px; background: #d59b2b; }
.report-list article.is-verified::before { background: #19824a; }
.report-list article.is-rejected::before { background: #c63c3c; }
.state-icon { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 9px; background: #fff5df; color: #956412; }
.is-verified .state-icon { background: #e9f7ee; color: #147540; }
.is-rejected .state-icon { background: #fff0f0; color: #b43232; }
.report-id time, .report-id strong, .report-id small, .report-person span, .report-person small { display: block; }
.report-id time { color: #8b9790; font-size: .58rem; }
.report-id strong { color: #203b2e; font-size: .76rem; }
.report-id small, .report-person { color: #7b8982; font-size: .61rem; }
.report-person i { margin-inline-end: .25rem; }
.report-money { display: grid; grid-template-columns: auto 1fr; gap: .1rem .35rem; align-items: center; }
.report-money small { color: #8a968f; font-size: .57rem; }
.report-money span, .report-money strong { color: #284737; font-family: ui-monospace, monospace; font-size: .68rem; }
.report-money strong.changed { color: #a66b10; }
.report-decision span { display: inline-block; padding: .18rem .4rem; border-radius: 7px; background: #fff4dc; color: #916111; font-size: .59rem; font-weight: 800; }
.is-verified .report-decision span { background: #e9f7ee; color: #13743f; }
.is-rejected .report-decision span { background: #fff0f0; color: #b43232; }
.report-decision p { margin: .2rem 0 0; overflow: hidden; color: #7d8a83; font-size: .59rem; text-overflow: ellipsis; white-space: nowrap; }
.report-actions { display: flex; gap: .3rem; }
.report-actions a, .report-actions button { width: 40px; height: 40px; display: grid; place-items: center; border: 1px solid #dce5e0; border-radius: 9px; background: #fff; color: #176f41; text-decoration: none; }
.report-actions button { color: #936414; border-color: #e3c47f; }
@media (max-width: 1000px) { .report-list article { grid-template-columns: auto 1fr 1fr auto; } .report-person, .report-money { grid-row: 2; } .report-decision { grid-column: 2 / 4; } }
@media (max-width: 650px) {
    .report-date { grid-template-columns: 1fr; }
    .report-date > form { align-items: stretch; flex-direction: column; }
    .report-list article { grid-template-columns: auto 1fr auto; }
    .report-person, .report-money, .report-decision { grid-column: 2 / -1; grid-row: auto; }
    .report-actions { grid-column: 1 / -1; }
    .report-actions a, .report-actions button { flex: 1; width: auto; }
}
</style>
