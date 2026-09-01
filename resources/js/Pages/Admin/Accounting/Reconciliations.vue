<script setup>
import { computed, reactive, ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import AdminLayout from "../../../Layouts/AdminLayout.vue";
import PageHeader from "../../../Components/Ui/PageHeader.vue";
import AccountingNav from "../../../Components/Accounting/AccountingNav.vue";
import AccountingPanel from "../../../Components/Accounting/AccountingPanel.vue";
import CollectionSheet from "../../../Components/Collections/CollectionSheet.vue";
import Pagination from "../../../Components/Ui/Pagination.vue";

defineOptions({ layout: AdminLayout });
const props = defineProps({ accounts: Array, adjustmentAccounts: Array, selectedAccount: Object, statementDate: String, bookBalance: Number, period: Object, reconciliations: Object, currency: Object, urls: Object });
const selector = reactive({ account_id: props.selectedAccount?.id || "", statement_date: props.statementDate });
const form = useForm({ account_id: props.selectedAccount?.id || "", statement_date: props.statementDate, statement_balance: "", notes: "" });
const selectedVariance = ref(null);
const resolution = useForm({ adjustment_account_id: "", posted_on: new Date().toISOString().slice(0, 10), notes: "" });
const difference = computed(() => Number(form.statement_balance || 0) - Number(props.bookBalance || 0));
const money = (value) => `${new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0))} ${props.currency.symbol}`;
const refresh = () => router.get(props.urls.index, selector, { preserveState: false, preserveScroll: true });
const submit = () => form.post(props.urls.store, { preserveScroll: true });

function openResolution(row) {
    selectedVariance.value = row;
    resolution.reset();
    resolution.clearErrors();
    const preferredType = Number(row.difference) < 0 ? "expense" : "revenue";
    resolution.adjustment_account_id = props.adjustmentAccounts.find((account) => account.type === preferredType)?.id ?? "";
    resolution.posted_on = new Date().toISOString().slice(0, 10);
}

function resolveVariance() {
    resolution.post(selectedVariance.value.resolveUrl, {
        preserveScroll: true,
        onSuccess: () => { selectedVariance.value = null; },
    });
}
</script>

<template>
    <Head title="مطابقة الصندوق والبنك" />
    <PageHeader title="مطابقة الصندوق والبنك" icon="bi-check2-square" subtitle="قارن الدفتر بالواقع، ثم أغلق كل فرق بوثيقة وقيد واضح" />
    <AccountingNav :urls="urls" active="reconciliations" />

    <section class="recon-explain"><i class="bi bi-shield-check"></i><div><strong>المطابقة لا تغيّر الدفتر تلقائياً</strong><small>الفرق يبقى مفتوحاً حتى تحدد سببه وحساب التسوية. عند الاعتماد ينشئ النظام قيداً مرتبطاً بالمطابقة ويحفظ المنفّذ والتاريخ.</small></div></section>

    <div class="recon-layout">
        <AccountingPanel title="مطابقة جديدة" description="أدخل ما وجدته في الصندوق أو كشف البنك." icon="bi-bank">
            <form class="selector" @change="refresh">
                <label><span>الحساب</span><select v-model="selector.account_id" class="form-select"><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.code }} — {{ account.name }}</option></select></label>
                <label><span>تاريخ الكشف أو الجرد</span><input v-model="selector.statement_date" type="date" class="form-control" /></label>
            </form>
            <form v-if="selectedAccount" class="recon-form" @submit.prevent="submit">
                <div class="balance-box"><span><small>رصيد الدفتر</small><strong>{{ money(bookBalance) }}</strong></span><i class="bi bi-arrow-left-right"></i><label><span>الرصيد الفعلي *</span><input v-model="form.statement_balance" type="number" step=".01" class="form-control" placeholder="0.00" required /></label></div>
                <div class="difference" :class="Math.abs(difference) < .01 ? 'ok' : 'warn'"><span><i class="bi" :class="Math.abs(difference) < .01 ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>{{ Math.abs(difference) < .01 ? 'مطابق بلا فرق' : 'فرق سيبقى مفتوحاً للمراجعة' }}</span><strong>{{ money(difference) }}</strong></div>
                <label><span>الفترة المحاسبية</span><div class="readonly"><strong>{{ period?.name || "لا توجد فترة" }}</strong><small v-if="period">{{ period.closed ? "مقفلة" : "مفتوحة" }}</small></div></label>
                <label><span>مرجع الكشف وملاحظات العدّ</span><textarea v-model="form.notes" rows="3" class="form-control" placeholder="رقم كشف البنك، الوردية، أو ملاحظات الجرد..."></textarea></label>
                <button class="btn btn-primary" :disabled="form.processing"><i class="bi bi-check2-circle"></i> حفظ نتيجة المطابقة</button>
            </form>
        </AccountingPanel>

        <AccountingPanel title="سجل المطابقات" :description="`${reconciliations.total} مراجعة محفوظة`" icon="bi-clock-history" compact>
            <div class="history">
                <article v-for="row in reconciliations.data" :key="row.id">
                    <span class="status" :class="row.status"><i class="bi" :class="row.status === 'matched' ? 'bi-check2' : row.status === 'resolved' ? 'bi-journal-check' : 'bi-exclamation-triangle'"></i></span>
                    <div class="identity"><strong>{{ row.accountCode }} — {{ row.accountName }}</strong><small>{{ row.date }} · {{ row.reconciler || "—" }}</small><p v-if="row.notes">{{ row.notes }}</p><p v-if="row.resolutionEntry" class="resolution-meta">قيد {{ row.resolutionEntry }} · {{ row.resolver }} · {{ row.resolvedAt }}</p></div>
                    <span><small>الدفتر</small><bdi>{{ money(row.bookBalance) }}</bdi></span><span><small>الفعلي</small><bdi>{{ money(row.statementBalance) }}</bdi></span>
                    <span class="row-diff" :class="row.status"><small>{{ row.statusLabel }}</small><bdi>{{ money(row.difference) }}</bdi></span>
                    <button v-if="row.resolveUrl" type="button" class="resolve-button" @click="openResolution(row)"><i class="bi bi-tools"></i> تسوية الفرق</button>
                </article>
                <div v-if="!reconciliations.data.length" class="empty"><i class="bi bi-clipboard-check"></i><strong>لا توجد مطابقات بعد</strong><span>سجّل أول مطابقة للصندوق أو البنك.</span></div>
            </div>
            <template #footer><Pagination :links="reconciliations.links" /></template>
        </AccountingPanel>
    </div>

    <CollectionSheet :open="Boolean(selectedVariance)" title="تسوية فرق المطابقة" :eyebrow="selectedVariance ? `${selectedVariance.accountCode} · فرق ${money(selectedVariance.difference)}` : ''" icon="bi-journal-plus" @close="selectedVariance = null">
        <form id="reconciliationResolution" class="resolution-form" @submit.prevent="resolveVariance">
            <p><i class="bi bi-info-circle"></i> تحقّق من السبب أولاً. الفرق السالب يُسجل عادةً على مصروف عجز، والموجب على إيراد زيادة. يمكنك اختيار الحساب الأنسب من دليلك.</p>
            <label><span>حساب التسوية المقابل *</span><select v-model="resolution.adjustment_account_id" required><option value="" disabled>اختر الحساب</option><option v-for="account in adjustmentAccounts" :key="account.id" :value="account.id">{{ account.code }} — {{ account.name }}</option></select><small v-if="resolution.errors.adjustment_account_id">{{ resolution.errors.adjustment_account_id }}</small></label>
            <label><span>تاريخ قيد التسوية *</span><input v-model="resolution.posted_on" type="date" :max="new Date().toISOString().slice(0, 10)" required /><small v-if="resolution.errors.posted_on">{{ resolution.errors.posted_on }}</small></label>
            <label><span>سبب الفرق وإجراء المراجعة *</span><textarea v-model="resolution.notes" rows="4" maxlength="1000" required placeholder="مثال: عجز عدّ وردية المساء بعد مراجعة إيصالات الصرف..."></textarea><small v-if="resolution.errors.notes">{{ resolution.errors.notes }}</small></label>
        </form>
        <template #footer><button type="button" class="btn btn-light" @click="selectedVariance = null">تراجع</button><button type="submit" form="reconciliationResolution" class="btn btn-warning" :disabled="resolution.processing"><i class="bi bi-journal-check"></i> ترحيل القيد وإغلاق الفرق</button></template>
    </CollectionSheet>
