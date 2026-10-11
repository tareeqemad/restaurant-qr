<script setup>
import { computed, ref } from "vue";
import { formatMoney } from "../../Composables/useMoney";
import InvoiceSummary from "./InvoiceSummary.vue";

const props = defineProps({
    workspace: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    currency: { type: Object, required: true },
    abilities: { type: Object, required: true },
    commands: { type: Object, default: () => ({}) },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits({
    close: () => true,
    command: (name, payload) =>
        typeof name === "string" && payload !== undefined,
});

const historyOpen = ref(false);
const billableTotal = computed(() =>
    (props.workspace?.orders ?? [])
        .filter((order) => order.status !== "cancelled")
        .reduce((total, order) => total + Number(order.total || 0), 0),
);
const fulfillmentStations = computed(() =>
    (props.workspace?.fulfillment?.stations ?? [])
        .map((station) => station.name)
        .join(" + "),
);

function quantity(value) {
    return Number(value).toLocaleString("en-US", { maximumFractionDigits: 2 });
}

function dateTime(value) {
    if (!value) return "";
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return "";

    return parsed.toLocaleString("ar-PS", {
        dateStyle: "short",
        timeStyle: "short",
    });
}
</script>

<template>
    <section
        v-if="workspace"
        class="checkout-workspace"
        aria-label="مساحة التحصيل"
    >
        <header class="workspace-heading">
            <div class="origin-title">
                <span>{{
                    workspace.kind === "session"
                        ? "تحصيل طاولة"
                        : "تحصيل طلب هاتفي"
                }}</span>
                <h2>{{ workspace.label }}</h2>
            </div>
            <div class="workspace-meta">
                <span v-if="workspace.covers"
                    ><i class="bi bi-people"></i> {{ workspace.covers }}</span
                >
                <span
                    ><i class="bi bi-layers"></i> {{ workspace.orders.length }}
                    {{ workspace.orders.length === 1 ? "جولة" : "جولات" }}</span
                >
                <button
                    type="button"
                    class="back-to-queue"
                    aria-label="العودة إلى قائمة العمل"
                    @click="emit('close')"
                >
                    <i class="bi bi-arrow-right"></i><span>قائمة العمل</span>
                </button>
            </div>
        </header>

        <div
            v-if="workspace.customer?.name || workspace.customer?.phone"
            class="customer-strip"
        >
            <span class="customer-avatar">{{
                (workspace.customer.name || "?").slice(0, 1)
            }}</span>
            <div>
                <strong>{{ workspace.customer.name || "زبون" }}</strong>
                <small
                    >{{ workspace.customer.phone || "بدون هاتف" }} ·
                    {{ workspace.customer.visits }} زيارة</small
                >
            </div>
            <span v-if="workspace.customer.loyalty" class="loyalty">
                {{ workspace.customer.loyalty.tier_label }} ·
                {{ workspace.customer.loyalty.points }} نقطة
            </span>
            <span v-if="workspace.customer.debt > 0" class="debt">
                دين سابق {{ formatMoney(workspace.customer.debt, currency) }}
            </span>
            <span v-if="workspace.customer.advance_balance > 0" class="advance">
                رصيد مقدم
                {{ formatMoney(workspace.customer.advance_balance, currency) }}
            </span>
        </div>

        <div
            v-if="workspace.pending_transfers.length"
            class="workspace-alert transfer-alert"
        >
            <i class="bi bi-bank"></i>
            <div>
                <strong
                    >{{ workspace.pending_transfers.length }} تحويل بانتظار
                    التأكيد</strong
                >
                <span
                    >طابق الحوالة مع البنك قبل تأكيدها.</span
                >
            </div>
            <button
                v-if="abilities.verify_transfer && commands.verify_transfer"
                type="button"
                :disabled="busy"
                @click="emit('command', 'transfers', { workspace })"
            >
                مراجعة الآن
            </button>
        </div>

        <div
            v-if="workspace.pending_changes.length"
            class="workspace-alert change-alert"
        >
            <i class="bi bi-arrow-repeat"></i>
            <div>
                <strong
                    >{{ workspace.pending_changes.length }} طلب تعديل أو إلغاء
                    معلّق</strong
                >
                <span>لا تعتمد مبلغاً قديماً قبل معالجة التعديل.</span>
            </div>
        </div>

        <div
            v-if="
                workspace.kind === 'session' &&
                workspace.fulfillment?.total > 0 &&
                !workspace.fulfillment.complete
            "
            class="workspace-alert fulfillment-alert"
        >
            <i class="bi bi-hourglass-split"></i>
            <div>
                <strong v-if="workspace.fulfillment.ready > 0">
                    {{ quantity(workspace.fulfillment.ready) }} قطعة جاهزة بانتظار الجرسون
                </strong>
                <strong v-else>
                    الطلب ما زال عند {{ fulfillmentStations || "المطبخ أو البار" }}
                </strong>
                <span>
                    تبقى الطاولة مشغولة حتى تسليم كل الأصناف.
                </span>
            </div>
        </div>

        <div
            v-if="workspace.can_close_without_billing"
            class="workspace-alert empty-session-alert"
        >
            <i class="bi bi-door-open"></i>
            <div>
                <strong>لا يوجد مبلغ قابل للفوترة</strong>
                <span
                    >الطلبات ملغاة أو الجلسة خالية؛ أغلقها من هنا لتصبح الطاولة
                    متاحة.</span
                >
            </div>
            <button
                v-if="commands.close_empty_session && abilities.collect_payment"
                type="button"
                :disabled="busy"
                @click="emit('command', 'close-empty-session', { workspace })"
            >
                إغلاق وتحرير
            </button>
        </div>

        <div
            v-if="
                workspace.kind === 'order' &&
                workspace.orders[0]?.status === 'pending'
            "
            class="workspace-alert approval-alert"
        >
            <i class="bi bi-send"></i>
            <div>
                <strong>الطلب لم يُرسل للتحضير بعد</strong
                ><span>راجعه ثم اعتمده لتصل البنود إلى المطبخ والبار.</span>
            </div>
            <button
                v-if="commands.approve_order && workspace.orders[0].can_approve"
                type="button"
                :disabled="busy"
                @click="
                    emit('command', 'approve-order', {
                        order: workspace.orders[0],
                    })
                "
            >
                اعتماد وإرسال
            </button>
        </div>

        <div class="workspace-grid">
            <div class="orders-column">
                <article
                    v-for="(order, index) in workspace.orders"
                    :key="order.id"
                    class="order-card"
                >
                    <header>
                        <div>
                            <strong>{{
                                workspace.kind === "session"
                                    ? `الجولة ${index + 1}`
                                    : order.number
                            }}</strong>
                            <small>{{
                                workspace.kind === "session"
                                    ? `${order.number} · ${order.status_label}`
                                    : order.status_label
                            }}</small>
                        </div>
                        <b>{{ formatMoney(order.total, currency) }}</b>
                    </header>
                    <div class="order-lines">
                        <div
                            v-for="line in order.items"
                            :key="line.id"
                            class="order-line"
                            :class="{ cancelled: line.status === 'cancelled' }"
                        >
                            <span class="line-qty"
                                >{{ quantity(line.quantity) }}×</span
                            >
                            <span class="line-copy">
                                <strong>{{ line.name }}</strong>
                                <small v-if="line.modifiers.length">{{
                                    line.modifiers
                                        .map((item) => item.name)
                                        .join("، ")
                                }}</small>
                                <small
                                    v-if="line.exclusions?.length"
                                    class="line-exclusions"
                                >
                                    بدون
                                    {{
                                        line.exclusions
                                            .map((item) => item.name)
                                            .join("، ")
                                    }}
                                </small>
                                <small v-if="line.notes"
                                    >ملاحظة: {{ line.notes }}</small
                                >
                                <small
                                    v-if="line.status === 'cancelled'"
                                    class="line-cancel-audit"
                                >
                                    <i class="bi bi-shield-check"></i>
                                    {{ line.cancelled_reason || "إلغاء مسجّل" }}
                                    <template v-if="line.cancelled_by">
                                        · {{ line.cancelled_by }}
                                    </template>
                                    <template v-if="dateTime(line.cancelled_at)">
                                        · {{ dateTime(line.cancelled_at) }}
                                    </template>
                                </small>
                            </span>
                            <span class="line-status">{{
                                line.status_label
                            }}</span>
                            <b
                                v-if="line.status === 'cancelled'"
                                class="line-not-charged"
                            >غير محسوب</b>
                            <b v-else>{{ formatMoney(line.subtotal, currency) }}</b>
                            <button
                                v-if="line.can_cancel && commands.cancel_item"
                                type="button"
                                class="line-cancel-action"
                                :disabled="busy"
                                :aria-label="`إلغاء ${line.name} من الحساب`"
                                title="إلغاء الصنف من الحساب"
                                @click="emit('command', 'cancel-item', { line, order })"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                    <div v-if="order.discounts.length" class="order-discounts">
                        <span
                            v-for="discount in order.discounts"
                            :key="discount.id"
                            class="discount-chip"
                        >
                            <i class="bi bi-tag"></i>
                            {{
                                discount.name || discount.category || "خصم"
                            }}
                            −{{ formatMoney(discount.amount, currency) }}
                            <button
                                v-if="
                                    abilities.remove_discount &&
                                    commands.remove_discount
                                "
                                type="button"
                                :disabled="
                                    busy ||
                                    [
                                        'paid',
                                        'cancelled',
                                        'unpaid_writeoff',
                                    ].includes(workspace.invoice?.status)
                                "
                                :aria-label="`إزالة ${discount.name || 'الخصم'}`"
                                @click="
                                    emit('command', 'remove-discount', {
                                        discount,
                                    })
                                "
                            >
                                <i class="bi bi-x"></i>
                            </button>
                        </span>
                    </div>
                </article>
            </div>

            <aside class="money-column">
                <InvoiceSummary
                    v-if="workspace.invoice"
                    :invoice="workspace.invoice"
                    :currency="currency"
                />
                <div v-else-if="!workspace.can_close_without_billing" class="invoice-missing">
                    <i class="bi bi-receipt"></i>
                    <strong>جاهزة لإصدار الفاتورة</strong>
                    <b class="invoice-missing-total">{{ formatMoney(billableTotal, currency) }}</b>
                    <button
                        v-if="
                            (workspace.kind === 'session'
                                ? commands.issue_session
                                : commands.issue_order) &&
                            abilities.collect_payment
                        "
                        type="button"
                        :disabled="busy || workspace.pending_changes.length > 0"
                        @click="emit('command', 'issue', { workspace })"
                    >
                        إصدار الفاتورة والتحصيل
                    </button>
                    <small v-if="workspace.pending_changes.length" class="invoice-blocked-note">
                        عالج طلبات التعديل المعلّقة أولاً.
                    </small>
                </div>

                <button
                    v-if="
                        workspace.kind === 'session' &&
                        abilities.record_transfer &&
                        commands.record_transfer &&
                        !workspace.pending_transfers.length
                    "
                    type="button"
                    class="discount-action transfer-record-action"
                    :disabled="busy || workspace.status !== 'active'"
                    @click="emit('command', 'record-transfer', { workspace })"
                >
                    <i class="bi bi-bank"></i>
                    <span
                        ><strong>تسجيل حوالة للمراجعة</strong
                        ><small
                            >لا تُحتسب دفعة قبل مطابقتها مع البنك</small
                        ></span
                    >
                    <i class="bi bi-chevron-left"></i>
                </button>

                <button
                    v-if="
                        abilities.discount &&
                        (workspace.kind === 'session'
                            ? commands.discount_session
                            : commands.discount_order)
                    "
                    type="button"
                    class="discount-action"
                    :disabled="
                        busy ||
                        ['paid', 'cancelled', 'unpaid_writeoff'].includes(
                            workspace.invoice?.status,
                        )
                    "
                    @click="emit('command', 'discount', { workspace })"
                >
                    <i class="bi bi-tag"></i>
                    <span
                        ><strong>إضافة خصم</strong
                        ><small>من نفس شاشة التحصيل</small></span
                    >
                    <i class="bi bi-chevron-left"></i>
                </button>

                <button
                    v-if="
                        workspace.invoice &&
                        abilities.collect_payment &&
                        commands.split
                    "
                    type="button"
                    class="discount-action split-action"
                    :disabled="
                        busy ||
                        workspace.invoice.parked ||
                        workspace.invoice.balance <= 0 ||
                        ['paid', 'cancelled', 'unpaid_writeoff'].includes(
                            workspace.invoice.status,
                        )
                    "
                    @click="emit('command', 'split', { workspace })"
                >
                    <i class="bi bi-people"></i>
                    <span
                        ><strong>{{
                            workspace.invoice.splits.length
                                ? "إدارة الأجزاء"
                                : "تقسيم الفاتورة"
                        }}</strong
                        ><small>تقسيم وتحصيل كل جزء هنا</small></span
                    >
                    <i class="bi bi-chevron-left"></i>
                </button>

                <button
                    v-if="
                        workspace.invoice &&
                        commands.settle_on_account &&
                        abilities.settle_on_account &&
                        workspace.customer?.id &&
                        workspace.invoice.balance > 0 &&
                        !workspace.invoice.parked
                    "
                    type="button"
                    class="discount-action debt-action"
                    :disabled="busy || workspace.pending_changes.length > 0"
                    @click="emit('command', 'settle-on-account', { workspace })"
                >
                    <i class="bi bi-person-lines-fill"></i>
                    <span
                        ><strong>{{
                            workspace.invoice.paid_total > 0
                                ? "تأجيل المتبقي كدين"
                                : "تسجيل الفاتورة كاملة ديناً"
                        }}</strong
                        ><small
                            >يسجل على {{ workspace.customer.name }} ويغلق
                            الطاولة</small
                        ></span
                    >
                    <i class="bi bi-chevron-left"></i>
                </button>

                <button
                    v-if="
                        workspace.invoice?.parked &&
                        commands.unpark &&
                        abilities.settle_on_account
                    "
                    type="button"
                    class="discount-action debt-action"
                    :disabled="busy"
                    @click="emit('command', 'unpark', { workspace })"
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span
                        ><strong>إلغاء تأجيل الدين</strong
                        ><small>إعادة الفاتورة للتحصيل المباشر</small></span
                    >
                    <i class="bi bi-chevron-left"></i>
                </button>

                <details
                    v-if="workspace.invoice"
                    class="history-card"
                    :open="historyOpen"
                    @toggle="historyOpen = $event.target.open"
                >
                    <summary>
                        <span
                            ><i class="bi bi-clock-history"></i> سجل الدفع
                            والاسترداد</span
                        >
                        <b>{{
                            workspace.invoice.payments.length +
                            workspace.invoice.refunds.length
                        }}</b>
                    </summary>
                    <div class="history-list">
                        <p
                            v-for="payment in workspace.invoice.payments"
                            :key="`p:${payment.id}`"
                        >
                            <i class="bi bi-arrow-down-circle"></i>
                            <span
                                ><strong>{{ payment.method_label }}</strong
                                ><small>{{
                                    payment.receiver || "—"
                                }}</small></span
                            >
                            <b>{{ formatMoney(payment.amount, currency) }}</b>
                            <button
                                v-if="
                                    payment.can_void &&
                                    abilities.void_payment &&
                                    commands.void_payment
                                "
                                type="button"
                                class="void-payment"
                                :disabled="busy || workspace.invoice.parked"
                                aria-label="إلغاء الدفعة"
                                @click="
                                    emit('command', 'void-payment', { payment })
                                "
                            >
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </p>
                        <p
                            v-for="refund in workspace.invoice.refunds"
                            :key="`r:${refund.id}`"
                            class="refund-row"
                        >
                            <i class="bi bi-arrow-up-circle"></i>
                            <span
                                ><strong
                                    >استرداد · {{ refund.method_label }}</strong
                                ><small>{{ refund.status_label }}</small></span
                            >
                            <b>- {{ formatMoney(refund.amount, currency) }}</b>
                        </p>
                        <span
                            v-if="
                                !workspace.invoice.payments.length &&
                                !workspace.invoice.refunds.length
                            "
                            class="no-history"
                            >لا توجد حركات بعد.</span
                        >
                        <button
                            v-if="
                                commands.refund &&
                                abilities.refund &&
                                workspace.invoice.refundable_balance > 0
                            "
                            type="button"
                            class="refund-action"
                            @click="emit('command', 'refund', { workspace })"
                        >
                            <i class="bi bi-arrow-counterclockwise"></i> تسجيل
                            استرداد
                        </button>
                    </div>
                </details>

                <details v-if="workspace.invoice" class="advanced-actions">
                    <summary>
                        <span><i class="bi bi-sliders"></i> إجراءات إضافية</span
                        ><i class="bi bi-chevron-down"></i>
                    </summary>
                    <div>
                        <a
                            :href="workspace.invoice.pdf_url"
                            target="_blank"
                            rel="noopener"
                            ><i class="bi bi-file-earmark-pdf"></i> PDF</a
                        >
                        <button
                            v-if="
                                commands.writeoff &&
                                abilities.writeoff &&
                                workspace.invoice.balance > 0 &&
                                ['issued', 'partially_paid'].includes(
                                    workspace.invoice.status,
                                )
                            "
                            type="button"
                            class="danger-admin"
                            :disabled="busy"
                            @click="emit('command', 'writeoff', { workspace })"
                        >
                            <i class="bi bi-journal-x"></i> شطب الرصيد
                        </button>
                        <button
                            v-if="
                                commands.cancel_invoice &&
                                abilities.cancel_invoice &&
                                workspace.invoice.payments.length === 0 &&
                                workspace.invoice.status === 'issued'
                            "
                            type="button"
                            class="danger-admin"
                            :disabled="busy"
                            @click="
                                emit('command', 'cancel-invoice', { workspace })
                            "
                        >
                            <i class="bi bi-receipt-cutoff"></i> إلغاء الفاتورة
                        </button>
                    </div>
                </details>
            </aside>
        </div>

        <footer v-if="workspace.invoice && commands.pay" class="payment-dock">
            <div>
                <span>المتبقي للتحصيل</span>
                <strong>{{
                    formatMoney(workspace.invoice.balance, currency)
                }}</strong>
            </div>
            <button
                v-if="
                    workspace.invoice.balance > 0 && abilities.collect_payment
                "
                type="button"
                class="primary-action"
                :disabled="
                    busy ||
                    workspace.invoice.parked ||
                    workspace.pending_changes.length > 0
                "
                @click="emit('command', 'payment', { workspace })"
            >
                <i class="bi bi-cash-stack"></i> تسجيل دفع
            </button>
            <button
                v-if="
                    workspace.invoice.balance > 0 && abilities.collect_payment
                "
                type="button"
                :disabled="
                    busy ||
                    workspace.invoice.parked ||
                    workspace.pending_changes.length > 0
                "
                @click="emit('command', 'full-cash', { workspace })"
            >
                قبض كامل نقدي
            </button>
            <span v-if="workspace.invoice.balance <= 0" class="paid-mark"
                ><i class="bi bi-check2-circle"></i> مكتملة</span
            >
            <a :href="workspace.invoice.print_url" target="_blank" rel="noopener" class="print-invoice">
                <i class="bi bi-printer"></i> طباعة
            </a>
        </footer>
    </section>

    <section v-else class="workspace-empty" aria-live="polite">
        <span class="empty-mark"
            ><i
                :class="
                    loading
                        ? 'bi bi-arrow-repeat spinning'
                        : 'bi bi-receipt-cutoff'
                "
            ></i
        ></span>
        <h2>{{ loading ? "جاري فتح الحساب…" : "اختر طاولة أو طلباً" }}</h2>
    </section>
</template>

<style scoped>
.checkout-workspace,
.workspace-empty {
    min-width: 0;
    border: 1px solid var(--line, var(--line));
    border-radius: 12px;
    background: var(--surface, #FFFDFA);
    box-shadow: none;
}
.checkout-workspace {
    position: relative;
    display: flex;
    min-height: 0;
    flex-direction: column;
    overflow: hidden;
}
.workspace-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--line, var(--line));
    background: var(--canvas, #F7F3ED);
}
.origin-title span {
    color: var(--muted, var(--muted));
    font-size: .875rem;
    font-weight: 700;
}
.origin-title h2 {
    margin: 0.12rem 0 0;
    color: var(--ink, var(--ink));
    font-size: 1.3rem;
}
.workspace-meta {
    display: flex;
    align-items: center;
    gap: 0.38rem;
}
.workspace-meta > span {
    display: inline-flex;
    min-height: 32px;
    align-items: center;
    gap: 0.25rem;
    padding-inline: 0.5rem;
    border-radius: 8px;
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
    font-size: .875rem;
    font-weight: 750;
}
.workspace-meta button {
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
}
.workspace-meta .back-to-queue {
    display: inline-flex;
    width: auto;
    padding-inline: 0.7rem;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.customer-strip {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: .75rem 1.1rem;
    border-bottom: 1px solid var(--line, var(--line));
    background: var(--surface, #FFFDFA);
}
.customer-avatar {
    display: grid;
    width: 32px;
    height: 32px;
    place-items: center;
    border-radius: 9px;
    color: #FFFDFA;
    background: var(--cx-primary);
    font-size: .875rem;
    font-weight: 800;
}
.customer-strip > div {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
}
.customer-strip strong {
    font-size: .875rem;
}
.customer-strip small {
    margin-top: 0.1rem;
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.loyalty,
.debt,
.advance {
    padding: 0.24rem 0.45rem;
    border-radius: 12px;
    font-size: .875rem;
    font-weight: 750;
}
.loyalty {
    color: #715606;
    background: #fff6d9;
}
.debt {
    color: #9b2731;
    background: #fff0f1;
}
.advance {
    color: var(--brand);
    background: var(--brand-soft);
}
.workspace-alert {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin: 0.5rem 1rem 0;
    padding: 0.52rem 0.65rem;
    border: 1px solid;
    border-radius: 10px;
}
.workspace-alert > i {
    font-size: 0.88rem;
}
.workspace-alert > div {
    display: flex;
    flex-direction: column;
}
.workspace-alert strong {
    font-size: .875rem;
}
.workspace-alert span {
    margin-top: 0.08rem;
    font-size: .875rem;
}
.transfer-alert {
    border-color: #f1d894;
    color: #765307;
    background: #fff9e9;
}
.transfer-alert > div {
    flex: 1;
}
.transfer-alert > button {
    min-height: 38px;
    padding-inline: 0.65rem;
    border: 1px solid #b58a17;
    border-radius: 8px;
    color: #FFFDFA;
    background: #8b6a0f;
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.change-alert {
    border-color: #f0b9bf;
    color: #902c36;
    background: #fff3f4;
}
.fulfillment-alert {
    border-color: #c8c2ef;
    color: #443b83;
    background: #f6f4ff;
}
.fulfillment-alert > div {
    flex: 1;
}
.empty-session-alert {
    border-color: #b8d4e7;
    color: #245b78;
    background: #f0f8fc;
}
.empty-session-alert > div {
    flex: 1;
}
.empty-session-alert > button {
    min-height: 38px;
    padding-inline: 0.65rem;
    border: 1px solid #397b9e;
    border-radius: 8px;
    color: #FFFDFA;
    background: #2e6e91;
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.approval-alert {
    border-color: var(--brand);
    color: var(--brand);
    background: #eff9f2;
}
.approval-alert > div {
    flex: 1;
}
.approval-alert > button {
    min-height: 38px;
    padding-inline: 0.65rem;
    border: 1px solid var(--brand);
    border-radius: 8px;
    color: #FFFDFA;
    background: var(--brand);
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.workspace-grid {
    display: grid;
    min-height: 0;
    flex: 1;
    grid-template-columns: minmax(0, 1fr) minmax(280px, 34%);
    gap: 1rem;
    padding: 1rem 1.1rem 6.3rem;
    overflow-y: auto;
}
.orders-column,
.money-column {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.65rem;
}
.discount-action {
    display: flex;
    min-height: 58px;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    color: var(--ink, var(--ink));
    background: var(--surface, #FFFDFA);
    text-align: start;
    font: inherit;
}
.discount-action > i:first-child {
    display: grid;
    width: 30px;
    height: 30px;
    place-items: center;
    border-radius: 8px;
    color: #FFFDFA;
    background: var(--cx-primary);
}
.discount-action span {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
}
.discount-action strong {
    font-size: .875rem;
}
.discount-action small {
    margin-top: 0.08rem;
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.discount-action > i:last-child {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.discount-action:disabled {
    opacity: 0.45;
}
.split-action > i:first-child {
    background: #366493;
}
.transfer-record-action > i:first-child {
    background: #7b5d0e;
}
.debt-action > i:first-child {
    background: #806515;
}
.order-card {
    border: 1px solid var(--line, var(--line));
    border-radius: 12px;
    overflow: hidden;
}
.order-card > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .8rem;
    background: var(--surface, #FFFDFA);
}
.order-card > header div {
    display: flex;
    flex-direction: column;
}
.order-card > header strong {
    color: var(--ink, var(--ink));
    font-size: .875rem;
}
.order-card > header small {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.order-card > header > b {
    color: var(--ink, var(--ink));
    font-size: .875rem;
}
.order-lines {
    display: flex;
    flex-direction: column;
}
.order-line {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr) auto auto auto;
    gap: 0.45rem;
    align-items: center;
    min-height: 66px;
    padding: .6rem .75rem;
    border-top: 1px solid var(--line, var(--line));
}
.order-line:first-child {
    border-top: 0;
}
.order-line.cancelled {
    border-color: var(--line);
    background: #fffafb;
}
.order-line.cancelled .line-qty,
.order-line.cancelled .line-copy > strong {
    opacity: 0.58;
    text-decoration: line-through;
}
.line-qty {
    display: grid;
    min-height: 30px;
    place-items: center;
    border-radius: 8px;
    color: var(--brand, #A3422B);
    background: var(--brand-soft, #F3E3D8);
    font-size: .875rem;
    font-weight: 800;
}
.line-copy {
    display: flex;
    min-width: 0;
    flex-direction: column;
}
.line-copy strong {
    overflow: hidden;
    color: var(--ink, var(--ink));
    font-size: 1rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.line-copy small {
    margin-top: 0.08rem;
    overflow: hidden;
    color: var(--muted, var(--muted));
    font-size: .875rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.line-copy .line-exclusions {
    color: #9a6210;
    font-weight: 750;
}
.line-copy .line-cancel-audit {
    color: #9b3e48;
    font-weight: 700;
    white-space: normal;
}
.line-status {
    padding: 0.16rem 0.35rem;
    border-radius: 6px;
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
    font-size: .875rem;
}
.line-not-charged {
    color: #a33641;
    font-size: .875rem;
    white-space: nowrap;
}
.line-cancel-action {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: #a43540;
    background: #fff5f6;
}
.line-cancel-action:hover:not(:disabled) {
    border-color: #d89299;
    background: #ffebed;
}
.line-cancel-action:disabled {
    opacity: 0.45;
}
.order-line > b {
    color: var(--ink, var(--ink));
    font-size: .875rem;
    white-space: nowrap;
}
.order-discounts {
    display: flex;
    gap: 0.3rem;
    padding: 0.45rem 0.6rem;
    border-top: 1px solid var(--line, var(--line));
    flex-wrap: wrap;
}
.order-discounts span {
    padding: 0.17rem 0.35rem;
    border-radius: 6px;
    color: #9b2832;
    background: #fff1f2;
    font-size: .875rem;
    font-weight: 700;
}
.order-discounts .discount-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
}
.discount-chip button {
    display: grid;
    width: 26px;
    height: 26px;
    place-items: center;
    margin-inline-start: 0.15rem;
    border: 0;
    border-radius: 6px;
    color: #9b2832;
    background: #ffe2e5;
}
.discount-chip button:disabled {
    opacity: 0.4;
}
.invoice-missing {
    display: grid;
    min-height: 210px;
    place-items: center;
    align-content: center;
    gap: 0.35rem;
    padding: 1rem;
    border: 1px dashed var(--line, var(--line));
    border-radius: 12px;
    color: var(--muted, var(--muted));
    text-align: center;
}
.invoice-missing > i {
    color: var(--muted, var(--muted));
    font-size: 1.4rem;
}
.invoice-missing strong {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.invoice-missing-total {
    color: var(--cx-primary);
    font-size: 1.15rem;
}
.invoice-missing span {
    font-size: .875rem;
}
.invoice-missing button {
    min-height: 44px;
    margin-top: 0.35rem;
    padding-inline: 1rem;
    border: 0;
    border-radius: 8px;
    color: #FFFDFA;
    background: var(--cx-primary);
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.invoice-blocked-note {
    color: #9b3038;
    font-size: .875rem;
    font-weight: 750;
}
.history-card {
    border: 1px solid var(--line, var(--line));
    border-radius: 12px;
    overflow: hidden;
}
.history-card summary {
    display: flex;
    min-height: 44px;
    align-items: center;
    justify-content: space-between;
    padding-inline: 0.65rem;
    cursor: pointer;
    list-style: none;
}
.history-card summary span {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    color: var(--muted, var(--muted));
    font-size: .875rem;
    font-weight: 750;
}
.history-card summary b {
    display: grid;
    min-width: 20px;
    height: 20px;
    place-items: center;
    border-radius: 12px;
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
    font-size: .875rem;
}
.history-list {
    padding: 0 0.6rem 0.55rem;
}
.history-list p {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr) auto 28px;
    gap: 0.35rem;
    align-items: center;
    margin: 0.35rem 0 0;
    padding: 0.4rem;
    border-radius: 8px;
    background: var(--surface, #FFFDFA);
}
.history-list p > i {
    color: var(--brand, #A3422B);
}
.history-list p > span {
    display: flex;
    flex-direction: column;
}
.history-list p strong,
.history-list p b {
    font-size: .875rem;
}
.history-list p small {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.history-list .refund-row i,
.history-list .refund-row b {
    color: #b02a37;
}
.void-payment {
    display: grid;
    width: 28px;
    height: 28px;
    place-items: center;
    border: 0;
    border-radius: 7px;
    color: #a5313b;
    background: #ffebed;
}
.void-payment:disabled {
    opacity: 0.4;
}
.no-history {
    display: block;
    padding: 0.7rem;
    color: var(--muted, var(--muted));
    text-align: center;
    font-size: .875rem;
}
.refund-action {
    width: 100%;
    min-height: 42px;
    margin-top: 0.45rem;
    border: 1px solid #e8b6bb;
    border-radius: 8px;
    color: #a32b36;
    background: #fff4f5;
    font: inherit;
    font-size: .875rem;
    font-weight: 800;
}
.advanced-actions {
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    overflow: hidden;
}
.advanced-actions summary {
    display: flex;
    min-height: 42px;
    align-items: center;
    justify-content: space-between;
    padding-inline: 0.6rem;
    color: var(--muted, var(--muted));
    cursor: pointer;
    list-style: none;
    font-size: .875rem;
    font-weight: 750;
}
.advanced-actions summary span {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.advanced-actions > div {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.35rem;
    padding: 0 0.55rem 0.55rem;
}
.advanced-actions a,
.advanced-actions button {
    display: inline-flex;
    min-height: 40px;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
    text-decoration: none;
    font: inherit;
    font-size: .875rem;
    font-weight: 750;
}
.advanced-actions .danger-admin {
    grid-column: 1 / -1;
    border-color: #e5b5ba;
    color: #9d3039;
    background: #fff5f6;
}
.advanced-actions button:disabled {
    opacity: 0.45;
}
.payment-dock {
    position: absolute;
    right: 0;
    bottom: 0;
    left: 0;
    display: flex;
    align-items: center;
    gap: 0.45rem;
    padding: .85rem 1.1rem;
    border-top: 2px solid var(--line, var(--line));
    background: var(--surface, #FFFDFA);
    box-shadow: none;
    backdrop-filter: none;
}
.payment-dock > div {
    display: flex;
    min-width: 160px;
    margin-inline-end: auto;
    flex-direction: column;
}
.payment-dock > div span {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.payment-dock > div strong {
    color: var(--cx-primary);
    font-size: 1.5rem;
    font-variant-numeric: tabular-nums;
}
.payment-dock button,
.payment-dock .print-invoice {
    min-height: 50px;
    padding-inline: 0.9rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    color: var(--ink, var(--ink));
    background: var(--surface, #FFFDFA);
    font: inherit;
    font-size: .95rem;
    font-weight: 800;
}
.payment-dock .print-invoice {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    text-decoration: none;
}
.payment-dock .primary-action {
    border-color: var(--cx-primary);
    color: #FFFDFA;
    background: var(--cx-primary);
}
.payment-dock button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.paid-mark {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: var(--brand);
    font-size: .875rem;
    font-weight: 800;
}
.workspace-empty {
    display: grid;
    min-height: 420px;
    place-items: center;
    align-content: center;
    padding: 1rem;
    color: var(--muted, var(--muted));
    text-align: center;
}
.empty-mark {
    display: grid;
    width: 58px;
    height: 58px;
    place-items: center;
    border-radius: 12px;
    color: var(--brand, #A3422B);
    background: var(--brand-soft, #F3E3D8);
    font-size: 1.35rem;
}
.workspace-empty h2 {
    margin: 0.7rem 0 0.2rem;
    color: var(--muted, var(--muted));
    font-size: 0.98rem;
}
.workspace-empty p {
    max-width: 390px;
    margin: 0;
    font-size: .875rem;
    line-height: 1.7;
}
.spinning {
    animation: spin 0.75s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
.payment-dock .primary-action:hover:not(:disabled) {
    background: var(--brand-hover, #823321);
    border-color: var(--brand-hover, #823321);
}
@media (max-width: 980px) {
    .workspace-grid {
        grid-template-columns: 1fr;
    }
    .money-column {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: start;
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning {
        animation: none;
    }
}
@media (max-width: 680px) {
    .workspace-heading {
        align-items: flex-start;
    }
    .workspace-meta > span {
        display: none;
    }
    .customer-strip {
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .workspace-grid {
        padding-inline: 0.65rem;
    }
    .money-column {
        display: flex;
    }
    .order-line {
        grid-template-columns: 30px minmax(0, 1fr) auto auto;
    }
    .line-status {
        display: none;
    }
    .payment-dock {
        flex-wrap: wrap;
    }
    .payment-dock > div {
        width: 100%;
    }
    .payment-dock button {
        flex: 1;
    }
    .workspace-meta .back-to-queue {
        min-width: 108px;
    }
}

/* Task-focused workspace layout. */
.checkout-workspace{border-radius:10px;overflow:hidden;padding:0;background:var(--surface)}.workspace-heading{padding:18px;border-bottom:1px solid var(--line);background:var(--surface)}.origin-title h2{font-size:25px}.workspace-meta{gap:10px}.customer-strip{margin:0;border-radius:0;border-bottom:1px solid var(--line);padding:12px 18px;background:var(--canvas)}.workspace-alert{margin:12px 16px;border-radius:6px;padding:12px}.workspace-grid{display:flex;flex-direction:column;gap:16px;padding:16px}.orders-column{min-width:0}.order-card{border-radius:6px;box-shadow:none}.order-card>header{background:var(--canvas);padding:12px;border-bottom:1px solid var(--line)}.back-to-queue{display:inline-flex;min-height:42px}.checkout-empty{min-height:420px;border:1px dashed var(--line);border-radius:10px;box-shadow:none}.checkout-empty h2{font-size:22px}@media(min-width:1400px){.workspace-grid{display:grid;grid-template-columns:minmax(0,1fr) 310px;align-items:start}}

/* Task-specific component layout. */
.workspace-empty{min-height:460px;border:1px dashed var(--line);border-radius:10px;background:transparent;box-shadow:none;display:grid;align-content:center;justify-items:center;padding:32px}.workspace-empty .empty-mark{width:54px;height:54px;border-radius:10px;background:var(--brand-soft);color:var(--brand)}.workspace-empty h2{font-size:23px;margin-top:16px}.payment-dock{position:sticky;bottom:0;padding:16px;background:var(--surface);border-top:1px solid var(--line);border-radius:0;gap:12px}.payment-dock .primary-action{min-height:52px;border-radius:8px;font-size:16px}.order-line{padding:14px 12px;border-bottom:1px solid var(--line);gap:10px}.line-qty{font-size:17px;min-width:28px}.line-copy strong{font-size:15px}.line-cancel-action{min-width:42px;min-height:42px}.money-column{min-width:0}.invoice-missing{padding:20px;border:1px solid var(--line);border-radius:8px}.invoice-missing-total{font-size:27px}.discount-action{min-height:46px;border-radius:6px;font-size:14px}
</style>
