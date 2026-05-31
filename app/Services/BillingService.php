<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\InvoicePaid;
use App\Events\TableStatusChanged;
use App\Helpers\Money;
use App\Helpers\SafeBroadcast;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceSplit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Services\Accounting\AccountingService;
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
            if ($order->status === OrderStatus::Cancelled->value) continue;
            foreach ($order->items as $item) {
                if ($item->status === OrderItemStatus::Cancelled->value) continue;
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
            $session = TableSession::whereKey($session->id)
                ->with('table')
                ->lockForUpdate()
                ->firstOrFail();

            $existingInvoice = $session->invoice()
                ->where('status', '!=', 'cancelled')
                ->latest()
                ->first();

            if ($existingInvoice) {
                return $existingInvoice;
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
    }

    public function issueInvoiceForOrder(Order $order, int $userId): Invoice
    {
        return DB::transaction(function () use ($order, $userId) {
            $order = Order::whereKey($order->id)
                ->with(['customer', 'items'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->table_session_id) {
                return $this->issueInvoice($order->tableSession, $userId);
            }

            $existingInvoice = $order->invoice()
                ->where('status', '!=', 'cancelled')
                ->latest()
                ->first();

            if ($existingInvoice) {
                return $existingInvoice;
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
                'notes' => trim(implode("\n", array_filter([
                    $order->sourceLabel().' / '.$order->order_type,
                    $order->external_reference ? 'مرجع خارجي: '.$order->external_reference : null,
                ]))),
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
    }

    public function addPayment(Invoice $invoice, float $amount, string $method, int $userId, ?string $reference = null, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $userId, $reference, $notes) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

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

            $shift = \App\Models\Shift::where('user_id', $userId)->where('status', 'open')->latest('opened_at')->first();

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
                'shift_id' => $shift?->id,
                'notes' => $notes,
                'paid_at' => now(),
            ]);

            $paidTotal = (float) $invoice->payments()->sum('amount');
            $balance = max(0, (float) $invoice->total - $paidTotal);

            $status = match (true) {
                $balance <= 0 => 'paid',
                $paidTotal > 0 => 'partially_paid',
                default => 'issued',
            };

            $invoice->update([
                'paid_total' => Money::round($paidTotal),
                'balance' => Money::round($balance),
                'status' => $status,
                'paid_at' => $status === 'paid' ? now() : null,
            ]);

            if ($status === 'paid') {
                if ($invoice->table_session_id) {
                    $this->closeOrdersAndSession($invoice);
                } elseif ($invoice->order_id) {
                    // Portal-flow invoice (takeaway / delivery) — settles a
                    // single order directly. Mark it completed so the diner
                    // sees "مكتمل" in their history instead of "تم التسليم"
                    // hanging forever after they've already paid.
                    $order = $invoice->order;
                    if ($order && ! in_array($order->status, [OrderStatus::Cancelled->value, OrderStatus::Completed->value])) {
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
            ]);

            app(AccountingService::class)->recordPaymentReceived($payment);

            return $payment;
        });
    }

    /**
     * Park a partially-paid invoice on the customer's debt ledger and free
     * the table. The invoice keeps its `balance > 0` and `partially_paid`
     * status (so payments still flow through `addPayment` unchanged); the
     * `settled_on_account_at` timestamp is what flags it as a real debt
     * vs. a still-active checkout in progress.
     *
     * Pre-conditions enforced inside the transaction:
     *   - Customer must be linked (refuse to create anonymous debts).
     *   - At least one payment must already exist (avoid letting a full
     *     ticket get parked on credit with zero cash collected — that's
     *     the writeOff path, not this one).
     *   - Customer's credit_limit, if set, must accommodate the new
     *     outstanding total. We compute the customer's total debt AFTER
     *     this settlement and compare to the cap.
     *
     * Activity log + manager notification fire AFTER commit so a rollback
     * never leaves orphan audit traces.
     */
    public function settleOnAccount(Invoice $invoice, int $userId, ?string $notes = null): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice, $userId, $notes) {
            $invoice = Invoice::whereKey($invoice->id)
                ->with('customer')
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
                throw new \RuntimeException('لا يمكن تأجيل فاتورة مغلقة أو ملغاة.');
            }

            if (! $invoice->customer_id) {
                throw new \RuntimeException('لا يمكن تسجيل دين بدون زبون مرتبط بالفاتورة. اربط زبوناً للجلسة أولاً.');
            }

            $balance = Money::round((float) $invoice->balance);
            if ($balance <= 0.001) {
                throw new \RuntimeException('لا يوجد رصيد متبقٍ ليُسجَّل كدين على هذه الفاتورة.');
            }

            $paidTotal = (float) $invoice->payments()->sum('amount');
            if ($paidTotal <= 0.001) {
                throw new \RuntimeException('لا يمكن تأجيل الفاتورة كاملة كدين — سجّل أولاً ولو دفعة جزئية، أو استخدم شطب الفاتورة (write-off).');
            }

            $customer = $invoice->customer;
            $existingDebt = $customer->outstandingDebt();      // excludes this invoice (still no flag)
            $newTotal = $existingDebt + $balance;
            if ($customer->credit_limit !== null && $newTotal - (float) $customer->credit_limit > 0.01) {
                throw new \RuntimeException(sprintf(
                    'تجاوز الحد الائتماني للزبون. الحد %s، الدين بعد هذه الفاتورة %s.',
                    number_format((float) $customer->credit_limit, 2),
                    number_format($newTotal, 2),
                ));
            }

            $invoice->update([
                'settled_on_account_at'         => now(),
                'settled_on_account_by_user_id' => $userId,
                'notes' => trim(($invoice->notes ?? '')
                          . ($notes ? "\n[on-account] {$notes}" : "\n[on-account] " . 'حُوِّل المتبقي إلى دين الزبون')),
            ]);

            if ($invoice->table_session_id) {
                $this->closeOrdersAndSession($invoice);
            }

            ActivityLog::log(
                'invoice.settled_on_account',
                "تأجيل دين فاتورة {$invoice->number} على الزبون {$customer->name} بمبلغ ".number_format($balance, 2),
                $invoice,
                [
                    'customer_id'     => $customer->id,
                    'balance_carried' => (float) $balance,
                    'new_total_debt'  => $newTotal,
                    'credit_limit'    => $customer->credit_limit,
                ],
            );

            return $invoice->refresh();
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
        \App\Models\Customer $customer,
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

        $allocations = [];

        // Drain the oldest open debts first, then the primary (current
        // visit) invoice. Lock each invoice row so concurrent cashier
        // sessions can't both apply the same payment dollar.
        $debtInvoices = $customer->invoices()
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

        $remaining = $amount;
        foreach ($queue as $invoiceId) {
            if ($remaining <= 0.001) break;

            $inv = Invoice::whereKey($invoiceId)->lockForUpdate()->first();
            if (! $inv) continue;
            if (in_array($inv->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) continue;

            $invBalance = Money::round((float) $inv->balance);
            if ($invBalance <= 0.001) continue;

            $apply = min($remaining, $invBalance);
            $this->addPayment($inv, $apply, $method, $userId, $reference, $notes);
            $allocations[] = ['invoice_id' => $inv->id, 'invoice_number' => $inv->number, 'amount' => $apply];
            $remaining = Money::round($remaining - $apply);
        }

        if ($remaining > 0.001) {
            throw new \RuntimeException(sprintf(
                'المبلغ المدفوع أكبر من إجمالي المستحق. المتبقّي بدون توزيع: %s. خفّض المبلغ.',
                number_format($remaining, 2),
            ));
        }

        ActivityLog::log(
            'customer.debt_payment',
            "دفعة من الزبون {$customer->name} بقيمة ".number_format($amount, 2)." موزّعة على ".count($allocations)." فاتورة",
            $customer,
            ['method' => $method, 'allocations' => $allocations],
        );

        app(NotifyService::class)->customerDebtChanged($customer->refresh());

        return $allocations;
    }

    public function writeOffInvoice(Invoice $invoice, int $userId, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $userId, $reason) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $writeoffAmount = Money::round((float) $invoice->balance);

            if (in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
                throw new \RuntimeException('لا يمكن شطب فاتورة مغلقة أو ملغاة.');
            }

            if ($writeoffAmount <= 0.001) {
                throw new \RuntimeException('لا يوجد رصيد متبقٍ لشطبه على هذه الفاتورة.');
            }

            $invoice->update([
                'status' => 'unpaid_writeoff',
                'balance' => 0,
                'notes' => trim(($invoice->notes ?? '')."\n[writeoff] ".$reason),
            ]);

            if ($invoice->table_session_id) {
                $this->closeOrdersAndSession($invoice);
            }

            ActivityLog::log('invoice.writeoff', "Write off invoice {$invoice->number}: {$reason}", $invoice);
            app(AccountingService::class)->recordInvoiceWriteoff($invoice, $writeoffAmount, $userId, $reason);

            return $invoice->refresh();
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
            $sumOfShares = array_sum(array_map(fn($s) => (float) ($s['amount'] ?? 0), $splits));

            if (abs($sumOfShares - $total) > 0.01) {
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

            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'bill_requested_at' => null,
                'bill_request_note' => null,
            ]);

            if ($session->table) {
                $previousStatus = $session->table->status;
                $session->table->update(['status' => 'available']);
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

    protected function closeOrdersAndSession(Invoice $invoice): void
    {
        $session = $invoice->tableSession()->with('orders', 'table')->first();
        if (! $session) {
            return;
        }

        foreach ($session->orders as $order) {
            if (! in_array($order->status, [OrderStatus::Cancelled->value, OrderStatus::Completed->value])) {
                $order->update(['status' => OrderStatus::Completed->value, 'completed_at' => now()]);
            }
        }
        $session->update([
            'status' => 'closed',
            'closed_at' => now(),
            'bill_requested_at' => null,
            'bill_request_note' => null,
        ]);
        if ($session->table) {
            $previousStatus = $session->table->status;
            $session->table->update(['status' => 'available']);
            SafeBroadcast::dispatch(new TableStatusChanged($session->table->refresh(), $previousStatus));
        }
    }
}
