<?php

namespace App\Services;

use App\Events\PendingTransferDeclared;
use App\Exceptions\DuplicatePendingTransferException;
use App\Helpers\SafeBroadcast;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PendingTransfer;
use App\Models\TableSession;
use App\Models\User;
use App\Notifications\PendingTransferRecordedNotification;
use App\Support\BranchContext;
use App\Support\SidebarBadges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Single place that turns a claimed bank transfer into a `pending_transfers`
 * row. Shared by every actor who can declare one:
 *   - the waiter (waiter-orders screen),
 *   - the cashier (from their own payment screen),
 *   - the CUSTOMER themselves (from the QR bill screen — no auth, so
 *     recorded_by_user_id is null).
 *
 * Creating the row here (instead of inline in one controller) is what lets a
 * customer's transfer reach the cashier queue without a staff member retyping
 * it. The notify + badge + activity-log side effects all live here so every
 * entry point behaves identically.
 */
class PendingTransferService
{
    public function __construct(protected BillingService $billing) {}

    /**
     * @param  int|null  $recordedByUserId  null when the CUSTOMER declared it themselves.
     */
    public function record(
        TableSession $session,
        float $amount,
        string $senderName,
        ?int $recordedByUserId = null,
        ?string $notes = null,
        ?string $phone = null,
        ?string $typedName = null,
        ?string $proofPath = null,
    ): PendingTransfer {
        $session->loadMissing('invoice', 'table');

        $customer = null;
        $phone = trim((string) ($phone ?? ''));
        if ($phone !== '') {
            $customer = Customer::findByPhone($phone);
        }

        // Display snapshot priority: matched customer → typed name → the
        // session's pre-linked customer → blank (cashier still has table + sender).
        $displayName = $customer?->name
            ?: trim((string) ($typedName ?? ''))
            ?: $session->customer_name
            ?: null;

        $displayPhone = $customer?->phone ?: ($phone !== '' ? $phone : $session->customer_phone);

        // One real transfer = one verifiable claim. Lock the parent session row
        // so two near-simultaneous declarations (a mobile double-tap, or the
        // customer AND the waiter both recording it) serialize: the second one
        // sees the first's pending row and bails, instead of stacking a
        // duplicate the cashier could verify into a double payment. The guard
        // lives HERE (not in one controller) so every entry point is covered.
        $transfer = DB::transaction(function () use (
            $session, $amount, $senderName, $displayName, $displayPhone,
            $notes, $recordedByUserId, $customer, $proofPath
        ) {
            TableSession::whereKey($session->id)->lockForUpdate()->first();

            $existing = PendingTransfer::where('table_session_id', $session->id)
                ->where('status', PendingTransfer::STATUS_PENDING)
                ->first();
            if ($existing) {
                throw new DuplicatePendingTransferException($existing);
            }

            $t = PendingTransfer::create([
                'branch_id'               => $session->branch_id,
                'table_session_id'        => $session->id,
                'invoice_id'              => $session->invoice?->id,
                'customer_id'             => $customer?->id ?? $session->customer_id,
                'amount'                  => $amount,
                'sender_name'             => trim($senderName),
                'customer_name_snapshot'  => $displayName,
                'customer_phone_snapshot' => $displayPhone,
                'notes'                   => $notes,
                'proof_path'              => $proofPath,
                'status'                  => PendingTransfer::STATUS_PENDING,
                'recorded_by_user_id'     => $recordedByUserId,
            ]);

            $who = $recordedByUserId ? 'الطاقم' : 'الزبون';
            ActivityLog::log(
                'pending_transfer.recorded',
                "تسجيل تحويل بانتظار التأكيد بمبلغ {$t->amount} للطاولة #{$session->table?->number} (بواسطة {$who})",
                $t,
                ['sender' => $t->sender_name, 'amount' => (float) $t->amount, 'by_customer' => $recordedByUserId === null],
            );

            return $t;
        });

        // Side effects AFTER commit: notify cashiers, refresh the sidebar,
        // and touch the pulse so their next lightweight poll reloads the queue.
        $this->notifyCashiers($transfer);
        SidebarBadges::bust();
        SafeBroadcast::dispatch(new PendingTransferDeclared($transfer));

        return $transfer;
    }

