<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\InvoicePaid;
use App\Events\TableStatusChanged;
use App\Helpers\Money;
use App\Helpers\SafeBroadcast;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

class BillingService
{
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

            $orders = $session->orders()
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->get();

            if ($orders->isEmpty()) {
                throw new \RuntimeException('لا توجد طلبات نشطة للفوترة');
            }

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

            return $payment;
        });
    }

    public function writeOffInvoice(Invoice $invoice, int $userId, string $reason): Invoice
    {
        $invoice->update([
            'status' => 'unpaid_writeoff',
            'notes' => trim(($invoice->notes ?? '')."\n[شطب] ".$reason),
        ]);
        if ($invoice->table_session_id) {
            $this->closeOrdersAndSession($invoice);
        }
        ActivityLog::log('invoice.writeoff', "شطب فاتورة {$invoice->number}: {$reason}", $invoice);
        return $invoice->refresh();
    }

    public function cancelInvoice(Invoice $invoice, int $userId, string $reason): Invoice
    {
        if ($invoice->payments()->exists()) {
            throw new \RuntimeException('لا يمكن إلغاء فاتورة فيها دفعات. اعمل استرداد أولاً.');
        }
        $invoice->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'notes' => trim(($invoice->notes ?? '')."\n[إلغاء] ".$reason),
        ]);
        ActivityLog::log('invoice.cancelled', "إلغاء فاتورة {$invoice->number}: {$reason}", $invoice);
        $invoice->tableSession?->update([
            'bill_requested_at' => null,
            'bill_request_note' => null,
        ]);

        return $invoice->refresh();
    }

    public function splitInvoice(Invoice $invoice, array $splits): Invoice
    {
        if ($invoice->payments()->exists()) {
            throw new \RuntimeException('لا يمكن تقسيم فاتورة فيها دفعات مسجلة');
        }

        return DB::transaction(function () use ($invoice, $splits) {
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

    public function paySplit(\App\Models\InvoiceSplit $split, int $userId, ?string $reference = null): Payment
    {
        if ($split->paid) {
            throw new \RuntimeException('الجزء مدفوع مسبقاً');
        }

        return DB::transaction(function () use ($split, $userId, $reference) {
            $payment = $this->addPayment($split->invoice, (float) $split->amount, $split->method, $userId, $reference, "دفعة جزء: {$split->label}");
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
