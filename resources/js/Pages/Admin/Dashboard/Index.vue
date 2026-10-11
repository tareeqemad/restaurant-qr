<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });
const props = defineProps({
    viewerName: String,
    can: { type: Object, required: true },
    stats: { type: Object, required: true },
    financialPulse: { type: Object, default: () => ({}) },
    actionCenter: { type: Array, default: () => [] },
    inventoryProcurement: { type: Object, required: true },
    customerPulse: { type: Object, required: true },
    dailyOps: { type: Object, required: true },
    branchSnapshot: { type: Array, default: () => [] },
    quickActions: { type: Array, default: () => [] },
    inventoryAlerts: { type: Array, default: () => [] },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const money = value => formatMoney(value, props.currency);
const severityRank = { critical: 0, warning: 1, info: 2 };
const priorities = computed(() => [...props.actionCenter].sort((a, b) =>
    (severityRank[a.severity] ?? 3) - (severityRank[b.severity] ?? 3) || Number(b.count) - Number(a.count)));
const pulse = computed(() => [
    props.can.financials
        ? { label: 'مبيعات اليوم', value: money(props.financialPulse.gross_sales), icon: 'bi-cash-stack', url: props.urls.endOfDay, tone: 'green' }
        : { label: 'طلبات اليوم', value: props.stats.today_orders, icon: 'bi-receipt', url: props.urls.orders, tone: 'green' },
    { label: 'قيد العمل', value: props.stats.active_orders, icon: 'bi-lightning-charge-fill', url: props.urls.orders, tone: 'amber' },
    { label: 'جاهزة للتقديم', value: props.dailyOps.ready_orders, icon: 'bi-check2-circle', url: props.urls.readyOrders, tone: props.dailyOps.ready_orders ? 'blue' : 'muted' },
    { label: 'إشغال الصالة', value: `${props.stats.occupied_tables} / ${props.stats.total_tables}`, icon: 'bi-grid-3x3-gap-fill', url: props.urls.tables, tone: 'primary' },
]);
const inventoryTotal = computed(() => Object.values(props.inventoryProcurement).reduce((sum, value) => sum + Number(value || 0), 0));
const hasManagement = computed(() => props.can.inventory || props.can.customers || props.branchSnapshot.length);
</script>

<template>
<Head title="لوحة التحكم" />
<main class="dashboard">
    <header class="hero">
        <div><small>ملخص اليوم</small><h1>أهلاً، {{ viewerName }}</h1></div>
        <span :class="priorities.length ? 'attention' : 'clear'"><i class="bi" :class="priorities.length ? 'bi-exclamation-triangle-fill' : 'bi-check2-circle-fill'"></i>{{ priorities.length ? `${priorities.length} مهام تحتاج متابعة` : 'لا توجد مهام عاجلة' }}</span>
    </header>

    <div class="dashboard-primary"><section class="tasks panel">
        <header><div><small>الأهم أولاً</small><h2>المطلوب الآن</h2></div><b>{{ priorities.length }}</b></header>
        <div v-if="priorities.length" class="task-grid">
            <a v-for="item in priorities.slice(0, 3)" :key="item.title" :href="item.route" class="task" :data-severity="item.severity">
                <i class="bi task-icon" :class="item.icon"></i><span><strong>{{ item.title }}</strong><small>{{ item.description }}</small></span><b>{{ item.count }}</b><i class="bi bi-chevron-left"></i>
            </a>
        </div>
        <details v-if="priorities.length > 3"><summary>{{ priorities.length - 3 }} مهام إضافية <i class="bi bi-chevron-down"></i></summary><div class="task-grid more"><a v-for="item in priorities.slice(3)" :key="item.title" :href="item.route" class="task" :data-severity="item.severity"><i class="bi task-icon" :class="item.icon"></i><span><strong>{{ item.title }}</strong><small>{{ item.description }}</small></span><b>{{ item.count }}</b></a></div></details>
        <div v-if="!priorities.length" class="all-clear"><i class="bi bi-check2-circle-fill"></i><strong>كل شيء تحت السيطرة الآن</strong><small>لا توجد إجراءات عاجلة ضمن صلاحياتك.</small></div>
    </section>

    </div>
    <section class="pulse"><span class="pulse-label"><i class="bi bi-activity"></i> نبض اليوم</span><a v-for="item in pulse" :key="item.label" :href="item.url" :data-tone="item.tone"><span><i class="bi" :class="item.icon"></i>{{ item.label }}</span><strong>{{ item.value }}</strong></a></section>

    <details v-if="can.inventory && inventoryAlerts.length" class="inventory panel">
        <summary><span><i class="bi bi-box-seam-fill"></i><b>المخزون والصلاحية</b><small>{{ inventoryTotal }} تنبيهاً؛ التفاصيل عند الحاجة</small></span><span class="badges"><em v-if="inventoryProcurement.out_stock">نافد {{ inventoryProcurement.out_stock }}</em><em v-if="inventoryProcurement.expired_batches">منتهي {{ inventoryProcurement.expired_batches }}</em><em>عرض {{ inventoryAlerts.length }}</em></span></summary>
        <div class="inventory-toolbar"><p>راجع الكمية الصالحة والموردين قبل إصدار الشراء.</p><a :href="urls.reorder"><i class="bi bi-cart-plus-fill"></i> اقتراح شراء</a></div>
        <div class="stock-grid"><a v-for="alert in inventoryAlerts" :key="`${alert.kind}-${alert.ingredientId}`" :href="alert.url" :data-severity="alert.severity"><i class="bi" :class="['expired','expiring'].includes(alert.kind) ? 'bi-calendar2-x-fill' : 'bi-box-seam-fill'"></i><span><strong>{{ alert.name }}</strong><small>{{ alert.title }} · {{ alert.detail }}</small><em v-if="alert.suppliers.length"><i class="bi bi-truck"></i>{{ alert.suppliers.join('، ') }}</em></span><i class="bi bi-chevron-left"></i></a></div>
    </details>

    <div class="support" :class="{ single: !can.financials }">
        <section class="shortcuts panel"><header><div><small>حسب صلاحياتك</small><h2>اختصارات العمل</h2></div></header><div class="shortcut-grid"><a v-for="item in quickActions" :key="item.label" :href="item.route"><i class="bi" :class="item.icon"></i><span><strong>{{ item.label }}</strong><small>{{ item.hint }}</small></span><i class="bi bi-chevron-left"></i></a></div></section>
        <section v-if="can.financials" class="finance panel"><header><div><small>اليوم فقط</small><h2>الحالة المالية</h2></div><a :href="urls.endOfDay">نهاية اليوم <i class="bi bi-arrow-left"></i></a></header><div><span><small>المقبوضات</small><strong>{{ money(financialPulse.payments) }}</strong></span><span><small>صافي التشغيل</small><strong :class="{ danger: financialPulse.net_operating < 0 }">{{ money(financialPulse.net_operating) }}</strong></span><span><small>ذمم مفتوحة</small><strong>{{ money(financialPulse.open_balance) }}</strong></span></div></section>
    </div>

    <details v-if="hasManagement" class="management panel"><summary><span><i class="bi bi-briefcase-fill"></i><b>تفاصيل الإدارة</b><small>الفروع والعملاء والمخزون</small></span><i class="bi bi-chevron-down"></i></summary><div class="management-grid"><a v-if="can.inventory" :href="urls.inventory"><b>المخزون والمشتريات</b><span>نافد <strong>{{ inventoryProcurement.out_stock }}</strong></span><span>منخفض <strong>{{ inventoryProcurement.low_stock }}</strong></span><span>ينتهي قريباً <strong>{{ inventoryProcurement.expiring_batches }}</strong></span></a><a v-if="can.customers" :href="urls.reservations"><b>العملاء والحجوزات</b><span>حجوزات اليوم <strong>{{ customerPulse.reservations_today }}</strong></span><span>بانتظار التأكيد <strong>{{ customerPulse.reservations_pending }}</strong></span><span>تقييمات منخفضة <strong>{{ customerPulse.low_reviews }}</strong></span></a><article v-if="branchSnapshot.length"><b>الفروع اليوم</b><span v-for="branch in branchSnapshot.slice(0,4)" :key="branch.id">{{ branch.name }} <strong>{{ money(branch.sales) }}</strong></span></article></div></details>
</main>
</template>

<style scoped>
.dashboard{display:grid;gap:.85rem}.hero,.panel,.pulse{background:var(--surface);border:1px solid var(--line);border-radius:12px}.hero{display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.25rem;background:var(--surface)}.hero small,.panel header small{color:var(--brand);font-size:.875rem}.hero h1{font-size:1.55rem;margin:.08rem 0}.hero p{margin:0;color:var(--brand);font-size:.875rem}.hero>span{display:flex;align-items:center;gap:.45rem;border-radius:11px;padding:.65rem .85rem;font-size:.875rem;font-weight:800}.hero .attention{background:#fff4e5;color:#9b5d00}.hero .clear{background:var(--brand-soft);color:var(--brand)}.panel{padding:1rem}.panel>header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem}.panel h2{font-size:1rem;margin:.08rem 0 0}.panel>header>b{display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:#edf4f0;color:var(--brand)}.task-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem}.task{display:grid;grid-template-columns:42px minmax(0,1fr) auto 18px;align-items:center;gap:.55rem;text-decoration:none;color:var(--ink);border:1px solid var(--line);border-inline-end:4px solid #d69a2a;border-radius:12px;padding:.7rem;background:var(--surface)}.task[data-severity=critical]{border-inline-end-color:#d34040}.task-icon{display:grid;place-items:center;width:40px;height:40px;border-radius:10px;background:#fff4e6;color:#b87200}.task[data-severity=critical] .task-icon{background:#fff0f0;color:#bd3636}.task span{display:grid;min-width:0}.task strong{font-size:.875rem}.task small{font-size:.875rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.task>b{font-size:1rem}.tasks details{margin-top:.65rem}.tasks summary{cursor:pointer;color:var(--brand);font-size:.875rem}.task-grid.more{margin-top:.6rem}.all-clear{display:grid;place-items:center;gap:.2rem;color:var(--brand);padding:1.2rem}.all-clear i{font-size:1.6rem}.all-clear small{color:var(--brand)}.pulse{display:grid;grid-template-columns:150px repeat(4,1fr);overflow:hidden}.pulse-label,.pulse a{display:flex;align-items:center;justify-content:space-between;gap:.4rem;padding:.75rem .85rem;border-inline-start:1px solid var(--line);text-decoration:none;color:var(--brand)}.pulse-label{justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:800}.pulse a span{font-size:.875rem}.pulse a strong{color:var(--ink);font-size:.875rem}.inventory{padding:0}.inventory>summary,.management>summary{display:flex;justify-content:space-between;align-items:center;cursor:pointer;padding:1rem;list-style:none}.inventory summary>span:first-child,.management summary>span:first-child{display:grid;grid-template-columns:34px auto;align-items:center}.inventory summary>span:first-child>i,.management summary>span:first-child>i{grid-row:1/3;color:var(--brand)}.inventory summary small,.management summary small{grid-column:2;color:var(--brand);font-size:.875rem}.badges{display:flex;gap:.35rem}.badges em{font-style:normal;background:#fff3df;color:#9b6309;border-radius:12px;padding:.28rem .52rem;font-size:.875rem}.inventory-toolbar{display:flex;justify-content:space-between;align-items:center;padding:.7rem 1rem;background:var(--surface-muted);border-block:1px solid var(--line)}.inventory-toolbar p{margin:0;font-size:.875rem;color:var(--muted)}.inventory-toolbar a{font-size:.875rem;color:var(--brand);text-decoration:none;font-weight:800}.stock-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem;padding:.8rem}.stock-grid>a{display:grid;grid-template-columns:36px 1fr auto;align-items:center;gap:.5rem;text-decoration:none;color:var(--ink);border:1px solid var(--line);border-radius:10px;padding:.55rem}.stock-grid>a>i:first-child{display:grid;place-items:center;width:34px;height:34px;border-radius:9px;background:#fff3df;color:#a56500}.stock-grid span{display:grid}.stock-grid small,.stock-grid em{font-size:.875rem;color:var(--brand);font-style:normal}.support{display:grid;grid-template-columns:1.45fr 1fr;gap:.85rem}.support.single{grid-template-columns:1fr}.shortcut-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem}.shortcut-grid a{display:grid;grid-template-columns:38px 1fr auto;align-items:center;gap:.5rem;padding:.6rem;border:1px solid var(--line);border-radius:11px;text-decoration:none;color:var(--ink)}.shortcut-grid>a>i:first-child{display:grid;place-items:center;width:36px;height:36px;border-radius:9px;background:var(--brand-soft);color:var(--brand)}.shortcut-grid span{display:grid}.shortcut-grid strong{font-size:.875rem}.shortcut-grid small{font-size:.875rem;color:var(--muted)}.finance header>a{font-size:.875rem;color:var(--brand);text-decoration:none}.finance>div{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}.finance span{display:grid;background:var(--surface-muted);border-radius:10px;padding:.75rem}.finance small{font-size:.875rem;color:var(--muted)}.finance strong{font-size:.875rem;color:var(--brand)}.finance strong.danger{color:#b83434}.management{padding:0}.management-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;padding:0 1rem 1rem}.management-grid>*{display:grid;gap:.42rem;border:1px solid var(--line);border-radius:11px;padding:.75rem;text-decoration:none;color:var(--ink)}.management-grid b{font-size:.875rem}.management-grid span{display:flex;justify-content:space-between;font-size:.875rem;color:var(--muted)}.management-grid strong{color:var(--ink)}@media(max-width:1000px){.task-grid{grid-template-columns:1fr}.pulse{grid-template-columns:120px repeat(2,1fr);}.pulse-label{grid-row:1/3}.support{grid-template-columns:1fr}}@media(max-width:650px){.hero{align-items:flex-start;gap:.7rem}.hero h1{font-size:1.18rem}.hero>span{font-size:.875rem}.pulse{grid-template-columns:1fr 1fr}.pulse-label{grid-column:1/-1;grid-row:auto}.pulse a{display:grid}.shortcut-grid,.stock-grid,.management-grid{grid-template-columns:1fr}.finance>div{grid-template-columns:1fr}.task{grid-template-columns:36px 1fr auto}.task>i:last-child{display:none}.badges{display:none}}

.hero{background:var(--ink);color:var(--surface);border-color:var(--ink);border-radius:12px;padding:24px}.hero h1{color:var(--surface)}.hero small{color:#e6c38f}.hero p{color:#ede6dd}.panel,.pulse{border-radius:12px;background:var(--surface)}.shortcut-grid>a>i:first-child{background:var(--brand-soft);color:var(--brand)}.shortcut-grid a{border-radius:8px;min-height:72px}.finance header>a,.inventory-toolbar a{color:var(--brand)}.hero .clear{background:var(--brand-soft);color:var(--brand)}.hero>span{flex-shrink:0}.finance strong{color:var(--ink)}

/* Operational layout and responsive hierarchy. */
.dashboard{gap:20px}.hero{padding:0 0 20px;background:transparent;color:var(--ink);border:0;border-bottom:1px solid var(--line);border-radius:0}.hero h1{font-size:28px;color:var(--ink);margin-top:6px}.hero small{color:var(--muted);font-size:14px}.hero>span{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:10px 14px;color:var(--ink)}.panel{border-radius:10px;padding:20px}.panel h2{font-size:21px}.tasks .task-grid{grid-template-columns:1fr;gap:0}.task{grid-template-columns:40px minmax(0,1fr) auto 18px;border:0;border-bottom:1px solid var(--line);border-radius:0;padding:16px 0;background:transparent;gap:14px}.task strong{font-size:16px}.task small{font-size:14px;white-space:normal;line-height:1.6}.task>b{font-size:24px;color:var(--brand)}.task-icon{border-radius:6px}.pulse{grid-template-columns:repeat(4,minmax(0,1fr));border:0;border-block:1px solid var(--line);border-radius:0;background:transparent}.pulse-label{display:none}.pulse a{display:grid;gap:12px;padding:18px;border-inline-start:1px solid var(--line);background:transparent}.pulse a strong{font-size:25px}.pulse a span{font-size:14px;color:var(--muted)}.support{grid-template-columns:1.2fr 1fr;gap:20px}.shortcut-grid{grid-template-columns:1fr;gap:0}.shortcut-grid a{border:0;border-bottom:1px solid var(--line);border-radius:0;padding:14px 0;min-height:68px}.shortcut-grid strong{font-size:16px}.finance>div{display:grid;grid-template-columns:1fr;gap:0}.finance span{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-bottom:1px solid var(--line);border-radius:0;background:transparent}.finance strong{font-size:19px}.management>summary,.inventory>summary{padding:18px 20px}.all-clear{place-items:start;text-align:start;padding:16px 0;grid-template-columns:32px 1fr;column-gap:12px}.all-clear small{grid-column:2}@media(max-width:850px){.support{grid-template-columns:1fr}.pulse{grid-template-columns:1fr 1fr}.hero{align-items:flex-start;flex-direction:column;gap:16px}}
</style>
