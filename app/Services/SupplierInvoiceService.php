<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Shift;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier Invoices & Payments — the AP (accounts-payable) side.
 *
 * - Create an invoice when we receive a bill from a supplier (may link to a PO)
 * - Record payments against it
 * - Recompute balance/status after every change
 */
class SupplierInvoiceService
{
    public function create(array $data, ?int $userId = null): SupplierInvoice
    {
        return DB::transaction(function () use ($data, $userId) {
            $total = (float) ($data['total'] ?? (($data['subtotal'] ?? 0) + ($data['tax_total'] ?? 0)));

            $invoice = SupplierInvoice::create([
                'number'            => $data['number'],
                'supplier_id'       => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'subtotal'          => $data['subtotal'] ?? 0,
                'tax_total'         => $data['tax_total'] ?? 0,
                'total'             => $total,
                'balance'           => $total,
                'status'            => 'unpaid',
                'invoice_date'      => $data['invoice_date'] ?? now()->toDateString(),
                'due_date'          => $data['due_date'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'attachment_path'   => $data['attachment_path'] ?? null,
                'created_by'        => $userId,
            ]);

            ActivityLog::log(
                'supplier_invoice.created',
                "فاتورة مورد #{$invoice->number} — قيمة: ".number_format($invoice->total, 2),
                $invoice
            );

            return $invoice->fresh('supplier');
        });
    }

    public function recordPayment(SupplierInvoice $invoice, array $data, int $userId): SupplierPayment
    {
        if ($invoice->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'لا يمكن تسديد فاتورة ملغاة.']);
        }
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الدفعة يجب أن تكون أكبر من صفر.']);
        }
        $balance = (float) $invoice->balance;
        if ($amount > $balance + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => "قيمة الدفعة ({$amount}) أكبر من المتبقي (".number_format($balance, 2).").",
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $userId) {
            $shiftId = $data['shift_id'] ?? Shift::where('user_id', $userId)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->value('id');

            $payment = $invoice->payments()->create([
                'amount'    => $amount,
                'method'    => $data['method']    ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'paid_on'   => $data['paid_on']   ?? now()->toDateString(),
                'notes'     => $data['notes']     ?? null,
                'paid_by'   => $userId,
                'shift_id'  => $shiftId,
            ]);

            $invoice->recomputeBalance();

            ActivityLog::log(
                'supplier_payment.recorded',
                "دفعة {$payment->methodLabel()} بقيمة ".number_format($payment->amount, 2)." لفاتورة {$invoice->number}",
                $payment
            );

            return $payment;
        });
    }

    public function cancel(SupplierInvoice $invoice, string $reason, int $userId): SupplierInvoice
    {
        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن إلغاء فاتورة عليها دفعات. احذف الدفعات أولاً.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $reason, $userId) {
            $invoice->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
                'notes'        => trim(($invoice->notes ?? '').' | إلغاء: '.$reason),
            ]);
            ActivityLog::log('supplier_invoice.cancelled', "إلغاء فاتورة مورد {$invoice->number}: {$reason}", $invoice);
            return $invoice->fresh();
        });
    }
}
