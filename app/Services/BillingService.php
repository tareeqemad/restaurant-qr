<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\InvoicePaid;
use App\Events\TableStatusChanged;
use App\Helpers\Money;
use App\Helpers\SafeBroadcast;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DebtWriteoff;
use App\Models\Invoice;
use App\Models\InvoiceSplit;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Scopes\BranchScope;
use App\Models\Setting;
use App\Models\TableSession;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use App\Support\PaymentMethods;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(
        protected ?OrderService $orders = null,
        protected ?InventoryService $inventory = null,
    ) {
        $this->orders = $this->orders ?? app(OrderService::class);
        $this->inventory = $this->inventory ?? app(InventoryService::class);
    }

    /**
     * Last-line guarantee that any non-cancelled item attached to the
     * invoice has had its ingredients deducted. With the configurable
     * `inventory.deduction_stage` setting, items may legitimately reach
     * invoice time still un-deducted (e.g. stage = served but waiter never
     * pressed "served" before billing). `ensureDeducted` is idempotent —
     * items already deducted at an earlier hook are skipped, so this is
     * safe to run regardless of the configured stage.
     */
    protected function settleStockForOrders(iterable $orders): void
    {
        foreach ($orders as $order) {
            if ($order->status === OrderStatus::Cancelled->value) {
                continue;
            }
            foreach ($order->items as $item) {
                if ($item->status === OrderItemStatus::Cancelled->value) {
                    continue;
                }
                $this->inventory->ensureDeducted($item);
            }
        }
    }

    /**
     * Approve any pending orders before the invoice locks the session.
     *
     * The waiter-approval step is the single place where ingredients come
     * off the shelf. If the cashier issues an invoice on a session that
     * still has pending orders (customer ordered → never approved →
     * cashier billed straight to pay), those orders would close without
     * a stock deduction ever firing. Calling approve() here keeps the
     * existing flow (stock validation, FIFO batch trace, KDS broadcast,
     * activity log) and just removes the requirement that a waiter
     * physically taps the button first.
     */
    protected function approvePendingOrders($pendingOrders, int $userId): void
    {
        foreach ($pendingOrders as $pending) {
            $this->orders->approve($pending, $userId);
        }
    }

    public function issueInvoice(TableSession $session, int $userId): Invoice
    {
        return DB::transaction(function () use ($session, $userId) {
            // Resolve by exact id WITHOUT the viewer's BranchScope, then pin
            // every inner read/write to the SESSION's branch. An operator
            // switched into another branch used to miss the existing-invoice
            // check below (the scoped query found nothing) and issue a
            // duplicate invoice over the same orders.
            $session = TableSession::withoutGlobalScope(BranchScope::class)
                ->whereKey($session->id)
                ->with('table')
                ->lockForUpdate()
                ->firstOrFail();

            return BranchContext::forBranch($session->branch_id, function () use ($session, $userId) {
                $existingInvoice = $session->invoice()
                    ->where('status', '!=', 'cancelled')
                    ->latest()
                    ->first();

                if ($existingInvoice) {
                    return $existingInvoice;
                }

                $hasPendingChange = OrderChangeRequest::where('status', OrderChangeRequest::STATUS_PENDING)
                    ->whereHas('order', fn ($query) => $query->where('table_session_id', $session->id))
                    ->exists();

                if ($hasPendingChange) {
                    throw new \RuntimeException('يوجد طلب تعديل أو إلغاء بانتظار الجرسون. عالجه أولاً ثم أصدر الفاتورة بالمبلغ الصحيح.');
                }

                $pendingOrders = $session->orders()
                    ->where('status', OrderStatus::Pending->value)
                    ->get();
                $this->approvePendingOrders($pendingOrders, $userId);

                $orders = $session->orders()
                    ->where('status', '!=', OrderStatus::Cancelled->value)
                    ->with('items')
                    ->get();

                if ($orders->isEmpty()) {
                    throw new \RuntimeException('لا توجد طلبات نشطة للفوترة');
                }

                $this->settleStockForOrders($orders);

                $subtotal = (float) $orders->sum('subtotal');
                $discountTotal = (float) $orders->sum('discount_total');
                $taxTotal = (float) $orders->sum('tax_total');
                $serviceTotal = (float) $orders->sum('service_total');
                $deliveryFee = (float) $orders->sum('delivery_fee');
                $tip = (float) $orders->sum('tip');
                $total = (float) $orders->sum('total');

                $invoice = Invoice::create([
                    'branch_id' => $session->branch_id,
                    'table_session_id' => $session->id,
                    'customer_id' => $session->customer_id,
                    'issued_by_user_id' => $userId,
                    'subtotal' => Money::round($subtotal),
                    'discount_total' => Money::round($discountTotal),
                    'tax_total' => Money::round($taxTotal),
                    'service_total' => Money::round($serviceTotal),
                    'delivery_fee' => Money::round($deliveryFee),
                    'tip' => Money::round($tip),
                    'total' => Money::round($total),
                    'balance' => Money::round($total),
                    'status' => 'issued',
                    'customer_name' => $session->customer_name,
                    'customer_phone' => $session->customer_phone,
                    'issued_at' => now(),
                ]);

                $session->update([
                    'bill_requested_at' => null,
                    'bill_request_note' => null,
                ]);

                ActivityLog::log('invoice.issued', "إصدار فاتورة {$invoice->number} للطاولة {$session->table->number}", $invoice, [
                    'total' => (float) $invoice->total,
                    'orders_count' => $orders->count(),
                ]);

                app(AccountingService::class)->recordInvoiceIssued($invoice);

                // Zero-total invoice (fully comped / discounted) — nothing to
                // collect. Auto-mark paid + close the session so the table is
                // freed and the cashier doesn't get stuck on a $0 invoice that
                // the payment form refuses to accept (min amount 0.01).
                if (Money::round($total) <= 0.001) {
                    $invoice->update([
                        'status' => 'paid',
                        'paid_total' => 0,
                        'balance' => 0,
                        'paid_at' => now(),
                    ]);
                    $this->closeOrdersAndSession($invoice);
                    SafeBroadcast::dispatch(new InvoicePaid($invoice->refresh()->load('tableSession.table')));
                    ActivityLog::log('invoice.zero_auto_closed', "إغلاق تلقائي لفاتورة صفرية {$invoice->number}", $invoice);
                }

                return $invoice;
            });
        });
    }

    public function issueInvoiceForOrder(Order $order, int $userId): Invoice
    {
        return DB::transaction(function () use ($order, $userId) {
            // Same cross-branch pin as issueInvoice(): exact-id resolve
            // without the viewer scope, then run on the ORDER's branch so
            // the duplicate-invoice check can't no-op from another branch.
            $order = Order::withoutGlobalScope(BranchScope::class)
                ->whereKey($order->id)
                ->with(['customer', 'items'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->table_session_id) {
                // Resolve the session unscoped too — the scoped relation
                // returns null cross-branch and would nullpointer here.
                $session = $order->tableSession()
                    ->withoutGlobalScope(BranchScope::class)
                    ->firstOrFail();

                return $this->issueInvoice($session, $userId);
            }

            return BranchContext::forBranch($order->branch_id, function () use ($order, $userId) {
                $existingInvoice = $order->invoice()
                    ->where('status', '!=', 'cancelled')
                    ->latest()
                    ->first();

                if ($existingInvoice) {
                    return $existingInvoice;
                }

                if ($order->changeRequests()->where('status', OrderChangeRequest::STATUS_PENDING)->exists()) {
                    throw new \RuntimeException('يوجد طلب تعديل أو إلغاء بانتظار الجرسون. عالجه أولاً ثم أصدر الفاتورة بالمبلغ الصحيح.');
                }

                if ($order->status === OrderStatus::Cancelled->value) {
                    throw new \RuntimeException('لا يمكن إصدار فاتورة لطلب ملغي.');
                }

                if ($order->items->isEmpty()) {
                    throw new \RuntimeException('لا توجد أصناف داخل هذا الطلب.');
                }

                if ($order->status === OrderStatus::Pending->value) {
                    $this->approvePendingOrders(collect([$order]), $userId);
                    $order = $order->refresh()->load('items');
                }

                $this->settleStockForOrders([$order]);

                $invoice = Invoice::create([
                    'branch_id' => $order->branch_id,
                    'table_session_id' => null,
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'issued_by_user_id' => $userId,
                    'subtotal' => Money::round((float) $order->subtotal),
                    'discount_total' => Money::round((float) $order->discount_total),
                    'tax_total' => Money::round((float) $order->tax_total),
                    'service_total' => Money::round((float) $order->service_total),
                    'delivery_fee' => Money::round((float) $order->delivery_fee),
                    'tip' => Money::round((float) $order->tip),
                    'total' => Money::round((float) $order->total),
                    'balance' => Money::round((float) $order->total),
                    'status' => 'issued',
                    'customer_name' => $order->customer_name ?: $order->customer?->name,
                    'customer_phone' => $order->customer_phone ?: $order->customer?->phone,
                    'notes' => $order->sourceLabel().' / '.$order->order_type,
                    'issued_at' => now(),
                ]);

                ActivityLog::log('invoice.issued', "إصدار فاتورة {$invoice->number} للطلب {$order->number}", $invoice, [
                    'total' => (float) $invoice->total,
                    'order_id' => $order->id,
                    'source' => $order->order_source,
                ]);

                app(AccountingService::class)->recordInvoiceIssued($invoice);

                if (Money::round((float) $order->total) <= 0.001) {
                    $invoice->update([
                        'status' => 'paid',
                        'paid_total' => 0,
                        'balance' => 0,
                        'paid_at' => now(),
                    ]);
                    SafeBroadcast::dispatch(new InvoicePaid($invoice->refresh()->load('order')));
                }

                return $invoice;
            });
        });
    }

    public function addPayment(
        Invoice $invoice,
        float $amount,
        string $method,
        int $userId,
        ?string $reference = null,
        ?string $notes = null,
        ?float $tenderedAmount = null,
        bool $saveChangeAsAdvance = false,
    ): Payment {
        return DB::transaction(function () use ($invoice, $amount, $method, $userId, $reference, $notes, $tenderedAmount, $saveChangeAsAdvance) {
            // Exact-id resolve without the viewer scope, then pin to the
            // INVOICE's branch: the payments sum and order lookup below are
            // invoice-keyed and must see the invoice's own rows even when the
            // payer stands on another branch (FIFO debt collection routes
            // cross-branch invoices through this exact path).
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            return BranchContext::forBranch($invoice->branch_id, function () use ($invoice, $amount, $method, $userId, $reference, $notes, $tenderedAmount, $saveChangeAsAdvance) {
                if (in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
                    throw new \RuntimeException('لا يمكن تسجيل دفعة على فاتورة مغلقة أو ملغاة.');
                }

                $amount = Money::round($amount);
                $balanceBeforePayment = Money::round((float) $invoice->balance);
                if ($balanceBeforePayment <= 0.001) {
                    throw new \RuntimeException('لا يوجد مبلغ متبقٍ على هذه الفاتورة.');
                }

                if ($amount - $balanceBeforePayment > 0.01) {
                    throw new \RuntimeException('قيمة الدفعة أكبر من المتبقي. سجّل فقط المبلغ المستحق على الفاتورة.');
                }

                $changeToAdvance = 0.0;
                if ($saveChangeAsAdvance) {
                    if ($method !== 'cash') {
                        throw new \RuntimeException('حفظ الباقي كرصيد متاح فقط عند استلام دفعة نقدية.');
                    }
                    if (! $invoice->customer_id) {
                        throw new \RuntimeException('اربط زبوناً برقم جواله قبل حفظ الباقي كرصيد مقدم.');
                    }
                    if ($tenderedAmount === null || $tenderedAmount + 0.001 < $amount) {
                        throw new \RuntimeException('أدخل المبلغ النقدي المستلم كاملاً لحساب الرصيد المقدم.');
                    }
                    $changeToAdvance = Money::round($tenderedAmount - $amount);
                    if ($changeToAdvance <= 0) {
                        throw new \RuntimeException('لا يوجد مبلغ زائد لحفظه كرصيد مقدم.');
                    }
                }

                $payment = Payment::create([
                    // Stamp branch_id from the invoice itself, NOT from
                    // BranchContext. Payments arrive from contexts where the
                    // active branch may differ (queue jobs, customer portal
                    // paying for takeaway, owner-level user in "all branches"
                    // mode). The invoice always has the canonical branch.
                    'branch_id' => $invoice->branch_id,
                    'invoice_id' => $invoice->id,
                    'method' => $method,
                    'amount' => $amount,
                    'reference' => $reference,
                    'received_by_user_id' => $userId,
                    'notes' => $notes,
                    'paid_at' => now(),
                ]);

                $advanceService = app(CustomerAdvanceService::class);
                if ($method === PaymentMethods::CUSTOMER_ADVANCE) {
                    if (! $invoice->customer_id) {
                        throw new \RuntimeException('لا يمكن استخدام رصيد مقدم على فاتورة غير مرتبطة بزبون.');
                    }
                    $advanceService->redeemForPayment($invoice->customer()->firstOrFail(), $payment, $userId);
                }

                $paidTotal = (float) $invoice->payments()->sum('amount');

                // Balance is measured against NET payments (gross paid − refunded),
                // exactly like Invoice::recomputeBalanceAfterRefund. The old formula
                // used paid_total alone and ignored refunded_total, so after any
                // partial refund an invoice could close as "paid" while the net cash
                // collected was still below the total — a silent shortfall. The two
                // formulas must agree or the same column drifts between the payment
                // path and the refund path.
                $netPaid = $paidTotal - (float) $invoice->refunded_total;
                $balance = max(0, round($invoice->collectibleTotal() - $netPaid, 2));

                $status = match (true) {
                    $balance <= 0.001 && $netPaid > 0 => 'paid',
                    $netPaid > 0 => 'partially_paid',
                    default => 'issued',
                };

                $invoice->update([
                    'paid_total' => Money::round($paidTotal),
                    'balance' => Money::round($balance),
                    'status' => $status,
                    'paid_at' => $status === 'paid' ? now() : null,
                ]);

                if ($status === 'paid') {
                    app(LoyaltyService::class)->awardPaidInvoice($invoice->refresh(), $userId);

                    if ($invoice->table_session_id) {
                        // Payment settles the money, not the kitchen work.
                        // closeOrdersAndSession now closes only after every
                        // active line has actually been served.
                        $this->closeOrdersAndSession($invoice);
                    } elseif ($invoice->order_id) {
                        // Portal-flow invoice (takeaway / delivery) — settles a
                        // single order directly. Payment settles the MONEY, not
                        // the food: completing any paid order here yanked a
                        // prepaid-but-uncooked ticket off the KDS/waiter boards
                        // before the kitchen ever fired it. Only an order already
                        // handed over (delivered) closes with the payment;
                        // anything earlier keeps its kitchen lifecycle and
                        // auto-completes at serve time instead
                        // (OrderService::completeIfPrepaid). The invoice is
                        // marked paid either way.
                        $order = $invoice->order;
                        if ($order && $order->status === OrderStatus::Delivered->value) {
                            $order->update([
                                'status' => OrderStatus::Completed->value,
                                'completed_at' => now(),
                            ]);
                        }
                    }
                    SafeBroadcast::dispatch(new InvoicePaid($invoice->refresh()->load('tableSession.table', 'order')));
                }

                ActivityLog::log('payment.received', "دفعة {$payment->amount} على الفاتورة {$invoice->number}", $payment, [
                    'method' => $method,
                    'invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'amount' => (float) $payment->amount,
                    'reference' => $reference,
                ], causerId: $userId);

                app(AccountingService::class)->recordPaymentReceived($payment);

                if ($changeToAdvance > 0) {
                    $advanceService->deposit(
                        customer: $invoice->customer()->firstOrFail(),
                        amount: $changeToAdvance,
                        method: 'cash',
                        branchId: (int) $invoice->branch_id,
                        userId: $userId,
                        notes: 'باقي دفعة الفاتورة '.$invoice->number,
                        sourcePayment: $payment,
                    );
                }

                return $payment;
            });
        });
    }

    /**
     * Void a single mistaken payment (wrong method, fat-fingered amount,
     * entered twice) — the "undo the last few seconds" correction, distinct
     * from a refund (which returns real money and hits sales-returns).
     *
     * The payment's live GL entry is REVERSED (Dr A/R / Cr cash, netting the
     * original to zero — a full audit trail stays in journal_entries). The
     * payment document is retained with status=voided, while Payment's global
     * posted scope keeps every live collection sum and invoice relation clean.
     *
     * Refuses when the payment has a refund against it, or the invoice is
     * cancelled or parked as customer debt (those go through their own flows).
     */
    public function voidPayment(Payment $payment, int $userId, string $reason): void
    {
        DB::transaction(function () use ($payment, $userId, $reason) {
            // Exact-id resolves without the viewer scope + pin to the
            // invoice's branch: the refund-existence guard and the split
            // lookup below are invoice-keyed and silently no-op'd for an
            // operator standing on another branch.
            $payment = Payment::withoutGlobalScope(BranchScope::class)
                ->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            BranchContext::forBranch($invoice->branch_id, function () use ($payment, $invoice, $userId, $reason) {
                if ($invoice->status === 'cancelled') {
                    throw new \RuntimeException('لا يمكن إلغاء دفعة على فاتورة ملغاة.');
                }
                if ($invoice->settled_on_account_at) {
                    throw new \RuntimeException('الفاتورة مؤجّلة كدين — عالج المتبقي من سجل الديون بدل إلغاء الدفعة.');
                }
                // Pending allocations reserve the original payment just as firmly
                // as completed ones. Cancel/reverse the refund first.
                if (Refund::where('invoice_id', $invoice->id)->whereIn('status', ['pending', 'completed'])->exists()) {
                    throw new \RuntimeException('على هذه الفاتورة استرداد — لا يمكن إلغاء الدفعة. عالج الاسترداد أولاً.');
                }
                // Reverse the payment's live (un-reversed) GL posting, if any.
                $entries = JournalEntry::where('source_type', Payment::class)
                    ->where('source_id', $payment->id)
                    ->orderBy('id')
                    ->get();
                $reversedIds = $entries
                    ->map(fn ($e) => (int) ($e->metadata['reverses_entry_id'] ?? 0))
                    ->filter()
                    ->all();
                $live = $entries
                    ->firstWhere(fn ($e) => in_array($e->event_type, ['payment_received', 'customer_advance_redeemed'], true)
                        && ! in_array((int) $e->id, $reversedIds, true));

                app(CustomerAdvanceService::class)->reversePaymentEffects($payment, $userId, $reason);
                if ($live) {
                    app(AccountingService::class)->reverseEntry(
                        original: $live,
                        eventType: 'payment_voided_'.$payment->id,
                        postedOn: now(),
                        description: "عكس دفعة ملغاة على فاتورة {$invoice->number}",
                        createdBy: $userId,
                    );
                }

                // ── Split-tab bookkeeping: a payment created by paySplit()
                // settled exactly one split, and the only link between them is
                // the note stamp «دفعة جزء: {label}» (no FK column). Deleting the
                // payment void must un-mark that split, or it reads «مدفوع» forever
                // over money that no longer exists and the tab can never be
                // re-collected. Matched by label + amount on paid splits of THIS
                // invoice; first match wins (paySplit refuses to double-pay a
                // split, so at most one paid row fits).
                $unmarkedSplitId = null;
                if (is_string($payment->notes) && str_starts_with($payment->notes, 'دفعة جزء: ')) {
                    $splitLabel = trim(mb_substr($payment->notes, mb_strlen('دفعة جزء: ')));
                    $paidSplit = InvoiceSplit::withoutGlobalScope(BranchScope::class)
                        ->where('invoice_id', $invoice->id)
                        ->where('paid', true)
                        ->where('label', $splitLabel)
                        ->where('amount', $payment->amount)
                        ->orderBy('id')
                        ->first();
                    if ($paidSplit) {
                        $paidSplit->update(['paid' => false, 'paid_at' => null]);
                        $unmarkedSplitId = $paidSplit->id;
                    }
                }

                $amount = (float) $payment->amount;
                $method = $payment->method;
                ActivityLog::log(
                    'payment.voided',
                    'إلغاء دفعة '.number_format($amount, 2)." ({$method}) على فاتورة {$invoice->number} — {$reason}",
                    $invoice,
                    ['payment_id' => $payment->id, 'amount' => $amount, 'method' => $method, 'reason' => $reason, 'unmarked_split_id' => $unmarkedSplitId]
                );

                // Preserve the payment document for audit. Payment's global
                // `posted` scope makes every existing sum ignore this row.
                $payment->update([
                    'status' => 'voided',
                    'voided_at' => now(),
                    'voided_by' => $userId,
                    'void_reason' => $reason,
                ]);

                // Recompute from the REMAINING payments (net of refunds), mirroring
                // addPayment's status/balance logic so the invoice reopens correctly.
                $paidTotal = (float) $invoice->payments()->sum('amount');
                $netPaid = $paidTotal - (float) $invoice->refunded_total;
                $balance = max(0, round($invoice->collectibleTotal() - $netPaid, 2));
                $status = match (true) {
                    $balance <= 0.001 && $netPaid > 0 => 'paid',
                    $netPaid > 0 => 'partially_paid',
                    default => 'issued',
                };
                $invoice->update([
                    'paid_total' => Money::round($paidTotal),
                    'balance' => Money::round($balance),
                    'status' => $status,
                    'paid_at' => $status === 'paid' ? ($invoice->paid_at ?? now()) : null,
                ]);
                app(LoyaltyService::class)->reverseForPaymentVoid($invoice->fresh(), $amount, $userId);
            });
        });
    }

    /**
     * Park an issued or partially-paid invoice on the customer's debt ledger
     * and free the table. The invoice keeps its `balance > 0` and current
     * status (so payments still flow through `addPayment` unchanged); the
     * `settled_on_account_at` timestamp is what flags it as a real debt
     * vs. a still-active checkout in progress.
     *
     * Pre-conditions enforced inside the transaction:
     *   - Customer must be linked (refuse to create anonymous debts).
     *   - Customer's credit_limit, if set, must accommodate the new
     *     outstanding total. We compute the customer's total debt AFTER
     *     this settlement and compare to the cap.
     *
     * Activity log + manager notification fire AFTER commit so a rollback
     * never leaves orphan audit traces.
     */
    public function settleOnAccount(Invoice $invoice, int $userId, ?string $notes = null, ?string $dueDate = null): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice, $userId, $notes, $dueDate) {
            // Cross-branch pin (same pattern as addPayment): the payments sum
            // and session close below are invoice-keyed and must not run
            // under the viewer's branch.
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($invoice->id)
                ->with('customer')
                ->lockForUpdate()
                ->firstOrFail();

            return BranchContext::forBranch($invoice->branch_id, function () use ($invoice, $userId, $notes, $dueDate) {
                if (in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
                    throw new \RuntimeException('لا يمكن تأجيل فاتورة مغلقة أو ملغاة.');
                }

                // Idempotency: a settled invoice stays 'partially_paid' with the
                // flag set (it IS the debt record), so nothing above blocks a
                // re-POST. On a second call outstandingDebt() already counts THIS
                // invoice, so the credit-limit math double-counts its balance, and
                // the session-close / notes / notifications all fire again.
                if ($invoice->settled_on_account_at) {
                    throw new \RuntimeException('الفاتورة مؤجّلة كدين مسبقاً.');
                }

                if (! $invoice->customer_id) {
                    throw new \RuntimeException('لا يمكن تسجيل دين بدون زبون مرتبط بالفاتورة. اربط زبوناً للجلسة أولاً.');
                }

                $balance = Money::round((float) $invoice->balance);
                if ($balance <= 0.001) {
                    throw new \RuntimeException('لا يوجد رصيد متبقٍ ليُسجَّل كدين على هذه الفاتورة.');
                }

                $customer = $invoice->customer;
                // Debt is a CUSTOMER-level figure: the ledger spans branches and
                // credit_limit is global, so the ceiling check must see every
                // branch's parked invoices (customer_id is the exact key —
                // nothing foreign can leak in). Scoped, a debtor maxed out at
                // branch A kept borrowing at branch B. Excludes this invoice
                // (still no flag).
                $existingDebt = BranchContext::unscoped(fn () => $customer->outstandingDebt());
                $newTotal = $existingDebt + $balance;
                if ($customer->credit_limit !== null && $newTotal - (float) $customer->credit_limit > 0.01) {
                    throw new \RuntimeException(sprintf(
                        'تجاوز الحد الائتماني للزبون. الحد %s، الدين بعد هذه الفاتورة %s.',
                        number_format((float) $customer->credit_limit, 2),
                        number_format($newTotal, 2),
                    ));
                }

                $termsDays = max(0, min(3650, (int) Setting::get('customer_credit_terms_days', 30)));
                $resolvedDueDate = $dueDate
                    ? \Carbon\Carbon::parse($dueDate)->toDateString()
                    : now()->addDays($termsDays)->toDateString();
                if ($dueDate) {
                    $termsDays = max(0, now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($dueDate)->startOfDay(), false));
                }

                $invoice->update([
                    'settled_on_account_at' => now(),
                    'settled_on_account_by_user_id' => $userId,
                    'due_date' => $resolvedDueDate,
                    'payment_terms_days' => $termsDays,
                    'notes' => trim(($invoice->notes ?? '')
                              .($notes ? "\n[on-account] {$notes}" : "\n[on-account] ".'حُوِّل المتبقي إلى دين الزبون')),
                ]);

                if ($invoice->table_session_id) {
                    // force: the debtor is out the door — free the table even
                    // if the kitchen never marked every line served.
                    $this->closeOrdersAndSession($invoice, force: true);
                }

                ActivityLog::log(
                    'invoice.settled_on_account',
                    "تأجيل دين فاتورة {$invoice->number} على الزبون {$customer->name} بمبلغ ".number_format($balance, 2),
                    $invoice,
                    [
                        'customer_id' => $customer->id,
                        'balance_carried' => (float) $balance,
                        'new_total_debt' => $newTotal,
                        'credit_limit' => $customer->credit_limit,
                        'due_date' => $resolvedDueDate,
                    ],
                    causerId: $userId,
                );

                return $invoice->refresh();
            });
        });

        // Manager notification after commit — separate to keep
        // the transaction lean and to avoid orphan toasts if it rolls back.
        $customer = $invoice->customer()->first();
        if ($customer) {
            app(NotifyService::class)->customerDebtChanged($customer, $invoice);
        }

        return $invoice;
    }

    /**
     * Reverse a mistaken settle-on-account — the "un-park".
     *
     * FLAG-ONLY by design: parking posts NO journal entries (the issuance
     * entry already carries the A/R; `settled_on_account_at` merely marks
     * the invoice as a real debt vs. a checkout in progress), so clearing
     * the flag posts none either. The invoice keeps its balance/status and
     * simply drops off the customer's debt ledger, back to being a normal
     * open partially-paid invoice the cashier can collect.
     *
     * Allowed ONLY while the parked debt is untouched:
     *   - the invoice actually carries the flag,
     *   - it wasn't cancelled or written off meanwhile,
     *   - NO payment landed after parking (a collected debt is history —
     *     rewriting it would orphan the FIFO allocations already logged).
     *
     * Deliberately does NOT reopen the table session parking closed — the
     * table was freed and possibly reseated; the invoice remains payable
     * without a session.
     */
    public function unparkSettleOnAccount(Invoice $invoice, int $userId): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice, $userId) {
            // Same cross-branch pin as settleOnAccount.
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($invoice->id)
                ->with('customer')
                ->lockForUpdate()
                ->firstOrFail();

            return BranchContext::forBranch($invoice->branch_id, function () use ($invoice, $userId) {
                if (! $invoice->settled_on_account_at) {
                    throw new \RuntimeException('الفاتورة ليست مؤجّلة كدين — لا يوجد تأجيل لإلغائه.');
                }

                if (in_array($invoice->status, ['cancelled', 'unpaid_writeoff'], true)) {
                    throw new \RuntimeException('الفاتورة ملغاة أو مشطوبة — لا يمكن إلغاء تأجيل الدين عليها.');
                }

                // Any payment AFTER the parking moment means the debt entered
                // collection (FIFO allocations reference it) — unscoped by
                // exact invoice_id since debt payments may come from any branch.
                $collectedAfterParking = Payment::withoutGlobalScope(BranchScope::class)
                    ->where('invoice_id', $invoice->id)
                    ->where('paid_at', '>', $invoice->settled_on_account_at)
                    ->exists();
                if ($collectedAfterParking) {
                    throw new \RuntimeException('سُدِّدت دفعات على هذا الدين بعد تأجيله — لا يمكن إلغاء التأجيل. عالجه من سجل ديون الزبون.');
                }

                $parkedAt = $invoice->settled_on_account_at;
                $invoice->update([
                    'settled_on_account_at' => null,
                    'settled_on_account_by_user_id' => null,
                    'due_date' => null,
                    'payment_terms_days' => null,
                    'notes' => trim(($invoice->notes ?? '')."\n[unpark] أُلغي تأجيل الدين وعادت الفاتورة للتحصيل المباشر"),
                ]);

                ActivityLog::log(
                    'invoice.unparked_on_account',
                    "إلغاء تأجيل دين فاتورة {$invoice->number} — عادت للتحصيل المباشر بمبلغ ".number_format((float) $invoice->balance, 2),
                    $invoice,
                    [
                        'customer_id' => $invoice->customer_id,
                        'balance_restored' => (float) $invoice->balance,
                        'parked_at' => (string) $parkedAt,
                        'by_user_id' => $userId,
                    ],
                    causerId: $userId,
                );

                return $invoice->refresh();
            });
        });

        // Mirror settleOnAccount: the customer's outstanding debt just
        // changed, tell the managers AFTER commit.
        $customer = $invoice->customer()->first();
        if ($customer) {
            app(NotifyService::class)->customerDebtChanged($customer, $invoice);
        }

        return $invoice;
    }

    /**
     * Apply a single payment intelligently across a customer's open debts
     * + (optionally) the current visit's invoice. Oldest debts go first —
     * standard FIFO — so a debt invoice from January closes before one from
     * April. Anything left over is applied to `$primaryInvoice` (the one
     * the cashier is checking out right now). Stops cleanly when the
     * amount is exhausted; never tries to "overpay" any single invoice.
     *
     * Returns an itemised allocation array `{ invoice_id, amount }` so the
     * receipt / activity log can show the diner exactly how their money
     * was split.
     *
     * Pre-condition: `$primaryInvoice` (if given) must already exist and
     * belong to `$customer` — caller's job to validate, this method does
     * not invent invoices.
     */
    public function payCustomerDebt(
        Customer $customer,
        float $amount,
        string $method,
        int $userId,
        ?Invoice $primaryInvoice = null,
        ?string $reference = null,
        ?string $notes = null,
    ): array {
        $amount = Money::round($amount);
        if ($amount <= 0.001) {
            throw new \RuntimeException('قيمة الدفعة يجب أن تكون أكبر من صفر.');
        }

        // The whole allocation is ONE atomic unit. Previously the loop ran
        // without an enclosing transaction — each addPayment committed on its
        // own, then the "amount exceeds total owed" check threw AFTER money was
        // already posted, leaving committed allocations behind an error screen
        // (real ledger + drawer moved while the cashier was told it failed).
        $allocations = DB::transaction(function () use ($customer, $amount, $method, $userId, $primaryInvoice, $reference, $notes) {
            // Drain the oldest open debts first, then the primary (current
            // visit) invoice. Lock each invoice row so concurrent cashier
            // sessions can't both apply the same payment dollar (the lock now
            // holds until this outer transaction commits).
            //
            // Unscoped by design: the debt ledger is CUSTOMER-keyed and spans
            // branches (matching the credit-limit check in settleOnAccount).
            // Under the viewer's BranchScope, a debt parked at branch A was
            // invisible at branch B — FIFO skipped it and the customer's
            // oldest debt never got collected.
            $debtInvoices = $customer->invoices()
                ->withoutGlobalScope(BranchScope::class)
                ->whereNotNull('settled_on_account_at')
                ->where('balance', '>', 0)
                ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
                ->orderBy('settled_on_account_at')
                ->pluck('id');

            $queue = $debtInvoices->toArray();
            if ($primaryInvoice) {
                $queue[] = $primaryInvoice->id;
            }
            // De-dupe in case the primary is also a debt (cashier reopened it
            // to add more money).
            $queue = array_values(array_unique($queue));

            // Pre-flight: reject up-front if the payment exceeds the total
            // collectable balance, so the common overpay case never partially
            // allocates (and never fires a stray "paid" broadcast) before
            // aborting. The in-loop remainder check below is the authoritative
            // guard against a concurrent balance change — if it throws, the
            // whole transaction rolls back and nothing is posted.
            $collectable = 0.0;
            foreach ($queue as $invoiceId) {
                // Exact ids from the unscoped queue above — resolve them
                // unscoped too or the cross-branch ones silently drop here.
                $inv = Invoice::withoutGlobalScope(BranchScope::class)->find($invoiceId);
                if (! $inv || in_array($inv->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
                    continue;
                }
                $collectable += max(0, (float) $inv->balance);
            }
            $collectable = Money::round($collectable);
            if ($amount - $collectable > 0.01) {
                throw new \RuntimeException(sprintf(
                    'المبلغ المدفوع (%s) أكبر من إجمالي المستحق (%s). خفّض المبلغ.',
                    number_format($amount, 2),
                    number_format($collectable, 2),
                ));
            }

            $allocations = [];
            $remaining = $amount;
            foreach ($queue as $invoiceId) {
                if ($remaining <= 0.001) {
                    break;
                }

                $inv = Invoice::withoutGlobalScope(BranchScope::class)
                    ->whereKey($invoiceId)->lockForUpdate()->first();
                if (! $inv) {
                    continue;
                }
                if (in_array($inv->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
                    continue;
                }

                $invBalance = Money::round((float) $inv->balance);
                if ($invBalance <= 0.001) {
                    continue;
                }

                $apply = min($remaining, $invBalance);
                $this->addPayment($inv, $apply, $method, $userId, $reference, $notes);
                $allocations[] = ['invoice_id' => $inv->id, 'invoice_number' => $inv->number, 'amount' => $apply];
                $remaining = Money::round($remaining - $apply);
            }

            if ($remaining > 0.001) {
                // Rolls back every allocation above — nothing is half-committed.
                throw new \RuntimeException(sprintf(
                    'المبلغ المدفوع أكبر من إجمالي المستحق. المتبقّي بدون توزيع: %s. خفّض المبلغ.',
                    number_format($remaining, 2),
                ));
            }

            return $allocations;
        });

        // Side effects run only after the transaction commits successfully.
        ActivityLog::log(
            'customer.debt_payment',
            "دفعة من الزبون {$customer->name} بقيمة ".number_format($amount, 2).' موزّعة على '.count($allocations).' فاتورة',
            $customer,
            [
                'method' => $method,
                'reference' => $reference,
                'allocations' => $allocations,
            ],
            causerId: $userId,
        );

        app(NotifyService::class)->customerDebtChanged($customer->refresh());

        return $allocations;
    }

    public function writeOffInvoice(Invoice $invoice, int $userId, string $reason, ?float $amount = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $userId, $reason, $amount) {
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $writeoffAmount = Money::round($amount ?? (float) $invoice->balance);

            if (in_array($invoice->status, ['paid', 'cancelled'], true) || (float) $invoice->balance <= 0.001) {
                throw new \RuntimeException('لا يمكن شطب فاتورة مغلقة أو ملغاة.');
            }
            if ($writeoffAmount <= 0.001 || $writeoffAmount - (float) $invoice->balance > 0.01) {
                throw new \RuntimeException('لا يوجد رصيد متبقٍ لشطبه على هذه الفاتورة.');
            }

            $writeoff = DebtWriteoff::create([
                'branch_id' => $invoice->branch_id,
                'number' => DebtWriteoff::generateNumber(),
                'invoice_id' => $invoice->id,
                'amount' => $writeoffAmount,
                'status' => 'posted',
                'reason' => trim($reason),
                'written_off_by' => $userId,
                'written_off_at' => now(),
            ]);
            $invoice->update([
                'written_off_total' => Money::round((float) $invoice->written_off_total + $writeoffAmount),
                'notes' => trim(($invoice->notes ?? '')."\n[writeoff {$writeoff->number}] ".$reason),
            ]);
            $invoice->fresh()->recomputeBalanceAfterRefund();

            if ($invoice->fresh()->balance <= 0.001 && $invoice->table_session_id) {
                $this->closeOrdersAndSession($invoice);
            }

            ActivityLog::log(
                'invoice.writeoff',
                "شطب دين الفاتورة {$invoice->number}: {$reason}",
                $invoice,
                [
                    'customer_id' => $invoice->customer_id,
                    'amount' => $writeoffAmount,
                    'writeoff_id' => $writeoff->id,
                    'writeoff_number' => $writeoff->number,
                    'reason' => $reason,
                ],
                causerId: $userId,
            );
            app(AccountingService::class)->recordDebtWriteoff($writeoff->fresh('invoice'));

            return $invoice->refresh();
        });
    }

    public function reverseWriteoff(DebtWriteoff $writeoff, int $userId, string $reason): Invoice
    {
        return DB::transaction(function () use ($writeoff, $userId, $reason) {
            $writeoff = DebtWriteoff::withoutGlobalScope(BranchScope::class)
                ->whereKey($writeoff->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($writeoff->invoice_id)->lockForUpdate()->firstOrFail();
            if ($writeoff->status !== 'posted') {
                throw new \RuntimeException('تم عكس عملية الشطب مسبقاً.');
            }

            app(AccountingService::class)->reverseDebtWriteoff($writeoff, $userId, $reason);
            $writeoff->update([
                'status' => 'reversed', 'reversed_by' => $userId,
                'reversed_at' => now(), 'reversal_reason' => trim($reason),
            ]);
            $invoice->update([
                'written_off_total' => max(0, Money::round((float) $invoice->written_off_total - (float) $writeoff->amount)),
            ]);
            $invoice->fresh()->recomputeBalanceAfterRefund();
            ActivityLog::log(
                'invoice.writeoff_reversed',
                "عكس شطب {$writeoff->number} على {$invoice->number}: {$reason}",
                $invoice,
                ['writeoff_id' => $writeoff->id, 'amount' => (float) $writeoff->amount],
                causerId: $userId,
            );

            return $invoice->fresh();
        });
    }

    public function cancelInvoice(Invoice $invoice, int $userId, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $userId, $reason) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->payments()->exists()) {
                throw new \RuntimeException('Cannot cancel an invoice that already has payments. Create a refund first.');
            }

            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'notes' => trim(($invoice->notes ?? '')."\n[cancel] ".$reason),
            ]);

            ActivityLog::log('invoice.cancelled', "Cancel invoice {$invoice->number}: {$reason}", $invoice);
            $invoice->tableSession?->update([
                'bill_requested_at' => null,
                'bill_request_note' => null,
            ]);

            app(AccountingService::class)->reverseInvoiceIssued($invoice, $userId, $reason);

            return $invoice->refresh();
        });
    }

    public function splitInvoice(Invoice $invoice, array $splits): Invoice
    {
        return DB::transaction(function () use ($invoice, $splits) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
                throw new \RuntimeException('لا يمكن تقسيم فاتورة مغلقة أو ملغاة.');
            }

            if ($invoice->payments()->exists()) {
                throw new \RuntimeException('لا يمكن تقسيم فاتورة فيها دفعات مسجلة');
            }

            if (Money::round((float) $invoice->balance) <= 0.001) {
                throw new \RuntimeException('لا يوجد رصيد متبقٍ لتقسيمه على هذه الفاتورة.');
            }

            $invoice->splits()->delete();

            $total = (float) $invoice->total;
            $sumOfShares = array_sum(array_map(fn ($s) => (float) ($s['amount'] ?? 0), $splits));

            // Require the shares to sum EXACTLY to the total (the equal-split UI
            // already assigns the rounding remainder to the last share). The old
            // 0.01 tolerance let 33.33×3 = 99.99 through, leaving a 0.01 residue
            // that never auto-closed the table and quietly piled up on A/R (1100).
            if (abs($sumOfShares - $total) > 0.001) {
                throw new \RuntimeException(sprintf('مجموع الأجزاء (%.2f) لا يساوي إجمالي الفاتورة (%.2f)', $sumOfShares, $total));
            }

            foreach ($splits as $i => $s) {
                $invoice->splits()->create([
                    // Carry the branch from the invoice — same reasoning
                    // as Payment above. `splits()` doesn't auto-fill it
                    // because InvoiceSplit::branch_id isn't on the invoice
                    // FK relation.
                    'branch_id' => $invoice->branch_id,
                    'label' => $s['label'] ?? ('الشخص '.($i + 1)),
                    'amount' => Money::round((float) $s['amount']),
                    'method' => $s['method'] ?? 'cash',
                ]);
            }

            ActivityLog::log('invoice.split', "تقسيم فاتورة {$invoice->number} على ".count($splits).' أجزاء', $invoice);

            return $invoice->refresh();
        });
    }

    public function paySplit(InvoiceSplit $split, int $userId, ?string $reference = null): Payment
    {
        return DB::transaction(function () use ($split, $userId, $reference) {
            $splitId = $split->id;
            $invoiceId = InvoiceSplit::whereKey($splitId)->value('invoice_id');

            $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $split = InvoiceSplit::whereKey($splitId)->lockForUpdate()->firstOrFail();
            $split->setRelation('invoice', $invoice);

            if ($split->paid) {
                throw new \RuntimeException('الجزء مدفوع مسبقاً');
            }

            if (in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
                throw new \RuntimeException('لا يمكن دفع جزء من فاتورة مغلقة أو ملغاة.');
            }

            $payment = $this->addPayment($invoice, (float) $split->amount, $split->method, $userId, $reference, "دفعة جزء: {$split->label}");
            $split->update(['paid' => true, 'paid_at' => now()]);

            return $payment;
        });
    }

    /**
     * Close a session that has nothing billable — either all orders were
     * cancelled, or an invoice with total = 0 was issued (fully comped /
     * discounted). The normal payment flow can't handle this because
     * `addPayment()` requires a non-zero amount and `issueInvoice()` rejects
     * a session with no active orders.
     *
     * This method skips the invoice/payment ceremony entirely: marks any
     * stragglers as completed, closes the session, frees the table, and
     * broadcasts the table-status change so the admin boards refresh.
     *
     * Safety: refuses to close if there are ORDERS that aren't cancelled
     * AND whose items carry a positive total — that would be silently
     * erasing real revenue. Only truly zero-billable sessions may be closed
     * this way.
     */
    public function closeSessionWithoutBilling(TableSession $session, int $userId, string $reason = ''): TableSession
    {
        return DB::transaction(function () use ($session, $userId, $reason) {
            $session->loadMissing('orders', 'table');

            // Guard: disallow if there's real money on the table.
            $billableTotal = (float) $session->orders
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->sum('total');
            if ($billableTotal > 0.001) {
                throw new \RuntimeException('الجلسة فيها طلبات مبلغها أكبر من صفر. استخدم الفوترة العادية.');
            }

            foreach ($session->orders as $order) {
                if (! in_array($order->status, [OrderStatus::Cancelled->value, OrderStatus::Completed->value])) {
                    $order->update(['status' => OrderStatus::Completed->value, 'completed_at' => now()]);
                }
            }

            $this->logUnansweredHelp($session);

            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'bill_requested_at' => null,
                'bill_request_note' => null,
                // The help twin must be cleared with the bill pair. The board
                // reads a raised hand off the ACTIVE session, so leaving it
                // stamped both mutes it silently and poisons any "who answered"
                // reporting with a request nobody ever acked.
                'help_requested_at' => null,
                'help_request_note' => null,
            ]);

            if ($session->table) {
                // The party left: this table needs wiping regardless of what
                // its status is allowed to become. Bussing is orthogonal to the
                // status guard below — a table flipped to reserved mid-service
                // still ends up with dirty plates on it.
                $previousStatus = $session->table->status;
                $freeing = $previousStatus === 'occupied';

                $session->table->update([
                    'needs_cleaning_since' => now(),
                ] + ($freeing ? ['status' => 'available'] : []));
                // Only free tables the session itself occupied — a table the
                // manager flipped to reserved/out_of_service mid-session must
                // keep that status (the cron sweep runs this silently).

                SafeBroadcast::dispatch(new TableStatusChanged($session->table->refresh(), $previousStatus));
            }

            $tableNumber = $session->table?->number ?? '—';
            ActivityLog::log(
                'session.closed_without_billing',
                "إغلاق جلسة الطاولة {$tableNumber} بدون فوترة".($reason ? ": {$reason}" : ''),
                $session,
                ['reason' => $reason, 'by_user_id' => $userId]
            );

            return $session->refresh();
        });
    }

    /**
     * TRUE when force-closing this session cannot lose money — the shared
     * "zero-exposure" contract between the manual «إغلاق الجلسة» button
     * (TableController@closeSession), the tables-board render condition,
     * and the `table-sessions:close-idle` cron sweep:
     *
     *   (a) no orders at all, or
     *   (b) nothing billable remains — every order cancelled or zero-total
     *       (the exact guard closeSessionWithoutBilling() enforces), or
     *   (c) the latest non-cancelled invoice is fully collected
     *       (balance <= 0; written-off counts — the manager decided).
     *
     * Sessions with unpaid/unbilled money must go through the cashier.
     */
    public function isZeroExposure(TableSession $session): bool
    {
        $billableTotal = (float) $session->orders()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('total');
        if ($billableTotal <= 0.001) {
            return true;
        }

        $invoice = $session->invoice()
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->first();

        if ($invoice === null || (float) $invoice->balance > 0.001) {
            return false;
        }

        // A paid invoice only covers the orders that existed when it was
        // issued. Orders placed AFTER it carry uncollected money — closing
        // now would silently complete them unbilled.
        return ! $session->orders()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('total', '>', 0)
            ->where('created_at', '>', $invoice->created_at)
            ->exists();
    }

    /**
     * Force-close an idle session under the zero-exposure contract above,
     * dispatching to the SAME code path a normal close would take so the
     * table is freed and TableStatusChanged broadcasts identically:
     *
     *   - fully-paid invoice  → closeOrdersAndSession() (the settle path)
     *   - nothing billable    → closeSessionWithoutBilling()
     *
     * Throws if real money is still on the table (guard inside
     * closeSessionWithoutBilling). Idempotent: an already-closed session
     * is returned untouched — safe for the cron sweep to re-hit.
     */
    public function closeZeroExposureSession(TableSession $session, ?int $userId = null, string $reason = ''): TableSession
    {
        return DB::transaction(function () use ($session, $userId, $reason) {
            $session = TableSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== 'active') {
                return $session;
            }

            $invoice = $session->invoice()
                ->where('status', '!=', 'cancelled')
                ->latest()
                ->first();

            $hasPostInvoiceOrders = $invoice && $session->orders()
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->where('total', '>', 0)
                ->where('created_at', '>', $invoice->created_at)
                ->exists();

            if ($invoice && (float) $invoice->balance <= 0.001 && ! $hasPostInvoiceOrders) {
                // Paid (or written-off) invoice whose session never closed —
                // finish the job the settle path missed. force: this sweep
                // exists to kill zombie sessions; the party is long gone and
                // the invoice is settled, so an unserved line on the books
                // must not keep the table occupied forever (the fulfillment
                // gate is for LIVE prepaid tickets, not idle leftovers).
                if ($this->closeOrdersAndSession($invoice, force: true)) {
                    ActivityLog::log(
                        'session.closed_zero_exposure',
                        "إغلاق جلسة الطاولة {$session->tableLabel()} — الفاتورة {$invoice->number} مسدّدة بالكامل".($reason ? ": {$reason}" : ''),
                        $session,
                        ['reason' => $reason, 'invoice_id' => $invoice->id, 'by_user_id' => $userId]
                    );
                }

                return $session->refresh();
            }

            return $this->closeSessionWithoutBilling($session, (int) $userId, $reason);
        });
    }

    /**
     * Close a financially-settled table only after production is fulfilled.
     * Returns false for prepaid tables that still have unserved lines.
     */
    public function closeSettledSessionIfFulfilled(Invoice $invoice): bool
    {
        return $this->closeOrdersAndSession($invoice);
    }

    /**
     * `$force` skips the fulfillment gate. The gate exists for PREPAID
     * flows — money lands before the kitchen finishes, and closing would
     * yank a live ticket off the KDS. Settle-on-account is the opposite
     * situation: the guest has already LEFT with their debt parked, so the
     * table must be freed no matter what the item statuses say (leaving it
     * "occupied" by a departed party blocks the next seating forever).
     */
    protected function closeOrdersAndSession(Invoice $invoice, bool $force = false): bool
    {
        $session = $invoice->tableSession()->with('orders.items', 'table')->first();
        if (! $session) {
            return false;
        }

        $unfinished = $session->orders
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->contains(function (Order $order) {
                $activeItems = $order->items->where('status', '!=', OrderItemStatus::Cancelled->value);

                return $activeItems->isNotEmpty()
                    && ! $activeItems->every(fn ($item) => $item->status === OrderItemStatus::Served->value);
            });

        if ($unfinished && ! $force) {
            return false;
        }

        foreach ($session->orders as $order) {
            if (! in_array($order->status, [OrderStatus::Cancelled->value, OrderStatus::Completed->value])) {
                $order->update(['status' => OrderStatus::Completed->value, 'completed_at' => now()]);
            }
        }
        $this->logUnansweredHelp($session);

        $session->update([
            'status' => 'closed',
            'closed_at' => now(),
            'bill_requested_at' => null,
            'bill_request_note' => null,
            'help_requested_at' => null,     // see closeSessionWithoutBilling
            'help_request_note' => null,
        ]);
        if ($session->table) {
            // Same guard as closeSessionWithoutBilling: don't yank a table
            // out of reserved/out_of_service just because its old session
            // finished (the idle-close cron reaches this path too) — but the
            // bussing debt is stamped either way.
            $previousStatus = $session->table->status;
            $freeing = $previousStatus === 'occupied';

            $session->table->update([
                'needs_cleaning_since' => now(),
            ] + ($freeing ? ['status' => 'available'] : []));

            SafeBroadcast::dispatch(new TableStatusChanged($session->table->refresh(), $previousStatus));
        }

        return true;
    }

    /**
     * A guest had their hand up when the session closed and nobody ever went.
     *
     * Closing has to mute the request — the board reads it off the active
     * session — but silently erasing it would destroy the one signal worth
     * having: that the floor missed someone. Record it before it goes.
     */
    protected function logUnansweredHelp(TableSession $session): void
    {
        if (! $session->help_requested_at || $session->help_ack_by_user_id) {
            return;
        }

        ActivityLog::log(
            'session.help_unanswered',
            'أُغلقت جلسة طاولة '.($session->table?->number ?? '—').' وطلب المساعدة ما حدا ردّ عليه',
            $session,
            [
                'requested_at' => $session->help_requested_at->toIso8601String(),
                'waited_minutes' => (int) abs($session->help_requested_at->diffInMinutes(now())),
                'note' => $session->help_request_note,
            ]
        );
    }
}