    /**
     * Confirm one declared transfer and turn it into an ordinary payment.
     * Both the classic and Vue cashier use this transaction so invoice issue,
     * double-submit protection, accounting, and audit behaviour cannot drift.
     *
     * @return array{transfer:PendingTransfer,invoice:\App\Models\Invoice,payment:\App\Models\Payment,remaining_balance:float}
     */
    public function verify(
        PendingTransfer $transfer,
        float $verifiedAmount,
        int $verifiedByUserId,
        ?string $verificationNotes = null,
    ): array {
        $result = DB::transaction(function () use ($transfer, $verifiedAmount, $verifiedByUserId, $verificationNotes) {
            $locked = PendingTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw new \RuntimeException('هذا التحويل لم يعد بانتظار التأكيد.');
            }

            $session = $locked->tableSession()->lockForUpdate()->firstOrFail();
            $invoice = $session->invoice()->where('status', '!=', 'cancelled')->latest()->first();
            if (! $invoice) {
                $invoice = $this->billing->issueInvoice($session, $verifiedByUserId);
            }

            $noteParts = ['تحويل بنكي من '.$locked->sender_name];
            if ($locked->notes) {
                $noteParts[] = $locked->notes;
            }
            if (filled($verificationNotes)) {
                $noteParts[] = trim((string) $verificationNotes);
            }

            $payment = $this->billing->addPayment(
                $invoice,
                $verifiedAmount,
                'transfer',
                $verifiedByUserId,
                $locked->sender_name,
                implode(' — ', $noteParts),
            );

            $locked->update([
                'status' => PendingTransfer::STATUS_VERIFIED,
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'verified_by_user_id' => $verifiedByUserId,
                'verified_at' => now(),
                'verification_notes' => $verificationNotes,
            ]);

            $message = "تأكيد التحويل #{$locked->id} على الفاتورة {$invoice->number}";
            if (abs($verifiedAmount - (float) $locked->amount) > 0.001) {
                $message .= " (المُدّعى {$locked->amount} ← المؤكد {$verifiedAmount})";
            }
            ActivityLog::log('pending_transfer.verified', $message, $locked, [
                'claimed' => (float) $locked->amount,
                'verified' => $verifiedAmount,
            ]);

            return [
                'transfer' => $locked->refresh(),
                'invoice' => $invoice->refresh(),
                'payment' => $payment,
                'remaining_balance' => (float) $invoice->fresh()->balance,
            ];
        });

        SidebarBadges::bust();

        return $result;
    }

    public function reject(PendingTransfer $transfer, string $reason, int $rejectedByUserId): PendingTransfer
    {
        $rejected = DB::transaction(function () use ($transfer, $reason, $rejectedByUserId) {
            $locked = PendingTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw new \RuntimeException('هذا التحويل لم يعد بانتظار التأكيد.');
            }

            $locked->update([
                'status' => PendingTransfer::STATUS_REJECTED,
                'verified_by_user_id' => $rejectedByUserId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            ActivityLog::log(
                'pending_transfer.rejected',
                "رفض التحويل #{$locked->id} — {$reason}",
                $locked,
                ['amount' => (float) $locked->amount],
            );

            return $locked->refresh();
        });

        SidebarBadges::bust();

        return $rejected;
    }

    /**
     * In-app notification to every active cashier / manager / admin on the
     * transfer's branch. Kitchen and waiter accounts are skipped — they can't
     * act on it.
     */
    protected function notifyCashiers(PendingTransfer $transfer): void
    {
        $branchId = $transfer->branch_id;

        $recipients = User::query()
            ->where('status', 'active')
            ->whereIn('role', ['cashier', 'manager', 'super_admin', 'admin'])
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->get();

        // Don't ping the cashier for a transfer they recorded themselves — they
        // already know. (Customer-declared rows have no recorder, so everyone
        // gets it.) Mirrors NotifyService::send()'s actor suppression.
        if ($transfer->recorded_by_user_id) {
            $recipients = $recipients->reject(fn ($u) => $u->id === $transfer->recorded_by_user_id);
        }

        if ($recipients->isEmpty()) {
            return;
        }

        BranchContext::forBranch($branchId, function () use ($recipients, $transfer) {
            Notification::send($recipients, new PendingTransferRecordedNotification($transfer));
        });
    }
}
