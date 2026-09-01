<script setup>
/**
 * كرت الصنف — one screen that answers "what's going on with sugar today?"
 * without bouncing between four others: stock by location, FIFO batches,
 * the movement ledger, the recipes that eat it, the manufacturing formula
 * if it is composite, every PO line that ever bought it, and the vendor
 * price history.
 *
 * Two things the Blade could not do and this can:
 *   • «تسجيل حركة» in the header actually opens something. The Blade's
 *     button pointed at `#cardAdjust`, a modal that exists nowhere in the
 *     tree — it was dead on every stock card in the app.
 *   • Paging the movement log keeps you on the movement tab, because the
 *     visit preserves component state instead of remounting the page.
 *
 * The 30-day consumption chart is hand-rolled CSS bars: no chart library
 * ships to the client, and the page must work with zero network beyond
 * the app itself.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import AdjustStockSheet from '../../../Components/Ingredients/AdjustStockSheet.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { formatMoney } from '../../../Composables/useMoney';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ingredient: { type: Object, required: true },
    status: { type: Object, required: true },
    hero: { type: Object, required: true },
    kpis: { type: Object, required: true },
    dailySeries: { type: Array, default: () => [] },
    lastIn: { type: Object, default: null },
    lastOut: { type: Object, default: null },
    locations: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    movements: { type: Object, required: true },
    usedInMenuItems: { type: Array, default: () => [] },
    usedInComposites: { type: Array, default: () => [] },
    formula: { type: Object, required: true },
    purchaseLines: { type: Array, default: () => [] },
    purchaseTotal: { type: Number, default: 0 },
    vendorPrices: { type: Array, default: () => [] },
    nav: { type: Object, required: true },
    adjust: { type: Object, required: true },
    today: { type: String, default: '' },
    currency: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const money = (v) => formatMoney(v, props.currency);

// ── Tabs ─────────────────────────────────────────────────────────────
const tab = ref('overview');

const tabs = computed(() => [
    { key: 'overview',  icon: 'bi-bar-chart-line',  label: 'نظرة عامة' },
    { key: 'general',   icon: 'bi-info-circle',     label: 'المعلومات العامة' },
    { key: 'locations', icon: 'bi-geo-alt-fill',    label: `المواقع (${props.locations.length})` },
    { key: 'batches',   icon: 'bi-box2-fill',       label: `الدفعات (${props.batches.length})` },
    { key: 'movements', icon: 'bi-arrow-down-up',   label: `الحركات (${props.movements.total})` },
    { key: 'recipes',   icon: 'bi-basket2',         label: `يُستخدم في (${props.usedInMenuItems.length + props.usedInComposites.length})` },
    ...(props.ingredient.isComposite
        ? [{ key: 'formula', icon: 'bi-diagram-3', label: `معادلة التصنيع (${props.formula.lines.length})` }]
        : []),
    { key: 'invoices',  icon: 'bi-receipt-cutoff',  label: `فواتير الصنف (${props.purchaseLines.length})` },
    { key: 'vendors',   icon: 'bi-tags',            label: `سعر المورد (${props.vendorPrices.length})` },
]);

// ── 30-day consumption chart (CSS bars, no library) ──────────────────
const chartMax = computed(() => Math.max(...props.dailySeries.map((p) => Number(p.qty) || 0), 0));
const barHeight = (qty) => {
    const max = chartMax.value;
    if (max <= 0) return 0;
    return Math.max(2, Math.round((Number(qty) / max) * 100));
};
const trimQty = (qty) => Number(qty ?? 0).toLocaleString('en-US', { maximumFractionDigits: 3 });

// ── Movement paging that does not throw you back to the first tab ────
const pageTo = (url) => {
    if (! url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
};

// ── Adjacent-ingredient navigation ───────────────────────────────────
// Ctrl+Home first · Ctrl+End last · Ctrl+→ previous · Ctrl+← next
// (Arabic/RTL: right means "back"). Never hijacks plain arrows, and never
// fires while the user is typing.
const adjustOpen = ref(false);

const adjustSeed = computed(() => ({
    id: props.ingredient.id,
    name: props.ingredient.name,
    baseCode: props.ingredient.baseCode,
    tracksExpiry: props.ingredient.tracksExpiry,
    currentStockLabel: props.adjust.currentStockLabel,
    currentStockExact: props.adjust.currentStockExact,
    baseUnitId: props.adjust.baseUnitId,
    costPerUnit: props.adjust.costPerUnit,
    locations: props.adjust.locations ?? [],
    actionUrl: props.urls.adjust,
}));

const isTyping = (el) => {
    if (! el) return false;
    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable;
};

const onKey = (e) => {
    if (! e.ctrlKey || e.altKey || e.shiftKey || e.metaKey) return;
    if (isTyping(document.activeElement)) return;
    const target = {
        Home: props.nav.firstUrl,
        End: props.nav.lastUrl,
        ArrowRight: props.nav.prevUrl,
        ArrowLeft: props.nav.nextUrl,
    }[e.key];
    if (target === undefined) return;
    e.preventDefault();
    if (target) router.visit(target);
};

onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));

const batchBadge = {
    expired: { label: 'منتهي', cls: 'bg-danger' },
    near: { label: 'قارب الانتهاء', cls: 'bg-warning text-dark' },
    drained: { label: 'نفدت', cls: 'bg-secondary' },
    active: { label: 'نشطة', cls: 'bg-success' },
};
</script>

<template>
    <Head :title="`كرت الصنف: ${ingredient.name}`" />

    <PageHeader :title="`كرت الصنف: ${ingredient.name}`" icon="bi-card-text"
                :crumbs="[{ label: 'المكونات', url: urls.index }]">
        <template #actions>
            <div class="ing-head-actions">
                <a v-if="can.manage" :href="urls.edit" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil"></i> تعديل
                </a>
                <button v-if="can.manage" type="button" class="btn btn-outline-primary btn-sm"
                        @click="adjustOpen = true">
                    <i class="bi bi-arrow-down-up"></i> تسجيل حركة
                </button>

                <div class="btn-group btn-group-sm" role="group" aria-label="التنقل بين الأصناف">
                    <a :href="nav.prevUrl || '#'" class="btn btn-outline-secondary"
                       :class="{ disabled: ! nav.prevUrl }" title="السابق (Ctrl+→)">
                        <i class="bi bi-chevron-right"></i> السابق
                    </a>
                    <a :href="nav.nextUrl || '#'" class="btn btn-outline-secondary"
                       :class="{ disabled: ! nav.nextUrl }" title="التالي (Ctrl+←)">
                        التالي <i class="bi bi-chevron-left"></i>
                    </a>
                </div>
                <small class="text-muted fw-bold">{{ nav.index }} / {{ nav.total }}</small>
            </div>
        </template>
    </PageHeader>

    <!-- ────────── Hero header ────────── -->
    <div class="card mb-3 ing-hero">
        <div class="card-body ing-hero__body">
            <div class="row align-items-center g-3">
                <div class="col-md-6">
                    <div class="d-flex align-items-center gap-3">
                        <div class="ing-hero__icon"><i class="bi bi-box-seam-fill"></i></div>
                        <div>
                            <h4 class="mb-1 fw-bold">{{ ingredient.name }}</h4>
                            <small class="text-muted">
                                <code>{{ ingredient.sku }}</code>
                                · الوحدة: <strong>{{ ingredient.baseUnitName }} ({{ ingredient.baseCode }})</strong>
                                <template v-if="ingredient.supplierName"> · المورد: {{ ingredient.supplierName }}</template>
                            </small>
                            <div class="mt-1">
                                <span class="badge" :class="`bg-${status.color}`">
                                    <i class="bi" :class="status.icon"></i> {{ status.label }}
                                </span>
                                <span v-if="ingredient.isComposite" class="badge bg-info ms-1">
                                    <i class="bi bi-diagram-3"></i> مكوّن مركّب
                                </span>
                                <span v-if="ingredient.yieldPctLabel" class="badge bg-secondary ms-1">
                                    نسبة استفادة: {{ ingredient.yieldPctLabel }}%
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="ing-hero__box">
                                <small class="text-muted d-block">المخزون الحالي</small>
                                <strong class="d-block fs-5"
                                        :class="hero.isOut ? 'text-danger' : (hero.isLow ? 'text-warning' : 'text-primary')"
                                        :title="`${hero.stockExact} ${ingredient.baseCode ?? ''}`">
                                    {{ hero.stockLabel }}
                                </strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="ing-hero__box">
                                <small class="text-muted d-block">القيمة</small>
                                <strong class="d-block fs-5">{{ money(hero.value) }}</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ────────── Stat strip ────────── -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card text-center"><div class="card-body p-3">
                <small class="text-muted d-block mb-1">استُلم آخر 30 يوم</small>
                <strong class="d-block text-success" :title="`${kpis.received30.exact} ${ingredient.baseCode ?? ''}`">
                    {{ kpis.received30.label }}
                </strong>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card text-center"><div class="card-body p-3">
                <small class="text-muted d-block mb-1">استُهلك آخر 30 يوم</small>
                <strong class="d-block text-primary" :title="`${kpis.consumed30.exact} ${ingredient.baseCode ?? ''}`">
                    {{ kpis.consumed30.label }}
                </strong>
                <small class="text-muted">≈ {{ kpis.weeklyAvgLabel }}/أسبوع</small>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card text-center" :class="{ 'border-warning': kpis.wasted30.positive }"><div class="card-body p-3">
                <small class="text-muted d-block mb-1">هدر آخر 30 يوم</small>
                <strong class="d-block" :class="kpis.wasted30.positive ? 'text-warning' : 'text-muted'"
                        :title="`${kpis.wasted30.exact} ${ingredient.baseCode ?? ''}`">
                    {{ kpis.wasted30.label }}
                </strong>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card text-center" :class="{ 'border-danger': kpis.daysLeft !== null && kpis.daysLeft < 7 }">
                <div class="card-body p-3">
                    <small class="text-muted d-block mb-1">يكفي تقريباً</small>
                    <template v-if="kpis.daysLeft !== null">
                        <strong class="d-block" :class="kpis.daysLeft < 7 ? 'text-danger' : 'text-primary'">
                            {{ kpis.daysLeft }} يوم
                        </strong>
                        <small class="text-muted">بمعدل الاستهلاك الحالي</small>
                    </template>
                    <template v-else>
                        <strong class="d-block text-muted">—</strong>
                        <small class="text-muted">لا استهلاك مسجّل</small>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- ────────── Tabs ────────── -->
    <div class="card">
        <div class="card-header p-0">
            <ul class="nav nav-tabs ing-tabs" role="tablist">
                <li v-for="t in tabs" :key="t.key" class="nav-item">
                    <button type="button" class="nav-link" :class="{ active: tab === t.key }"
                            role="tab" :aria-selected="tab === t.key" @click="tab = t.key">
                        <i class="bi" :class="t.icon"></i> {{ t.label }}
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body">

            <!-- ── Overview ── -->
            <section v-if="tab === 'overview'">
                <h6 class="text-muted mb-3"><i class="bi bi-graph-up"></i> الاستهلاك اليومي — آخر 30 يوم</h6>
                <div class="ing-chart" role="img" aria-label="الاستهلاك اليومي لآخر 30 يوم">
                    <div v-for="p in dailySeries" :key="p.date" class="ing-chart__col"
                         :title="`${p.date} — ${trimQty(p.qty)} ${ingredient.baseCode ?? ''}`">
                        <div class="ing-chart__bar" :style="{ height: `${barHeight(p.qty)}%` }"></div>
                        <span class="ing-chart__lbl">{{ p.date.slice(5) }}</span>
                    </div>
                </div>
                <p v-if="chartMax <= 0" class="text-muted small text-center mt-2">لا استهلاك مسجّل في آخر 30 يوم.</p>
                <hr>
                <div class="row g-2 small text-muted">
                    <div class="col-md-6">
                        <i class="bi bi-arrow-down-circle text-success"></i>
                        آخر استلام: {{ lastIn ? lastIn.ago : 'لا يوجد' }}
                        <template v-if="lastIn"> · <strong>{{ lastIn.qtyLabel }}</strong></template>
                    </div>
                    <div class="col-md-6">
                        <i class="bi bi-arrow-up-circle text-primary"></i>
                        آخر استهلاك: {{ lastOut ? lastOut.ago : 'لا يوجد' }}
                        <template v-if="lastOut"> · <strong>{{ lastOut.qtyLabel }}</strong></template>
                    </div>
                </div>
            </section>

            <!-- ── General info ── -->
            <section v-else-if="tab === 'general'">
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3"><i class="bi bi-tag"></i> الهوية</h6>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-5">SKU</dt>
                            <dd class="col-sm-7"><code>{{ ingredient.sku }}</code></dd>

                            <dt class="col-sm-5">الاسم (عربي)</dt>
                            <dd class="col-sm-7">{{ ingredient.name }}</dd>

                            <dt class="col-sm-5">المورد الافتراضي</dt>
                            <dd class="col-sm-7">{{ ingredient.supplierName || '—' }}</dd>

                            <dt class="col-sm-5">الحالة</dt>
                            <dd class="col-sm-7">
                                <span v-if="ingredient.active" class="badge bg-success">نشط</span>
                                <span v-else class="badge bg-secondary">معطّل</span>
                                <span v-if="ingredient.trackStock" class="badge bg-info ms-1">يتتبَّع المخزون</span>
                                <span v-else class="badge bg-light text-dark border ms-1">لا يتتبَّع</span>
                            </dd>

                            <dt class="col-sm-5">آخر تحديث</dt>
                            <dd class="col-sm-7"><small>{{ ingredient.updatedAt || '—' }}</small></dd>
                        </dl>
                    </div>

                    <div class="col-md-6">
                        <h6 class="text-muted mb-3"><i class="bi bi-cash-coin"></i> التكلفة والإعدادات</h6>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-5">الوحدة الأساسية</dt>
                            <dd class="col-sm-7">{{ ingredient.baseUnitName }} ({{ ingredient.baseCode }})</dd>

                            <dt class="col-sm-5">تكلفة الوحدة</dt>
                            <dd class="col-sm-7">{{ money(ingredient.costPerUnit) }} / {{ ingredient.baseCode }}</dd>

                            <template v-if="ingredient.yieldPctLabel">
                                <dt class="col-sm-5">نسبة الاستفادة</dt>
                                <dd class="col-sm-7">
                                    {{ ingredient.yieldPctLabel }}%
                                    <small class="text-muted d-block">
                                        التكلفة الفعلية: {{ money(ingredient.effectiveCostPerUnit) }} / {{ ingredient.baseCode }}
                                    </small>
                                </dd>
                            </template>

                            <dt class="col-sm-5">حد إعادة الطلب</dt>
                            <dd class="col-sm-7">
                                <template v-if="ingredient.reorderThresholdLabel">{{ ingredient.reorderThresholdLabel }}</template>
                                <span v-else class="text-muted">—</span>
                            </dd>

                            <dt class="col-sm-5">نوع المكوّن</dt>
                            <dd class="col-sm-7">
                                <span v-if="ingredient.isComposite" class="badge bg-info">
                                    <i class="bi bi-diagram-3"></i> مركّب (يحوي وصفة)
                                </span>
                                <span v-else class="badge bg-light text-dark border">خام</span>
                            </dd>

                            <template v-if="ingredient.compositeYieldLabel">
                                <dt class="col-sm-5">ناتج التصنيع</dt>
                                <dd class="col-sm-7">{{ ingredient.compositeYieldLabel }}</dd>
                            </template>
                        </dl>
                    </div>

                    <div v-if="ingredient.packUnits.length" class="col-12">
                        <h6 class="text-muted mb-2"><i class="bi bi-box-seam"></i> وحدات الشراء البديلة</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>الوحدة</th>
                                        <th class="text-end">المعامل ← {{ ingredient.baseCode }}</th>
                                        <th>الباركود</th>
                                        <th class="text-end">سعر الشراء</th>
                                        <th class="text-end">سعر البيع</th>
                                        <th>افتراضي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="u in ingredient.packUnits" :key="u.id">
                                        <td><strong>{{ u.name }}</strong></td>
                                        <td class="text-end"><code>×{{ u.factorLabel }}</code></td>
                                        <td><small class="text-muted">{{ u.barcode || '—' }}</small></td>
                                        <td class="text-end">{{ u.purchasePrice !== null ? money(u.purchasePrice) : '—' }}</td>
                                        <td class="text-end">{{ u.salePrice !== null ? money(u.salePrice) : '—' }}</td>
                                        <td>
                                            <span v-if="u.isDefault" class="badge bg-success"><i class="bi bi-check"></i></span>
                                            <span v-else class="text-muted">—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="ingredient.notes" class="col-12">
                        <h6 class="text-muted mb-2"><i class="bi bi-sticky"></i> ملاحظات</h6>
                        <div class="alert alert-light border mb-0 ing-notes">{{ ingredient.notes }}</div>
                    </div>
                </div>
            </section>

            <!-- ── Locations ── -->
            <section v-else-if="tab === 'locations'">
                <div v-if="locations.length === 0" class="text-center text-muted py-4">لا يوجد رصيد في أي موقع.</div>
                <div v-else class="table-responsive">
                    <table class="table align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>الموقع</th>
                                <th>الفرع</th>
                                <th class="text-end">الكمية</th>
                                <th class="text-end">حد الطلب</th>
                                <th class="text-end">القيمة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="loc in locations" :key="loc.id">
                                <td><strong>{{ loc.name }}</strong></td>
                                <td><small class="text-muted">{{ loc.branchName }}</small></td>
                                <td class="text-end fw-bold" :title="`${loc.qtyExact} ${ingredient.baseCode ?? ''}`">
                                    {{ loc.qtyLabel }}
                                </td>
                                <td class="text-end text-muted">{{ loc.thresholdLabel || '—' }}</td>
                                <td class="text-end">{{ money(loc.value) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ── Batches (FIFO + expiry) ── -->
            <section v-else-if="tab === 'batches'">
                <div v-if="batches.length === 0" class="text-center text-muted py-4">لا توجد دفعات مسجّلة لهذا المكوّن.</div>
                <div v-else class="table-responsive">
                    <table class="table align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>الدفعة</th>
                                <th>الموقع</th>
                                <th class="text-end">المتبقي / الأصلي</th>
                                <th class="text-end">تكلفة الوحدة</th>
                                <th>تاريخ الصلاحية</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in batches" :key="b.id" :class="b.rowTone ? `table-${b.rowTone}` : ''">
                                <td><code>{{ b.label }}</code></td>
                                <td><small>{{ b.locationName }}</small></td>
                                <td class="text-end" :title="`${b.qtyExact} ${ingredient.baseCode ?? ''}`">
                                    <strong>{{ b.remainingLabel }}</strong>
                                    <small class="text-muted">/ {{ b.initialLabel }}</small>
                                </td>
                                <td class="text-end">{{ money(b.unitCost) }}</td>
                                <td>
                                    <small v-if="b.expiryDate">{{ b.expiryDate }}</small>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <span class="badge" :class="batchBadge[b.statusKey].cls">
                                        {{ batchBadge[b.statusKey].label }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ── Movements log ── -->
            <section v-else-if="tab === 'movements'">
                <div v-if="movements.data.length === 0" class="text-center text-muted py-4">لا حركات مخزون لهذا المكوّن.</div>
                <template v-else>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>النوع</th>
                                    <th class="text-end">الكمية</th>
                                    <th class="text-end">الرصيد بعد</th>
                                    <th>السبب</th>
                                    <th>المستخدم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in movements.data" :key="m.id">
                                    <td><small>{{ m.at }}</small></td>
                                    <td><span class="badge" :class="`bg-${m.typeColor}`">{{ m.typeLabel }}</span></td>
                                    <td class="text-end fw-bold" :title="`${m.qtyExact} ${ingredient.baseCode ?? ''}`">
                                        {{ m.sign }}{{ m.qtyLabel }}
                                    </td>
                                    <td class="text-end text-muted" :title="`${m.afterExact} ${ingredient.baseCode ?? ''}`">
                                        {{ m.afterLabel }}
                                    </td>
                                    <td><small>{{ m.reason || '—' }}</small></td>
                                    <td><small class="text-muted">{{ m.userName || '—' }}</small></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paged in place: the visit preserves state so you stay
                         on this tab instead of being thrown to «نظرة عامة». -->
                    <nav v-if="movements.links.length > 3" class="d-flex justify-content-center mt-3">
                        <ul class="pagination mb-0">
                            <li v-for="(link, i) in movements.links" :key="i"
                                class="page-item" :class="{ active: link.active, disabled: ! link.url }">
                                <button v-if="link.url" type="button" class="page-link"
                                        @click="pageTo(link.url)" v-html="link.label"></button>
                                <span v-else class="page-link" v-html="link.label"></span>
                            </li>
                        </ul>
                    </nav>
                </template>
            </section>

            <!-- ── Recipes using this ingredient ── -->
            <section v-else-if="tab === 'recipes'">
                <div v-if="usedInMenuItems.length === 0 && usedInComposites.length === 0"
                     class="text-center text-muted py-4">هذا المكوّن غير مستخدم في أي وصفة بعد.</div>
                <template v-else>
                    <template v-if="usedInMenuItems.length">
                        <h6 class="text-muted mb-2">في أصناف القائمة ({{ usedInMenuItems.length }})</h6>
                        <div class="table-responsive mb-4">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="bg-light">
                                    <tr><th>الصنف</th><th>الفئة</th><th class="text-end">الكمية بالوصفة</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(line, i) in usedInMenuItems" :key="i">
                                        <td>
                                            <strong>{{ line.name }}</strong>
                                            <small class="text-muted d-block">{{ line.sku }}</small>
                                        </td>
                                        <td><small>{{ line.categoryName || '—' }}</small></td>
                                        <td class="text-end fw-bold">{{ line.qty }} {{ line.unitName }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <template v-if="usedInComposites.length">
                        <h6 class="text-muted mb-2">في مكوّنات مركّبة ({{ usedInComposites.length }})</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="bg-light">
                                    <tr><th>المكوّن المركّب</th><th class="text-end">الكمية</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(line, i) in usedInComposites" :key="i">
                                        <td>
                                            <strong>{{ line.name }}</strong>
                                            <small class="text-muted d-block">{{ line.sku }}</small>
                                        </td>
                                        <td class="text-end fw-bold">{{ line.qty }} {{ line.unitName }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </template>
            </section>

            <!-- ── Manufacturing formula (composite only, read-only) ── -->
            <section v-else-if="tab === 'formula'">
                <div v-if="formula.lines.length === 0" class="alert alert-warning d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div>
                        هذا مكوّن مركّب لكن لم يتم تعريف وصفة فرعية بعد.
                        <a :href="urls.edit" class="alert-link">أضِف المكوّنات الفرعية</a>
                        ليتمكن النظام من حساب التكلفة الفعلية وخصم المخزون تلقائياً.
                    </div>
                </div>
                <template v-else>
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="text-muted mb-0">
                            <i class="bi bi-diagram-3"></i>
                            وصفة تصنيع {{ formula.yieldLabel }} من {{ ingredient.name }}
                        </h6>
                        <a v-if="can.manage" :href="urls.edit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil-square"></i> تعديل الوصفة
                        </a>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>المكوّن الفرعي</th>
                                    <th class="text-end">الكمية</th>
                                    <th class="text-end">تكلفة الوحدة</th>
                                    <th class="text-end">تكلفة السطر</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(line, i) in formula.lines" :key="i">
                                    <td>
                                        <a v-if="line.url" :href="line.url" class="text-decoration-none fw-bold">{{ line.name }}</a>
                                        <strong v-else>{{ line.name }}</strong>
                                        <small class="text-muted d-block">{{ line.sku }}</small>
                                    </td>
                                    <td class="text-end">
                                        {{ line.qty }} {{ line.unitName }}
                                        <small class="text-muted d-block">= {{ line.qtyInBase }}</small>
                                    </td>
                                    <td class="text-end">{{ money(line.unitCost) }} / {{ line.subBaseCode }}</td>
                                    <td class="text-end fw-bold">{{ money(line.lineCost) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">إجمالي تكلفة دفعة الإنتاج</td>
                                    <td class="text-end text-primary">{{ money(formula.total) }}</td>
                                </tr>
                                <tr v-if="formula.perUnit !== null">
                                    <td colspan="3" class="text-end">تكلفة الوحدة المُصنَّعة ({{ ingredient.baseCode }})</td>
                                    <td class="text-end text-success">{{ money(formula.perUnit) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </template>
            </section>

            <!-- ── Item invoices (PO + supplier invoice history) ── -->
            <section v-else-if="tab === 'invoices'">
                <div v-if="purchaseLines.length === 0" class="text-center text-muted py-4">
                    <i class="bi bi-receipt fs-3 d-block mb-2"></i>
                    لا توجد فواتير شراء سابقة لهذا الصنف.
                </div>
                <template v-else>
                    <div class="alert alert-light border d-flex align-items-center gap-2 small mb-3">
                        <i class="bi bi-info-circle text-primary"></i>
                        كل صف يربط أمر شراء بفاتورة المورد المقابلة. الكميات معروضة بوحدة السطر
                        الأصلية كما أُدخلت في النظام، مع تحويلها داخل قسم "المتسلَّم".
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>أمر الشراء</th>
                                    <th>المورد</th>
                                    <th class="text-end">الكمية المطلوبة</th>
                                    <th class="text-end">المتسلَّم</th>
                                    <th class="text-end">سعر الوحدة</th>
                                    <th class="text-end">الإجمالي</th>
                                    <th>فاتورة المورد</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in purchaseLines" :key="line.id">
                                    <td><small>{{ line.date || '—' }}</small></td>
                                    <td>
                                        <a :href="line.poUrl" class="text-decoration-none fw-bold">{{ line.poNumber }}</a>
                                        <span class="badge ms-1" :class="`bg-${line.poColor}`">{{ line.poStatus }}</span>
                                    </td>
                                    <td><small>{{ line.supplierName || '—' }}</small></td>
                                    <td class="text-end">{{ line.ordered }} {{ line.unitName }}</td>
                                    <td class="text-end">
                                        <strong>{{ line.received }}</strong>
                                        <small class="text-muted">/ {{ line.ordered }}</small>
                                        <i v-if="line.receiptState === 'full'"
                                           class="bi bi-check-circle-fill text-success ms-1" title="استلام مكتمل"></i>
                                        <i v-else-if="line.receiptState === 'partial'"
                                           class="bi bi-hourglass-split text-warning ms-1" title="استلام جزئي"></i>
                                    </td>
                                    <td class="text-end">{{ money(line.unitPrice) }}</td>
                                    <td class="text-end fw-bold">{{ money(line.subtotal) }}</td>
                                    <td>
                                        <span v-if="line.invoices.length === 0" class="text-muted">—</span>
                                        <a v-for="inv in line.invoices" :key="inv.id" :href="inv.url"
                                           class="text-decoration-none d-block">
                                            <small><code>{{ inv.number }}</code></small>
                                            <small class="badge" :class="`bg-${inv.color}`">{{ inv.label }}</small>
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="6" class="text-end">إجمالي مشتريات هذا الصنف (آخر 100 سطر)</td>
                                    <td class="text-end text-primary">{{ money(purchaseTotal) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </template>
            </section>

            <!-- ── Vendor prices ── -->
            <section v-else-if="tab === 'vendors'">
                <div v-if="vendorPrices.length === 0" class="text-center text-muted py-4">لا تاريخ أسعار لهذا المكوّن.</div>
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>التاريخ</th>
                                <th>المورد</th>
                                <th class="text-end">سعر الوحدة الأساسية</th>
                                <th class="text-end">تغيّر</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in vendorPrices" :key="p.id">
                                <td><small>{{ p.date }}</small></td>
                                <td>{{ p.supplierName || '—' }}</td>
                                <td class="text-end fw-bold">{{ money(p.unitPrice) }}</td>
                                <td class="text-end">
                                    <span v-if="p.changePct !== null" class="badge"
                                          :class="p.changePct > 0 ? 'bg-danger' : (p.changePct < 0 ? 'bg-success' : 'bg-secondary')">
                                        {{ p.changePct > 0 ? '+' : '' }}{{ p.changePct.toFixed(1) }}%
                                    </span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </div>

    <AdjustStockSheet :open="adjustOpen" :ingredient="adjustSeed" :units="adjust.units"
                      :today="today" @close="adjustOpen = false" />
</template>

<style scoped>
.ing-head-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.ing-head-actions .btn { min-height: 44px; }

.ing-hero { border-radius: 14px; overflow: hidden; }
.ing-hero__body { background: linear-gradient(135deg, rgba(15, 71, 49, .04), rgba(15, 71, 49, .01)); }
.ing-hero__icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: rgba(15, 71, 49, .1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0f4731;
    font-size: 1.6rem;
    flex-shrink: 0;
}
.ing-hero__box {
    text-align: center;
    padding: .5rem;
    background: #fff;
    border-radius: 10px;
    border: 1px solid rgba(15, 71, 49, .1);
}

.ing-tabs { border-bottom: none; flex-wrap: wrap; }
.ing-tabs .nav-link {
    border: 0;
    background: none;
    font: inherit;
    font-size: .84rem;
    font-weight: 700;
    min-height: 46px;
    cursor: pointer;
    color: #475569;
}
.ing-tabs .nav-link.active { color: rgb(var(--primary-rgb)); border-bottom: 2px solid rgb(var(--primary-rgb)); }

/* 30-day consumption chart — plain CSS bars, zero libraries. */
.ing-chart {
    display: flex;
    align-items: flex-end;
    gap: 3px;
    height: 240px;
    padding-bottom: 22px;
    position: relative;
    overflow-x: auto;
}
.ing-chart__col {
    flex: 1 1 0;
    min-width: 14px;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
    position: relative;
}
.ing-chart__bar {
    width: 100%;
    background: rgba(15, 71, 49, .55);
    border-radius: 4px 4px 0 0;
    min-height: 1px;
    transition: background-color .15s;
}
.ing-chart__col:hover .ing-chart__bar { background: rgba(15, 71, 49, .85); }
.ing-chart__lbl {
    position: absolute;
    bottom: -20px;
    font-size: 9px;
    color: #94a3b8;
    white-space: nowrap;
    
}

.ing-notes { white-space: pre-line; }

.pagination .page-link { border: 0; background: none; font: inherit; cursor: pointer; }
</style>
