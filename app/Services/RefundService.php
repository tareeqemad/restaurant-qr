<?php

namespace App\Services;

use App\Helpers\Money;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Scopes\BranchScope;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use App\Support\PaymentMethods;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refund orchestration = credit-note document + payout settlement.
 * The invoice row is the concurrency boundary for issue/complete/cancel.
 */
class RefundService
{
    public function __construct(
        private readonly CreditNoteService $creditNotes,
        private readonly AccountingService $accounting,
        private readonly CustomerAdvanceService $advances,
    ) {}

    public function issue(
        Invoice $invoice,
        float $amount,
        string $method,
        string $reason,
        int $userId,
        array $opts = [],
    ): Refund {
        $amount = Money::round($amount);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الاسترداد يجب أن تكون أكبر من صفر.']);
        }
        if (! in_array($method, Refund::ACTIVE_METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'طريقة الاسترداد غير مدعومة.']);
        }
        $status = $opts['status'] ?? 'completed';
        if (! in_array($status, ['pending', 'completed'], true)) {
            throw ValidationException::withMessages(['status' => 'حالة الاسترداد غير صحيحة.']);
        }

        $refund = DB::transaction(function () use ($invoice, $amount, $method, $reason, $userId, $opts, $status) {
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            return BranchContext::forBranch($invoice->branch_id, function () use ($invoice, $amount, $method, $reason, $userId, $opts, $status) {
                if ($key = trim((string) ($opts['idempotency_key'] ?? ''))) {
                    if ($existing = Refund::withoutGlobalScope(BranchScope::class)->where('idempotency_key', $key)->first()) {
                        return $existing;
                    }
                }
                if (in_array($invoice->status, ['unpaid_writeoff', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['amount' => 'لا يمكن استرداد فاتورة مشطوبة أو ملغاة.']);
                }
                if ($invoice->settled_on_account_at) {
                    throw ValidationException::withMessages([
                        'amount' => 'هذه فاتورة دين. استخدم «تخفيض الدين» لإصدار إشعار دائن، ثم اصرف فقط أي رصيد مستحق للزبون.',
                    ]);
                }
                if ($method === PaymentMethods::CUSTOMER_ADVANCE && ! $invoice->customer_id) {
                    throw ValidationException::withMessages(['method' => 'اربط زبوناً بالفاتورة قبل تحويل الاسترداد إلى رصيده.']);
                }

                // credited_total includes pending credit notes, so a second
                // request cannot reserve the same sale/payment again.
                $available = $invoice->refundableBalance();
                if ($amount - $available > 0.01) {
                    throw ValidationException::withMessages([
                        'amount' => 'الحد المتاح للاسترداد الآن هو '.number_format($available, 2).'؛ يشمل حجز الطلبات المعلّقة.',
                    ]);
                }

                if (! empty($opts['payment_id'])) {
                    $payment = Payment::withoutGlobalScope('posted')->find($opts['payment_id']);
                    if (! $payment || (int) $payment->invoice_id !== (int) $invoice->id || $payment->status !== 'posted') {
                        throw ValidationException::withMessages(['payment_id' => 'الدفعة المحددة لا تعود لهذه الفاتورة أو تم إلغاؤها.']);
                    }
                }

                $creditNote = $this->creditNotes->issue(
                    $invoice,
                    $amount,
                    'refund',
                    $reason,
                    $userId,
                    ['lines' => $opts['lines'] ?? [], 'notes' => $opts['notes'] ?? null],
                );
                $invoice->refresh();

                $allocations = $method === 'original'
                    ? $this->originalPaymentAllocations($invoice, $amount)
                    : [[
                        'payment_id' => $opts['payment_id'] ?? null,
                        'method' => $method,
                        'amount' => $amount,
                        'reference' => $opts['reference'] ?? null,
                    ]];
                $storedMethod = count(array_unique(array_column($allocations, 'method'))) > 1
                    ? 'mixed' : (string) $allocations[0]['method'];

                $refund = Refund::create([
                    'branch_id' => $invoice->branch_id,
                    'number' => Refund::generateNumber(),
                    'invoice_id' => $invoice->id,
                    'credit_note_id' => $creditNote->id,
                    'payment_id' => count($allocations) === 1 ? $allocations[0]['payment_id'] : null,
                    'amount' => $amount,
                    'method' => $storedMethod,
                    'reference' => $opts['reference'] ?? null,
                    'idempotency_key' => $opts['idempotency_key'] ?? null,
                    'status' => $status,
                    'reason' => trim($reason),
                    'notes' => $opts['notes'] ?? null,
                    'processed_by' => $userId,
                    'refunded_at' => now(),
                ]);
                $refund->allocations()->createMany($allocations);

                if ($status === 'completed') {
                    $refund = $this->completeLocked($refund, $invoice, $userId, $opts['reference'] ?? null);
                }

                ActivityLog::log(
                    'refund.issued',
                    "استرداد {$refund->number} مقابل {$invoice->number}: ".number_format($amount, 2),
                    $refund,
                    ['credit_note_id' => $creditNote->id, 'status' => $status, 'allocations' => $allocations],
                    causerId: $userId,
                );

                return $refund->fresh(['invoice', 'creditNote.lines', 'allocations.payment', 'processor']);
            });
        });

        app(NotifyService::class)->refundIssued($refund);

        return $refund;
    }

    public function complete(Refund $refund, int $userId, ?string $reference = null): Refund
    {
        return DB::transaction(function () use ($refund, $userId, $reference) {
            $refund = Refund::withoutGlobalScope(BranchScope::class)
                ->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($refund->invoice_id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'يمكن إتمام الاستردادات المعلّقة فقط.']);
            }

            $otherReservations = (float) Refund::withoutGlobalScope(BranchScope::class)
                ->where('invoice_id', $invoice->id)->where('status', 'pending')
                ->whereKeyNot($refund->id)->sum('amount');
            $capacity = max(0, (float) $invoice->paid_total - (float) $invoice->refunded_total - $otherReservations);
            if ((float) $refund->amount - $capacity > 0.01) {
                throw ValidationException::withMessages(['amount' => 'تغيّر رصيد الفاتورة ولم يعد يغطي هذا الاسترداد.']);
            }

            return BranchContext::forBranch($refund->branch_id, function () use ($refund, $invoice, $userId, $reference) {
                if (! $refund->allocations()->exists()) {
                    $refund->allocations()->create([
                        'payment_id' => $refund->payment_id,
                        'method' => $refund->method,
                        'amount' => $refund->amount,
                        'reference' => $reference ?? $refund->reference,
                    ]);
                }
                // Upgrade a legacy pending request (created before credit-note
                // documents existed) at the moment it is approved.
                if (! $refund->credit_note_id) {
                    $note = $this->creditNotes->issue(
                        $invoice, (float) $refund->amount, 'refund', $refund->reason,
                        $userId, ['notes' => $refund->notes],
                    );
                    $refund->update(['credit_note_id' => $note->id]);
                    $invoice->refresh();
                }

                return $this->completeLocked($refund, $invoice, $userId, $reference);
            });
        });
    }

    public function cancel(Refund $refund, int $userId, string $reason): Refund
    {
        return DB::transaction(function () use ($refund, $userId, $reason) {
            $refund = Refund::withoutGlobalScope(BranchScope::class)
                ->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($refund->invoice_id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'لا يمكن إلغاء استرداد تم تنفيذه. استخدم عكس الاسترداد.']);
            }

            return BranchContext::forBranch($refund->branch_id, function () use ($refund, $invoice, $userId, $reason) {
                $refund->update([
                    'status' => 'cancelled', 'cancelled_by' => $userId,
                    'cancelled_at' => now(),
                    'notes' => trim(($refund->notes ?? '').' | إلغاء: '.$reason),
                ]);
                if ($refund->credit_note_id) {
                    $this->creditNotes->reverse($refund->creditNote()->firstOrFail(), $userId, 'إلغاء استرداد معلّق: '.$reason);
                }
                $invoice->fresh()->recomputeBalanceAfterRefund();
                ActivityLog::log('refund.cancelled', "إلغاء استرداد {$refund->number}: {$reason}", $refund, causerId: $userId);

                return $refund->fresh();
            });
        });
    }

    public function reverse(Refund $refund, int $userId, string $reason): Refund
    {
        return DB::transaction(function () use ($refund, $userId, $reason) {
            $refund = Refund::withoutGlobalScope(BranchScope::class)
                ->whereKey($refund->id)->with('allocations')->lockForUpdate()->firstOrFail();
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($refund->invoice_id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'completed') {
                throw ValidationException::withMessages(['status' => 'يمكن عكس الاسترداد المكتمل فقط.']);
            }

            return BranchContext::forBranch($refund->branch_id, function () use ($refund, $invoice, $userId, $reason) {
                $this->advances->reverseRefundCredits($refund, $userId, $reason);
                $this->accounting->reverseRefundCompleted($refund, $userId, $reason);
                $invoice->update([
                    'refunded_total' => max(0, Money::round((float) $invoice->refunded_total - (float) $refund->amount)),
                ]);
                if ($refund->credit_note_id) {
                    $this->creditNotes->reverse($refund->creditNote()->firstOrFail(), $userId, 'عكس الاسترداد: '.$reason);
                } else {
                    // Historical completed refunds had one combined GL entry.
                    // Migration backfilled credited_total for them, so reverse
                    // that backfill when their legacy document is reversed.
                    $invoice->update([
                        'credited_total' => max(0, Money::round((float) $invoice->credited_total - (float) $refund->amount)),
                    ]);
                }
                $invoice->fresh()->recomputeBalanceAfterRefund();
                $refund->update([
                    'status' => 'reversed', 'reversed_by' => $userId,
                    'reversed_at' => now(), 'reversal_reason' => trim($reason),
                ]);
                app(LoyaltyService::class)->reverseForRefund($invoice->fresh(), (float) $refund->amount, $userId);
                ActivityLog::log('refund.reversed', "عكس استرداد {$refund->number}: {$reason}", $refund, causerId: $userId);

                return $refund->fresh(['creditNote', 'allocations']);
            });
        });
    }

    private function completeLocked(Refund $refund, Invoice $invoice, int $userId, ?string $reference): Refund
    {
        $refund->update([
            'status' => 'completed', 'reference' => $reference ?? $refund->reference,
            'refunded_at' => now(), 'completed_at' => now(), 'completed_by' => $userId,
        ]);
        if ($reference) {
            $refund->allocations()->whereNull('reference')->update(['reference' => $reference]);
        }

        $invoice->increment('refunded_total', (float) $refund->amount);
        $invoice = $invoice->fresh();
        $invoice->recomputeBalanceAfterRefund();
        $refund = $refund->fresh('allocations');
        $this->advances->creditRefundAllocations($refund, $userId);
        $this->accounting->recordRefundCompleted($refund);
        app(LoyaltyService::class)->reverseForRefund($invoice->fresh(), (float) $refund->amount, $userId);
        ActivityLog::log('refund.completed', "إتمام استرداد {$refund->number}", $refund, causerId: $userId);

        return $refund->fresh(['invoice', 'creditNote.lines', 'allocations.payment']);
    }

    private function originalPaymentAllocations(Invoice $invoice, float $amount): array
    {
        $payments = Payment::withoutGlobalScope('posted')
            ->where('invoice_id', $invoice->id)->where('status', 'posted')
            ->orderBy('paid_at')->orderBy('id')->lockForUpdate()->get();
        $used = DB::table('refund_allocations')
            ->join('refunds', 'refunds.id', '=', 'refund_allocations.refund_id')
            ->whereIn('refunds.status', ['pending', 'completed'])
            ->whereIn('refund_allocations.payment_id', $payments->pluck('id'))
            ->groupBy('refund_allocations.payment_id')
            ->selectRaw('refund_allocations.payment_id, SUM(refund_allocations.amount) as amount')
            ->pluck('amount', 'payment_id');

        $remaining = $amount;
        $allocations = [];
        foreach ($payments as $payment) {
            if ($remaining <= 0.001) {
                break;
            }
            $available = max(0, (float) $payment->amount - (float) ($used[$payment->id] ?? 0));
            if ($available <= 0.001) {
                continue;
            }
            $take = Money::round(min($remaining, $available));
            $allocations[] = [
                'payment_id' => $payment->id,
                'method' => $payment->method,
                'amount' => $take,
                'reference' => $payment->reference,
            ];
            $remaining = Money::round($remaining - $take);
        }
        if ($remaining > 0.001) {
            throw ValidationException::withMessages(['amount' => 'تعذّر توزيع المبلغ بالكامل على الدفعات الأصلية.']);
        }

        return $allocations;
    }
}
