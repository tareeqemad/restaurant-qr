<?php

namespace App\Http\Controllers\Customer;

use App\Events\TableStatusChanged;
use App\Exceptions\DuplicatePendingTransferException;
use App\Helpers\Money;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\OrderChangeRequest;
use App\Models\PendingTransfer;
use App\Models\Setting;
use App\Services\NotifyService;
use App\Services\PendingTransferService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

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
        $bankTransferDetails = trim((string) Setting::get('bank_transfer_details', ''));
        $pendingTransfer = PendingTransfer::where('table_session_id', $session->id)
            ->where('status', PendingTransfer::STATUS_PENDING)
            ->latest()
            ->first();
        $hasPendingChangeRequest = $this->hasPendingChangeRequest((int) $session->id);

        // Inertia/Vue since Wave 2. Arabic snapshot names; totals
        // come from the Order columns — never recomputed from items.
        $localized = fn ($row) => $row?->name_snapshot;

        return Inertia::render('Customer/Bill', [
            'sessionInfo' => [
                'tableNumber' => $session->table->number ?? null,
                'billRequested' => (bool) ($session->bill_requested_at && ! ($invoice && ! in_array($invoice->status, ['cancelled'], true))),
                'billRequestedAgo' => $session->bill_requested_at?->diffForHumans(),
                'billRequestNote' => $session->bill_request_note,
                'defaultSenderName' => $session->customer_name,
            ],
            'invoice' => ($invoice && ! in_array($invoice->status, ['cancelled'], true)) ? [
                'number' => $invoice->number,
                'balanceDue' => (float) $invoice->balance > 0,
                'balance' => Money::format($invoice->balance),
            ] : null,
            'hasPendingChangeRequest' => $hasPendingChangeRequest,
            'orders' => $orders->map(fn ($order) => [
                'id' => $order->id,
                'number' => $order->number,
                'createdAgo' => $order->created_at->diffForHumans(),
                'items' => $order->items->map(fn ($it) => [
                    'name' => $localized($it),
                    'modifiers' => $it->modifiers->map(fn ($m) => $localized($m))->filter()->values()->all(),
                    'notes' => $it->notes,
                    'qty' => (int) $it->quantity,
                    'subtotal' => Money::format($it->subtotal),
                    'cancelled' => $it->status === 'cancelled',
                ])->values()->all(),
            ])->values()->all(),
            'totals' => [
                'subtotal' => Money::format($totals['subtotal']),
                'tax' => $totals['tax'] > 0 ? Money::format($totals['tax']) : null,
                'service' => $totals['service'] > 0 ? Money::format($totals['service']) : null,
                'total' => Money::format($totals['total']),
                'totalRaw' => number_format((float) $totals['total'], 2, '.', ''),
            ],
            'transfer' => [
                'details' => $bankTransferDetails,
                'pending' => $pendingTransfer ? [
                    'amount' => Money::format($pendingTransfer->amount),
                ] : null,
            ],
            'urls' => [
                'menu' => route('customer.menu.open', $session->table->qr_token),
                'track' => route('customer.track'),
                'requestBill' => route('customer.bill.request'),
                'declareTransfer' => route('customer.bill.transfer'),
            ],
        ]);
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

        if ($this->hasPendingChangeRequest((int) $session->id)) {
            return back()->with('info', 'طلب التعديل أو الإلغاء ما زال بانتظار الجرسون. ستتمكن من طلب الحساب بعد حسمه وتحديث الإجمالي.');
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

            // Wake the floor boards NOW. This was the only triage tier with no
            // broadcast behind it — the «طلبت الفاتورة» card materialized on
            // whatever unrelated event or 15s poll came next, so from the
            // waiter's side cards appeared at random moments. Same pattern as
            // the help-request broadcast in OrderStatusController.
            if ($session->table) {
                SafeBroadcast::dispatch(new TableStatusChanged($session->table->refresh(), $session->table->status));
            }
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

        if ($this->hasPendingChangeRequest((int) $session->id)) {
            return back()->with('info', 'لا ترسل التحويل الآن؛ طلب التعديل أو الإلغاء ما زال بانتظار الجرسون وقد يتغير الإجمالي.');
        }

        $data = $request->validate([
            'sender_name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:500'],
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        // Don't let the diner declare a transfer on an already-settled bill.
        if ($session->invoice && in_array($session->invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
            return back()->with('info', __('ui.customer_order.invoice_already_issued_payment'));
        }

        // Fast path for the common case; the service also guards atomically
        // (locked check) so a double-tap race can't slip two rows through.
        $existing = PendingTransfer::where('table_session_id', $session->id)
            ->where('status', PendingTransfer::STATUS_PENDING)
            ->exists();
        if ($existing) {
            return back()->with('info', 'سجّلنا تحويلك مسبقاً — الكاشير يتأكد منه الآن.');
        }

        $proofPath = $request->file('proof')->store('pending-transfer-proofs', 'local');
        abort_unless($proofPath, 500, 'تعذر حفظ صورة الوصل. حاول مرة أخرى.');

        try {
            BranchContext::forBranch($session->branch_id, function () use ($session, $data, $proofPath) {
                app(PendingTransferService::class)->record(
                    session: $session,
                    amount: (float) $data['amount'],
                    senderName: $data['sender_name'],
                    recordedByUserId: null,   // customer-initiated
                    notes: $data['notes'] ?? null,
                    phone: $session->customer_phone,
                    typedName: $data['sender_name'],
                    proofPath: $proofPath,
                );
            });
        } catch (DuplicatePendingTransferException $e) {
            Storage::disk('local')->delete($proofPath);

            return back()->with('info', 'سجّلنا تحويلك مسبقاً — الكاشير يتأكد منه الآن.');
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($proofPath);
            throw $e;
        }

        return back()->with('success', 'تم إرسال تحويلك للكاشير للتأكيد. رجاءً انتظر التأكيد قبل المغادرة.');
    }

    private function hasPendingChangeRequest(int $sessionId): bool
    {
        return OrderChangeRequest::where('status', OrderChangeRequest::STATUS_PENDING)
            ->whereHas('order', fn ($query) => $query->where('table_session_id', $sessionId))
            ->exists();
    }
}
