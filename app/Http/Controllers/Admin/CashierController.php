<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\Payment;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\OrderDiscountService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CashierController extends Controller
{
    public function __construct(protected BillingService $billing) {}

    public function index()
    {
        $this->authorize('viewAny', Payment::class);
        $activeSessions = TableSession::with(['table', 'orders', 'invoice'])
            ->where('status', 'active')
            ->orderByDesc('last_activity_at')
            ->get();

        $recentInvoices = Invoice::with(['tableSession.table', 'order'])
            ->whereDate('created_at', today())
            ->latest()
            ->limit(20)
            ->get();

        $todayPaid = Invoice::whereDate('created_at', today())->where('status', 'paid');
        $stats = [
            'active_sessions' => $activeSessions->count(),
            'invoices_today'  => Invoice::whereDate('created_at', today())->count(),
            'revenue_today'   => (float) (clone $todayPaid)->sum('total'),
            'cash_today'      => (float) Payment::whereDate('created_at', today())
                                                 ->where('method', 'cash')
                                                 ->sum('amount'),
        ];

        return view('admin.cashier.index', compact('activeSessions', 'recentInvoices', 'stats'));
    }

    public function show(TableSession $session)
    {
        $this->authorize('viewAny', Payment::class);
        $session->load(['table', 'orders.items.modifiers', 'invoice.payments', 'invoice.refunds.processor']);

        // Any bank transfer the diner/waiter claimed for THIS table — shown at the
        // top of the pay screen with inline verify/reject so the cashier acts
        // without leaving the payment flow.
        $pendingTransfers = \App\Models\PendingTransfer::with('recordedBy')
            ->where('table_session_id', $session->id)
            ->pending()
            ->latest()
            ->get();

        return view('admin.cashier.show', compact('session', 'pendingTransfers'));
    }

    public function issue(TableSession $session)
    {
        $this->authorize('create', Payment::class);
        try {
            $invoice = $this->billing->issueInvoice($session, auth()->id());
            return redirect()->route('admin.cashier.show', $session)->with('success', "تم إصدار الفاتورة {$invoice->number}");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function pay(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', \App\Support\PaymentMethods::inRule()],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        // Idempotency: the pay form embeds a one-per-render token. A genuine
        // double-submit (double-click / slow-network retry) of ONE partial
        // payment would otherwise post twice — neither exceeds the balance, so
        // nothing rejects the duplicate and the drawer ends up over. First POST
        // claims the token; a second with the same token is bounced.
        $idem = (string) $request->input('_idem', '');
        if ($idem !== '' && ! Cache::add('idem:pay:'.$idem, true, now()->addMinutes(10))) {
            return back()->with('info', 'تم تسجيل هذه الدفعة بالفعل — مُنع إرسال مكرر.');
        }

        try {
            $this->billing->addPayment($invoice, $data['amount'], $data['method'], auth()->id(), $data['reference'] ?? null, $data['notes'] ?? null);
            return back()->with('success', 'تم تسجيل الدفعة');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function writeoff(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate(['reason' => ['required', 'string']]);
        try {
            $this->billing->writeOffInvoice($invoice, auth()->id(), $data['reason']);
            return back()->with('success', 'تم شطب الفاتورة');
        } catch (\Throwable $e) {
            // writeOffInvoice throws on an already-closed / zero-balance invoice
            // (e.g. a stale second submit) — show it, don't 500.
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Park the remaining balance as a debt on the customer's account.
     * Closes the session/table but keeps the invoice open with
     * `balance > 0` and `settled_on_account_at` set — the customer-debt
     * ledger queries (and dashboard widget) pick it up from there.
     *
     * Refuses (via BillingService) if the invoice has no customer linked,
     * has zero payments collected, or would exceed the customer's credit
     * ceiling. Notes are optional but encouraged so the next cashier can
     * see context next time the customer comes back to pay.
     */
    public function settleOnAccount(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        try {
            $this->billing->settleOnAccount($invoice, auth()->id(), $data['notes'] ?? null);
            return redirect()->route('admin.cashier.index')
                ->with('success', "تم تأجيل المتبقي كدين على الزبون. أُغلقت الجلسة.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate(['reason' => ['required', 'string']]);
        try {
            $this->billing->cancelInvoice($invoice, auth()->id(), $data['reason']);
            return back()->with('success', 'تم إلغاء الفاتورة');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load(['tableSession.table', 'tableSession.orders.items.modifiers', 'order.items.modifiers', 'payments']);
        $pdf = Pdf::loadView('admin.cashier.invoice-pdf', compact('invoice'));
        return $pdf->download($invoice->number.'.pdf');
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['tableSession.table', 'tableSession.orders.items.modifiers', 'order.items.modifiers', 'payments']);
        return view('admin.cashier.invoice-print', compact('invoice'));
    }

    public function split(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate([
            'splits' => ['required', 'array', 'min:2'],
            'splits.*.label' => ['nullable', 'string', 'max:255'],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'splits.*.method' => ['required', \App\Support\PaymentMethods::inRule()],
        ]);
        try {
            $this->billing->splitInvoice($invoice, $data['splits']);
            return back()->with('success', 'تم تقسيم الفاتورة');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function paySplit(Request $request, Invoice $invoice, \App\Models\InvoiceSplit $split)
    {
        $this->authorize('create', Payment::class);
        abort_unless($split->invoice_id === $invoice->id, 404);
        try {
            $this->billing->paySplit($split, auth()->id(), $request->input('reference'));
            return back()->with('success', "تم دفع جزء {$split->label}");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function clearSplits(Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        // Lock + re-check inside a transaction: a bare exists()-then-delete
        // races paySplit (which locks the invoice first), so a split paid a
        // moment ago could be deleted, orphaning its committed payment.
        try {
            DB::transaction(function () use ($invoice) {
                $inv = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($inv->payments()->exists()) {
                    throw new \RuntimeException('لا يمكن إزالة التقسيم بعد تسجيل دفعات');
                }
                $inv->splits()->delete();
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'تم إلغاء التقسيم');
    }

    public function applyDiscountToOrder(Request $request, Order $order, OrderDiscountService $service)
    {
        $this->authorize('apply', OrderDiscount::class);
        $data = $this->validateDiscount($request);
        try {
            $service->applyToOrder($order, $data, $request->user());
            return back()->with('success', 'تم تطبيق الخصم');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function applyDiscountToSession(Request $request, TableSession $session, OrderDiscountService $service)
    {
        $this->authorize('apply', OrderDiscount::class);
        $data = $this->validateDiscount($request);
        try {
            $service->applyToSession($session, $data, $request->user());
            return back()->with('success', 'تم تطبيق الخصم على الجلسة');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function removeDiscount(OrderDiscount $discount, OrderDiscountService $service, Request $request)
    {
        $this->authorize('remove', $discount);
        try {
            $service->remove($discount, $request->user());
            return back()->with('success', 'تم إزالة الخصم');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reason is intentionally REQUIRED — every discount is audited, and a
     * blank reason makes the report column useless. The cashier types one
     * sentence at the till; this is the cheapest moment to capture context.
     */
    protected function validateDiscount(Request $request): array
    {
        return $request->validate([
            'type'               => ['required', 'in:percent,fixed'],
            'value'              => ['required', 'numeric', 'min:0.01'],
            'reason'             => ['required', 'string', 'max:500'],
            'category_lookup_id' => ['nullable', 'integer', 'exists:lookups,id'],
            'name'               => ['nullable', 'string', 'max:120'],
        ], [
            'type.required'   => 'اختر نوع الخصم.',
            'value.required'  => 'أدخل قيمة الخصم.',
            'reason.required' => 'سبب الخصم إلزامي.',
        ]);
    }
}
