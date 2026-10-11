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
.invoice-summary { padding: 1rem; border: 1px solid var(--line, var(--line)); border-radius: 12px; background: var(--surface, #FFFDFA); }
.invoice-summary header { display: flex; align-items: center; justify-content: space-between; padding-bottom: .65rem; border-bottom: 1px solid var(--line, var(--line)); }
.invoice-summary header > div { display: flex; flex-direction: column; }
.invoice-summary header span:first-child { color: var(--muted, var(--muted)); font-size: .875rem; }
.invoice-summary header strong { margin-top: .1rem; color: var(--ink, var(--ink)); font-size: 1.05rem; }
.status { padding: .22rem .48rem; border-radius: 12px; color: #7a4c00; background: #fff5dc; font-size: .875rem; font-weight: 800; }
.status.is-paid { color: #176d37; background: #e9f8ee; }
.status.is-cancelled { color: #8c2932; background: #fff0f1; }
dl { display: flex; margin: .65rem 0 0; flex-direction: column; gap: .6rem; margin-top: .8rem; }
dl > div { display: flex; align-items: center; justify-content: space-between; color: var(--muted, var(--muted)); font-size: .875rem; }
dt, dd { margin: 0; }
dd { color: var(--ink, var(--ink)); font-weight: 760; }
.discount dd { color: #b02a37; }
.grand { margin-top: .25rem; padding-top: .55rem; border-top: 1px dashed var(--line, var(--line)); }
.grand dt, .grand dd { color: var(--ink, var(--ink)); font-size: .875rem; font-weight: 800; }
.paid dd { color: var(--brand); }
.balance { margin-top: .15rem; padding: .85rem; border-radius: 8px; background: var(--brand-soft, #F3E3D8); }
.balance dt, .balance dd { color: var(--brand, #A3422B); font-size: 1.15rem; font-weight: 800; }
.parked { display: flex; align-items: center; gap: .35rem; margin: .55rem 0 0; padding: .45rem; border-radius: 8px; color: #7a4c00; background: #fff8e7; font-size: .875rem; font-weight: 700; }
footer { display: flex; gap: .4rem; margin-top: .6rem; }
footer a { display: inline-flex; min-height: 40px; align-items: center; gap: .3rem; padding-inline: .75rem; border: 1px solid var(--line, var(--line)); border-radius: 9px; color: var(--ink, var(--ink)); text-decoration: none; font-size: .875rem; font-weight: 750; }

/* Task-specific component layout. */
.invoice-summary{border:1px solid var(--line);border-radius:8px;padding:18px;background:var(--surface)}.invoice-summary>header{padding-bottom:14px;border-bottom:1px solid var(--line);margin-bottom:12px;align-items:flex-start}.invoice-summary h3{font-size:20px}.invoice-summary .status{font-size:14px;border-radius:4px}.invoice-summary dl>div{padding:8px 0}.invoice-summary .grand{border-top:1px solid var(--line);padding-top:14px}.invoice-summary .balance{padding:16px;margin:14px -18px -18px;border-radius:0 0 8px 8px;background:var(--ink);color:var(--surface)}.invoice-summary .balance strong,.invoice-summary .balance dd{font-size:27px;color:var(--surface)}.invoice-summary footer{display:flex;gap:10px;flex-wrap:wrap;padding-top:16px}.invoice-summary footer a{min-height:44px;border-radius:6px;font-size:14px}
.invoice-summary .balance dt{color:var(--surface)}.invoice-summary .balance{margin:14px -18px 0;border-radius:0}.invoice-summary header>div>strong{font-size:20px}
</style>
