<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shift;
use App\Services\Accounting\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles refunds against paid invoices.
 *
 * Business rules:
 *  - Cannot refund more than (paid_total − already_refunded)
 *  - Refund is linked to invoice; optionally to a specific payment
 *  - Updates invoice.refunded_total (denormalized) for fast AP/AR reporting
 *  - Creates an ActivityLog entry for audit trail
 *
 * What this service does NOT do (intentionally):
 *  - Doesn't reverse stock — use OrderService::cancelItem for that (stock ops are
 *    kitchen-centric, refunds are cashier-centric; separation of concerns)
 *  - Doesn't call external payment APIs — integrators wire that into controllers
 */
class RefundService
{
    /**
     * Issue a refund against an invoice.
     *
     * @param Invoice $invoice
     * @param float   $amount   Refund amount in invoice currency
     * @param string  $method   Payment method used to return money (cash/card/...)
     * @param string  $reason   Required — why is this refund happening?
     * @param array   $opts     payment_id, reference, notes, status, shift_id
     */
    public function issue(
        Invoice $invoice,
        float   $amount,
        string  $method,
        string  $reason,
        int     $userId,
        array   $opts = []
    ): Refund {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الاسترداد يجب أن تكون أكبر من صفر.']);
        }

        $refund = DB::transaction(function () use ($invoice, $amount, $method, $reason, $userId, $opts) {
            // Lock the invoice row so two cashiers can't refund the same balance simultaneously
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            $alreadyRefunded = (float) $invoice->refunded_total;
            $available       = (float) $invoice->paid_total - $alreadyRefunded;

            if ($amount > $available + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => "الحد الأقصى للاسترداد: ".number_format($available, 2).
                                " (المدفوع: ".number_format($invoice->paid_total, 2).
                                ", المسترد سابقاً: ".number_format($alreadyRefunded, 2).").",
                ]);
            }

            // Resolve active shift for the processor (optional — for shift reconciliation)
            $shiftId = $opts['shift_id'] ?? Shift::where('user_id', $userId)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->value('id');

            $status = $opts['status'] ?? 'completed';

            $refund = Refund::create([
                // Stamp branch_id from the invoice (NOT BranchContext) —
                // refunds may be processed from a different active branch.
                'branch_id'    => $invoice->branch_id,
                'number'       => Refund::generateNumber(),
                'invoice_id'   => $invoice->id,
                'payment_id'   => $opts['payment_id'] ?? null,
                'amount'       => $amount,
                'method'       => $method,
                'reference'    => $opts['reference'] ?? null,
                'status'       => $status,
                'reason'       => $reason,
                'notes'        => $opts['notes'] ?? null,
                'processed_by' => $userId,
                'shift_id'     => $shiftId,
                'refunded_at'  => now(),
            ]);

            // Only completed refunds affect the ledger. Pending ones are reservations.
            if ($status === 'completed') {
                $invoice->increment('refunded_total', $amount);
                app(AccountingService::class)->recordRefundCompleted($refund);
            }

            ActivityLog::log(
                'refund.issued',
                "استرداد {$refund->number} مقابل فاتورة {$invoice->number}: ".number_format($amount, 2),
                $refund
            );

            return $refund->fresh(['invoice', 'payment', 'processor']);
        });

        // Notify cashier/manager outside the transaction so a notify failure
        // can never roll back a successful refund.
        app(NotifyService::class)->refundIssued($refund);

        return $refund;
    }

    /**
     * Cancel a pending refund (e.g., card refund rejected by gateway).
     */
    public function cancel(Refund $refund, int $userId, string $reason): Refund
    {
        if ($refund->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'لا يمكن إلغاء استرداد تم تنفيذه.']);
        }

        return DB::transaction(function () use ($refund, $userId, $reason) {
            $refund->update([
                'status' => 'cancelled',
                'notes'  => trim(($refund->notes ?? '').' | إلغاء: '.$reason),
            ]);
            ActivityLog::log('refund.cancelled', "إلغاء استرداد {$refund->number}: {$reason}", $refund);
            return $refund->fresh();
        });
    }

    /**
     * Complete a pending refund (card gateway confirmed).
     */
    public function complete(Refund $refund, int $userId, ?string $reference = null): Refund
    {
        if ($refund->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'يمكن إتمام الاستردادات المعلّقة فقط.']);
        }

        return DB::transaction(function () use ($refund, $userId, $reference) {
            $refund->update([
                'status'    => 'completed',
                'reference' => $reference ?? $refund->reference,
            ]);

            // Now affect the ledger
            Invoice::whereKey($refund->invoice_id)->increment('refunded_total', $refund->amount);
            app(AccountingService::class)->recordRefundCompleted($refund);

            ActivityLog::log('refund.completed', "إتمام استرداد {$refund->number}", $refund);
            return $refund->fresh();
        });
    }
}
