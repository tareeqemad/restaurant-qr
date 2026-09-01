<script setup>
/**
 * تفاصيل تحويل بين الفروع.
 *
 * Every mutation (إرسال / استلام / إلغاء) is owner-level only. The three
 * `can.*` booleans arrive already ANDed with the transfer's status on the
 * server, so the client can't reveal a button the controller would 403.
 * A branch-level user looking at a still-open transfer gets the same
 * explanatory note the Blade showed.
 *
 * Line money is formatted in PHP with the same helpers the Blade used
 * (Qty::format for quantities and unit costs, number_format(…, 2) for
 * line/grand totals) so nothing re-rounds on the way to the screen.
 */
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    transfer: { type: Object, required: true },
    items: { type: Array, required: true },
    totalCost: { type: String, required: true },
    currency: { type: Object, required: true },
    can: { type: Object, required: true },
    readOnlyNotice: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();

const busy = ref(false);
const post = (url) => {
    if (busy.value) return;
    busy.value = true;
    router.post(url, {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
};

const send = async () => {
    const yes = await ask({
        title: `إرسال التحويل ${props.transfer.number}؟`,
        message: 'سيُخصم المخزون من فرع المصدر فوراً. متابعة؟',
        confirmLabel: 'إرسال',
    });
    if (yes) post(props.urls.send);
};

const receive = async () => {
    const yes = await ask({
        title: 'تأكيد الاستلام؟',
        message: 'تأكيد استلام البضاعة في فرع الوجهة؟',
        confirmLabel: 'تأكيد الاستلام',
    });
    if (yes) post(props.urls.receive);
};

// Cancel needs a typed reason (the controller requires it), so it gets its
// own sheet rather than the yes/no confirm.
const cancelling = ref(false);
const cancelReason = ref('');
const submitCancel = () => {
    const reason = cancelReason.value.trim();
    if (! reason || busy.value) return;
    busy.value = true;
    router.post(props.urls.cancel, { reason }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; cancelling.value = false; },
    });
};
</script>

