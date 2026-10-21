<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import AdminLayout from "../../../Layouts/AdminLayout.vue";
import { formatMoney } from "../../../Composables/useMoney";
import { useCashierPolling } from "../../../Composables/cashierPolling";
import { useCashierStore } from "../../../Stores/cashierStore";
import { sendCashierCommand, verifyCashierPayment } from "../../../Api/cashierApi";
import { createPaymentRecovery } from "../../../Stores/cashierPaymentRecovery";
import { confirmsPayment, confirmsRejection } from "../../../Stores/cashierAcknowledgement";
import AttentionRail from "../../../Components/Cashier/AttentionRail.vue";
import CheckoutWorkspace from "../../../Components/Cashier/CheckoutWorkspace.vue";
import ConfirmSheet from "../../../Components/Cashier/ConfirmSheet.vue";
import CustomerCreateSheet from "../../../Components/Cashier/CustomerCreateSheet.vue";
import CustomerAdvanceSheet from "../../../Components/Cashier/CustomerAdvanceSheet.vue";
import DiscountSheet from "../../../Components/Cashier/DiscountSheet.vue";
import NewOrderSheet from "../../../Components/Cashier/NewOrderSheet.vue";
import PaymentSheet from "../../../Components/Cashier/PaymentSheet.vue";
import QueuePane from "../../../Components/Cashier/QueuePane.vue";
import RecordTransferSheet from "../../../Components/Cashier/RecordTransferSheet.vue";
import ReasonSheet from "../../../Components/Cashier/ReasonSheet.vue";
import RefundSheet from "../../../Components/Cashier/RefundSheet.vue";
import SettleDebtSheet from "../../../Components/Cashier/SettleDebtSheet.vue";
import SplitSheet from "../../../Components/Cashier/SplitSheet.vue";
import TransferSheet from "../../../Components/Cashier/TransferSheet.vue";
import ServerHealth from "../../../Components/Ui/ServerHealth.vue";
import CashShiftPanel from "../../../Components/Cashier/CashShiftPanel.vue";

defineOptions({ layout: AdminLayout });

const props = defineProps({
    initialState: { type: Object, required: true },
    catalog: { type: Object, required: true },
    options: { type: Object, required: true },
    endpoints: { type: Object, required: true },
});

const cashier = useCashierStore();
cashier.start(props.initialState, props.endpoints.state);

const notice = ref(null);
const commandBusy = ref(false);
const handoverOpen = ref(false);
const paymentOpen = ref(false);
const fullCash = ref(false);
const paymentToken = ref("");
const pendingPayment = ref(null);
const paymentRecoveryError = ref("");
const paymentRecoveryMessage = ref("");
const lastPaymentReceipt = ref(null);
let paymentRecovery = null;
try {
    paymentRecovery = createPaymentRecovery(window.localStorage, props.options.payment_recovery_scope);
    pendingPayment.value = paymentRecovery.read();
} catch (error) {
    paymentRecoveryError.value = error.message || "تعذّر الوصول إلى سجل استعادة الدفع على هذا الجهاز.";
}
const refundOpen = ref(false);
const refundToken = ref("");
const discountOpen = ref(false);
const splitOpen = ref(false);
const splitRetry = ref({ signature: "", token: "" });
const transferOpen = ref(false);
const transferRetry = ref({ signature: "", token: "" });
const recordTransferOpen = ref(false);
const recordTransferToken = ref("");
const reasonAction = ref(null);
const settleOpen = ref(false);
const settleToken = ref("");
const newOrderOpen = ref(false);
const newOrderToken = ref("");
const customerCreateOpen = ref(false);
const customerCreateToken = ref("");
const customerCreateResult = ref(null);
const customerAdvanceOpen = ref(false);
const customerAdvanceToken = ref("");
const customerAdvanceLookup = ref(null);
const customerAdvanceLookupBusy = ref(false);
const commandError = ref("");
const commandErrors = ref({});
const confirmation = ref(null);
useCashierPolling(cashier);
let searchTimer = null;

const currency = computed(() => props.options.currency);
const commands = computed(() => props.endpoints.commands ?? {});
const handoverTasks = computed(() => [
    {
        key: "transfers",
        attentionType: "transfer",
        count: Number(cashier.counts.pending_transfers || 0),
        title: "تحويلات غير مؤكدة",
        detail: "طابقها مع البنك قبل تسليم العهدة.",
        icon: "bi-bank",
    },
    {
        key: "changes",
        attentionType: "change",
        count: Number(cashier.counts.pending_changes || 0),
        title: "تصحيحات معلّقة",
        detail: "اعتمد الطلب أو ارفضه بسبب واضح.",
        icon: "bi-arrow-repeat",
    },
    {
        key: "tables",
        attentionType: "bill",
        count: Number(cashier.counts.checkout_sessions || 0),
        title: "طاولات بانتظار التحصيل",
        detail: "افتح الحساب وتأكد من البنود والرصيد.",
        icon: "bi-grid-3x3-gap",
    },
    {
        key: "remote",
        attentionType: "remote",
        count: Number(cashier.counts.remote_unpaid || 0),
        title: "طلبات خارجية غير محصّلة",
        detail: "راجع الطلبات الهاتفية والاستلام الخارجي.",
        icon: "bi-bag-check",
    },
].filter((task) => task.count > 0));
const handoverCount = computed(() =>
    handoverTasks.value.reduce((sum, task) => sum + task.count, 0),
);
const hasOpenSheet = computed(() =>
    Boolean(
        paymentOpen.value ||
        refundOpen.value ||
        discountOpen.value ||
        splitOpen.value ||
        transferOpen.value ||
        recordTransferOpen.value ||
        reasonAction.value ||
        settleOpen.value ||
        newOrderOpen.value ||
        customerCreateOpen.value ||
        customerAdvanceOpen.value ||
        confirmation.value,
    ),
);
let previousBodyOverflow = "";

function closeTopSheet() {
    if (commandBusy.value) return;
    if (confirmation.value) {
        confirmation.value = null;
        commandError.value = "";
        return;
    }
    if (reasonAction.value) {
        closeReasonAction();
        return;
    }
    if (paymentOpen.value) {
        closePayment();
        return;
    }
    if (refundOpen.value) {
        closeRefund();
        return;
    }
    if (discountOpen.value) {
        closeDiscount();
        return;
    }
    if (splitOpen.value) {
        closeSplit();
        return;
    }
    if (transferOpen.value) {
        closeTransfer();
        return;
    }
    if (recordTransferOpen.value) {
        closeRecordTransfer();
        return;
    }
    if (settleOpen.value) {
        closeSettle();
        return;
    }
    if (newOrderOpen.value) closeNewOrder();
    else if (customerCreateOpen.value) closeCustomerCreate();
    else if (customerAdvanceOpen.value) closeCustomerAdvance();
}

function handleEscape(event) {
    if (event.key === "Escape" && hasOpenSheet.value) closeTopSheet();
}

function syncPaymentRecovery() {
    try { pendingPayment.value = paymentRecovery?.read(); }
    catch (error) { paymentRecoveryError.value = error.message; }
}

function syncBodyLock(open) {
    if (typeof document === "undefined") return;
    if (open) {
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
    } else {
        document.body.style.overflow = previousBodyOverflow;
    }
}

watch(
    () => cashier.search,
    () => {
        if (searchTimer) window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(async () => {
            await refreshState(true);
            syncWorkspaceUrl();
        }, 320);
    },
);

watch(
    () => [cashier.mode, cashier.filter],
    async () => {
        await refreshState(true);
        syncWorkspaceUrl();
    },
);

watch(hasOpenSheet, syncBodyLock);

