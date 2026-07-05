<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\NotifyService;
use App\Support\BranchContext;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function show(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing(['table.branch', 'invoice.payments']);
        $orders = $session->orders()->with('items.modifiers')->whereNotIn('status', ['cancelled'])->get();
        $totals = [
            'subtotal' => $orders->sum('subtotal'),
            'tax' => $orders->sum('tax_total'),
            'service' => $orders->sum('service_total'),
            'total' => $orders->sum('total'),
        ];
        $invoice = $session->invoice;

        // Bank-transfer declaration support: the restaurant's transfer details to
        // show the diner, and whether they've already declared one for this visit.
        $bankTransferDetails = trim((string) \App\Models\Setting::get('bank_transfer_details', ''));
        $pendingTransfer = \App\Models\PendingTransfer::where('table_session_id', $session->id)
            ->where('status', \App\Models\PendingTransfer::STATUS_PENDING)
            ->latest()
            ->first();

        return view('customer.bill', compact('orders', 'totals', 'session', 'invoice', 'bankTransferDetails', 'pendingTransfer'));
    }

    public function requestBill(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing(['table', 'invoice']);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($session->invoice && ! in_array($session->invoice->status, ['cancelled'], true)) {
            return back()->with('info', __('ui.customer_order.invoice_already_issued_payment'));
        }

        if ($session->bill_requested_at && $session->bill_requested_at->gt(now()->subMinutes(2))) {
            return back()->with('info', __('ui.customer_order.bill_already_requested'));
        }

        $session->update([
            'bill_requested_at' => now(),
            'bill_request_note' => $data['note'] ?? null,
        ]);
        $session->touch();

        ActivityLog::log(
            'session.bill_requested',
            __('ui.customer_order.bill_activity', ['table' => $session->table?->number]),
            $session,
            ['note' => $data['note'] ?? null]
        );

        // Notify cashier + waiter so the bill is prepared without delay.
        // Customer requests run outside the admin BranchContext, so we pin
        // the session's branch when dispatching.
        BranchContext::forBranch($session->branch_id, function () use ($session) {
            app(NotifyService::class)
                ->billRequested($session->fresh()->load('table'));
        });

        return back()->with('success', __('ui.customer_order.bill_request_received'));
    }

    /**
     * The diner declares they paid by bank transfer from their banking app.
     * This creates a pending-transfer claim tied to their table session — the
     * SAME record a waiter would create — so it lands in the cashier's queue
     * automatically. No payment is posted until the cashier confirms the money
     * arrived in the restaurant's account.
     */
    public function declareTransfer(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing(['table', 'invoice']);

        $data = $request->validate([
            'sender_name' => ['required', 'string', 'max:120'],
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ]);

        // Don't let the diner declare a transfer on an already-settled bill.
        if ($session->invoice && in_array($session->invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
            return back()->with('info', __('ui.customer_order.invoice_already_issued_payment'));
        }

        // Fast path for the common case; the service also guards atomically
        // (locked check) so a double-tap race can't slip two rows through.
        $existing = \App\Models\PendingTransfer::where('table_session_id', $session->id)
            ->where('status', \App\Models\PendingTransfer::STATUS_PENDING)
            ->exists();
        if ($existing) {
            return back()->with('info', 'سجّلنا تحويلك مسبقاً — الكاشير يتأكد منه الآن.');
        }

        try {
            BranchContext::forBranch($session->branch_id, function () use ($session, $data) {
                app(\App\Services\PendingTransferService::class)->record(
                    session: $session,
                    amount: (float) $data['amount'],
                    senderName: $data['sender_name'],
                    recordedByUserId: null,   // customer-initiated
                    notes: $data['notes'] ?? null,
                    phone: $session->customer_phone,
                    typedName: $data['sender_name'],
                );
            });
        } catch (\App\Exceptions\DuplicatePendingTransferException $e) {
            return back()->with('info', 'سجّلنا تحويلك مسبقاً — الكاشير يتأكد منه الآن.');
        }

        return back()->with('success', 'تم إرسال تحويلك للكاشير للتأكيد. رجاءً انتظر التأكيد قبل المغادرة.');
    }
}