<template>
    <Head :title="transfer.number" />

    <PageHeader :title="`تحويل ${transfer.number}`" icon="bi-truck"
                :subtitle="`${transfer.fromBranchName} ← → ${transfer.toBranchName} · ${transfer.statusLabel}`"
                :crumbs="[{ label: 'التحويلات بين الفروع', url: urls.index }]" />

    <div class="row g-3">
        <div class="col-lg-8">
            <DataPanel title="بنود التحويل" icon="bi-list-ul" :count="items.length">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>المكوّن</th>
                                <th class="text-end">الكمية</th>
                                <th>من موقع</th>
                                <th>إلى موقع</th>
                                <th class="text-end">سعر/وحدة</th>
                                <th class="text-end">قيمة السطر</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td class="fw-semibold">{{ item.ingredientName }}</td>
                                <td class="text-end">
                                    {{ item.quantity }}
                                    <small class="text-muted">{{ item.unitCode }}</small>
                                </td>
                                <td class="fs-13">{{ item.fromLocationName ?? '—' }}</td>
                                <td class="fs-13">{{ item.toLocationName ?? '—' }}</td>
                                <td class="text-end text-muted">{{ item.unitCost }} {{ currency.symbol }}</td>
                                <td class="text-end fw-semibold text-primary">{{ item.lineCost }} {{ currency.symbol }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-light">
                            <tr>
                                <td colspan="5" class="text-end fw-bold">إجمالي قيمة التحويل</td>
                                <td class="text-end fw-bold fs-18 text-primary">
                                    {{ totalCost }} {{ currency.symbol }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </DataPanel>

            <DataPanel v-if="transfer.notes || transfer.cancelReason" title="ملاحظات" icon="bi-chat-left-text">
                <div v-if="transfer.notes" class="mb-2">
                    <strong>ملاحظة الإنشاء:</strong>
                    <p class="mb-0 text-muted">{{ transfer.notes }}</p>
                </div>
                <div v-if="transfer.cancelReason">
                    <strong>سبب الإلغاء:</strong>
                    <p class="mb-0 text-danger">{{ transfer.cancelReason }}</p>
                </div>
            </DataPanel>
        </div>

        <div class="col-lg-4">
            <DataPanel title="الحالة" icon="bi-info-circle">
                <ul class="list-unstyled mb-0">
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">الحالة الحالية</span>
                        <span class="badge" :class="`bg-${transfer.statusColor}`">{{ transfer.statusLabel }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">من</span>
                        <strong>{{ transfer.fromBranchName }}</strong>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">إلى</span>
                        <strong>{{ transfer.toBranchName }}</strong>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">أنشأه</span>
                        <span>{{ transfer.creatorName ?? '—' }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">تاريخ الإنشاء</span>
                        <span class="fs-13">{{ transfer.createdAt }}</span>
                    </li>
                    <template v-if="transfer.sentAt">
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">أرسله</span>
                            <span>{{ transfer.senderName ?? '—' }}</span>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">تاريخ الإرسال</span>
                            <span class="fs-13">{{ transfer.sentAt }}</span>
                        </li>
                    </template>
                    <template v-if="transfer.receivedAt">
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">استلمه</span>
                            <span>{{ transfer.receiverName ?? '—' }}</span>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">تاريخ الاستلام</span>
                            <span class="fs-13">{{ transfer.receivedAt }}</span>
                        </li>
                    </template>
                </ul>
            </DataPanel>

            <!-- Action rail. Every button is gated server-side; `can.*`
                 already folds in the status check. -->
            <div class="d-grid gap-2 mt-3">
                <button v-if="can.send" type="button" class="btn btn-primary btn-lg bt-action"
                        :disabled="busy" @click="send">
                    <i class="bi bi-send me-1"></i> إرسال (خصم من المصدر)
                </button>

                <button v-if="can.receive" type="button" class="btn btn-success btn-lg bt-action"
                        :disabled="busy" @click="receive">
                    <i class="bi bi-check-circle-fill me-1"></i> تأكيد الاستلام
                </button>

                <button v-if="can.cancel" type="button" class="btn btn-outline-danger bt-action"
                        :disabled="busy" @click="cancelling = true">
                    <i class="bi bi-x-circle me-1"></i> إلغاء التحويل
                </button>

                <div v-if="readOnlyNotice" class="alert alert-info mb-0 small">
                    <i class="bi bi-info-circle"></i>
                    إجراءات الإرسال والاستلام والإلغاء محصورة بمسؤول النظام.
                </div>

                <a :href="urls.index" class="btn btn-light bt-action">
                    <i class="bi bi-arrow-right"></i> القائمة
                </a>
            </div>
        </div>
    </div>

    <Teleport to="body">
        <Transition name="btx">
            <div v-if="cancelling" class="btx-backdrop" @click.self="cancelling = false">
                <div class="btx-card" role="dialog" aria-modal="true">
                    <h3>إلغاء التحويل {{ transfer.number }}</h3>

                    <div v-if="transfer.isInTransit" class="btx-warn">
                        <i class="bi bi-info-circle"></i>
                        <span>هذا التحويل في الطريق. سيتم <strong>إعادة المخزون</strong> لفرع المصدر تلقائياً.</span>
                    </div>

                    <label class="btx-field">
                        <span>سبب الإلغاء <b class="text-danger">*</b></span>
                        <textarea v-model="cancelReason" rows="3" maxlength="500" class="form-control"></textarea>
                    </label>

                    <div class="btx-actions">
                        <button type="button" class="btn btn-light bt-action" @click="cancelling = false">تراجع</button>
                        <button type="button" class="btn btn-danger bt-action"
                                :disabled="! cancelReason.trim() || busy" @click="submitCancel">
                            تأكيد الإلغاء
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* Restaurant tablets: every control is a finger target. */
.bt-action {
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
}

.btx-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1080;
    background: rgba(15, 23, 42, .45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.btx-card {
    background: #fff;
    border-radius: 16px;
    padding: 1.1rem 1.2rem;
    width: min(480px, 100%);
    display: flex;
    flex-direction: column;
    gap: .8rem;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
}
.btx-card h3 { margin: 0; font-size: 1.02rem; font-weight: 800; color: #0f172a; }
.btx-warn {
    display: flex;
    gap: .45rem;
    align-items: flex-start;
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 10px;
    padding: .6rem .75rem;
    font-size: .82rem;
    line-height: 1.6;
}
.btx-field { display: flex; flex-direction: column; gap: .35rem; margin: 0; }
.btx-field > span { font-size: .84rem; font-weight: 800; color: #334155; }
.btx-actions { display: flex; gap: .5rem; justify-content: flex-end; flex-wrap: wrap; }

.btx-enter-active, .btx-leave-active { transition: opacity .15s ease; }
.btx-enter-from, .btx-leave-to { opacity: 0; }
</style>
