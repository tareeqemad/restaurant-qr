<script setup>
/**
 * تقييم المخزون — how much money is frozen in inventory right now, or as
 * it stood on any past date, ranked by value with an ABC/Pareto class.
 *
 * Every figure on this page is computed server-side: the per-row share,
 * the cumulative Pareto percentage, the ABC class, the clamped bar width,
 * and the two PHP-only quantity formatters (QuantityFormatter::smart and
 * Qty::format have no JS twin — porting the unit ladder to JS is how a
 * number silently changes). This file formats nothing but money.
 *
 * Two costing methods live on one screen: live rows are priced by the
 * per-branch weighted average of the batches still on hand, historical
 * rows by the last purchase price at-or-before the cutoff. Both pieces of
 * copy (the help card and the historical alert) must stay — dropping the
 * alert would leave the screen lying about its own numbers.
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    rows: { type: Array, default: () => [] },
    totalValue: { type: Number, default: 0 },
    rowCount: { type: Number, default: 0 },
    lowStockValue: { type: Number, default: 0 },
    abcCounts: { type: Object, required: true },
    abcValues: { type: Object, required: true },
    // null when totalValue <= 0 — the Blade printed the literal '0%' there.
    abcAPct: { type: Number, default: null },
    asOf: { type: String, default: null },
    isHistorical: { type: Boolean, default: false },
    branchId: { type: Number, default: null },
    branchName: { type: String, default: '—' },
    canExport: { type: Boolean, default: false },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
});

// Pure presentation — the Blade's $abcMeta, kept as a frozen constant.
const ABC = Object.freeze({
    A: { color: 'danger', label: 'A — أهم 80%' },
    B: { color: 'warning', label: 'B — التالي 15%' },
    C: { color: 'success', label: 'C — آخر 5%' },
});
const abcMeta = (k) => ABC[k] ?? { color: 'secondary', label: '—' };

const money = (v) => formatMoney(v, props.currency);
// number_format($v, $d) — half-up rounding, same as Intl's default
// "halfExpand". toFixed() would drift on binary-representation edges.
const pct = (v, d) => new Intl.NumberFormat('en-US', {
    minimumFractionDigits: d, maximumFractionDigits: d,
}).format(Number(v) || 0);

const form = reactive({ as_of: props.asOf ?? '' });

// The Blade form carried ONLY as_of, so submitting dropped every other
// query param. Sending just { as_of } reproduces that exactly.
const submit = () => {
    router.get(props.urls.self, { as_of: form.as_of || undefined }, {
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="تقييم المخزون" />

    <PageHeader title="تقييم المخزون" icon="bi-cash-stack"
                subtitle="قيمة كل صنف = كميته × تكلفته. يدعم نقطة زمنية في الماضي لإقفال الشهور المحاسبية."
                :crumbs="[{ label: 'التقارير', url: urls.reportsIndex }]" />

    <!-- Branch context chip — informational, states which scope the numbers belong to -->
    <div class="sv-context mb-3">
        <div class="sv-context__main">
            <i class="bi" :class="branchId ? 'bi-shop' : 'bi-building-fill'"></i>
            <span>التقرير معروض لـ:</span>
            <strong>{{ branchName }}</strong>
            <small v-if="branchId" class="text-muted ms-2">— كميات هذا الفرع فقط بأسعاره الفعلية</small>
            <small v-else class="text-muted ms-2">— مجموع كل الفروع (Σ كمية الفرع × سعر الفرع)</small>
        </div>
        <small class="text-muted">
            <i class="bi bi-info-circle"></i>
            غيّر الفرع من المبدّل أعلى الصفحة لرؤية تقييم فرع آخر.
        </small>
    </div>

    <!-- Native <details> on purpose: the disclosure works with JS broken. -->
    <details class="sv-help mb-3" open>
        <summary class="sv-help__head">
            <span class="sv-help__title">
                <i class="bi bi-info-circle-fill"></i>
                كيف أقرأ هذا التقرير؟ (شرح الأعمدة)
            </span>
            <i class="bi bi-chevron-down sv-help__caret"></i>
        </summary>
        <div class="sv-help__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="sv-help__card">
                        <h6><i class="bi bi-cash-stack text-primary"></i> الأعمدة المالية</h6>
                        <ul>
                            <li><b>الكمية</b>: المخزون الفعلي للصنف في الفرع المعروض.</li>
                            <li><b>سعر الوحدة</b>: متوسط مرجَّح من دفعات الشراء النشطة بهذا الفرع.</li>
                            <li><b>قيمة الصنف</b> = الكمية × سعر الوحدة. هذا هو المال المحبوس في هذا المكوّن.</li>
                            <li><b>حصة %</b>: حصة هذا الصنف من إجمالي قيمة المخزون.</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="sv-help__card">
                        <h6><i class="bi bi-pie-chart-fill text-warning"></i> «تراكمي %» وتحليل ABC</h6>
                        <ul>
                            <li><b>تراكمي %</b>: مجموع الحصص من أعلى صنف لهذا الصنف.</li>
                            <li>مثال: لو وصل لـ 80% عند الصف العاشر → أول 10 أصناف تشكّل 80% من قيمة مخزونك.</li>
                            <li><span class="badge bg-danger">A</span> أهم 80% من القيمة — جردها <b>أسبوعياً</b> وراقب تكلفتها.</li>
                            <li><span class="badge bg-warning text-dark">B</span> الـ 15% التالية — جردها <b>شهرياً</b>.</li>
                            <li><span class="badge bg-success">C</span> آخر 5% — جردها <b>كل ربع سنة</b>.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </details>

    <StatRail :stats="[
        { label: 'إجمالي قيمة المخزون', value: money(totalValue), icon: 'bi-cash-stack', color: 'primary' },
        { label: 'عدد الأصناف', value: rowCount, icon: 'bi-collection-fill', color: 'info' },
        { label: 'قيمة المنخفض المخزون', value: money(lowStockValue), icon: 'bi-exclamation-triangle', color: 'warning' },
        { label: 'فئة A (الأهم)', value: abcCounts.A, icon: 'bi-star-fill', color: 'danger' },
        { label: 'قيمة فئة A', value: money(abcValues.A), icon: 'bi-cash', color: 'danger' },
        { label: 'كـ %', value: abcAPct === null ? '0%' : pct(abcAPct, 1) + '%', icon: 'bi-pie-chart-fill', color: 'accent' },
    ]" />

    <DataPanel :title="isHistorical ? 'صورة المخزون كما كان في ' + asOf : 'صورة المخزون الحالية'"
               icon="bi-table" :count="rowCount">
        <template #actions>
            <!-- Streamed file: a plain <a>, never an Inertia visit. -->
            <a v-if="canExport" :href="urls.exportXlsx" class="btn btn-sm btn-success sv-btn"
               title="تنزيل التقرير كملف Excel — ورقتان: ملخص + تفاصيل بأعمدة منفصلة">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> تصدير Excel
            </a>
            <a v-if="isHistorical" :href="urls.current" class="btn btn-sm btn-light sv-btn">
                <i class="bi bi-arrow-clockwise me-1"></i> الصورة الحالية
            </a>
        </template>

        <template #filters>
            <form class="row g-2 align-items-end" @submit.prevent="submit">
                <div class="col-md-4">
                    <label class="form-label fs-12 mb-1">تقييم بتاريخ <small class="text-muted">(اتركه فارغاً للمخزون الحالي)</small></label>
                    <input v-model="form.as_of" type="date" class="form-control form-control-sm sv-input">
                </div>
                <!-- ABC summary mini-bar — read-only, lives inside the filter row -->
                <div class="col-md-8">
                    <div class="d-flex gap-2 align-items-center justify-content-end flex-wrap">
                        <span v-for="k in ['A', 'B', 'C']" :key="k"
                              class="badge fs-12"
                              :class="`bg-${abcMeta(k).color}-transparent text-${abcMeta(k).color}`">
                            {{ abcMeta(k).label }}:
                            {{ abcCounts[k] }} صنف
                            ({{ money(abcValues[k]) }})
                        </span>
                    </div>
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary btn-sm px-5 sv-btn"><i class="bi bi-search"></i> استعلام</button>
                </div>
            </form>
        </template>

        <div v-if="isHistorical" class="alert alert-info">
            <i class="bi bi-clock-history"></i>
            <strong>الوضع التاريخي:</strong>
            الكميات أُعيد بناؤها بإعادة عكس كل حركات ما بعد {{ asOf }}.
            التكلفة = آخر تكلفة 'in' في أو قبل التاريخ.
        </div>

        <EmptyState v-if="rows.length === 0"
                    icon="bi-box-seam"
                    title="لا أصناف للتقييم"
                    message="لا يوجد مكونات نشطة مع تتبع مخزون." />

        <template v-else>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>#</th>
                            <th>المكوّن</th>
                            <th>المورّد</th>
                            <th class="text-end" title="المخزون الفعلي للصنف في الفرع المعروض">
                                الكمية <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th class="text-end" title="متوسط مرجَّح من دفعات الشراء النشطة بهذا الفرع">
                                سعر الوحدة <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th class="text-end" title="الكمية × سعر الوحدة — المال المحبوس في هذا المكوّن">
                                قيمة الصنف <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th class="text-end" title="حصة هذا الصنف من إجمالي قيمة المخزون = القيمة ÷ الإجمالي × 100">
                                حصة % <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th class="text-end" title="مجموع الحصص من أعلى صنف لهذا الصنف. مثال: لو وصل لـ 80% عند الصف العاشر فأول 10 أصناف تشكّل 80% من مخزونك (نقطة باريتو).">
                                تراكمي % <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th title="A = أهم 80% من القيمة، B = التالي 15%، C = آخر 5%">
                                فئة ABC <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in rows" :key="r.ingredientId" :class="{ 'table-warning': r.isLowStock }">
                            <td class="text-muted">{{ i + 1 }}</td>
                            <td>
                                <div class="fw-semibold">{{ r.name }}</div>
                                <small v-if="r.sku" class="text-muted">{{ r.sku }}</small>
                            </td>
                            <td class="fs-12">{{ r.supplier ?? '—' }}</td>
                            <td class="text-end" :class="{ 'text-danger': r.qty <= 0 }" :title="r.qtyTitle">
                                {{ r.qtyDisplay }}
                            </td>
                            <td class="text-end">{{ r.unitCostDisplay }}</td>
                            <td class="text-end fw-bold text-primary">{{ money(r.value) }}</td>
                            <td class="text-end fs-13">{{ pct(r.sharePct, 2) }}%</td>
                            <td class="text-end fs-13"
                                :title="`بعد هذا الصنف، تكون قد غطّيت ${pct(r.cumulativePct, 1)}% من قيمة مخزونك`">
                                <div class="sv-cum">
                                    <span class="sv-cum__pct">{{ pct(r.cumulativePct, 1) }}%</span>
                                    <div class="progress sv-cum__bar">
                                        <div class="progress-bar" :class="`bg-${abcMeta(r.abcClass).color}`"
                                             :style="{ width: r.cumulativeBarPct + '%' }"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge" :class="`bg-${abcMeta(r.abcClass).color}`">{{ r.abcClass }}</span>
                            </td>
                            <td>
                                <a :href="r.priceHistoryUrl" class="text-decoration-none fs-13 sv-icon-link" title="تاريخ الأسعار">
                                    <i class="bi bi-graph-up-arrow"></i>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-light">
                        <tr>
                            <td colspan="5" class="text-end fw-bold">الإجمالي</td>
                            <td class="text-end fw-bold text-primary fs-15">{{ money(totalValue) }}</td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mt-3 fs-12 text-muted">
                <i class="bi bi-info-circle"></i>
                <strong>ABC:</strong>
                تحليل باريتو — صنف A يمثل أهم 80% من القيمة (يستحق أعلى تركيز إداري).
                صنف B يمثل التالي 15%. صنف C يمثل آخر 5%.
                استخدمها لتحديد أولوية الجرد، التفاوض مع المورّدين، ومتابعة الهدر.
            </div>
        </template>
    </DataPanel>
</template>

<style scoped>
/* ─── Branch context chip ─────────────────────────────────────── */
.sv-context {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: .75rem;
    background: linear-gradient(90deg, #f0fdf4 0%, #fff 100%);
    border: 1px solid #bbf7d0;
    border-radius: 12px;
    padding: .65rem 1rem;
}
.sv-context__main {
    display: inline-flex; align-items: center; gap: .5rem; flex-wrap: wrap;
    font-size: .9rem; color: #14532d;
}
.sv-context__main i { font-size: 1.1rem; color: #16a34a; }
.sv-context__main strong { color: #0f172a; font-size: 1rem; }

/* ─── Help banner — native <details>/<summary> ─────────────────── */
.sv-help {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
}
/* Strip the browser's default disclosure triangle */
.sv-help > summary { list-style: none; }
.sv-help > summary::-webkit-details-marker { display: none; }
.sv-help > summary::marker { content: ''; }

.sv-help__head {
    display: flex; align-items: center; justify-content: space-between;
    background: linear-gradient(90deg, #eff6ff 0%, #fff 100%);
    padding: .85rem 1.1rem;
    min-height: 44px;
    font-weight: 700; cursor: pointer;
    transition: background .15s;
    user-select: none;
}
.sv-help__head:hover { background: linear-gradient(90deg, #dbeafe 0%, #fff 100%); }
.sv-help__title { display: inline-flex; align-items: center; gap: .55rem; color: #1e3a8a; }
.sv-help__title i { color: #2563eb; font-size: 1.1rem; }

/* Rotate the caret to reflect open/closed state */
.sv-help__caret { color: #64748b; transition: transform .2s ease; }
.sv-help[open] .sv-help__caret { transform: rotate(180deg); }

.sv-help__body { padding: 1rem 1.1rem; border-top: 1px solid #f1f5f9; }
.sv-help__card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    height: 100%;
}
.sv-help__card h6 {
    font-weight: 800; color: #0f172a; margin-bottom: .65rem;
    display: inline-flex; align-items: center; gap: .35rem;
}
.sv-help__card ul {
    list-style: none; padding: 0; margin: 0;
    font-size: .82rem; color: #475569; line-height: 1.7;
}
.sv-help__card ul li { padding: 2px 0; }
.sv-help__card ul li b { color: #0f172a; }

/* ─── Cumulative % cell ───────────────────────────────────────── */
.sv-cum {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 3px;
    min-width: 80px;
}
.sv-cum__pct {
    font-weight: 800;
    font-size: .82rem;
    font-variant-numeric: tabular-nums;
    color: #0f172a;
}
.sv-cum__bar { width: 80px; height: 6px; }

/* Restaurant tablets: keep every tap target at 44px even on btn-sm. */
.sv-btn { min-height: 44px; display: inline-flex; align-items: center; gap: .25rem; }
.sv-input { min-height: 44px; }
.sv-icon-link { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; }
</style>
