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

        <p v-if="workspace.kind === 'session'" class="session-note">
            <i class="bi bi-receipt-check"></i> كل جولات الجلسة مجمّعة هنا وعلى
            فاتورة واحدة.
        </p>

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
                    >افتح التحويل من هنا وطابقه مع حساب البنك قبل تسجيل
                    الدفع.</span
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
                    جاهز {{ quantity(workspace.fulfillment.ready) }} · قيد التحضير
                    {{ quantity(workspace.fulfillment.preparing) }} · بالانتظار
                    {{ quantity(workspace.fulfillment.waiting) }}. الدفع لا يحرر الطاولة؛
                    تتحرر بعد تأكيد الجرسون تسليم كل الأصناف.
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
                    <span>راجع البنود ثم أصدر الفاتورة؛ سنفتح التحصيل مباشرة بعدها.</span>
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
                        إصدار ومتابعة للتحصيل
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
                            :href="workspace.invoice.print_url"
                            target="_blank"
                            rel="noopener"
                            ><i class="bi bi-printer"></i> طباعة الفاتورة</a
                        >
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
        <h2>{{ loading ? "جاري فتح المهمة…" : "اختر مهمة وابدأ التحصيل" }}</h2>
        <p>
            {{
                loading
                    ? "نبقيك في نفس الشاشة ونحمّل أحدث بيانات الفاتورة."
                    : "التحويلات والطلبات المتأخرة تظهر أولاً، وباقي الطاولات تبقى في القائمة الجانبية."
            }}
        </p>
    </section>
</template>

