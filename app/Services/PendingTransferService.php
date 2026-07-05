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
    ): PendingTransfer {
        $session->loadMissing('invoice', 'table');

        $customer = null;
        $phone = trim((string) ($phone ?? ''));
        if ($phone !== '') {
            $customer = Customer::findForLogin($phone);
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
            $notes, $recordedByUserId, $customer
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

        // Side effects AFTER commit: notify the cashiers, refresh the sidebar
        // badge, and push a live event so the cashier screen lights + chimes
        // now instead of on the next poll (SafeBroadcast no-ops if Reverb down).
        $this->notifyCashiers($transfer);
        SidebarBadges::bust();
        SafeBroadcast::dispatch(new PendingTransferDeclared($transfer));

        return $transfer;
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
