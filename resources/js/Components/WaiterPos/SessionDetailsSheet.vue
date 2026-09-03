<script setup>
import { computed, ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    orders: { type: Array, required: true },
    summary: { type: Object, required: true },
    currency: { type: Object, required: true },
    cancellingItemId: { type: Number, default: null },
});

const emit = defineEmits({
    close: () => true,
    'cancel-item': (payload) => Number.isInteger(payload?.itemId) && typeof payload?.reason === 'string',
});

const cancellingItem = ref(null);
const cancelReason = ref('');
const isCancelling = computed(() => (
    cancellingItem.value
    && Number(props.cancellingItemId) === Number(cancellingItem.value.id)
));

const chargedItemsCount = computed(() => props.orders.reduce(
    (total, order) => total + (order.items ?? []).reduce(
        (count, item) => count + (item.status === 'cancelled' ? 0 : Number(item.quantity || 0)),
        0,
    ),
    0,
));

function formatQuantity(quantity) {
    return new Intl.NumberFormat('ar', { maximumFractionDigits: 2 }).format(Number(quantity || 0));
}

function statusIcon(status) {
    return {
        pending: 'bi-hourglass-split',
        approved: 'bi-check2-circle',
        preparing: 'bi-fire',
        ready: 'bi-bell',
        served: 'bi-check2-all',
        delivered: 'bi-check2-all',
        completed: 'bi-check2-all',
        cancelled: 'bi-x-circle',
    }[status] ?? 'bi-circle';
}

function openCancellation(item) {
    if (!item?.canCancel || props.cancellingItemId) return;
    cancellingItem.value = item;
    cancelReason.value = '';
}

function closeCancellation() {
    if (isCancelling.value) return;
    cancellingItem.value = null;
    cancelReason.value = '';
}

function confirmCancellation() {
    const reason = cancelReason.value.trim();
    if (!cancellingItem.value || reason.length < 3 || isCancelling.value) return;

    emit('cancel-item', {
        itemId: Number(cancellingItem.value.id),
        reason,
    });
}

watch(
    () => props.orders,
    (orders) => {
        if (!cancellingItem.value) return;
        const current = orders
            .flatMap((order) => order.items ?? [])
            .find((item) => Number(item.id) === Number(cancellingItem.value.id));
        if (!current || current.status === 'cancelled') closeCancellation();
    },
    { deep: true },
);
</script>