<style scoped>
.checkout-workspace,
.workspace-empty {
    min-width: 0;
    border: 1px solid #e1e8e4;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 12px 34px -30px rgba(15, 49, 31, 0.8);
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
    padding: 0.8rem 1rem;
    border-bottom: 1px solid #e9efeb;
}
.origin-title span {
    color: #79867d;
    font-size: 0.64rem;
    font-weight: 700;
}
.origin-title h2 {
    margin: 0.12rem 0 0;
    color: #1b3022;
    font-size: 1.05rem;
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
    color: #627067;
    background: #f1f5f2;
    font-size: 0.67rem;
    font-weight: 750;
}
.workspace-meta button {
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    border: 1px solid #e1e7e3;
    border-radius: 10px;
    color: #67746c;
    background: #fff;
}
.workspace-meta .back-to-queue {
    display: inline-flex;
    width: auto;
    padding-inline: 0.7rem;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    font: inherit;
    font-size: 0.66rem;
    font-weight: 800;
}
.session-note {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
    padding: 0.45rem 1rem;
    border-bottom: 1px solid #e4ece7;
    color: #146f42;
    background: #f2f8f4;
    font-size: 0.67rem;
    font-weight: 750;
}
.customer-strip {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.55rem 1rem;
    border-bottom: 1px solid #edf1ee;
    background: #fafcfb;
}
.customer-avatar {
    display: grid;
    width: 32px;
    height: 32px;
    place-items: center;
    border-radius: 9px;
    color: #fff;
    background: var(--cx-primary);
    font-size: 0.77rem;
    font-weight: 850;
}
.customer-strip > div {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
}
.customer-strip strong {
    font-size: 0.74rem;
}
.customer-strip small {
    margin-top: 0.1rem;
    color: #7b887f;
    font-size: 0.62rem;
}
.loyalty,
.debt,
.advance {
    padding: 0.24rem 0.45rem;
    border-radius: 999px;
    font-size: 0.62rem;
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
    color: #17623d;
    background: #e9f7ee;
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
    font-size: 0.72rem;
}
.workspace-alert span {
    margin-top: 0.08rem;
    font-size: 0.62rem;
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
    color: #fff;
    background: #8b6a0f;
    font: inherit;
    font-size: 0.64rem;
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
    color: #fff;
    background: #2e6e91;
    font: inherit;
    font-size: 0.64rem;
    font-weight: 800;
}
.approval-alert {
    border-color: #b8d8c2;
    color: #225e37;
    background: #eff9f2;
}
.approval-alert > div {
    flex: 1;
}
.approval-alert > button {
    min-height: 38px;
    padding-inline: 0.65rem;
    border: 1px solid #2d7948;
    border-radius: 8px;
    color: #fff;
    background: #287043;
    font: inherit;
    font-size: 0.64rem;
    font-weight: 800;
}
.workspace-grid {
    display: grid;
    min-height: 0;
    flex: 1;
    grid-template-columns: minmax(0, 1fr) minmax(250px, 32%);
    gap: 0.7rem;
    padding: 0.7rem 1rem 5.3rem;
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
    min-height: 48px;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.6rem;
    border: 1px solid #dce7df;
    border-radius: 11px;
    color: #315640;
    background: #f7fbf8;
    text-align: start;
    font: inherit;
}
.discount-action > i:first-child {
    display: grid;
    width: 30px;
    height: 30px;
    place-items: center;
    border-radius: 8px;
    color: #fff;
    background: var(--cx-primary);
}
.discount-action span {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
}
.discount-action strong {
    font-size: 0.69rem;
}
.discount-action small {
    margin-top: 0.08rem;
    color: #7a887f;
    font-size: 0.58rem;
}
.discount-action > i:last-child {
    color: #829087;
    font-size: 0.65rem;
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
    border: 1px solid #e2e9e4;
    border-radius: 12px;
    overflow: hidden;
}
.order-card > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.55rem 0.65rem;
    background: #f8faf9;
}
.order-card > header div {
    display: flex;
    flex-direction: column;
}
.order-card > header strong {
    color: #27382d;
    font-size: 0.72rem;
}
.order-card > header small {
    color: #7a867e;
    font-size: 0.61rem;
}
.order-card > header > b {
    color: #294632;
    font-size: 0.73rem;
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
    min-height: 54px;
    padding: 0.42rem 0.6rem;
    border-top: 1px solid #edf1ee;
}
.order-line:first-child {
    border-top: 0;
}
.order-line.cancelled {
    border-color: #f0dcdf;
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
    color: var(--cx-primary);
    background: #edf7ef;
    font-size: 0.67rem;
    font-weight: 850;
}
.line-copy {
    display: flex;
    min-width: 0;
    flex-direction: column;
}
.line-copy strong {
    overflow: hidden;
    color: #28372e;
    font-size: 0.72rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.line-copy small {
    margin-top: 0.08rem;
    overflow: hidden;
    color: #7a867e;
    font-size: 0.59rem;
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
    color: #56645b;
    background: #f0f3f1;
    font-size: 0.58rem;
}
.line-not-charged {
    color: #a33641;
    font-size: 0.62rem;
    white-space: nowrap;
}
.line-cancel-action {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 1px solid #efcdd1;
    border-radius: 9px;
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
    color: #34473a;
    font-size: 0.68rem;
    white-space: nowrap;
}
.order-discounts {
    display: flex;
    gap: 0.3rem;
    padding: 0.45rem 0.6rem;
    border-top: 1px solid #edf1ee;
    flex-wrap: wrap;
}
.order-discounts span {
    padding: 0.17rem 0.35rem;
    border-radius: 6px;
    color: #9b2832;
    background: #fff1f2;
    font-size: 0.59rem;
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
    border: 1px dashed #ccd8d0;
    border-radius: 14px;
    color: #75837a;
    text-align: center;
}
.invoice-missing > i {
    color: #8caf98;
    font-size: 1.4rem;
}
.invoice-missing strong {
    color: #435248;
    font-size: 0.78rem;
}
.invoice-missing-total {
    color: var(--cx-primary);
    font-size: 1.15rem;
}
.invoice-missing span {
    font-size: 0.66rem;
}
.invoice-missing button {
    min-height: 44px;
    margin-top: 0.35rem;
    padding-inline: 1rem;
    border: 0;
    border-radius: 10px;
    color: #fff;
    background: var(--cx-primary);
    font: inherit;
    font-size: 0.72rem;
    font-weight: 800;
}
.invoice-blocked-note {
    color: #9b3038;
    font-size: 0.62rem;
    font-weight: 750;
}
.history-card {
    border: 1px solid #e1e8e3;
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
    color: #435248;
    font-size: 0.68rem;
    font-weight: 750;
}
.history-card summary b {
    display: grid;
    min-width: 20px;
    height: 20px;
    place-items: center;
    border-radius: 999px;
    color: #54705e;
    background: #edf3ef;
    font-size: 0.6rem;
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
    background: #f7faf8;
}
.history-list p > i {
    color: #1d7a40;
}
.history-list p > span {
    display: flex;
    flex-direction: column;
}
.history-list p strong,
.history-list p b {
    font-size: 0.63rem;
}
.history-list p small {
    color: #7b887f;
    font-size: 0.57rem;
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
    color: #7b887f;
    text-align: center;
    font-size: 0.65rem;
}
.refund-action {
    width: 100%;
    min-height: 42px;
    margin-top: 0.45rem;
    border: 1px solid #e8b6bb;
    border-radius: 9px;
    color: #a32b36;
    background: #fff4f5;
    font: inherit;
    font-size: 0.68rem;
    font-weight: 800;
}
.advanced-actions {
    border: 1px solid #e2e8e4;
    border-radius: 11px;
    overflow: hidden;
}
.advanced-actions summary {
    display: flex;
    min-height: 42px;
    align-items: center;
    justify-content: space-between;
    padding-inline: 0.6rem;
    color: #5d6b62;
    cursor: pointer;
    list-style: none;
    font-size: 0.66rem;
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
    border: 1px solid #dce4df;
    border-radius: 8px;
    color: #4e5f55;
    background: #fff;
    text-decoration: none;
    font: inherit;
    font-size: 0.62rem;
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
    padding: 0.65rem 1rem;
    border-top: 1px solid #dce5df;
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 -10px 25px -22px rgba(12, 43, 24, 0.7);
    backdrop-filter: blur(8px);
}
.payment-dock > div {
    display: flex;
    min-width: 160px;
    margin-inline-end: auto;
    flex-direction: column;
}
.payment-dock > div span {
    color: #758179;
    font-size: 0.61rem;
}
.payment-dock > div strong {
    color: var(--cx-primary);
    font-size: 0.96rem;
}
.payment-dock button {
    min-height: 44px;
    padding-inline: 0.9rem;
    border: 1px solid #d7e1da;
    border-radius: 10px;
    color: #36503f;
    background: #fff;
    font: inherit;
    font-size: 0.7rem;
    font-weight: 800;
}
.payment-dock .primary-action {
    border-color: var(--cx-primary);
    color: #fff;
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
    color: #16763a;
    font-size: 0.78rem;
    font-weight: 850;
}
.workspace-empty {
    display: grid;
    min-height: 420px;
    place-items: center;
    align-content: center;
    padding: 1rem;
    color: #7b887f;
    text-align: center;
}
.empty-mark {
    display: grid;
    width: 58px;
    height: 58px;
    place-items: center;
    border-radius: 18px;
    color: var(--cx-primary);
    background: #edf7ef;
    font-size: 1.35rem;
}
.workspace-empty h2 {
    margin: 0.7rem 0 0.2rem;
    color: #435248;
    font-size: 0.98rem;
}
.workspace-empty p {
    max-width: 390px;
    margin: 0;
    font-size: 0.7rem;
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
</style>
