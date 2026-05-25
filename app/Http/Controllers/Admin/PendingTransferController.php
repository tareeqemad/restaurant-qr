<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PendingTransfer;
use App\Models\TableSession;
use App\Notifications\PendingTransferRecordedNotification;
use App\Services\BillingService;
use App\Support\BranchContext;
use App\Support\SidebarBadges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Bank-transfer payments that the diner claims from their banking app
 * but the cashier still has to confirm in the restaurant's account.
 *
 * Three actors:
 *   - Waiter records the claim (store).
 *   - Cashier sees the queue (queue) and either verifies (verify) or
 *     rejects (reject).
 *   - End-of-day reconciliation (report) lines pending/verified/rejected
 *     up against the bank statement.
 *
 * Verification calls BillingService::addPayment so accounting, invoice
 * balance, and table-closing flow stay identical to a normal payment.
 * No special-casing downstream — the only difference between a verified
 * transfer and a regular cashier-collected one is the audit row in
 * `pending_transfers` that records who claimed it and who verified it.
 */
class PendingTransferController extends Controller
{
    public function __construct(protected BillingService $billing) {}

    /**
     * Waiter records a claimed bank transfer for a table session.
     *
     * The amount typically matches what the diner says they wired —
     * which is usually the running session total, but the form lets the
     * waiter type any number in case the diner pre-paid or under-paid.
     * Customer linkage is best-effort: if the phone matches a known
     * customer we snapshot their name/phone so the cashier sees who ate
     * even if the customer row gets soft-deleted later.
     */
    public function store(Request $request, TableSession $session)
    {
        $this->authorize('create', \App\Models\Order::class);

        $data = $request->validate([
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'sender_name'    => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_name'  => ['nullable', 'string', 'max:120'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $customer = null;
        $phone = trim((string) ($data['customer_phone'] ?? ''));
        if ($phone !== '') {
            $customer = Customer::findForLogin($phone);
        }

        // Display snapshot priority:
        //   1. matched customer's name (most authoritative),
        //   2. typed name from the form,
        //   3. fall back to the session's pre-linked customer name,
        //   4. blank — the cashier still has table + sender to match.
        $displayName = $customer?->name
            ?: trim((string) ($data['customer_name'] ?? ''))
            ?: $session->customer_name
            ?: null;

        $displayPhone = $customer?->phone ?: ($phone !== '' ? $phone : $session->customer_phone);

        $transfer = PendingTransfer::create([
            'branch_id'               => $session->branch_id,
            'table_session_id'        => $session->id,
            'invoice_id'              => $session->invoice?->id,
            'customer_id'             => $customer?->id ?? $session->customer_id,
            'amount'                  => $data['amount'],
            'sender_name'             => trim($data['sender_name']),
            'customer_name_snapshot'  => $displayName,
            'customer_phone_snapshot' => $displayPhone,
            'notes'                   => $data['notes'] ?? null,
            'status'                  => PendingTransfer::STATUS_PENDING,
            'recorded_by_user_id'     => auth()->id(),
        ]);

        ActivityLog::log(
            'pending_transfer.recorded',
            "تسجيل تحويل بانتظار التأكيد بمبلغ {$transfer->amount} للطاولة #{$session->table?->number}",
            $transfer,
            ['sender' => $transfer->sender_name, 'amount' => (float) $transfer->amount]
        );

        $this->notifyCashiers($transfer);
        SidebarBadges::bust();

        return back()->with('success', 'تم تسجيل التحويل. الكاشير سيتأكد من البنك ويغلق الطلب.');
    }

    /**
     * Cashier queue — everything in this branch still waiting for the
     * cashier to find it in the bank statement. Newest first because
     * the cashier usually checks the bank app right after the waiter
     * pings them, so freshly-recorded claims are the ones they're
     * actively looking for.
     *
     * `q` is a single free-text search applied across the columns the
     * cashier visually scans — sender, customer snapshot, table number,
     * amount. We keep it broad on purpose; the cashier might know the
     * sender's name, the diner's name, or just remember "the 80 shekel
     * transfer". A typed amount filters by exact match because the
     * cashier copies it from the bank app.
     */
    public function queue(Request $request)
    {
        $this->authorize('create', \App\Models\Payment::class);

        $search = trim((string) $request->get('q', ''));

        $pendingQuery = PendingTransfer::with(['tableSession.table', 'customer', 'recordedBy'])
            ->pending()
            ->latest();

        if ($search !== '') {
            $pendingQuery->where(function ($q) use ($search) {
                $q->where('sender_name', 'like', "%{$search}%")
                  ->orWhere('customer_name_snapshot', 'like', "%{$search}%")
                  ->orWhere('customer_phone_snapshot', 'like', "%{$search}%");
                if (is_numeric($search)) {
                    $q->orWhere('amount', '=', (float) $search);
                    $q->orWhereHas('tableSession.table', fn ($t) => $t->where('number', $search));
                }
            });
        }

        $pending = $pendingQuery->get();

        $recentlyClosed = PendingTransfer::with(['tableSession.table', 'verifiedBy', 'payment'])
            ->where('status', '!=', PendingTransfer::STATUS_PENDING)
            ->whereDate('updated_at', today())
            ->latest('updated_at')
            ->limit(20)
            ->get();

        return view('admin.cashier.pending-transfers', compact('pending', 'recentlyClosed', 'search'));
    }

    /**
     * Cashier confirms the transfer landed in the bank account.
     *
     * Three things happen in one transaction:
     *   1. Issue the invoice if none exists yet (waiter recorded the
     *      claim before the cashier touched the table).
     *   2. Call BillingService::addPayment with whatever amount the
     *      cashier confirms — usually the claimed amount, but the
     *      form allows correcting it when the bank shows less/more.
     *      A short-pay leaves the invoice with a balance, which the
     *      existing flow handles (settle-on-account / second payment).
     *   3. Flip this row to `verified` and link `payment_id` back.
     *
     * The original claim stays on `pending_transfers.amount`. The
     * verified amount is the Payment row's amount. Reports diff them.
     */
    public function verify(Request $request, PendingTransfer $transfer)
    {
        $this->authorize('create', \App\Models\Payment::class);
        abort_unless($transfer->isPending(), 422, 'هذا التحويل لم يعد بانتظار التأكيد.');

        $data = $request->validate([
            'verified_amount'     => ['nullable', 'numeric', 'min:0.01'],
            'verification_notes'  => ['nullable', 'string', 'max:500'],
        ]);

        $verifiedAmount = isset($data['verified_amount'])
            ? (float) $data['verified_amount']
            : (float) $transfer->amount;

        try {
            DB::transaction(function () use ($transfer, $verifiedAmount, $data) {
                $session = $transfer->tableSession()->lockForUpdate()->firstOrFail();

                $invoice = $session->invoice()->where('status', '!=', 'cancelled')->latest()->first();
                if (! $invoice) {
                    $invoice = $this->billing->issueInvoice($session, auth()->id());
                }

                // Sender's name (+ optional cashier notes) go into the
                // payment notes so the accounting export shows context.
                $paymentNoteParts = ['تحويل بنكي من '.$transfer->sender_name];
                if ($transfer->notes) {
                    $paymentNoteParts[] = $transfer->notes;
                }
                if (! empty($data['verification_notes'])) {
                    $paymentNoteParts[] = $data['verification_notes'];
                }
                $paymentNotes = implode(' — ', $paymentNoteParts);

                $payment = $this->billing->addPayment(
                    $invoice,
                    $verifiedAmount,
                    'transfer',
                    auth()->id(),
                    $transfer->sender_name,
                    $paymentNotes,
                );

                $transfer->update([
                    'status'              => PendingTransfer::STATUS_VERIFIED,
                    'invoice_id'          => $invoice->id,
                    'payment_id'          => $payment->id,
                    'verified_by_user_id' => auth()->id(),
                    'verified_at'         => now(),
                    'verification_notes'  => $data['verification_notes'] ?? null,
                ]);

                $logExtra = [
                    'claimed'  => (float) $transfer->amount,
                    'verified' => $verifiedAmount,
                ];
                $message = "تأكيد التحويل #{$transfer->id} على الفاتورة {$invoice->number}";
                if (abs($verifiedAmount - (float) $transfer->amount) > 0.001) {
                    $message .= " (المُدّعى {$transfer->amount} ← المؤكد {$verifiedAmount})";
                }
                ActivityLog::log('pending_transfer.verified', $message, $transfer, $logExtra);
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        SidebarBadges::bust();
        return back()->with('success', 'تم تأكيد التحويل وتسجيله كدفعة.');
    }

    /**
     * Re-open a rejected transfer. Useful when the cashier rejected
     * before the funds had actually settled (banks lag), then the
     * transfer landed later and they want to verify it now.
     *
     * Refuses to reopen a `verified` row — that one already has a
     * Payment attached and the right correction path is to refund the
     * payment, not flip the pending row back. Only `rejected → pending`
     * is allowed.
     */
    public function reopen(Request $request, PendingTransfer $transfer)
    {
        $this->authorize('create', \App\Models\Payment::class);
        abort_unless(
            $transfer->status === PendingTransfer::STATUS_REJECTED,
            422,
            'فقط التحويلات المرفوضة يمكن إعادة فتحها. التحويل المؤكد فيه دفعة فعلية — استخدم استرجاع.'
        );

        $transfer->update([
            'status'              => PendingTransfer::STATUS_PENDING,
            'rejected_at'         => null,
            'rejection_reason'    => null,
            // verified_by stays as the cashier who handled it last for traceability.
        ]);

        ActivityLog::log(
            'pending_transfer.reopened',
            "إعادة فتح التحويل #{$transfer->id}",
            $transfer,
            ['amount' => (float) $transfer->amount]
        );

        SidebarBadges::bust();
        return back()->with('success', 'تم إعادة التحويل لقائمة الانتظار.');
    }

    /**
     * Cashier couldn't find the transfer in the bank app. We record
     * the rejection reason — the waiter sees it in the activity log
     * and can either re-record with a corrected amount or follow up
     * with the diner.
     */
    public function reject(Request $request, PendingTransfer $transfer)
    {
        $this->authorize('create', \App\Models\Payment::class);
        abort_unless($transfer->isPending(), 422, 'هذا التحويل لم يعد بانتظار التأكيد.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $transfer->update([
            'status'              => PendingTransfer::STATUS_REJECTED,
            'verified_by_user_id' => auth()->id(),
            'rejected_at'         => now(),
            'rejection_reason'    => $data['reason'],
        ]);

        ActivityLog::log(
            'pending_transfer.rejected',
            "رفض التحويل #{$transfer->id} — {$data['reason']}",
            $transfer,
            ['amount' => (float) $transfer->amount]
        );

        SidebarBadges::bust();
        return back()->with('success', 'تم رفض التحويل وتسجيل السبب.');
    }

    /**
     * End-of-day reconciliation. Matches bank statement rows against
     * the verified/rejected/pending claims for the picked date.
     */
    public function report(Request $request)
    {
        $this->authorize('create', \App\Models\Payment::class);

        $date = $request->date('date') ?? today();

        $rows = PendingTransfer::with(['tableSession.table', 'recordedBy', 'verifiedBy', 'payment'])
            ->whereDate('created_at', $date)
            ->orderBy('created_at')
            ->get();

        $summary = [
            'pending'  => $rows->where('status', PendingTransfer::STATUS_PENDING),
            'verified' => $rows->where('status', PendingTransfer::STATUS_VERIFIED),
            'rejected' => $rows->where('status', PendingTransfer::STATUS_REJECTED),
        ];

        $totals = collect($summary)->map(fn ($g) => (float) $g->sum('amount'));

        return view('admin.cashier.transfers-report', compact('rows', 'summary', 'totals', 'date'));
    }

    /**
     * Dispatch the in-app notification to every cashier / shift cashier
     * / manager on the same branch. We filter on role so kitchen and
     * waiter accounts don't get woken up by something they can't act on.
     */
    protected function notifyCashiers(PendingTransfer $transfer): void
    {
        $branchId = $transfer->branch_id;

        $recipients = \App\Models\User::query()
            ->where('status', 'active')
            ->whereIn('role', ['cashier', 'manager', 'super_admin', 'admin'])
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        // Wrap the dispatch in the originating branch context so the
        // notification rows are stamped with the right branch_id even
        // when the recipient lookup runs under a different context.
        BranchContext::forBranch($branchId, function () use ($recipients, $transfer) {
            Notification::send($recipients, new PendingTransferRecordedNotification($transfer));
        });
    }
}
