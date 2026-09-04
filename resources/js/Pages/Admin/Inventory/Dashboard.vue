<script setup>
/**
 * The inventory landing page is an operational hand-off board, not another
 * report. It keeps the full purchase-to-ledger chain visible while showing
 * only one work queue at a time so it remains usable during busy shifts.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    branchName: { type: String, default: null },
    baseCurrency: { type: String, default: 'ILS' },
    subtitle: { type: String, required: true },
    actionCount: { type: Number, default: 0 },
    stats: { type: Object, required: true },
    workflow: { type: Array, default: () => [] },
    accountingBridge: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    actions: { type: Array, default: () => [] },
    reorderQueue: { type: Array, default: () => [] },
    expiringBatches: { type: Array, default: () => [] },
    overduePurchaseOrders: { type: Array, default: () => [] },
    uninvoicedPurchaseOrders: { type: Array, default: () => [] },
    openPurchaseOrders: { type: Array, default: () => [] },
    supplierInvoiceQueue: { type: Array, default: () => [] },
    invoiceVarianceItems: { type: Array, default: () => [] },
    recentReceipts: { type: Array, default: () => [] },
    highWaste: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const initialPane = props.reorderQueue.length
    ? 'needs'
    : (props.uninvoicedPurchaseOrders.length || props.supplierInvoiceQueue.length ? 'accounting' : 'purchasing');
const activePane = ref(initialPane);

const panes = computed(() => [
    { key: 'needs', label: 'النواقص', icon: 'bi-clipboard-data', count: props.reorderQueue.length },
    { key: 'purchasing', label: 'أوامر الشراء', icon: 'bi-bag-check', count: props.openPurchaseOrders.length },
    {
        key: 'accounting', label: 'الفوترة والمحاسبة', icon: 'bi-receipt',
        count: props.uninvoicedPurchaseOrders.length + props.supplierInvoiceQueue.length + props.invoiceVarianceItems.length,
    },
    {
        key: 'control', label: 'الصلاحية والرقابة', icon: 'bi-shield-check',
        count: props.expiringBatches.length + props.highWaste.length,
    },
]);

const stockHealth = computed(() => {
    if (!props.stats.tracked) return 100;
    return Math.max(0, Math.min(100, Math.round((props.stats.healthy / props.stats.tracked) * 100)));
});
</script>

<template>
    <Head title="المخزون والمشتريات" />

    <main class="inventory-board">
        <header class="inventory-hero">
            <div class="inventory-hero__copy">
                <span class="eyebrow"><i class="bi bi-box-seam"></i> مركز التوريد والمخزون</span>
                <h1>ما الذي يحتاج إجراء الآن؟</h1>
                <p>{{ branchName ? `فرع ${branchName}` : 'كل الفروع' }} · من طلب التوريد حتى القيد والسداد في مسار واحد.</p>
            </div>
            <div class="inventory-hero__actions">
                <Link v-if="can.createPurchaseOrder" :href="urls.createPurchaseOrder" class="button button--primary">
                    <i class="bi bi-plus-lg"></i><span>أمر شراء جديد</span>
                </Link>
                <Link :href="urls.reorderSuggestions" class="button button--soft">
                    <i class="bi bi-lightning-charge"></i><span>اقتراحات التوريد</span>
                </Link>
            </div>
        </header>

        <section class="pulse-grid" aria-label="ملخص المخزون والمشتريات">
            <article class="pulse-card pulse-card--inventory">
                <span class="pulse-card__icon"><i class="bi bi-boxes"></i></span>
                <span class="pulse-card__label">قيمة المخزون</span>
                <strong>{{ stats.stockValueLabel }}</strong>
                <small>{{ stats.tracked }} مكون متتبع · سلامة {{ stockHealth }}%</small>
            </article>
            <article class="pulse-card" :class="actionCount ? 'pulse-card--danger' : 'pulse-card--good'">
                <span class="pulse-card__icon"><i class="bi bi-lightning-charge-fill"></i></span>
                <span class="pulse-card__label">يحتاج قراراً</span>
                <strong>{{ actionCount }}</strong>
                <small>{{ stats.outStock }} نافد · {{ stats.lowStock }} منخفض</small>
            </article>
            <article class="pulse-card" :class="stats.uninvoicedPos ? 'pulse-card--warning' : 'pulse-card--good'">
                <span class="pulse-card__icon"><i class="bi bi-file-earmark-check"></i></span>
                <span class="pulse-card__label">مستلم بلا فاتورة</span>
                <strong>{{ stats.uninvoicedValueLabel }}</strong>
                <small>{{ stats.uninvoicedPos }} أمر ما زال في حلقة المطابقة</small>
            </article>
            <article class="pulse-card" :class="stats.apOverdue ? 'pulse-card--danger' : 'pulse-card--neutral'">
                <span class="pulse-card__icon"><i class="bi bi-wallet2"></i></span>
                <span class="pulse-card__label">ذمم الموردين</span>
                <strong>{{ stats.apDueLabel }}</strong>
                <small v-if="stats.apOverdue">منها {{ stats.apOverdueLabel }} متأخرة</small>
                <small v-else>لا توجد مستحقات متأخرة</small>
            </article>
        </section>

        <section class="workflow-panel" aria-labelledby="workflow-title">
            <div class="section-heading">
                <div>
                    <span>الدورة الصحيحة</span>
                    <h2 id="workflow-title">من الاحتياج إلى حساب المورد</h2>
                </div>
                <small>لا يُثبت أمر الشراء قيداً؛ القيد يبدأ عند الاستلام الفعلي.</small>
            </div>
            <div class="workflow-grid">
                <Link v-for="stage in workflow" :key="stage.key" :href="stage.url"
                      class="workflow-card" :class="`workflow-card--${stage.tone}`">
                    <span class="workflow-card__step">{{ stage.step }}</span>
                    <span class="workflow-card__icon"><i class="bi" :class="stage.icon"></i></span>
                    <span class="workflow-card__body">
                        <b>{{ stage.title }}</b>
                        <small>{{ stage.hint }}</small>
                    </span>
                    <span class="workflow-card__metric"><b>{{ stage.count }}</b><small>{{ stage.metric }}</small></span>
                    <i class="bi bi-chevron-left workflow-card__arrow"></i>
                </Link>
            </div>
        </section>

        <section class="work-panel">
            <nav class="work-tabs" aria-label="قوائم عمل المخزون">
                <button v-for="pane in panes" :key="pane.key" type="button"
                        :class="{ active: activePane === pane.key }"
                        :aria-pressed="activePane === pane.key"
                        @click="activePane = pane.key">
                    <i class="bi" :class="pane.icon"></i>
                    <span>{{ pane.label }}</span>
                    <small>{{ pane.count }}</small>
                </button>
            </nav>

            <div v-if="activePane === 'needs'" class="pane">
                <div class="pane-heading">
                    <div><span>ابدأ من هنا</span><h2>قائمة شراء عملية</h2><p>الكميات مقترحة حتى مستوى أمان يساوي ضعفي حد إعادة الطلب.</p></div>
                    <Link :href="urls.reorderSuggestions" class="text-action">فتح الاقتراح الكامل <i class="bi bi-arrow-left"></i></Link>
                </div>
                <div v-if="reorderQueue.length" class="reorder-grid">
                    <article v-for="item in reorderQueue" :key="item.id" class="reorder-card">
                        <div class="reorder-card__head">
                            <span class="stock-dot" :class="item.stock <= 0 ? 'stock-dot--out' : 'stock-dot--low'"></span>
                            <div><h3>{{ item.name }}</h3><small>{{ item.supplierName }}</small></div>
                            <strong>{{ item.needCostLabel }}</strong>
                        </div>
                        <div class="reorder-card__numbers">
                            <span><small>المتوفر</small><b :class="item.stockTone" :title="item.stockTitle">{{ item.stockDisplay }}</b></span>
                            <i class="bi bi-arrow-left"></i>
                            <span><small>اقترح شراء</small><b :title="item.needTitle">{{ item.needDisplay }}</b></span>
                        </div>
                    </article>
                </div>
                <div v-else class="empty-state"><i class="bi bi-check2-circle"></i><h3>المخزون فوق حدود الطلب</h3><p>لا توجد نواقص تحتاج طلب توريد الآن.</p></div>
            </div>

            <div v-else-if="activePane === 'purchasing'" class="pane split-layout">
                <section class="queue-card">
                    <div class="queue-card__head"><div><span>قيد التنفيذ</span><h2>أوامر الشراء المفتوحة</h2></div><Link :href="urls.purchaseOrders">عرض الكل</Link></div>
                    <div v-if="openPurchaseOrders.length" class="queue-list">
                        <Link v-for="po in openPurchaseOrders" :key="po.id" :href="po.url" class="queue-row">
                            <span class="queue-row__icon"><i class="bi bi-bag"></i></span>
                            <span class="queue-row__body"><b>{{ po.number }}</b><small>{{ po.supplierName }}</small></span>
                            <span class="queue-row__meta"><b>{{ po.totalLabel }}</b><small :class="`status-${po.statusColor}`">{{ po.statusLabel }}</small></span>
                            <i class="bi bi-chevron-left"></i>
                        </Link>
                    </div>
                    <div v-else class="empty-state empty-state--compact"><i class="bi bi-check2"></i><h3>لا أوامر مفتوحة</h3><p>أنشئ أمراً فقط عندما يوجد احتياج فعلي.</p></div>
                </section>
                <section class="queue-card">
                    <div class="queue-card__head"><div><span>آخر حركة</span><h2>الاستلامات الأخيرة</h2></div><small>{{ stats.receipts7d }} خلال 7 أيام</small></div>
                    <div v-if="recentReceipts.length" class="queue-list">
                        <article v-for="receipt in recentReceipts" :key="receipt.id" class="queue-row">
                            <span class="queue-row__icon queue-row__icon--good"><i class="bi bi-box-arrow-in-down"></i></span>
                            <span class="queue-row__body"><b>{{ receipt.number }}</b><small>{{ receipt.supplierName }} · {{ receipt.receivedAtLabel }}</small></span>
                            <Link v-if="receipt.poUrl" :href="receipt.poUrl" class="inline-link">{{ receipt.poNumber }}</Link>
                        </article>
                    </div>
                    <div v-else class="empty-state empty-state--compact"><i class="bi bi-inbox"></i><h3>لا استلامات بعد</h3><p>الاستلام الفعلي سيظهر هنا فور حفظه.</p></div>
                </section>
            </div>

            <div v-else-if="activePane === 'accounting'" class="pane accounting-pane">
                <section class="accounting-card" :class="`accounting-card--${accountingBridge.status}`">
                    <div class="accounting-card__head">
                        <div><span>مطابقة فورية</span><h2>جسر المخزون والحسابات</h2><p>التشغيل مقابل الرصيد المرحّل في الدفتر، بالعملة الأساسية {{ baseCurrency }}.</p></div>
                        <span class="reconcile-state"><i class="bi" :class="accountingBridge.status === 'balanced' ? 'bi-check2-circle' : 'bi-exclamation-triangle'"></i>{{ accountingBridge.statusLabel }}</span>
                    </div>
                    <div class="reconcile-grid">
                        <article v-for="row in accountingBridge.rows" :key="row.key">
                            <h3>{{ row.label }}</h3>
                            <div><span>تشغيلي <b>{{ row.operationalLabel }}</b></span><span>الدفتر <b>{{ row.ledgerLabel }}</b></span></div>
                            <small :class="Math.abs(row.difference) > .01 ? 'difference' : 'matched'">
                                {{ Math.abs(row.difference) > .01 ? `فرق ${row.differenceLabel}` : 'متطابق' }}
                            </small>
                        </article>
                    </div>
                    <Link v-if="accountingBridge.journalUrl" :href="accountingBridge.journalUrl" class="text-action">فتح دفتر القيود <i class="bi bi-arrow-left"></i></Link>
                </section>

                <div class="split-layout">
                    <section class="queue-card">
                        <div class="queue-card__head"><div><span>أغلق حلقة الاستلام</span><h2>مستلم وغير مفوتر</h2></div><strong>{{ stats.uninvoicedValueLabel }}</strong></div>
                        <div v-if="uninvoicedPurchaseOrders.length" class="queue-list">
                            <article v-for="po in uninvoicedPurchaseOrders" :key="po.id" class="queue-row queue-row--action">
                                <span class="queue-row__icon queue-row__icon--warning"><i class="bi bi-file-earmark-plus"></i></span>
                                <Link :href="po.url" class="queue-row__body"><b>{{ po.number }}</b><small>{{ po.supplierName }} · {{ po.pendingLines }} بند · {{ po.receivedAtLabel }}</small></Link>
                                <span class="queue-row__meta"><b>{{ po.pendingValueLabel }}</b><small>غير مفوتر</small></span>
                                <Link v-if="can.createSupplierInvoice" :href="po.createInvoiceUrl" class="row-button">سجل الفاتورة</Link>
                            </article>
                        </div>
                        <div v-else class="empty-state empty-state--compact"><i class="bi bi-check2-circle"></i><h3>كل الاستلامات مفوترة</h3><p>لا يوجد رصيد معلّق بين المخزن وذمم الموردين.</p></div>
                    </section>

                    <section class="queue-card">
                        <div class="queue-card__head"><div><span>ترتيب السداد</span><h2>فواتير قريبة أو متأخرة</h2></div><Link :href="urls.supplierInvoices">عرض الكل</Link></div>
                        <div v-if="supplierInvoiceQueue.length" class="queue-list">
                            <Link v-for="invoice in supplierInvoiceQueue" :key="invoice.id" :href="invoice.url" class="queue-row">
                                <span class="queue-row__icon"><i class="bi bi-receipt"></i></span>
                                <span class="queue-row__body"><b>{{ invoice.number }}</b><small>{{ invoice.supplierName }} · {{ invoice.statusLabel }}</small></span>
                                <span class="queue-row__meta"><b :class="invoice.tone">{{ invoice.balanceLabel }}</b><small>متبقي</small></span>
                                <i class="bi bi-chevron-left"></i>
                            </Link>
                        </div>
                        <div v-else class="empty-state empty-state--compact"><i class="bi bi-check2-circle"></i><h3>لا سداد عاجل</h3><p>لا توجد فاتورة متأخرة أو تستحق خلال سبعة أيام.</p></div>
                    </section>
                </div>

                <details v-if="invoiceVarianceItems.length" class="variance-box">
                    <summary><span><i class="bi bi-slash-circle"></i><b>{{ invoiceVarianceItems.length }} فرق بين الفاتورة والاستلام</b></span><i class="bi bi-chevron-down"></i></summary>
                    <div class="variance-list">
                        <Link v-for="item in invoiceVarianceItems" :key="item.id" :href="item.invoiceUrl" class="variance-row">
                            <span><b>{{ item.description }}</b><small>{{ item.invoiceNumber }} · {{ item.supplierName }}</small></span>
                            <span :class="item.varianceQtyTone">كمية {{ item.varianceQtyLabel }}</span>
                            <span :class="item.varianceTotalTone">قيمة {{ item.varianceTotalLabel }}</span>
                        </Link>
                    </div>
                </details>
            </div>

            <div v-else class="pane split-layout">
                <section class="queue-card">
                    <div class="queue-card__head"><div><span>FIFO والصلاحية</span><h2>دفعات تحتاج تصرفاً</h2></div><Link :href="urls.batches">عرض الدفعات</Link></div>
                    <div v-if="expiringBatches.length" class="queue-list">
                        <Link v-for="batch in expiringBatches" :key="batch.id" :href="batch.url" class="queue-row">
                            <span class="queue-row__icon queue-row__icon--warning"><i class="bi bi-calendar-x"></i></span>
                            <span class="queue-row__body"><b>{{ batch.ingredientName }}</b><small>{{ batch.locationName }} · دفعة {{ batch.batchLabel }}</small></span>
                            <b :class="batch.tone">{{ batch.daysLabel }}</b><i class="bi bi-chevron-left"></i>
                        </Link>
                    </div>
                    <div v-else class="empty-state empty-state--compact"><i class="bi bi-calendar-check"></i><h3>الصلاحيات مستقرة</h3><p>لا دفعات متبقية تنتهي خلال سبعة أيام.</p></div>
                </section>
                <section class="queue-card">
                    <div class="queue-card__head"><div><span>آخر 7 أيام</span><h2>الهدر والتالف</h2></div><strong>{{ stats.waste7dLabel }}</strong></div>
                    <div v-if="highWaste.length" class="queue-list">
                        <article v-for="row in highWaste" :key="row.ingredientId" class="queue-row">
                            <span class="queue-row__icon queue-row__icon--danger"><i class="bi bi-trash3"></i></span>
                            <span class="queue-row__body"><b>{{ row.name }}</b><small>{{ row.eventsCount }} حركة · {{ row.qtyDisplay }}</small></span>
                            <b class="text-danger">{{ row.totalCostLabel }}</b>
                        </article>
                    </div>
                    <div v-else class="empty-state empty-state--compact"><i class="bi bi-check2-circle"></i><h3>لا هدر مسجل</h3><p>لا توجد حركات هدر خلال آخر سبعة أيام.</p></div>
                </section>
            </div>
        </section>
    </main>
</template>

<style scoped>
.inventory-board{--ink:#163a2a;--muted:#6d7d74;--line:#dfe9e3;--green:#176f45;--green-dark:#105a37;--green-soft:#edf8f1;display:grid;min-width:0;gap:12px;padding-block-end:28px;color:var(--ink)}
.inventory-board>*,.inventory-hero__copy,.inventory-hero__actions{min-width:0;max-width:100%;box-sizing:border-box}
.inventory-hero{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px;border:1px solid var(--line);border-radius:18px;background:linear-gradient(120deg,#f4faf6 0%,#fff 70%);box-shadow:0 8px 24px rgba(22,58,42,.045)}
.inventory-hero__copy{display:grid;gap:4px}.eyebrow,.section-heading span,.pane-heading span,.queue-card__head span,.accounting-card__head span{color:var(--green);font-size:.7rem;font-weight:850}.inventory-hero h1{margin:0;font-size:clamp(1.35rem,2.2vw,1.75rem);font-weight:950}.inventory-hero p,.pane-heading p,.accounting-card__head p{margin:0;color:var(--muted);font-size:.78rem}.inventory-hero__actions{display:flex;flex-wrap:wrap;gap:8px}.button,.text-action,.row-button,.inline-link{text-decoration:none}.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-block-size:42px;padding:9px 14px;border:1px solid transparent;border-radius:11px;font-size:.76rem;font-weight:850}.button--primary{color:#fff;background:var(--green)}.button--primary:hover{color:#fff;background:var(--green-dark)}.button--soft{border-color:#cfe2d6;color:var(--green);background:#fff}
.pulse-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.pulse-card{position:relative;display:grid;grid-template-columns:auto 1fr;grid-template-areas:'icon label' 'icon value' 'icon note';column-gap:10px;min-width:0;padding:13px;border:1px solid var(--line);border-radius:14px;background:#fff}.pulse-card__icon{grid-area:icon;display:grid;width:36px;height:36px;place-items:center;border-radius:10px;color:var(--green);background:var(--green-soft)}.pulse-card__label{grid-area:label;color:var(--muted);font-size:.67rem;font-weight:800}.pulse-card strong{grid-area:value;font-size:1rem;font-weight:950}.pulse-card small{grid-area:note;overflow:hidden;color:var(--muted);font-size:.63rem;text-overflow:ellipsis;white-space:nowrap}.pulse-card--danger{border-color:#f1c8c8}.pulse-card--danger .pulse-card__icon{color:#b4232c;background:#fff0f1}.pulse-card--warning{border-color:#ead9af}.pulse-card--warning .pulse-card__icon{color:#94610c;background:#fff8e7}.pulse-card--good .pulse-card__icon{color:#16834f;background:#eaf9f0}.pulse-card--neutral .pulse-card__icon{color:#4d6472;background:#f1f5f7}
.workflow-panel,.work-panel{border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(22,58,42,.035)}.workflow-panel{padding:14px}.section-heading,.pane-heading,.queue-card__head,.accounting-card__head{display:flex;align-items:center;justify-content:space-between;gap:16px}.section-heading h2,.pane-heading h2,.queue-card__head h2,.accounting-card__head h2{margin:2px 0 0;font-size:1rem;font-weight:930}.section-heading>small{max-width:360px;color:var(--muted);font-size:.68rem;text-align:end}.workflow-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;margin-block-start:11px}.workflow-card{display:grid;grid-template-columns:auto auto 1fr auto auto;align-items:center;gap:8px;min-width:0;min-height:64px;padding:10px;border:1px solid #e3ebe6;border-radius:13px;color:var(--ink);background:#fbfdfc;text-decoration:none}.workflow-card:hover{border-color:#9ac7aa;background:#f5fbf7}.workflow-card__step{align-self:start;color:#91a198;font-size:.56rem;font-weight:900}.workflow-card__icon{display:grid;width:32px;height:32px;place-items:center;border-radius:9px;color:var(--green);background:var(--green-soft)}.workflow-card__body{display:grid;gap:2px;min-width:0}.workflow-card__body b{font-size:.74rem}.workflow-card__body small{color:var(--muted);font-size:.6rem;line-height:1.45}.workflow-card__metric{display:grid;text-align:end}.workflow-card__metric b{font-size:.92rem}.workflow-card__metric small{color:var(--muted);font-size:.56rem;white-space:nowrap}.workflow-card__arrow{font-size:.62rem;color:#9aa8a0}.workflow-card--danger{border-color:#f0cece}.workflow-card--danger .workflow-card__icon{color:#b4232c;background:#fff0f1}.workflow-card--warning .workflow-card__icon{color:#94610c;background:#fff7e4}.workflow-card--success .workflow-card__icon{color:#16834f;background:#eaf9f0}
.work-tabs{display:flex;gap:6px;overflow-x:auto;padding:8px;border-block-end:1px solid var(--line);scrollbar-width:thin}.work-tabs button{display:flex;flex:1 0 160px;align-items:center;justify-content:center;gap:8px;min-block-size:46px;padding:9px 12px;border:0;border-radius:12px;color:#68776e;background:transparent;font-size:.76rem;font-weight:850}.work-tabs button small{display:grid;min-width:22px;height:22px;place-items:center;border-radius:99px;background:#eef2ef;font-size:.62rem}.work-tabs button.active{color:var(--green);background:var(--green-soft);box-shadow:inset 0 -2px var(--green)}.pane{padding:18px}.pane-heading{margin-block-end:14px}.pane-heading>div{display:grid;gap:2px}.text-action{display:inline-flex;align-items:center;gap:7px;min-block-size:44px;color:var(--green);font-size:.73rem;font-weight:850}
.reorder-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.reorder-card{display:grid;gap:13px;padding:15px;border:1px solid #e1e9e4;border-radius:15px;background:#fbfdfc}.reorder-card__head{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:9px}.stock-dot{width:9px;height:9px;border-radius:50%}.stock-dot--out{background:#c53039;box-shadow:0 0 0 5px #fff0f1}.stock-dot--low{background:#d18a15;box-shadow:0 0 0 5px #fff7e8}.reorder-card h3{margin:0;font-size:.86rem}.reorder-card small{color:var(--muted);font-size:.66rem}.reorder-card__head>strong{color:var(--green);font-size:.82rem}.reorder-card__numbers{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px;padding:10px 12px;border-radius:11px;background:#f1f5f2}.reorder-card__numbers span{display:grid;gap:3px}.reorder-card__numbers span:last-child{text-align:end}.reorder-card__numbers i{color:#9aa9a0}.reorder-card__numbers b{font-size:.78rem}
.split-layout{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.queue-card{min-width:0;border:1px solid #e1e9e4;border-radius:16px;background:#fff}.queue-card__head{min-height:68px;padding:13px 15px;border-block-end:1px solid #edf2ef}.queue-card__head>div{display:grid}.queue-card__head>a,.queue-card__head>small{color:var(--green);font-size:.68rem;font-weight:800;text-decoration:none}.queue-card__head>strong{font-size:.82rem}.queue-list{display:grid}.queue-row{display:flex;align-items:center;gap:10px;min-height:62px;padding:10px 13px;border-block-end:1px solid #edf2ef;color:var(--ink);text-decoration:none}.queue-row:last-child{border-block-end:0}.queue-row:hover{background:#f8fbf9}.queue-row__icon{display:grid;flex:0 0 36px;width:36px;height:36px;place-items:center;border-radius:10px;color:#4d6574;background:#f0f4f6}.queue-row__icon--good{color:#187b4c;background:#edf9f1}.queue-row__icon--warning{color:#94610c;background:#fff7e4}.queue-row__icon--danger{color:#b4232c;background:#fff0f1}.queue-row__body{display:grid;gap:2px;min-width:0;flex:1;color:var(--ink);text-decoration:none}.queue-row__body b{overflow:hidden;font-size:.76rem;text-overflow:ellipsis;white-space:nowrap}.queue-row__body small{overflow:hidden;color:var(--muted);font-size:.63rem;text-overflow:ellipsis;white-space:nowrap}.queue-row__meta{display:grid;gap:2px;text-align:end}.queue-row__meta b{font-size:.72rem;white-space:nowrap}.queue-row__meta small{color:var(--muted);font-size:.59rem}.queue-row>i{color:#a3afa8;font-size:.65rem}.inline-link{color:var(--green);font-size:.67rem;font-weight:850}.row-button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:7px 10px;border-radius:9px;color:#fff;background:var(--green);font-size:.65rem;font-weight:850;white-space:nowrap}.status-danger,.text-danger{color:#b4232c!important}.status-warning,.text-warning{color:#94610c!important}.status-success{color:#187b4c}.status-info{color:#21677a}.status-secondary{color:#69786f}
.accounting-pane{display:grid;gap:12px}.accounting-card{padding:16px;border:1px solid #dce9e1;border-radius:17px;background:linear-gradient(135deg,#f1faf4,#fff)}.accounting-card--review{border-color:#ead6a8;background:linear-gradient(135deg,#fff8e9,#fff)}.reconcile-state{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:7px 10px;border-radius:99px;background:#eaf8ef;white-space:nowrap}.accounting-card--review .reconcile-state{color:#8b5c0b;background:#fff0c9}.reconcile-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-block:14px}.reconcile-grid article{display:grid;gap:9px;padding:12px;border:1px solid #e2eae5;border-radius:13px;background:rgba(255,255,255,.82)}.reconcile-grid h3{margin:0;font-size:.7rem}.reconcile-grid article>div{display:flex;justify-content:space-between;gap:8px}.reconcile-grid article>div span{display:grid;color:var(--muted);font-size:.59rem}.reconcile-grid article>div b{color:var(--ink);font-size:.7rem}.reconcile-grid article>small{font-size:.62rem;font-weight:850}.matched{color:#16804d}.difference{color:#b4232c}.variance-box{border:1px solid #ead9af;border-radius:14px;background:#fffaf0}.variance-box summary{display:flex;align-items:center;justify-content:space-between;min-height:48px;padding:10px 13px;cursor:pointer;list-style:none}.variance-box summary span{display:flex;align-items:center;gap:8px;color:#8b5c0b;font-size:.72rem}.variance-list{border-block-start:1px solid #f0e4c6}.variance-row{display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:12px;min-height:52px;padding:9px 13px;border-block-end:1px solid #f3ead5;color:var(--ink);font-size:.67rem;text-decoration:none}.variance-row:last-child{border-block-end:0}.variance-row>span:first-child{display:grid}.variance-row small{color:var(--muted);font-size:.6rem}
.empty-state{display:grid;min-height:180px;place-items:center;align-content:center;gap:5px;padding:22px;text-align:center}.empty-state--compact{min-height:190px}.empty-state i{font-size:1.55rem;color:#64a77d}.empty-state h3{margin:0;font-size:.85rem}.empty-state p{margin:0;color:var(--muted);font-size:.68rem}
@media(min-width:1181px){.pulse-grid{gap:0;overflow:hidden;border:1px solid var(--line);border-radius:15px;background:#fff}.pulse-card{border:0;border-radius:0;background:transparent}.pulse-card+.pulse-card{border-inline-start:1px solid var(--line)}.workflow-card__body small{display:none}}
@media(min-width:1181px) and (max-width:1440px){.inventory-board{gap:9px}.inventory-hero{padding:14px 16px;border-radius:15px}.inventory-hero h1{font-size:1.42rem}.inventory-hero p{font-size:.7rem}.button{min-block-size:40px;padding:8px 12px}.pulse-card{padding:10px}.workflow-panel{padding:11px}.workflow-grid{margin-block-start:8px}.workflow-card{min-height:58px;padding:8px}.work-tabs{padding:6px}.work-tabs button{min-block-size:42px}.pane{padding:14px}.queue-card__head{min-height:60px;padding:10px 12px}.queue-row{min-height:56px;padding:8px 11px}.accounting-card{padding:13px}.reconcile-grid{margin-block:10px}}
@media(max-width:1180px){.pulse-grid,.workflow-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:780px){.inventory-board{gap:12px}.inventory-hero{width:100%;align-items:stretch;padding:18px;overflow:hidden}.inventory-hero,.section-heading,.pane-heading,.accounting-card__head{flex-direction:column}.inventory-hero__copy p{overflow-wrap:anywhere;line-height:1.75}.inventory-hero__actions{display:grid;width:100%;grid-template-columns:1fr 1fr}.section-heading,.pane-heading,.accounting-card__head{align-items:stretch}.section-heading>small{text-align:start}.pulse-grid{grid-template-columns:1fr 1fr}.workflow-grid,.split-layout,.reorder-grid,.reconcile-grid{grid-template-columns:1fr}.workflow-card{grid-template-columns:auto auto 1fr auto auto}.pane{padding:12px}.work-tabs button{flex-basis:138px}.queue-row--action{display:grid;grid-template-columns:auto 1fr auto}.queue-row--action .row-button{grid-column:2/4}.variance-row{grid-template-columns:1fr auto}.variance-row>span:last-child{grid-column:2}.reconcile-grid article>div{justify-content:flex-start;gap:28px}}
@media(max-width:500px){.pulse-grid{grid-template-columns:1fr}.inventory-hero__actions{grid-template-columns:1fr}.inventory-hero h1{font-size:1.5rem}.workflow-grid{grid-template-columns:1fr}.workflow-card__body small{display:none}.work-tabs button{flex-basis:122px}.queue-row__meta{display:none}.queue-row--action .queue-row__meta{display:grid}.reorder-card__head{grid-template-columns:auto 1fr}.reorder-card__head>strong{grid-column:2}.accounting-card{padding:12px}.accounting-card__head p{font-size:.72rem}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}
</style>