<template>
    <Teleport to="body">
        <div
            class="session-details-backdrop"
            @click.self="emit('close')"
            @keydown.escape.window="emit('close')"
        >
            <section
                class="session-details"
                role="dialog"
                aria-modal="true"
                aria-labelledby="session-details-title"
            >
                <header class="session-details__header">
                    <div class="session-details__heading">
                        <span class="session-details__icon" aria-hidden="true">
                            <i class="bi bi-receipt-cutoff"></i>
                        </span>
                        <div>
                            <h2 id="session-details-title">تفاصيل حساب الطاولة</h2>
                            <p>{{ orders.length }} {{ orders.length === 1 ? 'جولة' : 'جولات' }} · {{ formatQuantity(chargedItemsCount) }} صنف</p>
                        </div>
                    </div>
                    <button type="button" class="session-details__close" aria-label="إغلاق التفاصيل" @click="emit('close')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <div class="session-details__body">
                    <section class="bill-summary" aria-label="ملخص الحساب">
                        <div class="bill-summary__primary">
                            <small>المبلغ المطلوب من الطاولة</small>
                            <strong>{{ formatMoney(summary.outstanding, currency) }}</strong>
                        </div>
                        <div class="bill-summary__stat">
                            <small>إجمالي الطلبات</small>
                            <b>{{ formatMoney(summary.total, currency) }}</b>
                        </div>
                        <div class="bill-summary__stat">
                            <small>تمت تسويته</small>
                            <b>{{ formatMoney(summary.settled, currency) }}</b>
                        </div>
                    </section>

                    <div class="rounds-heading">
                        <div>
                            <strong>مِمَّ يتكوّن الحساب؟</strong>
                            <span>كل ما طُلب في الجلسة الحالية</span>
                        </div>
                        <span class="rounds-heading__count">{{ orders.length }}</span>
                    </div>

                    <div v-if="orders.length" class="session-rounds">
                        <article v-for="order in orders" :key="order.id" class="session-round">
                            <header class="session-round__header">
                                <div>
                                    <span class="session-round__number">الجولة {{ order.round }}</span>
                                    <small v-if="order.createdTime">{{ order.createdTime }}</small>
                                </div>
                                <span class="session-round__status" :class="`is-${order.status}`">
                                    <i class="bi" :class="statusIcon(order.status)"></i>
                                    {{ order.statusLabel }}
                                </span>
                                <strong>{{ formatMoney(order.total, currency) }}</strong>
                            </header>

                            <div v-if="order.items?.length" class="session-round__items">
                                <div
                                    v-for="item in order.items"
                                    :key="item.id"
                                    class="session-item"
                                    :class="{ 'is-cancelled': item.status === 'cancelled' }"
                                >
                                    <span class="session-item__quantity">{{ formatQuantity(item.quantity) }}×</span>
                                    <div class="session-item__copy">
                                        <div class="session-item__name-row">
                                            <strong>{{ item.name }}</strong>
                                            <span class="session-item__status" :class="`is-${item.status}`">
                                                {{ item.statusLabel }}
                                            </span>
                                        </div>
                                        <span v-if="item.modifiers?.length" class="session-item__detail">
                                            <i class="bi bi-plus-circle"></i>
                                            {{ item.modifiers.join('، ') }}
                                        </span>
                                        <span v-if="item.exclusions?.length" class="session-item__detail session-item__detail--excluded">
                                            <i class="bi bi-dash-circle"></i>
                                            بدون {{ item.exclusions.join('، ') }}
                                        </span>
                                        <span v-if="item.notes" class="session-item__detail">
                                            <i class="bi bi-chat-left-text"></i>
                                            {{ item.notes }}
                                        </span>
                                        <span v-if="item.status === 'cancelled' && item.cancelledReason" class="session-item__detail session-item__detail--excluded">
                                            <i class="bi bi-journal-check"></i>
                                            سبب الإلغاء: {{ item.cancelledReason }}
                                        </span>
                                    </div>
                                    <div class="session-item__side">
                                        <strong class="session-item__price">
                                            {{ item.status === 'cancelled' ? 'غير محسوب' : formatMoney(item.subtotal, currency) }}
                                        </strong>
                                        <button
                                            v-if="item.canCancel"
                                            type="button"
                                            class="session-item__cancel"
                                            :disabled="Boolean(cancellingItemId)"
                                            @click="openCancellation(item)"
                                        >
                                            <i class="bi bi-x-circle"></i>
                                            إلغاء
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="session-round__empty">لا تتوفر تفاصيل أصناف لهذه الجولة القديمة.</p>

                            <dl
                                v-if="order.discountTotal > 0.009 || order.taxTotal > 0.009 || order.serviceTotal > 0.009"
                                class="session-round__adjustments"
                            >
                                <div v-if="order.discountTotal > 0.009">
                                    <dt>الخصم</dt>
                                    <dd>− {{ formatMoney(order.discountTotal, currency) }}</dd>
                                </div>
                                <div v-if="order.taxTotal > 0.009">
                                    <dt>الضريبة</dt>
                                    <dd>+ {{ formatMoney(order.taxTotal, currency) }}</dd>
                                </div>
                                <div v-if="order.serviceTotal > 0.009">
                                    <dt>الخدمة</dt>
                                    <dd>+ {{ formatMoney(order.serviceTotal, currency) }}</dd>
                                </div>
                            </dl>

                            <p v-if="order.notes" class="session-round__note">
                                <i class="bi bi-sticky"></i>
                                ملاحظة الجولة: {{ order.notes }}
                            </p>
                        </article>
                    </div>

                    <div v-else class="session-details__empty">
                        <i class="bi bi-receipt"></i>
                        <strong>لا توجد طلبات في هذه الجلسة</strong>
                    </div>
                </div>

                <div v-if="cancellingItem" class="cancel-confirm-backdrop" @click.self="closeCancellation">
                    <form class="cancel-confirm" @submit.prevent="confirmCancellation">
                        <span class="cancel-confirm__icon" aria-hidden="true">
                            <i class="bi bi-exclamation-triangle"></i>
                        </span>
                        <div class="cancel-confirm__copy">
                            <small>إلغاء موثّق من الحساب</small>
                            <h3>إلغاء «{{ cancellingItem.name }}»؟</h3>
                            <p v-if="cancellingItem.cancelMode === 'waste'" class="is-waste">
                                الصنف دخل التحضير؛ سيُحذف من حساب الزبون وتُسجّل مكوناته كهدر.
                            </p>
                            <p v-else>
                                سيُحذف من المبلغ المطلوب وتُعاد مكوناته للمخزون إن كانت خُصمت.
                            </p>
                        </div>
                        <label class="cancel-confirm__reason">
                            <span>سبب الإلغاء <b>مطلوب</b></span>
                            <textarea
                                v-model="cancelReason"
                                rows="3"
                                maxlength="500"
                                placeholder="مثال: الزبون غيّر رأيه"
                                autofocus
                            ></textarea>
                            <small>سيظهر السبب في سجل الطلب باسم الموظف الذي نفّذ العملية.</small>
                        </label>
                        <div class="cancel-confirm__actions">
                            <button type="button" class="is-secondary" :disabled="isCancelling" @click="closeCancellation">
                                تراجع
                            </button>
                            <button type="submit" class="is-danger" :disabled="isCancelling || cancelReason.trim().length < 3">
                                <i class="bi" :class="isCancelling ? 'bi-hourglass-split' : 'bi-x-circle'"></i>
                                {{ isCancelling ? 'جارٍ تحديث الحساب…' : 'تأكيد إلغاء الصنف' }}
                            </button>
                        </div>
                    </form>
                </div>

                <footer class="session-details__footer">
                    <span>
                        <i class="bi bi-info-circle"></i>
                        هذا كشف للجرسون؛ التحصيل وإغلاق الفاتورة من شاشة الكاشير.
                    </span>
                    <button type="button" @click="emit('close')">تم</button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.session-details-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1094;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(15, 23, 42, .56);
    backdrop-filter: blur(3px);
}

