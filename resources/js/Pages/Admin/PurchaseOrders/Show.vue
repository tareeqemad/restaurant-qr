<script setup>
/**
 * تفاصيل أمر الشراء.
 *
 * The PO has a strict workflow — draft → معتمد → مُرسل →
 * (مستلم جزئياً) → مستلم — and at each state only one or two actions make
 * sense. Every button here is gated by a SERVER-computed boolean that ANDs
 * the policy with the state guard: the policy alone isn't enough, because
 * BasePolicy::before bypasses every check for owner-level users, and
 * without the state guard a super-admin would be handed every button at
 * once.
 *
 * The money on this screen is deliberately the EFFECTIVE money — the
 * weighted average of what actually arrived, not what was ordered. That's
 * the number that feeds the supplier invoice and the weighted-average cost,
 * so the PO price is demoted to a delta note beside it.
 */
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { formPost } from '../../../Support/formPost';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    po: { type: Object, required: true },
    facts: { type: Array, default: () => [] },
    lines: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    receipts: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    labels: { type: Object, required: true },
    invoicePrimary: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const busy = ref(false);

const approve = () => {
    if (busy.value) return;
    busy.value = true;
    formPost(props.urls.approve);
};

const send = () => {
    if (busy.value) return;
    busy.value = true;
    formPost(props.urls.send);
};

// ── Cancel sheet ─────────────────────────────────────────────────────
// The server requires a reason (required|max:500), so the button stays
// disabled until one is typed rather than bouncing the user off a 422.
const cancelling = ref(false);
const reason = ref('');

const openCancel = () => {
    cancelling.value = true;
    reason.value = '';
};

const submitCancel = () => {
    const text = reason.value.trim();
    if (! text || busy.value) return;
    busy.value = true;
    formPost(props.urls.cancel, { reason: text });
};
</script>

