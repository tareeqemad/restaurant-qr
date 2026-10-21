<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import FinancialWorkspace from '../../../Components/Accounting/FinancialWorkspace.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
defineOptions({ layout: AdminLayout });
defineProps({ title: String, period: Object, rows: Object, backUrl: String, createUrl: String });
</script>
<template>
<Head :title="title" />
<FinancialWorkspace area="مراجعة الإقفال" mode="records">
    <template #heading><PageHeader :title="title" icon="bi-list-check" :subtitle="`${period.name} · ${period.from} — ${period.to}`" /></template>
    <div class="review-actions"><Link :href="backUrl">العودة إلى فحص الشهر</Link><Link v-if="createUrl" :href="createUrl">فتح المطابقات</Link><strong>{{ rows.total }} سجل يحتاج متابعة</strong></div>
    <p class="scope-note">الجلسات والطلبات وفواتير الموردين تشمل المتبقي المفتوح حتى نهاية الشهر، ولو بدأ قبله. فروق المطابقة تخص مدى الشهر المحدد.</p>
    <div class="review-list"><article v-for="row in rows.data" :key="row.id"><div><strong>{{ row.reference }}</strong><span>{{ row.status }}</span><small>{{ row.date }}</small></div><Link v-if="row.url" :href="row.url">فتح السجل</Link><span v-else>مرجع للمتابعة مع المسؤول المخوّل</span></article><p v-if="!rows.data.length">لا توجد سجلات معلّقة من هذا النوع ضمن الفحص.</p></div>
    <Pagination :links="rows.links" />
</FinancialWorkspace>
</template>
<style scoped>
.review-actions{display:flex;align-items:center;flex-wrap:wrap;gap:16px;margin-bottom:16px}.review-actions a,.review-list a{padding:10px 14px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--brand);font-weight:700}.scope-note{font-size:14px;color:var(--muted);line-height:1.8}.review-list{background:var(--surface);border:1px solid var(--line);border-radius:8px}.review-list article{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px;border-bottom:1px solid var(--line)}.review-list article>div{display:grid;gap:6px}.review-list small,.review-list article>span{color:var(--muted);font-size:14px}.review-list>p{padding:24px}@media(max-width:600px){.review-list article{align-items:start;flex-direction:column}}
</style>