watch(
    () => cashier.financialUpdate?.id,
    () => {
        const update = cashier.financialUpdate;
        if (!update) return;
        const ownPayment = pendingPayment.value
            ? { invoiceId: pendingPayment.value.invoiceId, amount: pendingPayment.value.payload.amount }
            : lastPaymentReceipt.value ? { invoiceId: lastPaymentReceipt.value.invoice_id, amount: lastPaymentReceipt.value.amount } : null;
        if (ownPayment && Number(cashier.workspace?.invoice?.id) === Number(ownPayment.invoiceId)
            && Math.abs(Number(update.previousTotal) - Number(update.currentTotal) - Number(ownPayment.amount)) < .01) return;
        showNotice(
            "warning",
            `تغيّر الحساب أثناء فتحه من ${formatMoney(update.previousTotal, currency.value)} إلى ${formatMoney(update.currentTotal, currency.value)}. راجع البنود المحدثة قبل إصدار الفاتورة أو التحصيل.`,
        );
    },
);

onMounted(async () => {
    window.addEventListener("keydown", handleEscape);
    window.addEventListener("storage", syncPaymentRecovery);
    syncBodyLock(hasOpenSheet.value);
    await openInitialTask();
    if (pendingPayment.value) await verifyPendingPayment();
});

onBeforeUnmount(() => {
    cashier.stop();
    if (searchTimer) window.clearTimeout(searchTimer);
    window.removeEventListener("keydown", handleEscape);
    window.removeEventListener("storage", syncPaymentRecovery);
    if (typeof document !== "undefined")
        document.body.style.overflow = previousBodyOverflow;
});

async function refreshState(force = false) {
    try {
        await cashier.refresh({ force });
    } catch {
        showNotice("error", cashier.lastError || "تعذّر تحديث شاشة الكاشير.");
    }
}

async function selectWorkspace(kind, id) {
    try {
        await cashier.select(kind, id);
        syncWorkspaceUrl();
    } catch {
        showNotice("error", cashier.lastError || "تعذّر فتح مساحة التحصيل.");
    }
}

async function clearWorkspace() {
    try {
        await cashier.clearSelection();
        syncWorkspaceUrl();
    } catch {
        showNotice("error", cashier.lastError || "تعذّر إغلاق مساحة التحصيل.");
    }
}

async function focusHandoverTask(task) {
    handoverOpen.value = false;
    const attention = cashier.attention.find(
        (item) => item.type === task.attentionType,
    );
    if (attention?.selection) {
        await selectWorkspace(attention.selection.kind, attention.selection.id);
        return;
    }

    cashier.search = "";
    cashier.filter = "checkout";
    cashier.mode = task.key === "tables" ? "tables" : task.key === "remote" ? "remote" : "all";
    syncWorkspaceUrl();
}

async function openInitialTask() {
    if (
        cashier.selection ||
        cashier.filter !== "checkout" ||
        cashier.search.trim() !== ""
    ) {
        return;
    }

    const first = cashier.sessions[0] ?? cashier.remoteOrders[0];
    if (first) await selectWorkspace(first.kind, first.id);
}

function syncWorkspaceUrl() {
    const url = new URL(window.location.href);
    url.searchParams.delete("session");
    url.searchParams.delete("session_id");
    url.searchParams.delete("order_id");

    if (cashier.selection) {
        url.searchParams.set(
            cashier.selection.kind === "session" ? "session_id" : "order_id",
            String(cashier.selection.id),
        );
    }

    cashier.mode === "all"
        ? url.searchParams.delete("mode")
        : url.searchParams.set("mode", cashier.mode);
    cashier.filter === "checkout"
        ? url.searchParams.delete("filter")
        : url.searchParams.set("filter", cashier.filter);
    cashier.search
        ? url.searchParams.set("search", cashier.search)
        : url.searchParams.delete("search");

    // Keep Inertia's encrypted history payload intact while changing only the
    // cashier address. This makes refresh/back safe without reloading the page.
    window.history.replaceState(window.history.state, "", url);
}

function showNotice(type, message) {
    if (!message) return;
    notice.value = { type, message };
}

