<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    invoices: { type: Object, required: true },
    stats: { type: Object, required: true },
    filteredStats: { type: Object, required: true },
    filters: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    scopeOptions: { type: Array, default: () => [] },
    dateFieldOptions: { type: Array, default: () => [] },
    paymentDefaults: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const filter = reactive({
    search: props.filters.search ?? '',
    supplierId: props.filters.supplierId ?? '',
    scope: props.filters.scope ?? 'all',
    dateField: props.filters.dateField ?? 'invoice_date',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const datesOpen = ref(Boolean(filter.from || filter.to || filter.dateField !== 'invoice_date'));
const paying = ref(null);
const cancelling = ref(null);
const submitting = ref(false);
const errors = ref({});
const payment = reactive({ amount: '', method: 'cash', paid_on: '', exchange_rate: '', reference: '', notes: '' });
const cancelReason = ref('');

const query = computed(() => ({
    search: filter.search || undefined,
    supplier_id: filter.supplierId || undefined,
    scope: filter.scope !== 'all' ? filter.scope : undefined,
    date_field: filter.dateField !== 'invoice_date' ? filter.dateField : undefined,
    from: filter.from || undefined,
    to: filter.to || undefined,
}));
const hasFilters = computed(() => Object.values(query.value).some(Boolean));

function visit(patch = {}) {
    Object.assign(filter, patch);
    router.get(props.urls.index, query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    datesOpen.value = false;
    visit({ search: '', supplierId: '', scope: 'all', dateField: 'invoice_date', from: '', to: '' });
}

function openPayment(invoice) {
    paying.value = invoice;
    errors.value = {};
    Object.assign(payment, {
        amount: invoice.amounts.rawBalance.toFixed(2),
        method: 'cash',
        paid_on: props.paymentDefaults.date,
        exchange_rate: '',
        reference: '',
        notes: '',
    });
}

function submitPayment() {
    if (!paying.value || submitting.value) return;
    submitting.value = true;
    errors.value = {};
    router.post(paying.value.urls.pay, { ...payment }, {
        preserveScroll: true,
        onError: (bag) => { errors.value = bag; },
        onSuccess: () => { paying.value = null; },
        onFinish: () => { submitting.value = false; },
    });
}

function openCancel(invoice) {
    cancelling.value = invoice;
    cancelReason.value = '';
    errors.value = {};
}

function submitCancel() {
    if (!cancelling.value || !cancelReason.value.trim() || submitting.value) return;
    submitting.value = true;
    errors.value = {};
    router.post(cancelling.value.urls.cancel, { reason: cancelReason.value.trim() }, {
        preserveScroll: true,
        onError: (bag) => { errors.value = bag; },
        onSuccess: () => { cancelling.value = null; },
        onFinish: () => { submitting.value = false; },
    });
}

function closeModal() {
    if (submitting.value) return;
    paying.value = null;
    cancelling.value = null;
    errors.value = {};
}

function handleKey(event) {
    if (event.key === 'Escape') closeModal();
}

watch(() => Boolean(paying.value || cancelling.value), (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
onMounted(() => window.addEventListener('keydown', handleKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKey);
    document.body.style.overflow = '';
});
</script>

<template>
    <Head title="فواتير الموردين" />

    <PageHeader title="فواتير الموردين" icon="bi-file-earmark-text-fill"
                subtitle="اعرف ما يستحق الآن وسجّل الدفعة دون مغادرة السجل">
        <template #actions>
            <a v-if="can.create" :href="urls.create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> فاتورة جديدة
            </a>
        </template>
    </PageHeader>

    <div class="si-stats">
        <StatRail :stats="[
            { label: 'إجمالي المستحقات', value: stats.totalAp, icon: 'bi-wallet2', color: 'danger' },
            { label: 'متأخرة', value: stats.overdue, icon: 'bi-alarm-fill', color: 'danger' },
            { label: 'خلال 7 أيام', value: stats.dueThisWeek, icon: 'bi-calendar-event', color: 'accent' },
            { label: 'مشتريات الشهر', value: stats.thisMonth, icon: 'bi-graph-up-arrow', color: 'primary' },
        ]" />
    </div>

    <div class="si-lenses" aria-label="اختصارات حالة الفواتير">
        <button type="button" :class="{ active: filter.scope === 'all' }" @click="visit({ scope: 'all' })">
            <i class="bi bi-layers"></i> الكل
        </button>
        <button type="button" :class="{ active: filter.scope === 'open' }" @click="visit({ scope: 'open' })">
            <i class="bi bi-hourglass-split"></i> المفتوحة
        </button>
        <button type="button" class="danger" :class="{ active: filter.scope === 'overdue' }" @click="visit({ scope: 'overdue' })">
            <i class="bi bi-alarm"></i> المتأخرة <b>{{ stats.overdue }}</b>
        </button>
        <button type="button" class="warning" :class="{ active: filter.scope === 'due_week' }" @click="visit({ scope: 'due_week' })">
            <i class="bi bi-calendar-week"></i> قريبة الاستحقاق
        </button>
        <button type="button" :class="{ active: filter.scope === 'paid' }" @click="visit({ scope: 'paid' })">
            <i class="bi bi-check2-circle"></i> المدفوعة
        </button>
    </div>

    <DataPanel title="دفتر فواتير الموردين" :count="invoices.total" icon="bi-journal-text">
        <template #filters>
            <form class="si-filter" @submit.prevent="visit()">
                <label class="si-search">
                    <i class="bi bi-search"></i>
                    <input v-model="filter.search" placeholder="رقم الفاتورة أو أمر الشراء…">
                </label>
                <select v-model="filter.supplierId" aria-label="المورد" @change="visit()">
                    <option value="">كل الموردين</option>
                    <option v-for="supplier in suppliers" :key="supplier.id" :value="String(supplier.id)">{{ supplier.name }}</option>
                </select>
                <select v-model="filter.scope" aria-label="الحالة" @change="visit()">
                    <option v-for="option in scopeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <button class="btn btn-primary" aria-label="بحث"><i class="bi bi-search"></i></button>
            </form>

            <div class="si-filter-tools">
                <button type="button" :class="{ active: datesOpen }" @click="datesOpen = !datesOpen">
                    <i class="bi bi-calendar3"></i> الفترة
                    <span v-if="filter.from || filter.to" class="si-dot"></span>
                </button>
                <button v-if="hasFilters" type="button" @click="clearFilters"><i class="bi bi-x-circle"></i> مسح الفلاتر</button>
            </div>

            <Transition name="si-slide">
                <div v-if="datesOpen" class="si-dates">
                    <label><span>احتساب الفترة حسب</span><select v-model="filter.dateField" @change="visit()"><option v-for="option in dateFieldOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                    <label><span>من</span><input v-model="filter.from" type="date" @change="visit()"></label>
                    <label><span>إلى</span><input v-model="filter.to" type="date" @change="visit()"></label>
                </div>
            </Transition>
        </template>

        <div class="si-summary">
            <span><small>النتائج</small><b>{{ filteredStats.count }}</b></span>
            <span><small>إجمالي الفواتير</small><b>{{ filteredStats.total }}</b></span>
            <span><small>المدفوع</small><b class="success">{{ filteredStats.paid }}</b></span>
            <span><small>المتبقي</small><b :class="{ danger: filteredStats.rawBalance > 0 }">{{ filteredStats.balance }}</b></span>
            <span><small>متأخرة</small><b :class="{ danger: filteredStats.overdue > 0 }">{{ filteredStats.overdue }}</b></span>
        </div>

        <div v-if="invoices.data.length" class="si-list">
            <article v-for="invoice in invoices.data" :key="invoice.id" class="si-card"
                     :class="{ overdue: invoice.overdue, dueSoon: invoice.dueInDays !== null && invoice.dueInDays <= 7 }">
                <div class="si-state"></div>
                <div class="si-main">
                    <div class="si-title">
                        <a :href="invoice.urls.show">{{ invoice.number }}</a>
                        <span class="si-status" :class="`is-${invoice.statusColor}`">{{ invoice.statusLabel }}</span>
                        <a v-if="invoice.attachmentUrl" :href="invoice.attachmentUrl" target="_blank" class="si-attachment" title="فتح المرفق"><i class="bi bi-paperclip"></i></a>
                    </div>
                    <strong>{{ invoice.supplier }}</strong>
                    <div class="si-meta">
                        <a v-if="invoice.purchaseOrder" :href="invoice.purchaseOrder.url"><i class="bi bi-truck"></i>{{ invoice.purchaseOrder.number }}</a>
                        <span v-else><i class="bi bi-dash-circle"></i>بدون أمر شراء</span>
                        <span v-if="invoice.branch" class="si-branch" :style="{ '--hue': invoice.branch.hue }"><i class="bi bi-building"></i>{{ invoice.branch.name }}</span>
                    </div>
                </div>

                <div class="si-money">
                    <span><small>الإجمالي</small><b>{{ invoice.amounts.total }}</b></span>
                    <span><small>مدفوع</small><b class="success">{{ invoice.amounts.paid }}</b></span>
                    <span class="balance"><small>متبقي</small><b :class="{ danger: invoice.amounts.rawBalance > 0 }">{{ invoice.amounts.balance }}</b></span>
                    <small v-if="invoice.amounts.baseBalance" class="si-base">≈ {{ invoice.amounts.baseBalance }} بالدفاتر</small>
                </div>

                <div class="si-due">
                    <span><i class="bi bi-receipt"></i>{{ invoice.invoiceDate }}</span>
                    <strong v-if="invoice.overdue" class="danger"><i class="bi bi-alarm-fill"></i>متأخرة {{ invoice.overdueDays }} يوم</strong>
                    <strong v-else-if="invoice.dueInDays !== null && invoice.dueInDays <= 7" class="warning"><i class="bi bi-calendar-event"></i>تستحق خلال {{ invoice.dueInDays }} يوم</strong>
                    <span v-else-if="invoice.dueDate"><i class="bi bi-calendar-event"></i>{{ invoice.dueDate }}</span>
                    <span v-else><i class="bi bi-calendar-x"></i>بلا تاريخ استحقاق</span>
                </div>

                <div class="si-actions">
                    <button v-if="invoice.can.pay" type="button" class="pay" @click="openPayment(invoice)"><i class="bi bi-cash-coin"></i><span>سجّل دفعة</span></button>
                    <a :href="invoice.urls.show"><i class="bi bi-eye"></i><span>التفاصيل</span></a>
                    <button v-if="invoice.can.cancel" type="button" class="cancel" @click="openCancel(invoice)"><i class="bi bi-x-circle"></i><span>إلغاء</span></button>
                </div>
            </article>
        </div>

        <EmptyState v-else icon="bi-file-earmark-text" title="لا توجد فواتير مطابقة"
                    :message="hasFilters ? 'وسّع الفترة أو امسح الفلاتر.' : 'سجّل فاتورة المورد عند استلامها.'" />

        <template #footer><Pagination :links="invoices.links" /></template>
    </DataPanel>

    <Teleport to="body">
        <Transition name="si-modal">
            <div v-if="paying" class="si-backdrop" @click.self="closeModal">
                <form class="si-modal" @submit.prevent="submitPayment">
                    <header>
                        <div><small>{{ paying.supplier }}</small><h3>تسجيل دفعة — {{ paying.number }}</h3></div>
                        <button type="button" @click="closeModal"><i class="bi bi-x-lg"></i></button>
                    </header>
                    <div class="si-balance"><span>الرصيد المتبقي</span><strong>{{ paying.amounts.balance }}</strong></div>
                    <div v-if="Object.keys(errors).length" class="si-errors"><i class="bi bi-exclamation-triangle-fill"></i><span>{{ Object.values(errors)[0] }}</span></div>
                    <div class="si-form-grid">
                        <label><span>قيمة الدفعة *</span><input v-model="payment.amount" type="number" step="0.01" min="0.01" :max="paying.amounts.rawBalance" required></label>
                        <label><span>طريقة الدفع *</span><select v-model="payment.method" required><option value="cash">نقدي</option><option value="bank_transfer">تحويل بنكي مباشر</option></select></label>
                        <label><span>تاريخ الدفع *</span><input v-model="payment.paid_on" type="date" required></label>
                        <label v-if="paying.payment.needsExchangeRate"><span>سعر صرف {{ paying.currency }} إلى {{ paymentDefaults.baseCurrency }}</span><input v-model="payment.exchange_rate" type="number" step="0.00000001" min="0.000001" :placeholder="`آخر سعر: ${paying.payment.lastExchangeRate}`"></label>
                        <label class="wide"><span>مرجع التحويل / السند</span><input v-model="payment.reference" maxlength="100" placeholder="اختياري"></label>
                        <label class="wide"><span>ملاحظات</span><textarea v-model="payment.notes" rows="2" maxlength="500"></textarea></label>
                    </div>
                    <footer><button type="button" class="btn btn-light" @click="closeModal">تراجع</button><button class="btn btn-success" :disabled="submitting"><i class="bi bi-check2-circle"></i>{{ submitting ? 'جارٍ التسجيل…' : 'تسجيل الدفعة' }}</button></footer>
                </form>
            </div>
        </Transition>

        <Transition name="si-modal">
            <div v-if="cancelling" class="si-backdrop" @click.self="closeModal">
                <form class="si-modal si-modal--small" @submit.prevent="submitCancel">
                    <header><div><small>لن ينشأ أثر نقدي</small><h3>إلغاء {{ cancelling.number }}</h3></div><button type="button" @click="closeModal"><i class="bi bi-x-lg"></i></button></header>
                    <p>الإلغاء متاح فقط قبل تسجيل أي دفعة. سيُعكس قيد الفاتورة محاسبياً مع حفظ السبب.</p>
                    <div v-if="Object.keys(errors).length" class="si-errors"><i class="bi bi-exclamation-triangle-fill"></i><span>{{ Object.values(errors)[0] }}</span></div>
                    <label class="si-cancel-reason"><span>سبب الإلغاء *</span><textarea v-model="cancelReason" rows="4" maxlength="500" required placeholder="اكتب سبباً واضحاً للمراجعة المحاسبية…"></textarea></label>
                    <footer><button type="button" class="btn btn-light" @click="closeModal">تراجع</button><button class="btn btn-danger" :disabled="submitting || !cancelReason.trim()"><i class="bi bi-x-circle"></i>تأكيد الإلغاء</button></footer>
                </form>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.si-lenses { display:flex; gap:.5rem; margin-bottom:.85rem; overflow-x:auto; padding-bottom:.1rem; }
.si-lenses button { flex:1; min-width:145px; min-height:46px; border:1px solid #dfe8e2; border-radius:12px; background:#fff; color:#52665b; font-weight:750; white-space:nowrap; }
.si-lenses button i { margin-inline-end:.35rem; color:#167343; }
.si-lenses button b { margin-inline-start:.3rem; padding:.1rem .4rem; border-radius:99px; background:#eef4f0; }
.si-lenses button.active { border-color:#1b7b49; background:#eff8f3; color:#0e6b3b; }
.si-lenses .danger i { color:#c43d3d; }
.si-lenses .danger.active { border-color:#e5aaaa; background:#fff3f3; color:#a92e2e; }
.si-lenses .warning i { color:#b67918; }
.si-lenses .warning.active { border-color:#e5c07a; background:#fff9ed; color:#865b16; }
.si-filter { display:grid; grid-template-columns:minmax(260px,1fr) 230px 210px 48px; gap:.55rem; }
.si-search { display:flex; align-items:center; gap:.5rem; padding:0 .8rem; border:1px solid #dfe7e2; border-radius:10px; background:#fff; }
.si-search:focus-within { border-color:#1b7b49; box-shadow:0 0 0 3px rgba(27,123,73,.08); }
.si-search input { width:100%; min-height:44px; border:0; outline:0; background:transparent; }
.si-filter select, .si-dates select, .si-dates input, .si-form-grid input, .si-form-grid select, .si-form-grid textarea, .si-cancel-reason textarea { min-height:44px; padding:.55rem .7rem; border:1px solid #dfe7e2; border-radius:10px; background:#fff; }
.si-filter-tools { display:flex; gap:.45rem; margin-top:.55rem; }
.si-filter-tools button { min-height:38px; border:0; border-radius:9px; background:#f0f4f2; color:#5d7066; padding:.4rem .7rem; font-size:.7rem; }
.si-filter-tools button.active { background:#e8f4ed; color:#0f7040; }
.si-dot { display:inline-block; width:7px; height:7px; margin-inline-start:.3rem; border-radius:50%; background:#d59425; }
.si-dates { display:grid; grid-template-columns:1.4fr 1fr 1fr; gap:.55rem; margin-top:.55rem; padding:.65rem; border:1px solid #e5ebe7; border-radius:11px; background:#f8faf9; }
.si-dates label, .si-form-grid label, .si-cancel-reason { display:flex; flex-direction:column; gap:.3rem; color:#607268; font-size:.68rem; font-weight:700; }
.si-summary { display:grid; grid-template-columns:repeat(5,1fr); gap:.45rem; margin-bottom:.8rem; }
.si-summary span { display:flex; align-items:center; justify-content:space-between; padding:.55rem .65rem; border-radius:10px; background:#f5f8f6; }
.si-summary small { color:#7b8982; }
.si-summary b { color:#1a3628; }
.success { color:#148047 !important; }
.danger { color:#bd3737 !important; }
.warning { color:#a46a10 !important; }
.si-list { display:grid; gap:.65rem; }
.si-card { position:relative; display:grid; grid-template-columns:minmax(240px,1.15fr) minmax(280px,1fr) minmax(155px,.65fr) auto; gap:.85rem; align-items:center; min-height:108px; padding:.85rem 1rem; border:1px solid #e2eae5; border-radius:14px; background:#fff; overflow:hidden; transition:.15s ease; }
.si-card:hover { border-color:#b8d3c2; box-shadow:0 7px 20px rgba(23,79,46,.07); }
.si-state { position:absolute; inset-block:0; inset-inline-start:0; width:4px; background:#8da097; }
.si-card.overdue .si-state { background:#d64040; }
.si-card.dueSoon .si-state { background:#da9327; }
.si-title { display:flex; align-items:center; gap:.4rem; margin-bottom:.28rem; }
.si-title > a:first-child { color:#166f42; font-family:ui-monospace,monospace; font-weight:850; text-decoration:none; }
.si-main > strong { color:#1a3327; font-size:.9rem; }
.si-status { padding:.14rem .42rem; border-radius:99px; font-size:.61rem; font-weight:800; }
.si-status.is-success { color:#0d713b; background:#e8f6ed; }
.si-status.is-warning { color:#946111; background:#fff3d9; }
.si-status.is-danger { color:#ad3030; background:#feecec; }
.si-status.is-secondary { color:#65736c; background:#eef1ef; }
.si-attachment { color:#65776d; }
.si-meta { display:flex; flex-wrap:wrap; gap:.25rem .65rem; margin-top:.4rem; color:#7a8981; font-size:.68rem; }
.si-meta a { color:#357457; text-decoration:none; }
.si-meta i { margin-inline-end:.25rem; }
.si-branch { padding:.1rem .35rem; border-radius:6px; background:hsl(var(--hue) 55% 95%); color:hsl(var(--hue) 45% 30%); }
.si-money { display:grid; grid-template-columns:repeat(3,1fr); gap:.35rem; }
.si-money span { display:flex; flex-direction:column; padding:.42rem .5rem; border-radius:9px; background:#f6f8f7; }
.si-money small { color:#849189; font-size:.6rem; }
.si-money b { color:#294438; font-family:ui-monospace,monospace; font-size:.76rem; }
.si-money .balance { background:#fff7f1; }
.si-base { grid-column:1/-1; padding-inline:.2rem; }
.si-due { display:flex; flex-direction:column; gap:.3rem; color:#718179; font-size:.68rem; }
.si-due i { margin-inline-end:.3rem; }
.si-actions { display:flex; gap:.35rem; }
.si-actions button, .si-actions a { min-width:39px; min-height:39px; padding:0 .55rem; border:1px solid #dfe7e2; border-radius:9px; background:#fff; color:#51655a; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; }
.si-actions span { display:none; }
.si-actions .pay { color:#0d713d; border-color:#a8d6bb; background:#eef8f2; }
.si-actions .cancel { color:#af3535; border-color:#ebc0c0; }
.si-backdrop { position:fixed; inset:0; z-index:1090; display:grid; place-items:center; padding:1rem; background:rgba(13,29,20,.48); backdrop-filter:blur(2px); }
.si-modal { width:min(640px,96vw); max-height:92vh; overflow:auto; padding:1rem; border-radius:17px; background:#fff; box-shadow:0 24px 70px rgba(0,0,0,.24); }
.si-modal--small { width:min(480px,96vw); }
.si-modal header { display:flex; justify-content:space-between; align-items:center; padding-bottom:.7rem; border-bottom:1px solid #e6ece8; }
.si-modal header small { color:#7c8b83; }
.si-modal h3 { margin:.12rem 0 0; color:#1b3428; font-size:1.05rem; }
.si-modal header button { width:38px; height:38px; border:0; border-radius:10px; background:#f2f5f3; }
.si-balance { display:flex; align-items:center; justify-content:space-between; margin:.75rem 0; padding:.7rem .8rem; border-radius:11px; background:#eff8f3; color:#34604a; }
.si-balance strong { color:#0d713d; font-family:ui-monospace,monospace; }
.si-errors { display:flex; align-items:center; gap:.45rem; margin:.6rem 0; padding:.65rem; border-radius:10px; background:#fff0f0; color:#ab3030; font-size:.72rem; }
.si-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:.65rem; }
.si-form-grid .wide { grid-column:1/-1; }
.si-modal p { color:#6f7e76; font-size:.75rem; }
.si-modal footer { display:flex; justify-content:flex-end; gap:.5rem; margin-top:.9rem; padding-top:.8rem; border-top:1px solid #e6ece8; }
.si-slide-enter-active,.si-slide-leave-active,.si-modal-enter-active,.si-modal-leave-active { transition:.16s ease; }
.si-slide-enter-from,.si-slide-leave-to,.si-modal-enter-from,.si-modal-leave-to { opacity:0; transform:translateY(-5px); }
@media (max-width:1100px) {
    .si-card { grid-template-columns:minmax(220px,1fr) minmax(240px,1fr) auto; }
    .si-due { grid-column:1/3; flex-direction:row; }
}
@media (max-width:767.98px) {
    .si-stats :deep(.stat-rail) { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.55rem; }
    .si-stats :deep(.stat-rail-item) { min-height:70px; padding:.6rem; }
    .si-filter { grid-template-columns:1fr; }
    .si-dates { grid-template-columns:1fr 1fr; }
    .si-dates label:first-child { grid-column:1/-1; }
    .si-summary { grid-template-columns:repeat(2,1fr); }
    .si-summary span:nth-child(2) { grid-column:span 1; }
    .si-card { grid-template-columns:minmax(0,1fr); gap:.6rem; padding:.78rem; }
    .si-money { grid-template-columns:repeat(3,minmax(0,1fr)); }
    .si-due { grid-column:auto; flex-direction:row; flex-wrap:wrap; }
    .si-actions { border-top:1px solid #edf1ee; padding-top:.55rem; }
    .si-actions button,.si-actions a { flex:1; }
    .si-actions span { display:inline; margin-inline-start:.3rem; font-size:.67rem; }
}
@media (max-width:430px) {
    .si-money { grid-template-columns:1fr; }
    .si-summary { grid-template-columns:1fr 1fr; }
    .si-form-grid { grid-template-columns:1fr; }
    .si-form-grid .wide { grid-column:auto; }
}
</style>
