<script setup>
/**
 * تقرير نهاية اليوم — the sheet a manager reads before locking up: what was
 * billed, what was actually collected and by whom, the invoice states, the
 * day's top sellers, and every ingredient that left the store today (sale
 * vs waste) with its cost.
 *
 * Completeness beats beauty here. Every figure is server-computed — including
 * the two inventory cost totals, which the old Blade accumulated while it
 * iterated the rendered rows. A financial total built by a template is a
 * total that drifts.
 *
 * Two deliberate quirks preserved from the Blade:
 *   • the inventory costs print with 2 decimals and NO currency symbol,
 *     unlike every other money cell on the page;
 *   • the invoice aggregates include draft and cancelled invoices, so the
 *     three status counters do not have to sum to invoices_count.
 *
 * Printing is browser-native window.print() over the live DOM. The @media
 * print rules must stay in an UNSCOPED <style> block: they target
 * .app-sidebar / .app-header / footer, which live in AdminLayout, and a
 * scoped block's data-v attribute would silently stop them matching.
 */
import { reactive, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    date: { type: String, required: true },
    summary: { type: Object, required: true },
    byMethod: { type: Array, default: () => [] },
    byMethodTotals: { type: Object, required: true },
    byCollector: { type: Array, default: () => [] },
    topItems: { type: Array, default: () => [] },
    inventoryUsage: { type: Array, default: () => [] },
    inventoryTotals: { type: Object, required: true },
    statTones: { type: Object, required: true },
    invoicesPaidLabel: { type: String, required: true },
    brandName: { type: String, default: '' },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const form = reactive({ date: props.date });

const money = (v) => formatMoney(v, props.currency);

// Explicit submit only — the Blade never auto-submitted on change.
const submit = () => router.get(props.urls.self, { date: form.date }, {
    preserveState: true,
    preserveScroll: true,
});

const print = () => window.print();

// The print rules at the bottom of this file must reach outside the
// component (AdminLayout's sidebar/header), so they cannot be `scoped`.
// An unscoped block is injected once and never removed, which would rewrite
// the printout of every page visited afterwards in the same SPA session —
// so they are gated on a body class this page owns only while it is mounted.
onMounted(() => document.body.classList.add('printing-end-of-day'));
onBeforeUnmount(() => document.body.classList.remove('printing-end-of-day'));
</script>

<template>
    <Head title="تقرير نهاية اليوم" />

    <PageHeader :title="`تقرير نهاية اليوم — ${date}`" icon="bi-calendar-check"
                subtitle="ملخص شامل للمبيعات والنقدية والخصومات"
                :crumbs="[{ label: 'التقارير', url: urls.reportsIndex }]">
        <template #actions>
            <button type="button" class="btn btn-light" @click="print">
                <i class="bi bi-printer"></i> طباعة
            </button>
        </template>
    </PageHeader>

    <!-- Print-only header — replaces the page header on paper, since the
         chrome around it is hidden. -->
    <div class="eod-print-header d-none">
        <div class="eod-print-brand"><strong>{{ brandName }}</strong></div>
        <div class="eod-print-title">تقرير نهاية اليوم</div>
        <div class="eod-print-date">التاريخ: {{ date }}</div>
        <hr>
    </div>

    <div class="data-panel-filters mb-3 eod-filters">
        <form class="row g-2 align-items-end" @submit.prevent="submit">
            <div class="col-md-4 mx-auto">
                <label class="form-label small text-muted fw-bold">تاريخ الإقفال</label>
                <input v-model="form.date" type="date" name="date" class="form-control">
            </div>
            <div class="col-12 text-center mt-2">
                <button type="submit" class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
            </div>
        </form>
    </div>

    <StatRail :stats="[
        { label: 'إجمالي المقبوض', value: money(summary.total_collected), icon: 'bi-wallet2', color: 'success' },
        { label: 'إجمالي الفواتير', value: money(summary.total_billed), icon: 'bi-receipt', color: 'primary' },
        { label: 'المبيعات بدون ضريبة', value: money(summary.gross_sales), icon: 'bi-cash-stack', color: 'accent' },
        { label: 'عدد الطلبات', value: summary.orders_count, icon: 'bi-bag-check', color: statTones.orders },
        { label: 'الفواتير المدفوعة', value: invoicesPaidLabel, icon: 'bi-check-circle', color: 'success' },
        { label: 'الخصومات', value: money(summary.discount_total), icon: 'bi-tag', color: statTones.discount },
    ]" />

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><strong><i class="bi bi-cash"></i> الدفعات حسب الطريقة</strong></div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead class="bg-light"><tr><th>الطريقة</th><th>عدد</th><th>الإجمالي</th></tr></thead>
                        <tbody>
                            <tr v-for="(m, i) in byMethod" :key="i">
                                <!-- An unrecognised method renders an empty cell — as it does today. -->
                                <td>{{ m.label }}</td>
                                <td>{{ m.count }}</td>
                                <td class="fw-bold">{{ money(m.total) }}</td>
                            </tr>
                            <tr v-if="byMethod.length === 0">
                                <td colspan="3" class="text-center text-muted py-3">لا دفعات</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-light fw-bold">
                            <tr>
                                <td>الإجمالي</td>
                                <td>{{ byMethodTotals.count }}</td>
                                <td>{{ money(byMethodTotals.total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong><i class="bi bi-receipt"></i> حالات الفواتير</strong></div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between"><span>مدفوعة كاملة</span><strong class="text-success">{{ summary.invoices_paid }}</strong></li>
                    <li class="list-group-item d-flex justify-content-between"><span>غير مدفوعة/جزئية</span><strong class="text-warning">{{ summary.invoices_unpaid }}</strong></li>
                    <li class="list-group-item d-flex justify-content-between"><span>شطب</span><strong class="text-dark">{{ summary.invoices_writeoff }}</strong></li>
                    <li class="list-group-item d-flex justify-content-between"><span>الخصومات</span><strong>{{ money(summary.discount_total) }}</strong></li>
                    <li class="list-group-item d-flex justify-content-between"><span>الضريبة المحصّلة</span><strong>{{ money(summary.tax_total) }}</strong></li>
                    <li class="list-group-item d-flex justify-content-between"><span>رسوم الخدمة</span><strong>{{ money(summary.service_total) }}</strong></li>
                </ul>
            </div>

            <div v-if="byCollector.length" class="card">
                <div class="card-header"><strong><i class="bi bi-person-check"></i> التحصيل حسب المستخدم</strong></div>
                <table class="table mb-0">
                    <thead class="bg-light"><tr><th>المستخدم المسؤول</th><th>عمليات</th><th>نقدي</th><th>تحويل</th><th>الإجمالي</th></tr></thead>
                    <tbody>
                        <!-- cash + transfer < total is normal: card/app/credit
                             money lives in `total` with no column of its own. -->
                        <tr v-for="(c, i) in byCollector" :key="i">
                            <td class="fw-bold">{{ c.name }}</td>
                            <td>{{ c.count }}</td>
                            <td>{{ money(c.cash) }}</td>
                            <td>{{ money(c.transfer) }}</td>
                            <td class="fw-bold">{{ money(c.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><strong><i class="bi bi-trophy"></i> أكثر الأصناف مبيعاً</strong></div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead class="bg-light"><tr><th>#</th><th>الصنف</th><th>كمية</th><th>إجمالي</th></tr></thead>
                        <tbody>
                            <tr v-for="r in topItems" :key="r.rank">
                                <td>{{ r.rank }}</td>
                                <td class="fw-bold">{{ r.name }}</td>
                                <td>{{ r.qtyText }}</td>
                                <td>{{ money(r.total) }}</td>
                            </tr>
                            <tr v-if="topItems.length === 0">
                                <td colspan="4" class="text-center text-muted py-3">—</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="card">
                <div class="card-header">
                    <strong><i class="bi bi-boxes"></i> استهلاك المخزون</strong>
                </div>
                <div class="px-3 pt-2 pb-1 small text-muted eod-usage-note">
                    المكوّنات اللي خرجت من المستودع اليوم — سواء استُهلكت في تحضير الأصناف المباعة (<span class="text-primary fw-bold">بيع</span>) أو سُجِّلت كهدر (<span class="text-danger fw-bold">هدر</span>).
                    التكلفة محسوبة بـ <strong>متوسط سعر الشراء</strong> الفعلي للمكوّن وقت الحركة.
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead class="bg-light"><tr><th>المكون</th><th>النوع</th><th>كمية</th><th>تكلفة</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, i) in inventoryUsage" :key="i">
                                <td class="small">{{ row.name }}</td>
                                <td>
                                    <span v-if="row.isWaste" class="badge bg-danger-transparent text-danger">هدر</span>
                                    <span v-else class="badge bg-primary-transparent text-primary">بيع</span>
                                </td>
                                <!-- qtyText / qtyExact come from QuantityFormatter, a
                                     PHP-only unit ladder with no JS twin. -->
                                <td :title="row.qtyExact">{{ row.qtyText }}</td>
                                <td class="small">{{ row.costText }}</td>
                            </tr>
                            <tr v-if="inventoryUsage.length === 0">
                                <td colspan="4" class="text-center text-muted py-3">—</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="inventoryTotals.showFooter" class="bg-light">
                            <tr>
                                <td colspan="3" class="text-primary"><strong>تكلفة الاستهلاك (بيع)</strong></td>
                                <td class="fw-bold text-primary">{{ inventoryTotals.usageText }}</td>
                            </tr>
                            <tr v-if="inventoryTotals.showWasteRow">
                                <td colspan="3" class="text-danger"><strong>تكلفة الهدر</strong></td>
                                <td class="fw-bold text-danger">{{ inventoryTotals.wasteText }}</td>
                            </tr>
                            <tr>
                                <td colspan="3"><strong>الإجمالي</strong></td>
                                <td class="fw-bold">{{ inventoryTotals.grandText }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.eod-filters {
    background: white;
    border-radius: 14px;
    padding: 1rem;
    border: 1px solid rgba(var(--primary-rgb), .1);
}
.eod-filters .form-control,
.eod-filters .btn { min-height: 44px; }
.eod-usage-note {
    line-height: 1.55;
    background: rgba(15, 71, 49, .03);
    border-bottom: 1px solid rgba(15, 71, 49, .06);
}
</style>

<style>
/* UNSCOPED on purpose. These rules reach outside this component into
   AdminLayout (.app-sidebar, .app-header, footer). A `scoped` block would
   stamp a data-v attribute on every selector, none of them would match, and
   the printout would regress to "sidebar and nav on paper". Everything below
   lives inside @media print, so nothing leaks onto the screen.

   Goals:
     1. Hide all admin chrome (sidebar, header, page header, filter form,
        print button itself) so only the report renders.
     2. Show the print-only header block (brand + date) instead.
     3. Stack the three columns into one flow — A4 portrait is too narrow for
        the 5/4/3 grid, items get cropped otherwise.
     4. Force backgrounds + borders to print so the report doesn't come out as
        a wall of plain text.
     5. One page when possible: trim padding, shrink stat cards.

   Note vs the Blade: it hid `.breadcrumb-wrap` / `.breadcrumb-actions`,
   neither of which exists — the header renders `.page-header`, so the title
   and breadcrumb trail printed. Hiding `.page-header` fixes that. */
@media print {
    /* Strip the admin shell — only the report content prints. */
    body.printing-end-of-day { background: white !important; }
    body.printing-end-of-day .app-sidebar,
    body.printing-end-of-day .app-header,
    body.printing-end-of-day .page-header,
    body.printing-end-of-day .data-panel-filters,
    body.printing-end-of-day .stat-rail,
    body.printing-end-of-day nav,
    body.printing-end-of-day header,
    body.printing-end-of-day footer,
    body.printing-end-of-day .alert,
    body.printing-end-of-day button { display: none !important; }
    body.printing-end-of-day .app-content,
    body.printing-end-of-day .main-content,
    body.printing-end-of-day .content,
    body.printing-end-of-day main { margin: 0 !important; padding: 8mm !important; max-width: 100% !important; }

    /* Show the print-only header. */
    body.printing-end-of-day .eod-print-header.d-none { display: block !important; }
    body.printing-end-of-day .eod-print-header {
        text-align: center;
        margin-bottom: 6mm;
        font-family: var(--market-font-family);
    }
    body.printing-end-of-day .eod-print-brand   { font-size: 16pt; }
    body.printing-end-of-day .eod-print-title   { font-size: 20pt; font-weight: 900; margin-top: 2mm; }
    body.printing-end-of-day .eod-print-date    { font-size: 11pt; color: #444; margin-top: 1mm; }
    body.printing-end-of-day .eod-print-header hr { border-top: 2px solid #000; margin-top: 3mm; }

    /* Stack the columns vertically — three side-by-side tables don't fit a
       portrait A4 and end up clipped. Each section gets its own width and a
       small break-inside hint. */
    body.printing-end-of-day .col-lg-5,
    body.printing-end-of-day .col-lg-4,
    body.printing-end-of-day .col-lg-3 {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
        page-break-inside: avoid;
    }
    body.printing-end-of-day .row.g-3 > [class*="col-"] { margin-bottom: 6mm; }

    /* Cards: kill drop-shadows + soften borders for ink-friendly output.
       Force backgrounds in card headers so the section titles still stand
       out on paper. */
    body.printing-end-of-day .card {
        box-shadow: none !important;
        border: 1px solid #999 !important;
        border-radius: 4px !important;
        page-break-inside: avoid;
    }
    body.printing-end-of-day .card-header {
        background: #f0f0f0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        border-bottom: 1px solid #999 !important;
        padding: 4mm 5mm !important;
    }
    body.printing-end-of-day .card-body,
    body.printing-end-of-day .card-body.p-0 { padding: 0 !important; }

    /* Tables: tighter rows, visible borders, repeating headers across page
       breaks so a 30-row table stays readable. */
    body.printing-end-of-day table { width: 100% !important; border-collapse: collapse !important; }
    body.printing-end-of-day table thead { display: table-header-group; }
    body.printing-end-of-day table th,
    body.printing-end-of-day table td {
        padding: 2mm 3mm !important;
        border-bottom: 1px solid #ccc !important;
        font-size: 10pt !important;
    }
    body.printing-end-of-day table thead th {
        background: #e8e8e8 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        font-weight: 800;
    }
    body.printing-end-of-day table tfoot td {
        background: #f5f5f5 !important;
        -webkit-print-color-adjust: exact !important;
        font-weight: 800;
        border-top: 2px solid #000 !important;
    }

    /* Badges: text-only, no colored background on paper. */
    body.printing-end-of-day .badge {
        background: transparent !important;
        color: #000 !important;
        border: 1px solid #999 !important;
        padding: 1px 4px !important;
        font-weight: 700 !important;
    }

    /* Hint colors: convert to print-safe equivalents so important rows still
       stand out (variance, danger…). */
    body.printing-end-of-day .text-success { color: #1a6b34 !important; }
    body.printing-end-of-day .text-danger  { color: #8b1a1a !important; }
    body.printing-end-of-day .text-warning { color: #7a5800 !important; }
    body.printing-end-of-day .text-primary { color: #1a2a6b !important; }

    @page {
        size: A4 portrait;
        margin: 10mm 10mm 15mm 10mm;
    }
}
</style>