function commandToken() {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now()}-${Math.random().toString(16).slice(2)}`
    );
}

function runCommand(name, payload = {}) {
    if (name === "customer-advance") {
        commandError.value = "";
        commandErrors.value = {};
        customerAdvanceLookup.value = null;
        customerAdvanceToken.value = commandToken();
        customerAdvanceOpen.value = true;
        return;
    }

    if (name === "new-customer") {
        commandError.value = "";
        commandErrors.value = {};
        customerCreateResult.value = null;
        customerCreateToken.value = commandToken();
        customerCreateOpen.value = true;
        return;
    }

    if (name === "new-order") {
        commandError.value = "";
        commandErrors.value = {};
        newOrderToken.value = commandToken();
        newOrderOpen.value = true;
        return;
    }

    if (name === "approve-order" && payload.order) {
        commandError.value = "";
        confirmation.value = {
            name: "approve-order",
            title: "إرسال الطلب للتحضير",
            message: `سيُعتمد الطلب ${payload.order.number} وتصل بنوده للمطبخ أو البار حسب محطة كل صنف.`,
            confirmLabel: "اعتماد وإرسال",
            orderId: payload.order.id,
            token: commandToken(),
        };
        return;
    }

    if (
        name === "close-empty-session" &&
        cashier.workspace?.kind === "session"
    ) {
        commandError.value = "";
        confirmation.value = {
            name: "close-empty-session",
            title: "إغلاق الجلسة الخالية",
            message:
                "لا يوجد مبلغ قابل للفوترة. ستُغلق الجلسة وتتحرر الطاولة مع إبقائها بحاجة للتنظيف.",
            confirmLabel: "إغلاق وتحرير الطاولة",
            sessionId: cashier.workspace.id,
            token: commandToken(),
        };
        return;
    }

    if (name === "cancel-item" && payload.line) {
        commandError.value = "";
        commandErrors.value = {};
        const becomesWaste = ["preparing", "ready", "served"].includes(
            payload.line.status,
        );
        reasonAction.value = {
            name,
            item: payload.line,
            token: commandToken(),
            title: `إلغاء ${payload.line.name} من الحساب`,
            message: becomesWaste
                ? "هذا الصنف دخل التحضير أو تم تسليمه. سيُستبعد من حساب الزبون، لكن مكوناته ستبقى مستهلكة وتُسجل كهدر مع اسمك وسبب الإلغاء."
                : "سيُستبعد الصنف من حساب الزبون، ويُعاد احتساب الجولة. أي مكونات حُجزت ولم يبدأ تحضيرها ستعود للمخزون.",
            confirmLabel: becomesWaste
                ? "إلغاء وتسجيل كهدر"
                : "إلغاء وتحديث الحساب",
        };
        return;
    }

    if (name === "cancel-invoice" && cashier.workspace?.invoice) {
        commandError.value = "";
        commandErrors.value = {};
        reasonAction.value = {
            name,
            invoiceId: cashier.workspace.invoice.id,
            token: commandToken(),
            title: "إلغاء الفاتورة",
            message:
                "سيتم إلغاء الفاتورة وعكس قيد إصدارها بالكامل. لا تستخدم هذا الإجراء إذا تم قبض أي مبلغ؛ عندها استخدم الاسترداد أو إلغاء الدفعة أولاً.",
            confirmLabel: "إلغاء الفاتورة وعكس القيد",
        };
        return;
    }

    if (name === "writeoff" && cashier.workspace?.invoice) {
        commandError.value = "";
        commandErrors.value = {};
        reasonAction.value = {
            name,
            invoiceId: cashier.workspace.invoice.id,
            token: commandToken(),
            title: "شطب الرصيد المتبقي",
            message: `سيُغلق الرصيد ${formatMoney(cashier.workspace.invoice.balance, currency.value)} كدين معدوم ويُسجل قيد مصروف مقابل الذمم. هذا ليس خصماً ولا استرداداً.`,
            confirmLabel: "شطب الرصيد وتسجيل القيد",
        };
        return;
    }

    if (name === "unpark" && cashier.workspace?.invoice?.parked) {
        commandError.value = "";
        confirmation.value = {
            name: "unpark",
            title: "إلغاء تأجيل الدين",
            message:
                "ستعود الفاتورة للتحصيل المباشر وتختفي من سجل الديون. الجلسة والطاولة لن تُفتحا من جديد لأنها قد تكون استُخدمت بعد الإغلاق.",
            confirmLabel: "إعادة للتحصيل",
            invoiceId: cashier.workspace.invoice.id,
            token: commandToken(),
        };
        return;
    }

    if (name === "settle-on-account" && cashier.workspace?.invoice) {
        commandError.value = "";
        commandErrors.value = {};
        settleToken.value = commandToken();
        settleOpen.value = true;
        return;
    }

    if (name === "void-payment" && payload.payment) {
        commandError.value = "";
        commandErrors.value = {};
        reasonAction.value = {
            name,
            payment: payload.payment,
            token: commandToken(),
            title: "إلغاء دفعة خاطئة",
            message: `ستُحذف دفعة ${formatMoney(payload.payment.amount, currency.value)} من التحصيل ويُعكس قيدها المحاسبي. استخدم الاسترداد بدل هذا الإجراء إذا كان المال أُعيد فعلياً للزبون.`,
            confirmLabel: "إلغاء الدفعة وعكس القيد",
        };
        return;
    }

    if (name === "transfers" && cashier.workspace?.pending_transfers?.length) {
        commandError.value = "";
        commandErrors.value = {};
        transferOpen.value = true;
        return;
    }

    if (name === "record-transfer" && cashier.workspace?.kind === "session") {
        commandError.value = "";
        commandErrors.value = {};
        recordTransferToken.value = commandToken();
        recordTransferOpen.value = true;
        return;
    }

    if (name === "split" && cashier.workspace?.invoice) {
        commandError.value = "";
        commandErrors.value = {};
        splitOpen.value = true;
        return;
    }

    if (name === "remove-discount" && payload.discount) {
        commandError.value = "";
        confirmation.value = {
            name: "remove-discount",
            title: "إزالة الخصم",
            message: `سيُحذف خصم ${payload.discount.name || "المحدد"} وتُعاد الفاتورة وقيدها المحاسبي للقيمة الجديدة.`,
            confirmLabel: "إزالة الخصم",
            discountId: payload.discount.id,
            token: commandToken(),
        };
        return;
    }

    if (name === "discount" && cashier.workspace) {
        commandError.value = "";
        commandErrors.value = {};
        discountOpen.value = true;
        return;
    }

    if (name === "refund" && cashier.workspace?.invoice) {
        openRefund();
        return;
    }

    if (name === "issue" && cashier.workspace) {
        issueInvoice();
        return;
    }

    if (!["payment", "full-cash"].includes(name) || !cashier.workspace?.invoice)
        return;

    try { pendingPayment.value = paymentRecovery?.read(); } catch (error) { paymentRecoveryError.value = error.message; }
    if (pendingPayment.value || paymentRecoveryError.value) {
        showNotice("warning", "تحقّق من الدفعة المحفوظة أعلى الشاشة قبل بدء دفعة أخرى.");
        return;
    }

    fullCash.value = name === "full-cash";
    paymentToken.value = commandToken();
    commandError.value = "";
    commandErrors.value = {};
    paymentOpen.value = true;
}

async function issueInvoice() {
    const selected = cashier.workspace;
    if (commandBusy.value || !selected) return;

    commandBusy.value = true;
    let issued = false;
    try {
        const endpoint = selected.kind === "session"
            ? commands.value.issue_session.replace(":session", selected.id)
            : commands.value.issue_order.replace(":order", selected.id);
        const result = await sendCashierCommand(endpoint, {});
        if (!result.ok) {
            showNotice("error", result.data.message || "تعذّر إصدار الفاتورة.");
            return;
        }
        issued = true;

        // The cashier may select another table while the write is pending.
        // Never open its payment form as a side effect of the previous bill.
        if (cashier.selection?.kind !== selected.kind || cashier.selection?.id !== selected.id) return;
        await cashier.refresh({ force: true, scope: "workspace" });
        if (cashier.workspace?.kind === selected.kind
            && cashier.workspace?.id === selected.id
            && cashier.workspace?.invoice?.balance > 0) {
            runCommand("payment", { workspace: cashier.workspace });
        }
    } catch {
        showNotice("error", issued
            ? "تم إصدار الفاتورة؛ تعذّر تحميل الحساب. حدّث الشاشة."
            : "لم يصل تأكيد الفاتورة. حدّث الحساب قبل إعادة المحاولة.");
    } finally {
        commandBusy.value = false;
    }
}

function openRefund() {
    refundToken.value = commandToken();
    commandError.value = "";
    commandErrors.value = {};
    refundOpen.value = true;
}

async function confirmCommand() {
    if (commandBusy.value || !confirmation.value || !cashier.workspace) return;

    commandBusy.value = true;
    commandError.value = "";

    try {
        let endpoint;
        let payload = {};

        if (confirmation.value.name === "remove-discount") {
            endpoint = commands.value.remove_discount.replace(
                ":discount",
                confirmation.value.discountId,
            );
            payload = { token: confirmation.value.token };
        } else if (confirmation.value.name === "unpark") {
            endpoint = commands.value.unpark.replace(
                ":invoice",
                confirmation.value.invoiceId,
            );
            payload = { token: confirmation.value.token };
        } else if (confirmation.value.name === "approve-order") {
            endpoint = commands.value.approve_order.replace(
                ":order",
                confirmation.value.orderId,
            );
            payload = { token: confirmation.value.token };
        } else if (confirmation.value.name === "close-empty-session") {
            endpoint = commands.value.close_empty_session.replace(
                ":session",
                confirmation.value.sessionId,
            );
            payload = { token: confirmation.value.token };
        } else {
            return;
        }

        const result = await sendCashierCommand(endpoint, payload);

        if (!result.ok) {
            if (result.status === 409) {
                confirmation.value = null;
                showNotice("warning", result.data.message);
                await refreshState(true);
                return;
            }
            commandError.value = result.data.message || "تعذّر تنفيذ العملية.";
            return;
        }

        const completedName = confirmation.value.name;
        confirmation.value = null;
        showNotice("success", result.data.message);
        if (completedName === "close-empty-session") {
            await clearWorkspace();
            await refreshState(true);
            return;
        }
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد المحاولة من نفس النافذة؛ رمز العملية يمنع التنفيذ المكرر.";
    } finally {
        commandBusy.value = false;
    }
}

function closePayment() {
    if (commandBusy.value) return;
    paymentOpen.value = false;
    commandError.value = "";
    commandErrors.value = {};
}

function closeRefund() {
    if (commandBusy.value) return;
    refundOpen.value = false;
    commandError.value = "";
    commandErrors.value = {};
}

function closeDiscount() {
    if (commandBusy.value) return;
    discountOpen.value = false;
    commandError.value = "";
    commandErrors.value = {};
}

function closeSplit() {
    if (commandBusy.value) return;
    splitOpen.value = false;
    commandError.value = "";
    commandErrors.value = {};
}

function closeTransfer() {
    if (commandBusy.value) return;
    transferOpen.value = false;
    commandError.value = "";
    commandErrors.value = {};
}

function closeRecordTransfer() {
    if (commandBusy.value) return;
    recordTransferOpen.value = false;
    commandError.value = "";
    commandErrors.value = {};
}

function closeReasonAction() {
    if (commandBusy.value) return;
    reasonAction.value = null;
    commandError.value = "";
    commandErrors.value = {};
}

function closeSettle() {
    if (commandBusy.value) return;
    settleOpen.value = false;
    commandError.value = "";
    commandErrors.value = {};
}

function closeNewOrder() {
    if (commandBusy.value) return;
    newOrderOpen.value = false;
    commandError.value = "";
    commandErrors.value = {};
}

function closeCustomerCreate() {
    if (commandBusy.value) return;
    customerCreateOpen.value = false;
    customerCreateResult.value = null;
    commandError.value = "";
    commandErrors.value = {};
}

function closeCustomerAdvance() {
    if (commandBusy.value) return;
    customerAdvanceOpen.value = false;
    customerAdvanceLookup.value = null;
    commandError.value = "";
    commandErrors.value = {};
}

function splitCommandToken(signature) {
    if (splitRetry.value.signature !== signature) {
        splitRetry.value = { signature, token: commandToken() };
    }
    return splitRetry.value.token;
}

function transferCommandToken(signature) {
    if (transferRetry.value.signature !== signature) {
        transferRetry.value = { signature, token: commandToken() };
    }
    return transferRetry.value.token;
}

async function submitPayment(payload) {
    if (commandBusy.value || !cashier.workspace?.invoice) return;
    try {
        if (!paymentRecovery || paymentRecoveryError.value) throw new Error("تعذّر حفظ معلومات استعادة الدفع. لم تُرسل الدفعة.");
        pendingPayment.value = await paymentRecovery.reserve({
            token: paymentToken.value, invoiceId: Number(cashier.workspace.invoice.id),
            invoiceNumber: cashier.workspace.invoice.number, payload, createdAt: new Date().toISOString(),
        });
        paymentOpen.value = false;
        await sendPendingPayment();
    } catch (error) {
        commandError.value = error.message;
    }
}

async function finishRecoveredPayment(data, operation) {
    lastPaymentReceipt.value = data;
    await paymentRecovery.clearConfirmed(operation.token);
    pendingPayment.value = paymentRecovery.read();
    paymentRecoveryMessage.value = "";
    showNotice("success", data.payment_status === "voided" ? "تم العثور على الدفعة؛ أُلغيت لاحقاً. راجع سجل الفاتورة." : "تم التحقق: الدفعة مسجلة، ولم تُكرر.");
    await refreshState(true);
}

async function verifyPendingPayment() {
    if (!pendingPayment.value || commandBusy.value) return;
    const operation = pendingPayment.value;
    commandBusy.value = true;
    paymentRecoveryMessage.value = "جارٍ التحقق من النتيجة المحفوظة…";
    try {
        const result = await verifyCashierPayment(commands.value.verify_payment.replace(":invoice", operation.invoiceId), operation.token);
        if (result.ok && confirmsPayment(result.data.data, operation)) await finishRecoveredPayment(result.data.data, operation);
        else paymentRecoveryMessage.value = result.ok
            ? "لم يصل تأكيد محفوظ بعد. يمكنك إعادة إرسال نفس العملية؛ لن تتحول إلى دفعة جديدة."
            : result.data.message || "تعذّر التحقق. احتفظ بهذه العملية وراجع صلاحية الدخول.";
    } catch {
        paymentRecoveryMessage.value = "تعذّر الاتصال للتحقق. العملية محفوظة على هذا الجهاز حتى بعد إغلاق الصفحة.";
    } finally { commandBusy.value = false; }
}

async function sendPendingPayment() {
    if (!pendingPayment.value || commandBusy.value) return;
    const operation = pendingPayment.value;
    commandBusy.value = true;
    paymentRecoveryMessage.value = "جارٍ تأكيد الدفعة…";
    try {
        const endpoint = operation.payload.split_id
            ? commands.value.pay_split.replace(":invoice", operation.invoiceId).replace(":split", operation.payload.split_id)
            : commands.value.pay.replace(":invoice", operation.invoiceId);
        const result = await sendCashierCommand(endpoint, { token: operation.token, ...operation.payload });
        if (result.ok && confirmsPayment(result.data.data, operation)) {
            await finishRecoveredPayment(result.data.data, operation);
        } else if (result.status === 422 && confirmsRejection(result.data, operation)) {
            await paymentRecovery.clearConfirmed(operation.token);
            pendingPayment.value = null;
            paymentRecoveryMessage.value = "";
            showNotice("error", result.data.message || "رُفضت الدفعة ولم تُسجل. صحّح البيانات وأعد فتح التحصيل.");
            await refreshState(true);
        } else {
            paymentRecoveryMessage.value = result.data.message || "لم تصل نتيجة مؤكدة. تحقّق قبل أي دفعة أخرى.";
        }
    } catch {
        paymentRecoveryMessage.value = "النتيجة غير مؤكدة بسبب الاتصال. تحقّق أو أعد إرسال نفس العملية؛ بياناتها محفوظة حتى بعد إعادة فتح الصفحة.";
    } finally { commandBusy.value = false; }
}

async function lookupAdvanceCustomer(phone) {
    if (customerAdvanceLookupBusy.value) return;
    customerAdvanceLookupBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const url = `${commands.value.customer_lookup}?phone=${encodeURIComponent(phone)}`;
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
        });
        const data = await response.json();
        if (!response.ok) {
            commandError.value = data.message || "تعذّر البحث عن الزبون.";
            commandErrors.value = data.errors ?? {};
            customerAdvanceLookup.value = null;
            return;
        }
        customerAdvanceLookup.value = data.data;
    } catch {
        commandError.value = "تعذّر الاتصال أثناء البحث عن الزبون.";
        customerAdvanceLookup.value = null;
    } finally {
        customerAdvanceLookupBusy.value = false;
    }
}

async function submitCustomerAdvance(payload) {
    if (commandBusy.value) return;
    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const result = await sendCashierCommand(
            commands.value.customer_advance,
            {
                token: customerAdvanceToken.value,
                ...payload,
            },
        );
        if (!result.ok) {
            if (result.status === 409) {
                customerAdvanceOpen.value = false;
                showNotice("warning", result.data.message);
                return;
            }
            commandError.value =
                result.data.message || "تعذّر حفظ الرصيد المقدم.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        customerAdvanceOpen.value = false;
        customerAdvanceLookup.value = result.data.data.customer;
        showNotice("success", result.data.message);
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد المحاولة من نفس النافذة؛ لن يتكرر إيداع الرصيد.";
    } finally {
        commandBusy.value = false;
    }
}

function requestCustomerAdvanceReversal(transaction) {
    commandError.value = "";
    commandErrors.value = {};
    reasonAction.value = {
        name: "reverse-customer-advance",
        transaction,
        token: commandToken(),
        title: "عكس إيداع رصيد خاطئ",
        message: `سيُخصم الإيداع ${formatMoney(transaction.amount, currency.value)} من رصيد الزبون ويُعكس القيد المحاسبي. لا يمكن التنفيذ إذا استُخدم هذا الرصيد.`,
        confirmLabel: "عكس الإيداع والقيد",
    };
}

async function submitRefund(payload) {
    if (commandBusy.value || !cashier.workspace?.invoice) return;

    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const endpoint = commands.value.refund.replace(
            ":invoice",
            cashier.workspace.invoice.id,
        );
        const result = await sendCashierCommand(endpoint, {
            token: refundToken.value,
            ...payload,
        });

        if (!result.ok) {
            if (result.status === 409) {
                refundOpen.value = false;
                showNotice("warning", result.data.message);
                await refreshState(true);
                return;
            }
            commandError.value =
                result.data.message || "تعذّر تسجيل الاسترداد.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        refundOpen.value = false;
        showNotice("success", result.data.message);
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. لا تُنشئ استرداداً جديداً؛ أعد المحاولة بنفس النافذة بعد عودة الاتصال.";
    } finally {
        commandBusy.value = false;
    }
}

async function submitDiscount(payload) {
    if (commandBusy.value || !cashier.workspace) return;

    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const endpoint =
            cashier.workspace.kind === "session"
                ? commands.value.discount_session.replace(
                      ":session",
                      cashier.workspace.id,
                  )
                : commands.value.discount_order.replace(
                      ":order",
                      cashier.workspace.id,
                  );
        const result = await sendCashierCommand(endpoint, payload);

        if (!result.ok) {
            commandError.value = result.data.message || "تعذّر تطبيق الخصم.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        discountOpen.value = false;
        showNotice("success", result.data.message);
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. حدّث الشاشة وتأكد من إجمالي الفاتورة قبل إعادة المحاولة.";
    } finally {
        commandBusy.value = false;
    }
}

async function executeSplitCommand(signature, endpoint, payload) {
    if (commandBusy.value) return;

    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const result = await sendCashierCommand(endpoint, {
            token: splitCommandToken(signature),
            ...payload,
        });

        if (!result.ok) {
            if (result.status === 409) {
                splitRetry.value = { signature: "", token: "" };
                showNotice("warning", result.data.message);
                await refreshState(true);
                return;
            }
            commandError.value =
                result.data.message || "تعذّر تنفيذ عملية التقسيم.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        splitRetry.value = { signature: "", token: "" };
        showNotice("success", result.data.message);
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد نفس العملية من هذه النافذة؛ لن تُكرر الدفعة.";
    } finally {
        commandBusy.value = false;
    }
}

async function saveSplits(splits) {
    const invoice = cashier.workspace?.invoice;
    if (!invoice) return;
    const signature = `save:${invoice.id}:${JSON.stringify(splits)}`;
    await executeSplitCommand(
        signature,
        commands.value.split.replace(":invoice", invoice.id),
        { splits },
    );
}

async function paySplit({ split, reference }) {
    const invoice = cashier.workspace?.invoice;
    if (!invoice || commandBusy.value) return;
    try {
        if (!paymentRecovery) throw new Error('تعذّر الوصول إلى سجل استعادة الدفع.');
        pendingPayment.value = await paymentRecovery.reserve({ token: commandToken(), invoiceId: invoice.id, invoiceNumber: invoice.number,
            payload: { amount: split.amount, method: split.method, split_id: split.id, reference }, createdAt: new Date().toISOString() });
        splitOpen.value = false;
        await sendPendingPayment();
    } catch (error) { commandError.value = error.message; }
}

async function clearSplits() {
    const invoice = cashier.workspace?.invoice;
    if (!invoice) return;
    await executeSplitCommand(
        `clear:${invoice.id}`,
        commands.value.clear_splits.replace(":invoice", invoice.id),
        {},
    );
}

async function executeTransferCommand(signature, endpoint, payload) {
    if (commandBusy.value) return;

    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const result = await sendCashierCommand(endpoint, {
            token: transferCommandToken(signature),
            ...payload,
        });

        if (!result.ok) {
            if (result.status === 409) {
                transferRetry.value = { signature: "", token: "" };
                showNotice("warning", result.data.message);
                await refreshState(true);
                return;
            }
            commandError.value = result.data.message || "تعذّر معالجة التحويل.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        transferRetry.value = { signature: "", token: "" };
        showNotice(
            result.data.warning ? "warning" : "success",
            result.data.warning || result.data.message,
        );
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد نفس القرار من هذه النافذة؛ لن تتكرر الدفعة.";
    } finally {
        commandBusy.value = false;
    }
}

async function submitRecordedTransfer(payload) {
    if (commandBusy.value || cashier.workspace?.kind !== "session") return;

    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const endpoint = commands.value.record_transfer.replace(
            ":session",
            cashier.workspace.id,
        );
        const result = await sendCashierCommand(endpoint, {
            token: recordTransferToken.value,
            ...payload,
        });

        if (!result.ok) {
            if (
                result.status === 409 ||
                String(result.data.message || "").includes("يوجد تحويل معلّق")
            ) {
                recordTransferOpen.value = false;
                showNotice("warning", result.data.message);
                await refreshState(true);
                return;
            }
            commandError.value = result.data.message || "تعذّر تسجيل الحوالة.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        recordTransferOpen.value = false;
        showNotice("success", result.data.message);
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد المحاولة من النافذة نفسها؛ رمز العملية يمنع تسجيل حوالة مكررة.";
    } finally {
        commandBusy.value = false;
    }
}

async function verifyTransfer({
    transfer,
    verified_amount,
    verification_notes,
}) {
    const signature = `verify:${transfer.id}:${verified_amount}:${verification_notes || ""}`;
    await executeTransferCommand(
        signature,
        commands.value.verify_transfer.replace(":transfer", transfer.id),
        { verified_amount, verification_notes },
    );
}

async function rejectTransfer({ transfer, reason }) {
    await executeTransferCommand(
        `reject:${transfer.id}:${reason}`,
        commands.value.reject_transfer.replace(":transfer", transfer.id),
        { reason },
    );
}

async function submitReasonAction(reason) {
    if (commandBusy.value || !reasonAction.value) return;

    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        let endpoint;
        if (reasonAction.value.name === "void-payment") {
            endpoint = commands.value.void_payment.replace(
                ":payment",
                reasonAction.value.payment.id,
            );
        } else if (reasonAction.value.name === "reverse-customer-advance") {
            endpoint = commands.value.reverse_customer_advance.replace(
                ":transaction",
                reasonAction.value.transaction.id,
            );
        } else if (reasonAction.value.name === "writeoff") {
            endpoint = commands.value.writeoff.replace(
                ":invoice",
                reasonAction.value.invoiceId,
            );
        } else if (reasonAction.value.name === "cancel-invoice") {
            endpoint = commands.value.cancel_invoice.replace(
                ":invoice",
                reasonAction.value.invoiceId,
            );
        } else if (reasonAction.value.name === "cancel-item") {
            endpoint = commands.value.cancel_item.replace(
                ":item",
                reasonAction.value.item.id,
            );
        } else {
            return;
        }
        const result = await sendCashierCommand(endpoint, {
            token: reasonAction.value.token,
            reason,
        });

        if (!result.ok) {
            if (result.status === 409) {
                reasonAction.value = null;
                showNotice("warning", result.data.message);
                await refreshState(true);
                return;
            }
            commandError.value = result.data.message || "تعذّر تنفيذ العملية.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        if (
            reasonAction.value.name === "reverse-customer-advance" &&
            result.data.data?.customer
        ) {
            customerAdvanceLookup.value = {
                found: true,
                customer: result.data.data.customer,
            };
        }
        reasonAction.value = null;
        showNotice("success", result.data.message);
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد المحاولة من نفس النافذة؛ لن يتكرر الإلغاء.";
    } finally {
        commandBusy.value = false;
    }
}

async function submitSettlement(payload) {
    const invoice = cashier.workspace?.invoice;
    if (commandBusy.value || !invoice) return;

    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const endpoint = commands.value.settle_on_account.replace(
            ":invoice",
            invoice.id,
        );
        const result = await sendCashierCommand(endpoint, {
            token: settleToken.value,
            notes: payload?.notes || null,
            due_date: payload?.due_date || null,
        });

        if (!result.ok) {
            if (result.status === 409) {
                settleOpen.value = false;
                showNotice("warning", result.data.message);
                await refreshState(true);
                return;
            }
            commandError.value =
                result.data.message || "تعذّر تأجيل الرصيد كدين.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        settleOpen.value = false;
        showNotice("success", result.data.message);
        await refreshState(true);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد المحاولة من نفس النافذة؛ لن يتكرر تأجيل الدين.";
    } finally {
        commandBusy.value = false;
    }
}

async function submitNewOrder(payload) {
    if (commandBusy.value) return;

    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const result = await sendCashierCommand(commands.value.create_order, {
            token: newOrderToken.value,
            ...payload,
        });

        if (!result.ok) {
            if (result.status === 409) {
                newOrderOpen.value = false;
                showNotice("warning", result.data.message);
                await refreshState(true);
                return;
            }
            commandError.value = result.data.message || "تعذّر إنشاء الطلب.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        newOrderOpen.value = false;
        showNotice("success", result.data.message);
        await refreshState(true);
        await selectWorkspace("order", result.data.data.order_id);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد الإرسال من نفس النافذة؛ لن يتكرر إنشاء الطلب.";
    } finally {
        commandBusy.value = false;
    }
}

async function submitCustomerCreate(payload) {
    if (commandBusy.value) return;
    commandBusy.value = true;
    commandError.value = "";
    commandErrors.value = {};

    try {
        const result = await sendCashierCommand(
            commands.value.create_customer,
            {
                token: customerCreateToken.value,
                ...payload,
            },
        );
        if (!result.ok) {
            commandError.value = result.data.message || "تعذّر حفظ الزبون.";
            commandErrors.value = result.data.errors ?? {};
            return;
        }

        customerCreateResult.value = result.data.data;
        showNotice("success", result.data.message);
    } catch {
        commandError.value =
            "انقطع الاتصال. أعد المحاولة من نفس النافذة؛ لن يتكرر تسجيل الزبون.";
    } finally {
        commandBusy.value = false;
    }
}
</script>

<template>
    <Head title="الكاشير" />

    <main class="cashier-page" :class="{ 'has-selection': cashier.selection }">
        <header class="cashier-header">
            <div class="page-title">
                <span class="page-mark"
                    ><i class="bi bi-cash-register"></i
                ></span>
                <div>
                    <small
                        >تحصيل
                        {{ cashier.today.collector_name ?? "حسابك" }}</small
                    >
                    <h1>الكاشير</h1>
                </div>
            </div>

            <details class="shift-summary"><summary><i class="bi bi-wallet2"></i> تحصيلي اليوم <strong>{{ formatMoney(cashier.today.net, currency) }}</strong><i class="bi bi-chevron-down"></i></summary><div class="today-kpis" aria-label="ملخص تحصيلي اليوم">
                <span class="is-primary">
                    <small>صافي تحصيلي</small>
                    <strong :class="{ 'is-negative': cashier.today.net < 0 }">{{
                        formatMoney(cashier.today.net, currency)
                    }}</strong>
                </span>
                <span
                    ><small>صافي النقد المحصّل اليوم</small
                    ><strong>{{
                        formatMoney(cashier.today.cash, currency)
                    }}</strong></span
                >
                <span
                    ><small>غير نقدي</small
                    ><strong>{{
                        formatMoney(cashier.today.non_cash, currency)
                    }}</strong></span
                >
                <span v-if="cashier.today.advance_used > 0"
                    ><small>رصيد مستخدم</small
                    ><strong>{{
                        formatMoney(cashier.today.advance_used, currency)
                    }}</strong></span
                >
                <span v-if="cashier.today.refunds > 0" class="is-refund"
                    ><small>استرداداتي</small
                    ><strong
                        >−{{
                            formatMoney(cashier.today.refunds, currency)
                        }}</strong
                    ></span
                >
            </div>

            </details>

            <div class="header-actions">
                <ServerHealth :last-successful-at="cashier.lastSuccessfulRefresh" :failed="Boolean(cashier.lastError)" :refreshing="cashier.refreshing" :stale-after-ms="30000" />
                <button
                    type="button"
                    class="handover-trigger"
                    :class="{ ready: handoverCount === 0 }"
                    :aria-expanded="handoverOpen"
                    aria-controls="cashier-handover"
                    @click="handoverOpen = !handoverOpen"
                >
                    <i class="bi" :class="handoverCount ? 'bi-clipboard2-pulse' : 'bi-clipboard2-check'"></i>
                    <span>تسليم العهدة</span>
                    <b v-if="handoverCount">{{ handoverCount }}</b>
                </button>
                <Link
                    :href="endpoints.tables"
                    preserve-scroll
                    view-transition
                    aria-label="فتح لوحة الطاولات"
                >
                    <i class="bi bi-grid-3x3-gap"></i><span>الطاولات</span>
                </Link>
                <button
                    v-if="
                        commands.create_customer &&
                        cashier.abilities.create_customer
                    "
                    type="button"
                    aria-label="تسجيل زبون"
                    @click="runCommand('new-customer')"
                >
                    <i class="bi bi-person-plus"></i><span>زبون</span>
                </button>
                <button
                    v-if="
                        commands.customer_advance &&
                        cashier.abilities.collect_payment
                    "
                    type="button"
                    aria-label="إضافة رصيد مقدم لزبون"
                    @click="runCommand('customer-advance')"
                >
                    <i class="bi bi-wallet2"></i><span>رصيد زبون</span>
                </button>
                <button
                    v-if="
                        commands.create_order && cashier.abilities.create_order
                    "
                    type="button"
                    aria-label="إدخال طلب هاتفي"
                    @click="runCommand('new-order')"
                >
                    <i class="bi bi-telephone-plus"></i><span>طلب هاتفي</span>
                </button>
                <button
                    type="button"
                    :disabled="cashier.refreshing"
                    aria-label="تحديث شاشة الكاشير"
                    @click="refreshState(true)"
                >
                    <i
                        class="bi bi-arrow-clockwise"
                        :class="{ spinning: cashier.refreshing }"
                    ></i>
                    <span>تحديث</span>
                </button>
            </div>
        </header>

        <CashShiftPanel :shifts="cashier.snapshot?.cash_shifts" :commands="commands" :currency="currency" :current-branch="options.branch_id" :recovery-scope="options.payment_recovery_scope" :can-collect="cashier.abilities.collect_payment" :blocked="commandBusy || Boolean(pendingPayment)" @updated="refreshState(true)" />
        <section v-if="pendingPayment || paymentRecoveryError" class="payment-recovery" role="status" aria-live="polite">
            <div><strong>دفعة بانتظار التحقق</strong><p v-if="pendingPayment">{{ pendingPayment.invoiceNumber }} · {{ formatMoney(pendingPayment.payload.amount, currency) }} · {{ options.payment_methods.find(method => method.code === pendingPayment.payload.method)?.label || 'طريقة الدفع المحفوظة' }}</p><p>{{ paymentRecoveryError || paymentRecoveryMessage || 'استعد نتيجة هذه العملية قبل بدء دفعة جديدة.' }}</p></div>
            <div v-if="pendingPayment" class="payment-recovery-actions"><button type="button" :disabled="commandBusy" @click="verifyPendingPayment">تحقّق من النتيجة</button><button type="button" :disabled="commandBusy" @click="sendPendingPayment">إعادة إرسال نفس الدفعة</button></div>
        </section>
        <section v-if="lastPaymentReceipt" class="payment-confirmed" role="status">
            <div><strong>دفعة مؤكدة · {{ lastPaymentReceipt.invoice_number }}</strong><span>{{ formatMoney(lastPaymentReceipt.amount, currency) }} · {{ new Date(lastPaymentReceipt.paid_at).toLocaleString('ar') }}</span></div>
            <a :href="lastPaymentReceipt.print_url" target="_blank" rel="noopener"><i class="bi bi-printer"></i> طباعة الفاتورة</a>
            <button type="button" aria-label="إخفاء تأكيد الدفعة" @click="lastPaymentReceipt = null">×</button>
        </section>

        <div
            v-if="notice || cashier.lastError"
            class="cashier-notice"
            :class="`is-${notice?.type ?? 'error'}`"
            role="status"
        >
            <i class="bi bi-info-circle"></i>
            <span>{{ notice?.message || cashier.lastError }}</span>
            <button
                type="button"
                aria-label="إغلاق التنبيه"
                @click="
                    notice = null;
                    cashier.lastError = '';
                "
            >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <section
            v-if="handoverOpen"
            id="cashier-handover"
            class="handover-panel"
            :class="{ ready: handoverCount === 0 }"
            aria-label="مراجعة تسليم عهدة الكاشير"
        >
            <header>
                <span><i class="bi" :class="handoverCount ? 'bi-clipboard2-pulse' : 'bi-shield-check'"></i></span>
                <div>
                    <small>مراجعة تشغيلية قبل مغادرة الوردية</small>
                    <strong>{{ handoverCount ? `${handoverCount} حركة تحتاج إنهاء` : 'لا توجد مهام تحصيل معلّقة' }}</strong>
                    <p>صافي تحصيلك {{ formatMoney(cashier.today.net, currency) }} · نقد محصل محسوب بعد الاستردادات {{ formatMoney(cashier.today.cash, currency) }}</p>
                </div>
                <button type="button" aria-label="إغلاق مراجعة العهدة" @click="handoverOpen = false"><i class="bi bi-x-lg"></i></button>
            </header>
            <div v-if="handoverTasks.length" class="handover-tasks">
                <button v-for="task in handoverTasks" :key="task.key" type="button" @click="focusHandoverTask(task)">
                    <span><i class="bi" :class="task.icon"></i></span>
                    <span><strong>{{ task.title }}</strong><small>{{ task.detail }}</small></span>
                    <b>{{ task.count }}</b>
                    <i class="bi bi-arrow-left"></i>
                </button>
            </div>
            <p v-else class="handover-ready"><i class="bi bi-check2-circle"></i> لا توجد تحويلات أو تصحيحات أو حسابات معلّقة في طابورك.</p>
            <footer><i class="bi bi-info-circle"></i> هذه مراجعة لعهدة المستخدم الحالي؛ الإقفال والمطابقة النهائية ينفذهما المحاسب من مركز المحاسبة.</footer>
        </section>

        <AttentionRail
            :items="cashier.attention"
            :currency="currency"
            @select="selectWorkspace($event.kind, $event.id)"
        />

        <div class="cashier-layout">
            <QueuePane
                :sessions="cashier.sessions"
                :remote-orders="cashier.remoteOrders"
                :selection="cashier.selection"
                :mode="cashier.mode"
                :filter="cashier.filter"
                :search="cashier.search"
                :counts="cashier.counts"
                :currency="currency"
                :loading="cashier.refreshing"
                @select="selectWorkspace"
                @update:mode="cashier.mode = $event"
                @update:filter="cashier.filter = $event"
                @update:search="cashier.search = $event"
                @refresh="refreshState(true)"
            />

            <CheckoutWorkspace
                :workspace="cashier.workspace"
                :loading="cashier.refreshing && Boolean(cashier.selection)"
                :currency="currency"
                :abilities="cashier.abilities"
                :commands="commands"
                :busy="commandBusy"
                @close="clearWorkspace"
                @command="runCommand"
            />
        </div>

        <PaymentSheet
            :open="paymentOpen"
            :invoice="cashier.workspace?.invoice"
            :customer="cashier.workspace?.customer"
            :methods="options.payment_methods"
            :currency="currency"
            :full-cash="fullCash"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closePayment"
            @submit="submitPayment"
        />

        <CustomerAdvanceSheet
            :open="customerAdvanceOpen"
            :lookup="customerAdvanceLookup"
            :methods="options.payment_methods"
            :currency="currency"
            :busy="commandBusy"
            :lookup-busy="customerAdvanceLookupBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeCustomerAdvance"
            @lookup="lookupAdvanceCustomer"
            @submit="submitCustomerAdvance"
            @reverse="requestCustomerAdvanceReversal"
        />

        <RefundSheet
            :open="refundOpen"
            :invoice="cashier.workspace?.invoice"
            :methods="options.refund_methods"
            :currency="currency"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeRefund"
            @submit="submitRefund"
        />

        <DiscountSheet
            :open="discountOpen"
            :workspace="cashier.workspace"
            :categories="options.discount_categories"
            :cap="cashier.abilities.discount_cap"
            :currency="currency"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeDiscount"
            @submit="submitDiscount"
        />

        <SplitSheet
            :open="splitOpen"
            :invoice="cashier.workspace?.invoice"
            :methods="options.payment_methods"
            :currency="currency"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeSplit"
            @save="saveSplits"
            @pay="paySplit"
            @clear="clearSplits"
        />

        <TransferSheet
            :open="transferOpen"
            :transfers="cashier.workspace?.pending_transfers"
            :currency="currency"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeTransfer"
            @verify="verifyTransfer"
            @reject="rejectTransfer"
        />

        <RecordTransferSheet
            :open="recordTransferOpen"
            :workspace="cashier.workspace"
            :currency="currency"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeRecordTransfer"
            @submit="submitRecordedTransfer"
        />

        <ReasonSheet
            :open="Boolean(reasonAction)"
            :title="reasonAction?.title ?? ''"
            :message="reasonAction?.message ?? ''"
            :confirm-label="reasonAction?.confirmLabel ?? ''"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeReasonAction"
            @submit="submitReasonAction"
        />

        <SettleDebtSheet
            :open="settleOpen"
            :invoice="cashier.workspace?.invoice"
            :customer="cashier.workspace?.customer"
            :currency="currency"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeSettle"
            @submit="submitSettlement"
        />

        <NewOrderSheet
            :open="newOrderOpen"
            :catalog="catalog"
            :currency="currency"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            @close="closeNewOrder"
            @submit="submitNewOrder"
        />

        <CustomerCreateSheet
            :open="customerCreateOpen"
            :busy="commandBusy"
            :error="commandError"
            :errors="commandErrors"
            :result="customerCreateResult"
            @close="closeCustomerCreate"
            @submit="submitCustomerCreate"
        />

        <ConfirmSheet
            :open="Boolean(confirmation)"
            :title="confirmation?.title ?? ''"
            :message="confirmation?.message ?? ''"
            :confirm-label="confirmation?.confirmLabel ?? ''"
            :busy="commandBusy"
            :error="commandError"
            @close="
                confirmation = null;
                commandError = '';
            "
            @confirm="confirmCommand"
        />
    </main>
</template>

<style scoped>
.payment-recovery,.payment-confirmed{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;border:1px solid #B78338;border-radius:8px;background:#fff5df;font-size:14px}.payment-recovery p{margin:4px 0}.payment-recovery-actions{display:flex;gap:8px;flex-wrap:wrap}.payment-recovery button,.payment-confirmed a,.payment-confirmed button{min-height:44px;padding:8px 12px;border:1px solid #A3422B;border-radius:6px;background:#FFFDFA;color:#A3422B;font:inherit}.payment-confirmed{border-color:#DFD7CB;background:#FFFDFA}.payment-confirmed>div{display:grid;gap:4px}.payment-confirmed>div span{color:#626B6D}@media(max-width:700px){.payment-recovery,.payment-confirmed{align-items:stretch;flex-wrap:wrap}}

.cashier-page {
    --cx-primary: var(--brand, #A3422B);
    min-height: calc(100dvh - 170px);
    box-sizing: border-box;
    padding: .75rem 0 1rem;
    color: var(--ink, var(--ink));
    background: transparent;
    font-family: inherit;
}
.cashier-header {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 1rem;
    align-items: center;
    max-width: 1680px;
    margin: 0 auto 0.6rem;
    padding: 0 0 1rem;
    border-bottom: 1px solid var(--line, var(--line));
    margin-bottom: 1rem;
}
.page-title {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}
.page-mark {
    display: grid;
    width: 48px;
    height: 48px;
    place-items: center;
    border-radius: 12px;
    color: #FFFDFA;
    background: var(--brand, #A3422B);
    box-shadow: none;
    font-size: 0.95rem;
}
.page-title > div {
    display: flex;
    flex-direction: column;
}
.page-title small {
    color: var(--muted, var(--muted));
    font-size: .875rem;
    font-weight: 700;
}
.page-title h1 {
    margin: 0.05rem 0 0;
    color: var(--ink, var(--ink));
    font-size: 1.5rem;
    font-weight: 800;
}
.today-kpis {
    display: flex;
    justify-content: center;
    gap: .6rem;
}
.today-kpis > span {
    display: flex;
    min-width: 105px;
    min-height: 56px;
    box-sizing: border-box;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: .55rem .75rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    background: var(--surface, #FFFDFA);
}
.today-kpis small {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.today-kpis strong {
    color: var(--ink, var(--ink));
    font-size: 1.05rem;
    font-variant-numeric: tabular-nums;
}
.today-kpis .is-primary {
    border-color: var(--line, var(--line));
    background: var(--brand-soft, #F3E3D8);
}
.today-kpis .is-primary strong {
    color: var(--cx-primary);
}
.today-kpis .is-refund {
    border-color: #f1c6ca;
    background: #fff7f8;
}
.today-kpis .is-refund strong,
.today-kpis strong.is-negative {
    color: #a62f39;
}
.header-actions {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}
.connection {
    display: inline-flex;
    min-height: 36px;
    align-items: center;
    gap: 0.3rem;
    padding-inline: 0.55rem;
    border-radius: 9px;
    color: var(--brand);
    background: var(--brand-soft);
    font-size: .875rem;
    font-weight: 750;
}
.connection.offline {
    color: #a02f39;
    background: #fff0f1;
}
.header-actions a,
.header-actions button {
    display: inline-flex;
    min-height: 44px;
    box-sizing: border-box;
    align-items: center;
    gap: 0.35rem;
    padding-inline: 0.7rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
    text-decoration: none;
    font: inherit;
    font-size: .875rem;
    font-weight: 750;
}
.header-actions button:disabled {
    opacity: 0.55;
}
.header-actions .handover-trigger {
    border-color: #e2c58f;
    color: #875810;
    background: #fff9ed;
}
.header-actions .handover-trigger.ready {
    border-color: var(--brand);
    color: var(--brand);
    background: #eff9f2;
}
.handover-trigger b {
    display: grid;
    min-width: 19px;
    height: 19px;
    place-items: center;
    border-radius: 8px;
    color: #FFFDFA;
    background: #b26a12;
    font-size: .875rem;
}
.handover-panel {
    max-width: 1680px;
    margin: 0 auto 0.55rem;
    overflow: hidden;
    border: 1px solid #e4c88f;
    border-radius: 12px;
    background: var(--surface, #FFFDFA);
    box-shadow: none;
}
.handover-panel > header {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.7rem 0.8rem;
    background: var(--surface, #FFFDFA);
}
.handover-panel > header > span {
    display: grid;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 11px;
    color: #9d620d;
    background: var(--surface, #FFFDFA);
}
.handover-panel > header > div {
    display: grid;
    flex: 1;
}
.handover-panel > header small {
    color: #936c30;
    font-size: .875rem;
    font-weight: 800;
}
.handover-panel > header strong {
    color: #4b3718;
    font-size: .875rem;
}
.handover-panel > header p {
    margin: 0.1rem 0 0;
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.handover-panel > header > button {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 0;
    border-radius: 8px;
    color: var(--muted, var(--muted));
    background: rgba(255, 255, 255, 0.82);
}
.handover-panel.ready {
    border-color: var(--brand);
}
.handover-panel.ready > header {
    background: #edf8f1;
}
.handover-panel.ready > header > span,
.handover-panel.ready > header small {
    color: var(--brand);
}
.handover-panel.ready > header strong {
    color: var(--brand);
}
.handover-tasks {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.45rem;
    padding: 0.55rem;
    border-top: 1px solid var(--line);
}
.handover-tasks > button {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr) auto 12px;
    align-items: center;
    gap: 0.45rem;
    min-height: 58px;
    padding: 0.45rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    color: var(--ink, var(--ink));
    background: var(--surface, #FFFDFA);
    text-align: start;
}
.handover-tasks > button:hover {
    border-color: #d7b677;
    background: #fffdf8;
}
.handover-tasks > button > span:first-child {
    display: grid;
    width: 31px;
    height: 31px;
    place-items: center;
    border-radius: 8px;
    color: #9b620e;
    background: #fff4df;
}
.handover-tasks > button > span:nth-child(2) {
    display: grid;
    min-width: 0;
}
.handover-tasks strong {
    overflow: hidden;
    font-size: .875rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.handover-tasks small {
    overflow: hidden;
    color: var(--muted, var(--muted));
    font-size: .875rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.handover-tasks b {
    color: #9d5700;
    font-size: .875rem;
}
.handover-tasks > button > i {
    color: var(--muted, var(--muted));
    font-size: .875rem;
}
.handover-ready {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
    padding: 0.7rem 0.85rem;
    border-top: 1px solid var(--line);
    color: var(--brand);
    font-size: .875rem;
    font-weight: 800;
}
.handover-panel > footer {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.75rem;
    border-top: 1px solid var(--line, var(--line));
    color: var(--muted, var(--muted));
    background: var(--surface, #FFFDFA);
    font-size: .875rem;
}
.cashier-notice {
    display: flex;
    max-width: 1680px;
    min-height: 44px;
    box-sizing: border-box;
    align-items: center;
    gap: 0.5rem;
    margin: 0 auto 0.55rem;
    padding: 0.45rem 0.65rem;
    border: 1px solid #f0d693;
    border-radius: 11px;
    color: #76520a;
    background: #fff9e9;
    font-size: .875rem;
}
.cashier-notice.is-error {
    border-color: #efbcc1;
    color: #8f2933;
    background: #fff3f4;
}
.cashier-notice.is-success {
    border-color: var(--line);
    color: #196f38;
    background: #effaf2;
}
.cashier-notice span {
    flex: 1;
}
.cashier-notice button {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 0;
    color: inherit;
    background: transparent;
}
.cashier-page > :deep(.attention-rail) {
    max-width: 1680px;
    margin-inline: auto;
}
.cashier-layout {
    display: grid;
    max-width: 1680px;
    height: calc(100dvh - 315px);
    min-height: 520px;
    grid-template-columns: minmax(310px, 360px) minmax(0, 1fr);
    gap: 1rem;
    margin: 0.65rem auto 0;
    margin-top: 1rem;
}
.spinning {
    animation: spin 0.75s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 1050px) {
    .cashier-header {
        grid-template-columns: auto 1fr;
    }
    .today-kpis {
        grid-column: 1 / -1;
        grid-row: 2;
        justify-content: stretch;
    }
    .today-kpis > span {
        flex: 1;
    }
    .header-actions {
        justify-self: end;
    }
    .handover-tasks {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .cashier-layout {
        height: auto;
        min-height: 0;
        grid-template-columns: minmax(250px, 290px) minmax(0, 1fr);
    }
}
@media (max-width: 760px) {
    .cashier-page {
        padding-inline: 0.5rem;
    }
    .cashier-header {
        gap: 0.5rem;
    }
    .header-actions .connection {
        display: none;
    }
    .header-actions a,
    .header-actions button {
        width: 44px;
        justify-content: center;
        padding: 0;
    }
    .header-actions a span,
    .header-actions button span {
        display: none;
    }
    .today-kpis {
        overflow-x: auto;
    }
    .today-kpis > span {
        min-width: 112px;
    }
    .handover-panel > header {
        align-items: flex-start;
    }
    .handover-panel > header p {
        line-height: 1.55;
    }
    .handover-tasks {
        grid-template-columns: 1fr;
    }
    .cashier-layout {
        display: flex;
        flex-direction: column;
    }
    .cashier-layout > :deep(.queue-pane) {
        max-height: 330px;
    }
    .cashier-layout > :deep(.checkout-workspace) {
        min-height: 560px;
    }
    .cashier-page.has-selection > :deep(.attention-rail),
    .cashier-page.has-selection .cashier-layout > :deep(.queue-pane) {
        display: none;
    }
    .cashier-page.has-selection .today-kpis {
        display: none;
    }
    .cashier-page.has-selection .cashier-layout {
        margin-top: 0.35rem;
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning {
        animation: none;
    }
}

/* Task-focused workspace layout. */
.cashier-page{gap:14px;min-width:0}.cashier-header{display:grid;grid-template-columns:1fr auto;gap:14px;padding:0 0 14px;border:0;border-bottom:1px solid var(--line);border-radius:0;background:transparent;align-items:center}.page-mark{display:none}.page-title h1{font-size:26px}.page-title small{font-size:14px;color:var(--muted)}.header-actions{grid-column:1/-1;display:flex;justify-content:flex-start;flex-wrap:wrap;gap:8px}.header-actions button,.header-actions a{min-height:42px;border-radius:8px}.shift-summary{position:relative;align-self:center}.shift-summary summary{display:flex;gap:10px;align-items:center;cursor:pointer;list-style:none;font-size:14px;padding:12px;background:var(--surface);border:1px solid var(--line);border-radius:8px}.shift-summary summary strong{font-size:18px}.today-kpis{position:absolute;inset-inline-end:0;top:calc(100% + 6px);z-index:55;min-width:min(360px,90vw);display:grid;grid-template-columns:1fr 1fr;padding:14px;background:var(--surface);border:1px solid var(--line);border-radius:8px;box-shadow:0 12px 32px #25343A18}.today-kpis>span{padding:8px}.cashier-layout{display:grid;grid-template-columns:minmax(270px,31%) minmax(0,1fr);align-items:start;gap:14px;min-height:550px}.cashier-layout>:deep(.queue-pane){position:sticky;top:16px;max-height:calc(100dvh - 140px)}.cashier-layout>:deep(.checkout-workspace){min-width:0}.handover-panel{border-radius:8px}@media(max-width:760px){.cashier-header{grid-template-columns:1fr}.shift-summary{justify-self:stretch}.shift-summary summary strong{margin-inline-start:auto}.cashier-layout{grid-template-columns:1fr;min-height:0}.cashier-layout>:deep(.queue-pane){position:static;max-height:none}.has-selection :deep(.queue-pane){display:none}.cashier-layout>:deep(.checkout-empty){display:none}.today-kpis{inset-inline:0;min-width:0}}
</style>