.session-details {
    position: relative;
    width: min(650px, 100%);
    max-height: calc(100vh - 40px);
    max-height: calc(100dvh - 40px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .65);
    border-radius: 22px;
    background: #f8faf9;
    box-shadow: 0 28px 70px -24px rgba(15, 23, 42, .62);
}

.session-details__header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 17px 20px;
    color: #fff;
    background: linear-gradient(135deg, #105d42 0%, #197252 100%);
}

.session-details__heading {
    min-width: 0;
    flex: 1;
    display: flex;
    align-items: center;
    gap: 12px;
}

.session-details__icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 13px;
    background: rgba(255, 255, 255, .13);
    font-size: 1.15rem;
}

.session-details__heading h2,
.session-details__heading p {
    margin: 0;
}

.session-details__heading h2 {
    color: inherit;
    font-size: 1rem;
    font-weight: 900;
}

.session-details__heading p {
    margin-top: 3px;
    color: rgba(255, 255, 255, .78);
    font-size: .74rem;
}

.session-details__close {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 12px;
    color: #fff;
    background: rgba(255, 255, 255, .12);
    cursor: pointer;
}

.session-details__close:hover {
    background: rgba(255, 255, 255, .22);
}

.session-details__body {
    display: grid;
    gap: 15px;
    padding: 18px;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.bill-summary {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) repeat(2, minmax(0, 1fr));
    overflow: hidden;
    border: 1px solid #dce7e0;
    border-radius: 16px;
    background: #fff;
}

.bill-summary > div {
    min-height: 76px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 4px;
    padding: 12px 14px;
}

.bill-summary > div + div {
    border-inline-start: 1px solid #e5ece8;
}

.bill-summary small {
    color: #718078;
    font-size: .68rem;
    font-weight: 700;
}

.bill-summary b,
.bill-summary strong {
    color: #22342b;
    font-size: .92rem;
    font-variant-numeric: tabular-nums;
}

.bill-summary__primary {
    background: #eff8f2;
}

.bill-summary__primary strong {
    color: #11653f;
    font-size: 1.2rem;
    font-weight: 950;
}

.rounds-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-inline: 2px;
}

.rounds-heading > div {
    flex: 1;
    display: grid;
    gap: 2px;
}

.rounds-heading strong {
    color: #24342c;
    font-size: .86rem;
    font-weight: 900;
}

.rounds-heading span {
    color: #78867e;
    font-size: .69rem;
}

.rounds-heading__count {
    min-width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    color: #176b45 !important;
    background: #e7f4eb;
    font-size: .78rem !important;
    font-weight: 900;
}

.session-rounds {
    display: grid;
    gap: 12px;
}

.session-round {
    overflow: hidden;
    border: 1px solid #dce5e0;
    border-radius: 16px;
    background: #fff;
}

.session-round__header {
    min-height: 52px;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 10px;
    padding: 10px 13px;
    border-bottom: 1px solid #edf1ef;
    background: #fbfdfc;
}

.session-round__header > div {
    display: flex;
    align-items: center;
    gap: 8px;
}

