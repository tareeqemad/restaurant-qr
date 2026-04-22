<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\InvoicePaid;
use App\Helpers\Money;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function issueInvoice(TableSession $session, int $userId): Invoice
    {
        return DB::transaction(function () use ($session, $userId) {
            $orders = $session->orders()
                ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
                ->get();

            if ($orders->isEmpty()) {
                throw new \RuntimeException('لا توجد طلبات نشطة للفوترة');
            }

            $subtotal = (float) $orders->sum('subtotal');
            $discountTotal = (float) $orders->sum('discount_total');
            $taxTotal = (float) $orders->sum('tax_total');
            $serviceTotal = (float) $orders->sum('service_total');
            $tip = (float) $orders->sum('tip');
            $total = (float) $orders->sum('total');

            $invoice = Invoice::create([
                'table_session_id' => $session->id,
                'issued_by_user_id' => $userId,
                'subtotal' => Money::round($subtotal),
                'discount_total' => Money::round($discountTotal),
                'tax_total' => Money::round($taxTotal),
                'service_total' => Money::round($serviceTotal),
                'tip' => Money::round($tip),
                'total' => Money::round($total),
                'balance' => Money::round($total),
                'status' => 'issued',
                'customer_name' => $session->customer_name,
                'customer_phone' => $session->customer_phone,
                'issued_at' => now(),
            ]);

            ActivityLog::log('invoice.issued', "إصدار فاتورة {$invoice->number} للطاولة {$session->table->number}", $invoice, [
                'total' => (float) $invoice->total,
                'orders_count' => $orders->count(),
            ]);

            return $invoice;
        });
    }

    public function addPayment(Invoice $invoice, float $amount, string $method, int $userId, ?string $reference = null, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $userId, $reference, $notes) {
            $shift = \App\Models\Shift::where('user_id', $userId)->where('status', 'open')->latest('opened_at')->first();

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'method' => $method,
                'amount' => Money::round($amount),
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
                $this->closeOrdersAndSession($invoice);
                \App\Helpers\SafeBroadcast::dispatch(new InvoicePaid($invoice->refresh()->load('tableSession.table')));
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
        $this->closeOrdersAndSession($invoice);
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

    protected function closeOrdersAndSession(Invoice $invoice): void
    {
        $session = $invoice->tableSession()->with('orders', 'table')->first();
        foreach ($session->orders as $order) {
            if (! in_array($order->status, [OrderStatus::Cancelled->value, OrderStatus::Completed->value])) {
                $order->update(['status' => OrderStatus::Completed->value, 'completed_at' => now()]);
            }
        }
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        if ($session->table) {
            $session->table->update(['status' => 'available']);
        }
    }
}
