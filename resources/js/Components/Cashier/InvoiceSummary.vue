<script setup>
import { computed } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    invoice: { type: Object, required: true },
    currency: { type: Object, required: true },
});

const additions = computed(() => [
    ['الضريبة', props.invoice.tax_total],
    ['الخدمة', props.invoice.service_total],
    ['التوصيل', props.invoice.delivery_fee],
    ['الإكرامية', props.invoice.tip],
].filter(([, amount]) => Number(amount) > 0));
</script>

<template>
    <section class="invoice-summary" aria-label="ملخص الفاتورة">
        <header>
            <div>
                <span>الفاتورة</span>
                <strong>{{ invoice.number }}</strong>
            </div>
            <span class="status" :class="`is-${invoice.status}`">{{ invoice.status_label }}</span>
        </header>

        <dl>
            <div><dt>المجموع</dt><dd>{{ formatMoney(invoice.subtotal, currency) }}</dd></div>
            <div v-if="invoice.discount_total > 0" class="discount">
                <dt>الخصومات</dt><dd>- {{ formatMoney(invoice.discount_total, currency) }}</dd>
            </div>
            <div v-for="row in additions" :key="row[0]"><dt>{{ row[0] }}</dt><dd>{{ formatMoney(row[1], currency) }}</dd></div>
            <div class="grand"><dt>الإجمالي</dt><dd>{{ formatMoney(invoice.total, currency) }}</dd></div>
            <div class="paid"><dt>صافي المدفوع</dt><dd>{{ formatMoney(invoice.net_paid, currency) }}</dd></div>
            <div class="balance"><dt>المتبقي</dt><dd>{{ formatMoney(invoice.balance, currency) }}</dd></div>
        </dl>

        <p v-if="invoice.parked" class="parked"><i class="bi bi-clock-history"></i> المتبقي مؤجل كدين على الزبون</p>

        <footer>
            <a :href="invoice.print_url" target="_blank" rel="noopener"><i class="bi bi-printer"></i> طباعة</a>
            <a :href="invoice.pdf_url"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        </footer>
    </section>
</template>

<style scoped>
.invoice-summary { padding: .8rem; border: 1px solid #dfe7e2; border-radius: 14px; background: #fff; }
.invoice-summary header { display: flex; align-items: center; justify-content: space-between; padding-bottom: .65rem; border-bottom: 1px solid #edf1ee; }
.invoice-summary header > div { display: flex; flex-direction: column; }
.invoice-summary header span:first-child { color: #7b887f; font-size: .63rem; }
.invoice-summary header strong { margin-top: .1rem; color: #203427; font-size: .78rem; }
.status { padding: .22rem .48rem; border-radius: 999px; color: #7a4c00; background: #fff5dc; font-size: .65rem; font-weight: 800; }
.status.is-paid { color: #176d37; background: #e9f8ee; }
.status.is-cancelled { color: #8c2932; background: #fff0f1; }
dl { display: flex; margin: .65rem 0 0; flex-direction: column; gap: .38rem; }
dl > div { display: flex; align-items: center; justify-content: space-between; color: #627068; font-size: .71rem; }
dt, dd { margin: 0; }
dd { color: #2b3c31; font-weight: 760; }
.discount dd { color: #b02a37; }
.grand { margin-top: .25rem; padding-top: .55rem; border-top: 1px dashed #dce4df; }
.grand dt, .grand dd { color: #1f3126; font-size: .8rem; font-weight: 850; }
.paid dd { color: #176d37; }
.balance { margin-top: .15rem; padding: .55rem .6rem; border-radius: 9px; background: #eff8f1; }
.balance dt, .balance dd { color: var(--cx-primary); font-size: .86rem; font-weight: 900; }
.parked { display: flex; align-items: center; gap: .35rem; margin: .55rem 0 0; padding: .45rem; border-radius: 8px; color: #7a4c00; background: #fff8e7; font-size: .68rem; font-weight: 700; }
footer { display: flex; gap: .4rem; margin-top: .6rem; }
footer a { display: inline-flex; min-height: 40px; align-items: center; gap: .3rem; padding-inline: .75rem; border: 1px solid #dce5df; border-radius: 9px; color: #3e5546; text-decoration: none; font-size: .7rem; font-weight: 750; }
</style>