.session-round__number {
    color: #25362d;
    font-size: .8rem;
    font-weight: 900;
}

.session-round__header small {
    color: #8a968f;
    font-size: .65rem;
    font-variant-numeric: tabular-nums;
}

.session-round__header > strong {
    color: #155f3e;
    font-size: .86rem;
    font-weight: 950;
    font-variant-numeric: tabular-nums;
}

.session-round__status,
.session-item__status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 999px;
    color: #475569;
    background: #f1f5f9;
    white-space: nowrap;
}

.session-round__status {
    padding: 4px 8px;
    font-size: .65rem;
    font-weight: 800;
}

.session-round__status.is-pending,
.session-item__status.is-pending { color: #9a5708; background: #fff4d8; }
.session-round__status.is-approved,
.session-round__status.is-preparing,
.session-item__status.is-approved,
.session-item__status.is-preparing { color: #1d5da8; background: #e7f1ff; }
.session-round__status.is-ready,
.session-item__status.is-ready { color: #08744b; background: #dcf8e8; }
.session-round__status.is-completed,
.session-round__status.is-delivered,
.session-item__status.is-served { color: #526159; background: #edf2ef; }
.session-item__status.is-cancelled { color: #b4232d; background: #fff0f0; }

.session-round__items {
    padding: 0 13px;
}

.session-item {
    min-height: 62px;
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
}

.session-item + .session-item {
    border-top: 1px dashed #e5ebe7;
}

.session-item__quantity {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    color: #176b45;
    background: #eaf6ee;
    font-size: .73rem;
    font-weight: 900;
    font-variant-numeric: tabular-nums;
}

.session-item__copy {
    min-width: 0;
    display: grid;
    gap: 3px;
}

.session-item__name-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

.session-item__name-row > strong {
    color: #25342c;
    font-size: .79rem;
    font-weight: 850;
}

.session-item__status {
    padding: 2px 6px;
    font-size: .58rem;
    font-weight: 800;
}

.session-item__detail {
    display: inline-flex;
    align-items: flex-start;
    gap: 5px;
    color: #78857e;
    font-size: .65rem;
    line-height: 1.45;
}

.session-item__detail--excluded {
    color: #a44d4d;
}

.session-item__price {
    color: #25382e;
    font-size: .76rem;
    font-weight: 900;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.session-item__side {
    display: grid;
    justify-items: end;
    gap: 6px;
}

.session-item__cancel {
    min-height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 3px 8px;
    border: 1px solid #fecaca;
    border-radius: 8px;
    color: #b4232d;
    background: #fff7f7;
    font-family: inherit;
    font-size: .61rem;
    font-weight: 850;
    cursor: pointer;
}

.session-item__cancel:hover:not(:disabled) {
    border-color: #f29da3;
    background: #fff0f0;
}

.session-item__cancel:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.session-item.is-cancelled {
    opacity: .65;
}

.session-item.is-cancelled .session-item__name-row > strong {
    text-decoration: line-through;
}

.session-item.is-cancelled .session-item__price {
    color: #9b5459;
    font-size: .65rem;
}

.session-round__adjustments {
    display: grid;
    gap: 5px;
    margin: 0;
    padding: 9px 13px;
    border-top: 1px solid #edf1ef;
    background: #fbfdfc;
}

.session-round__adjustments > div {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    color: #65736b;
    font-size: .67rem;
}

.session-round__adjustments dt,
.session-round__adjustments dd {
    margin: 0;
}

.session-round__adjustments dd {
    font-weight: 850;
    font-variant-numeric: tabular-nums;
}

.session-round__note,
.session-round__empty {
    margin: 0;
    padding: 9px 13px;
    border-top: 1px solid #edf1ef;
    color: #78693f;
    background: #fffdf5;
    font-size: .67rem;
    line-height: 1.5;
}

.session-details__empty {
    min-height: 130px;
    display: grid;
    place-items: center;
    align-content: center;
    gap: 7px;
    color: #7b8981;
}

.session-details__empty i {
    font-size: 1.6rem;
}

.cancel-confirm-backdrop {
    position: absolute;
    inset: 0;
    z-index: 3;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(15, 23, 42, .45);
    backdrop-filter: blur(2px);
}

.cancel-confirm {
    width: min(440px, 100%);
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr);
    gap: 13px;
    padding: 17px;
    border: 1px solid #fee2e2;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 22px 55px -24px rgba(15, 23, 42, .6);
}

.cancel-confirm__icon {
    width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    color: #b4232d;
    background: #fff0f0;
    font-size: 1.05rem;
}

.cancel-confirm__copy {
    min-width: 0;
}

.cancel-confirm__copy small {
    color: #a3484f;
    font-size: .64rem;
    font-weight: 800;
}

.cancel-confirm__copy h3 {
    margin: 2px 0 4px;
    color: #2a3430;
    font-size: .92rem;
    font-weight: 950;
}

.cancel-confirm__copy p {
    margin: 0;
    color: #6d7a73;
    font-size: .69rem;
    line-height: 1.6;
}

.cancel-confirm__copy p.is-waste {
    color: #9b5c13;
}

.cancel-confirm__reason,
.cancel-confirm__actions {
    grid-column: 1 / -1;
}

.cancel-confirm__reason {
    display: grid;
    gap: 6px;
}

.cancel-confirm__reason > span {
    color: #36443d;
    font-size: .72rem;
    font-weight: 850;
}

.cancel-confirm__reason > span b {
    margin-inline-start: 5px;
    color: #b4232d;
    font-size: .61rem;
}

.cancel-confirm__reason textarea {
    width: 100%;
    min-height: 82px;
    box-sizing: border-box;
    resize: vertical;
    padding: 10px 11px;
    border: 1.5px solid #dce5e0;
    border-radius: 11px;
    outline: 0;
    color: #26342d;
    background: #fff;
    font-family: inherit;
    font-size: .76rem;
    line-height: 1.5;
}

.cancel-confirm__reason textarea:focus {
    border-color: #c44b54;
    box-shadow: 0 0 0 4px rgba(196, 75, 84, .1);
}

.cancel-confirm__reason > small {
    color: #829087;
    font-size: .62rem;
    line-height: 1.45;
}

.cancel-confirm__actions {
    display: flex;
    gap: 9px;
}

.cancel-confirm__actions button {
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 11px;
    font-family: inherit;
    font-size: .72rem;
    font-weight: 900;
    cursor: pointer;
}

.cancel-confirm__actions .is-secondary {
    min-width: 86px;
    border: 1px solid #dce5e0;
    color: #56645d;
    background: #fff;
}

.cancel-confirm__actions .is-danger {
    flex: 1;
    border: 1px solid #b4232d;
    color: #fff;
    background: #b4232d;
}

.cancel-confirm__actions button:disabled {
    border-color: #e3e8e5;
    color: #98a29d;
    background: #eef2f0;
    cursor: not-allowed;
}

.session-details__footer {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 18px calc(12px + env(safe-area-inset-bottom));
    border-top: 1px solid #dfe7e2;
    background: #fff;
}

.session-details__footer span {
    flex: 1;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #718078;
    font-size: .67rem;
    line-height: 1.45;
}

.session-details__footer button {
    min-width: 88px;
    min-height: 42px;
    border: 0;
    border-radius: 11px;
    color: #fff;
    background: #197252;
    font-family: inherit;
    font-size: .78rem;
    font-weight: 900;
    cursor: pointer;
}

@media (max-width: 575.98px) {
    .session-details-backdrop {
        align-items: flex-end;
        padding: 0;
        backdrop-filter: none;
    }

    .session-details {
        width: 100%;
        max-height: 94vh;
        max-height: 94dvh;
        border-width: 0;
        border-radius: 20px 20px 0 0;
    }

    .session-details__header,
    .session-details__body {
        padding-inline: 15px;
    }

    .bill-summary {
        grid-template-columns: 1fr 1fr;
    }

    .bill-summary__primary {
        grid-column: 1 / -1;
        min-height: 68px !important;
    }

    .bill-summary > div + div {
        border-inline-start: 0;
        border-top: 1px solid #e5ece8;
    }

    .bill-summary > div:last-child {
        border-inline-start: 1px solid #e5ece8;
    }

    .session-round__header {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .session-round__header > strong {
        grid-column: 2;
        grid-row: 2;
    }

    .session-round__status {
        grid-column: 2;
        grid-row: 1;
    }

    .session-item {
        grid-template-columns: 32px minmax(0, 1fr);
    }

    .session-item__price {
        grid-column: 2;
    }

    .session-item__side {
        grid-column: 2;
        grid-row: 2;
        grid-auto-flow: column;
        align-items: center;
        justify-content: space-between;
    }

    .session-details__footer span {
        display: none;
    }

    .session-details__footer button {
        width: 100%;
    }

    .cancel-confirm-backdrop {
        align-items: flex-end;
        padding: 10px;
    }

    .cancel-confirm {
        border-radius: 17px;
    }
}
</style>