<template>
    <Head :title="po.number" />

    <PageHeader :title="`أمر شراء ${po.number}`" icon="bi-truck"
                :subtitle="po.supplierName ?? 'بدون مورد'"
                :crumbs="[{ label: 'أوامر الشراء', url: urls.index }]">
        <template #actions>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span v-if="can.showStatusBadge" class="badge fs-13 px-3 py-2 me-2"
                      :class="`bg-${po.statusColor}-transparent text-${po.statusColor}`">
                    <i class="bi bi-info-circle"></i> {{ po.statusLabel }}
                </span>

                <!-- Primary next step — exactly one of these can ever render. -->
                <button v-if="can.approve" type="button" class="btn btn-success po-act"
                        :disabled="busy" title="اعتماد الأمر للسماح بإرساله للمورد" @click="approve">
                    <i class="bi bi-check2-circle"></i> اعتماد الأمر
                </button>
                <button v-else-if="can.send" type="button" class="btn btn-primary po-act"
                        :disabled="busy" title="تأكيد إرسال الأمر للمورد" @click="send">
                    <i class="bi bi-send-check"></i> إرسال للمورد
                </button>
                <a v-else-if="can.receive" :href="urls.receive" class="btn btn-success po-act"
                   title="تسجيل استلام البضاعة من المورد">
                    <i class="bi bi-box-arrow-in-down"></i> {{ labels.receive }}
                </a>

                <!-- Invoicing — relevant once goods arrived AND there's still
                     uninvoiced quantity. Hides once everything received has
                     been billed, so nothing gets double-registered. -->
                <a v-if="can.invoice" :href="urls.invoice" class="po-act"
                   :class="invoicePrimary ? 'btn btn-primary btn-lg fw-bold px-4' : 'btn btn-outline-primary'"
                   title="تسجيل فاتورة المورد المرتبطة بهذا الأمر">
                    <i class="bi bi-receipt-cutoff me-1"></i> {{ labels.invoice }}
                </a>
                <span v-else-if="can.fullyInvoiced" class="badge bg-success-transparent text-success fs-13 px-3 py-2"
                      title="كل الكميات المستلمة مغطّاة بفواتير مورد مسجَّلة">
                    <i class="bi bi-receipt-cutoff me-1"></i> فُوتر بالكامل
                </span>

                <a v-if="can.edit" :href="urls.edit" class="btn btn-light po-act" title="تعديل بنود الأمر">
                    <i class="bi bi-pencil"></i> تعديل
                </a>

                <button v-if="can.cancel" type="button" class="btn btn-outline-danger po-act"
                        title="إلغاء أمر الشراء" @click="openCancel">
                    <i class="bi bi-x-circle"></i> إلغاء
                </button>
            </div>
        </template>
    </PageHeader>

    <StatRail :stats="[
        { label: 'الحالة', value: po.statusLabel, icon: 'bi-info-circle', color: po.railColor },
        { label: 'الاعتماد', value: po.approved ? 'معتمد' : 'بانتظار',
          icon: po.approved ? 'bi-check-circle-fill' : 'bi-hourglass-split',
          color: po.approved ? 'success' : 'warning' },
        { label: 'البنود', value: po.itemsCount, icon: 'bi-list-ul', color: 'primary' },
        { label: 'الإجمالي', value: po.totalLabel, icon: 'bi-cash-coin', color: 'success' },
        { label: 'استلامات', value: po.receiptsCount, icon: 'bi-clipboard2-check', color: 'info' },
        { label: 'فواتير', value: po.invoicesCount, icon: 'bi-receipt', color: 'accent' },
    ]" />

    <div class="row g-3 mb-3">
        <div class="col-xl-4">
            <DataPanel title="معلومات أمر الشراء" icon="bi-info-circle">
                <div class="p-3">
                    <div v-for="(f, i) in facts" :key="i" class="d-flex align-items-start gap-2 mb-3">
                        <div class="po-fact-icon"><i class="bi" :class="f.icon"></i></div>
                        <div class="flex-grow-1">
                            <div class="text-muted small fw-bold">{{ f.label }}</div>
                            <div class="fw-bold" :class="{ 'po-mono': f.mono }">{{ f.value }}</div>
                        </div>
                    </div>

                    <div v-if="po.notes" class="mt-3 p-2 rounded po-notes">
                        <div class="small fw-bold text-muted mb-1">ملاحظات</div>
                        <div class="po-pre">{{ po.notes }}</div>
                    </div>
                    <div v-if="po.cancelReason" class="mt-3 p-2 rounded alert-danger po-cancel-note">
                        <div class="small fw-bold mb-1">سبب الإلغاء</div>
                        <div>{{ po.cancelReason }}</div>
                    </div>
                </div>
            </DataPanel>
        </div>

        <div class="col-xl-8">
            <DataPanel title="بنود أمر الشراء" :count="po.itemsCount" icon="bi-list-ul">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>المكوّن</th>
                                <th>مطلوب</th>
                                <th>مستلم</th>
                                <th>مفوتر</th>
                                <th title="السعر الفعلي يأخذ من الاستلام لو تم؛ وإلا من الـ PO الأصلي">
                                    السعر <i class="bi bi-info-circle text-muted small"></i>
                                </th>
                                <th>الإجمالي</th>
                                <th>التقدم</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in lines" :key="line.id">
                                <td>
                                    <div class="fw-bold">{{ line.name }}</div>
                                    <small v-if="line.notes" class="text-muted">{{ line.notes }}</small>
                                </td>
                                <td :title="line.orderedTitle">{{ line.orderedLabel }}</td>
                                <td>
                                    <span class="badge" :class="`bg-${line.receivedColor}`" :title="line.receivedTitle">
                                        {{ line.receivedLabel }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge" :class="line.invoicedCovered ? 'bg-success' : 'bg-light text-muted'">
                                        {{ line.invoicedLabel }}
                                    </span>
                                </td>
                                <td>
                                    <!-- Effective price. Bold + coloured when it differs
                                         from the ordered price, so a vendor price change
                                         is obvious at a glance. -->
                                    <div class="fw-bold"
                                         :class="line.priceChanged ? (line.priceUp ? 'text-warning' : 'text-info') : ''">
                                        {{ line.priceLabel }}
                                    </div>
                                    <small v-if="line.priceChanged" class="d-block text-muted po-fs11"
                                           :title="line.orderedPriceTitle">
                                        <i class="bi" :class="line.priceUp ? 'bi-arrow-up-short' : 'bi-arrow-down-short'"></i>
                                        {{ line.priceDeltaLabel }} عن الـ PO
                                    </small>
                                    <small v-else-if="line.hasReceipt" class="text-success po-fs11">
                                        <i class="bi bi-check2"></i> مطابق لسعر الـ PO
                                    </small>
                                    <small v-else class="text-muted po-fs11">
                                        <i class="bi bi-clock"></i> سعر الـ PO (لم يُستلم بعد)
                                    </small>
                                </td>
                                <td class="fw-bold po-strong-total">
                                    {{ line.subtotalLabel }}
                                    <small v-if="line.priceChanged" class="d-block text-muted po-fs11">
                                        (PO: {{ line.orderedSubtotalLabel }})
                                    </small>
                                </td>
                                <td class="po-progress-cell">
                                    <div class="progress po-progress">
                                        <div class="progress-bar po-progress-bar" :style="{ width: `${line.percent}%` }"></div>
                                    </div>
                                    <small class="text-muted">{{ line.percentLabel }}%</small>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="5" class="text-end fw-bold">
                                    الإجمالي الكلي
                                    <small v-if="! totals.match" class="text-muted d-block po-fs11">
                                        (حسب أسعار الاستلام الفعلية)
                                    </small>
                                </td>
                                <td colspan="2" class="fw-bold fs-5 po-strong-total">
                                    {{ totals.effectiveLabel }}
                                    <small v-if="! totals.match" class="d-block po-fs11"
                                           :class="totals.deltaUp ? 'text-warning' : 'text-info'">
                                        <i class="bi" :class="totals.deltaUp ? 'bi-arrow-up-short' : 'bi-arrow-down-short'"></i>
                                        {{ totals.deltaLabel }} عن طلب الـ PO ({{ totals.orderedLabel }})
                                    </small>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </DataPanel>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-6">
            <DataPanel title="سجل الاستلامات" icon="bi-clipboard2-check" :count="po.receiptsCount">
                <EmptyState v-if="! receipts.length" icon="bi-box-arrow-in-down"
                            title="لا استلامات بعد"
                            message="عند استلام البضاعة سيظهر هنا رقم استلام مستقل لكل عملية." />
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>رقم الاستلام</th>
                                <th>التاريخ</th>
                                <th>البنود</th>
                                <th>بواسطة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in receipts" :key="r.id">
                                <td class="fw-bold po-mono">{{ r.number }}</td>
                                <td>{{ r.receivedAt }}</td>
                                <td>{{ r.itemsCount }}</td>
                                <td>{{ r.receiverName }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </DataPanel>
        </div>

        <div class="col-xl-6">
            <DataPanel title="فواتير المورد المرتبطة" icon="bi-receipt" :count="po.invoicesCount">
                <EmptyState v-if="! invoices.length" icon="bi-receipt"
                            title="لا فواتير مرتبطة"
                            message="سجّل فاتورة المورد بعد وصول الفاتورة الورقية أو الإلكترونية." />
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>الفاتورة</th>
                                <th>الإجمالي</th>
                                <th>المتبقي</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="inv in invoices" :key="inv.id">
                                <td><a :href="inv.url" class="fw-bold">{{ inv.number }}</a></td>
                                <td>{{ inv.totalLabel }}</td>
                                <td class="fw-bold" :class="inv.balanceOpen ? 'text-danger' : 'text-success'">
                                    {{ inv.balanceLabel }}
                                </td>
                                <td><span class="badge" :class="`bg-${inv.statusColor}`">{{ inv.statusLabel }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </DataPanel>
        </div>
    </div>

    <Teleport to="body">
        <Transition name="pc">
            <div v-if="can.cancel && cancelling" class="pc-backdrop" @click.self="cancelling = false">
                <div class="pc-card" role="dialog" aria-modal="true">
                    <h3>إلغاء أمر الشراء</h3>

                    <div v-if="po.partiallyReceived" class="pc-warn">
                        <i class="bi bi-exclamation-triangle"></i>
                        هناك بنود مستلمة جزئياً. الاستلامات السابقة ستبقى في المخزون.
                    </div>

                    <label>
                        <span>سبب الإلغاء *</span>
                        <textarea v-model="reason" rows="3" maxlength="500"></textarea>
                    </label>

                    <div class="pc-actions">
                        <button type="button" class="pc-btn" @click="cancelling = false">تراجع</button>
                        <button type="button" class="pc-btn pc-btn--danger"
                                :disabled="! reason.trim() || busy" @click="submitCancel">
                            <i class="bi bi-x-circle"></i> تأكيد الإلغاء
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* Tablet targets — this screen gets driven from the receiving dock. */
.po-act { min-height: 44px; display: inline-flex; align-items: center; gap: .35rem; }

.po-fact-icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: rgba(var(--accent-rgb), .12);
    color: var(--accent);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.po-mono { font-family: 'Courier New', monospace; }
.po-pre { white-space: pre-wrap; }
.po-notes { background: rgba(var(--accent-rgb), .05); border-right: 3px solid var(--accent); }
.po-cancel-note { border-right: 3px solid #b91c1c; }

.po-fs11 { font-size: .69rem; }
.po-strong-total { color: var(--primary); }

.po-progress-cell { min-width: 150px; }
.po-progress { height: 8px; background: rgba(var(--primary-rgb), .08); }
.po-progress-bar { background: linear-gradient(90deg, var(--primary), var(--accent)); }

.pc-backdrop {
    position: fixed;
    inset: 0;
    z-index: 18000;
    background: rgba(15, 23, 42, .5);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.pc-card {
    width: min(460px, 100%);
    background: #fff;
    border-radius: 18px;
    padding: 1.2rem;
    display: flex;
    flex-direction: column;
    gap: .8rem;
}
.pc-card h3 { margin: 0; font-size: 1.02rem; font-weight: 900; }
.pc-card label { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
.pc-card label > span { font-size: .8rem; font-weight: 800; color: #334155; }
.pc-card textarea {
    border: 1.5px solid #e2e8f0;
    border-radius: 11px;
    padding: .6rem .8rem;
    font: inherit;
    font-size: .88rem;
    resize: none;
}
.pc-warn {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 12px;
    padding: .6rem .8rem;
    font-size: .82rem;
}
.pc-actions { display: flex; gap: .6rem; }
.pc-btn {
    flex: 1;
    min-height: 46px;
    border: 0;
    border-radius: 12px;
    background: #f1f5f9;
    color: #334155;
    font: inherit;
    font-weight: 800;
    cursor: pointer;
}
.pc-btn--danger { background: #dc2626; color: #fff; }
.pc-btn:disabled { opacity: .5; }

.pc-enter-active, .pc-leave-active { transition: opacity .15s; }
.pc-enter-from, .pc-leave-to { opacity: 0; }
</style>