</template>

<style scoped>
.recon-explain{display:flex;gap:10px;margin-bottom:11px;padding:12px 14px;border:1px solid #b9d7e8;border-radius:13px;color:#1d6287;background:#f0f8fc}.recon-explain>div{display:grid}.recon-explain strong{font-size:.77rem}.recon-explain small{font-size:.66rem;line-height:1.6}.recon-layout{display:grid;grid-template-columns:minmax(320px,.68fr) minmax(0,1.32fr);align-items:start;gap:12px}.recon-layout>:first-child{position:sticky;top:183px}.selector,.recon-form,.resolution-form{display:grid;gap:10px}.selector{padding-bottom:12px;border-bottom:1px solid #edf1ee}.selector label,.recon-form>label,.resolution-form label{display:grid;gap:5px}.selector label>span,.recon-form label>span,.resolution-form label>span{font-size:.68rem;font-weight:850}.balance-box{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:9px;margin-top:2px;padding:11px;border-radius:11px;background:#f4f7f5}.balance-box>span{display:grid}.balance-box small{color:#738178;font-size:.63rem}.balance-box strong{font-size:.82rem}.balance-box>i{color:#8a978f}.balance-box label{display:grid;gap:3px}.difference{display:flex;align-items:center;justify-content:space-between;padding:10px 11px;border-radius:10px}.difference span{display:flex;align-items:center;gap:6px;font-size:.68rem}.difference strong{font-size:.76rem}.difference.ok{color:#1f6b50;background:#eaf5ed}.difference.warn{color:#a15700;background:#fff1dd}.readonly{display:flex;align-items:center;justify-content:space-between;padding:9px 10px;border:1px solid #dfe7e2;border-radius:8px;background:#f8faf9}.readonly strong{font-size:.72rem}.readonly small{font-size:.63rem}.history{display:grid}.history article{display:grid;grid-template-columns:34px minmax(170px,1fr) repeat(3,minmax(86px,.5fr)) auto;align-items:center;gap:9px;padding:12px 14px;border-bottom:1px solid #edf1ee}.status{display:grid;width:32px;height:32px;place-items:center;border-radius:9px}.status.matched{color:#1f6b50;background:#e8f4eb}.status.variance{color:#a25700;background:#fff0dc}.status.resolved{color:#315f95;background:#edf4ff}.history .identity{display:grid}.history .identity strong{font-size:.72rem}.history .identity small,.history .identity p{margin:0;color:#75837a;font-size:.62rem}.history .identity .resolution-meta{color:#315f95;font-weight:750}.history article>span:not(.status){display:grid}.history article>span small{color:#78867e;font-size:.59rem}.history article>span bdi{font-size:.67rem;font-weight:800}.row-diff.matched{color:#1f6b50}.row-diff.variance{color:#ad2929}.row-diff.resolved{color:#315f95}.resolve-button{min-height:38px;padding:0 10px;border:1px solid #dfbd70;border-radius:8px;color:#855c11;background:#fff8e8;font-size:.64rem;font-weight:850}.empty{display:grid;justify-items:center;gap:4px;padding:55px;color:#839087}.empty i{font-size:1.4rem}.empty strong{font-size:.76rem}.empty span{font-size:.66rem}.resolution-form p{margin:0;padding:10px;border-radius:9px;color:#6f5c2d;background:#fff8e8;font-size:.69rem;line-height:1.7}.resolution-form select,.resolution-form input,.resolution-form textarea{min-height:44px;padding:8px;border:1px solid #dce5e0;border-radius:9px}.resolution-form textarea{resize:vertical}.resolution-form label small{color:#b12f37;font-size:.63rem}@media(max-width:1050px){.recon-layout{grid-template-columns:1fr}.recon-layout>:first-child{position:static}}@media(max-width:760px){.history article{grid-template-columns:31px 1fr auto}.history article>span:not(.status):not(.row-diff){display:none}.row-diff{grid-column:3;grid-row:1}.resolve-button{grid-column:2/-1}.balance-box{grid-template-columns:1fr}.balance-box>i{display:none}}
</style>
