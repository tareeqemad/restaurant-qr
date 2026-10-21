<script setup>
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import FinancialWorkspace from '../../../Components/Accounting/FinancialWorkspace.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { formatMoney } from '../../../Composables/useMoney';
defineOptions({ layout: AdminLayout });
const props = defineProps({ rows: Object, selected: Object, movements: Object, filters: Object, urls: Object, currency: Object });
const filters = reactive({ from: props.filters.from || '', to: props.filters.to || '', status: props.filters.status || '' });
const money = value => formatMoney(value, props.currency);
const refresh = () => router.get(props.urls.cashShifts, filters, { preserveScroll: true });
const open = row => router.get(props.urls.cashShifts, { ...filters, shift_id: row.id }, { preserveScroll: true });
const kinds = { payment: 'قبض فاتورة', payment_void: 'عكس قبض', refund: 'استرداد نقدي', refund_reversal: 'عكس استرداد', advance_deposit: 'رصيد مقدم', advance_reversal: 'عكس رصيد مقدم', custody_in: 'إدخال نقد للعهدة', custody_out: 'إخراج نقد من العهدة' };
</script>
<template>
<Head title="عهد الموظفين" />
<FinancialWorkspace area="المحاسبة" mode="records">
    <template #heading><PageHeader title="عهد الموظفين" icon="bi-cash-stack" subtitle="كل وردية بموظفها ووقت افتتاحها وإغلاقها، حتى لو امتدت بعد منتصف الليل" /></template>
    <template #navigation><AccountingNav :urls="urls" active="cashShifts" /></template>
    <form class="shift-filters" @submit.prevent="refresh"><label>افتُتحت من<input v-model="filters.from" type="date"></label><label>إلى<input v-model="filters.to" type="date"></label><label>الحالة<select v-model="filters.status"><option value="">كل الورديات</option><option value="open">مفتوحة</option><option value="closed">مغلقة</option><option value="variance">بفرق عند الإغلاق</option></select></label><button>عرض</button></form>
    <p class="note">هذا سجل عهد التحصيل؛ الافتتاح والعدّ لا ينشئان قيودًا محاسبية. راجع الفرق وسببه ثم سوّه من المطابقات عند الحاجة. الدفعات الأقدم من تشغيل الورديات تبقى في سجل الدفعات دون نسبة رجعية.</p>
    <section class="shift-list"><article v-for="row in rows.data" :key="row.id"><div><strong>{{ row.employee }} · #{{ row.id }}</strong><small>{{ row.branch }} · {{ row.closed_at ? 'مغلقة' : 'مفتوحة' }}</small><small>{{ row.opened_at }} ← {{ row.closed_at || 'مستمرة' }}</small></div><span>افتتاح<bdi>{{ money(row.opening_cash) }}</bdi></span><span>متوقع<bdi>{{ money(row.expected_cash) }}</bdi></span><span>معدود<bdi>{{ row.counted_cash === null ? '—' : money(row.counted_cash) }}</bdi></span><span :class="{ variance: Math.abs(row.variance || 0) >= .01 }">الفرق<bdi>{{ row.variance === null ? '—' : money(row.variance) }}</bdi></span><button type="button" @click="open(row)">الحركات والملاحظات</button></article><p v-if="!rows.data.length">لا توجد ورديات ضمن هذه المرشحات.</p></section>
    <Pagination :links="rows.links" />
    <section v-if="selected" class="shift-detail"><h2>حركات عهدة {{ selected.employee }} #{{ selected.id }}</h2><p>{{ selected.notes || 'لا توجد ملاحظات إغلاق.' }}</p><div class="movement-head"><span>العملية والمرجع</span><span>فرع المستند</span><span>النقد</span><span>الوقت</span></div><article v-for="movement in movements.data" :key="movement.id"><span>{{ kinds[movement.kind] || movement.kind }} · {{ movement.reference || movement.event_key }}<small v-if="movement.reason" style="display:block">{{ movement.reason }}</small></span><span>#{{ movement.source_branch_id }}</span><bdi>{{ money(movement.amount) }}</bdi><time>{{ movement.occurred_at }}</time></article><p v-if="!movements.data.length">لا توجد حركة نقدية في هذه الوردية.</p><Pagination :links="movements.links" /></section>
</FinancialWorkspace>
</template>
<style scoped>
.shift-filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px}.shift-filters label{display:grid;gap:6px}.shift-filters input,.shift-filters select,button{min-height:44px;padding:8px 12px;border:1px solid var(--line);border-radius:6px;font:inherit;background:var(--surface);color:var(--ink)}button{color:var(--brand);font-weight:700}.note{color:var(--muted);font-size:14px;line-height:1.8;margin:16px 0}.shift-list,.shift-detail{background:var(--surface);border:1px solid var(--line);border-radius:8px}.shift-list article{display:grid;grid-template-columns:2fr repeat(4,1fr) auto;gap:14px;align-items:center;padding:18px;border-bottom:1px solid var(--line)}.shift-list article>div,.shift-list article>span{display:grid;gap:6px}.shift-list small{color:var(--muted)}.shift-list bdi{font-weight:800}.variance{color:#9d2835}.shift-list>p{padding:20px}.shift-detail{padding:20px;margin-top:22px}.shift-detail h2{font-size:20px}.shift-detail article,.movement-head{display:grid;grid-template-columns:2fr 1fr 1fr 1.5fr;gap:14px;padding:12px 0;border-bottom:1px solid var(--line)}.movement-head{font-weight:700}@media(max-width:850px){.shift-list article{grid-template-columns:repeat(2,1fr)}.shift-list article>div{grid-column:1/-1}.shift-detail article,.movement-head{grid-template-columns:repeat(2,1fr)}}
</style>
