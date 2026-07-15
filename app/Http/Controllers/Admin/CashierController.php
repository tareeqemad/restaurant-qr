<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\OrderDiscountService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            // NET of same-day completed cash refunds — otherwise the drawer
            // figure reads high on any day a cash refund was paid out.
            'cash_today'      => (float) Payment::whereDate('created_at', today())
                                                 ->where('method', 'cash')
                                                 ->sum('amount')
                                 - (float) Refund::whereDate('refunded_at', today())
                                                 ->where('method', 'cash')
                                                 ->where('status', 'completed')
                                                 ->sum('amount'),
        ];

        return view('admin.cashier.index', compact('activeSessions', 'recentInvoices', 'stats'));
    }

    /**
     * Permanent redirect alias for the retired classic cashier page.
     *
     * The server-rendered pay screen was merged into the live Volt dashboard
     * (one screen does everything now: pay, discount, refund, settle, un-park,
     * cancel, write-off, void, splits, pending transfers). This route survives
     * so the waiter/tables-board «كاشير» deep-links and any old bookmarks land
     * on the merged screen with the session pre-selected — zero board churn.
     */
    public function show(TableSession $session)
    {
        $this->authorize('viewAny', Payment::class);

        return redirect()->route('admin.cashier.index', ['session' => $session->id]);
    }

    public function issue(TableSession $session)
    {
        $this->authorize('create', Payment::class);
        try {
            $invoice = $this->billing->issueInvoice($session, auth()->id());
            return redirect()->route('admin.cashier.index', ['session' => $session->id])->with('success', "تم إصدار الفاتورة {$invoice->number}");
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

    /**
     * Void a single mistaken payment (wrong method / fat-fingered amount /
     * double entry) — reverses its ledger posting and reopens the invoice.
     * For legitimately returning money to a customer, use a Refund instead.
     */
    public function voidPayment(Request $request, Payment $payment)
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        try {
            $this->billing->voidPayment($payment, auth()->id(), $data['reason']);
            return back()->with('success', 'تم إلغاء الدفعة وعكس قيدها.');
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

            return $this->redirectAfterInvoiceAction(
                $request,
                'تم شطب الفاتورة',
                fn () => back()->with('success', 'تم شطب الفاتورة'),
            );
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
            $message = 'تم تأجيل المتبقي كدين على الزبون. أُغلقت الجلسة.';

            return $this->redirectAfterInvoiceAction(
                $request,
                $message,
                fn () => redirect()->route('admin.cashier.index')->with('success', $message),
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reverse a settle-on-account — lift the parked-debt flag so the
     * invoice goes back to being a live checkout (wrong customer picked,
     * accidental park, diner came back to the till two minutes later).
     * All the guards (not parked / already collected / closed statuses)
     * live in BillingService::unparkSettleOnAccount; whatever Arabic
     * error it throws is flashed as-is. Same ability as settle-on-account
     * — parking and un-parking are two sides of the same drawer decision.
     */
    public function unpark(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        try {
            $this->billing->unparkSettleOnAccount($invoice, auth()->id());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->redirectAfterInvoiceAction(
            $request,
            'تم إلغاء تأجيل الدين — حصّل المتبقي من هنا.',
            function () use ($invoice) {
                // Land the cashier on the invoice's own checkout, not back on the
                // debts page. Un-parking clears settled_on_account_at, so the invoice
                // drops off the debt ledger; its dine-in session is already closed,
                // so it's off the active-sessions dashboard too. The merged
                // dashboard renders any session (closed included) with its invoice
                // via ?session=ID, so the restored balance stays collectable.
                // Session-less invoices fall back to back().
                if ($invoice->table_session_id) {
                    return redirect()
                        ->route('admin.cashier.index', ['session' => $invoice->table_session_id])
                        ->with('success', 'تم إلغاء تأجيل الدين — حصّل المتبقي من هنا.');
                }

                return back()->with('success', 'تم إلغاء تأجيل الدين — عادت الفاتورة إلى التحصيل العادي.');
            },
        );
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate(['reason' => ['required', 'string']]);
        try {
            $this->billing->cancelInvoice($invoice, auth()->id(), $data['reason']);

            return $this->redirectAfterInvoiceAction(
                $request,
                'تم إلغاء الفاتورة',
                fn () => back()->with('success', 'تم إلغاء الفاتورة'),
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function pdf(Invoice $invoice)
    {
        // Same ability as the cashier show page — a receipt carries the
        // customer's name/phone and the full money trail, so it must not
        // be an unguarded GET for anyone who guesses an invoice id.
        $this->authorize('viewAny', Payment::class);
        $invoice->load(['tableSession.table', 'tableSession.orders.items.modifiers', 'order.items.modifiers', 'payments']);
        $pdf = Pdf::loadView('admin.cashier.invoice-pdf', compact('invoice'));
        return $pdf->download($invoice->number.'.pdf');
    }

    public function print(Invoice $invoice)
    {
        // Mirrors pdf() — see the WHY there.
        $this->authorize('viewAny', Payment::class);
        $invoice->load(['tableSession.table', 'tableSession.orders.items.modifiers', 'order.items.modifiers', 'payments']);
        return view('admin.cashier.invoice-print', compact('invoice'));
    }

    /**
     * Create OR replace the invoice's splits. «تعديل التقسيم» re-posts
     * through this same action — BillingService::splitInvoice clears and
     * recreates the rows atomically, which is exactly the regroup we want
     * while nothing is paid yet.
     */
    public function split(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate([
            'splits' => ['required', 'array', 'min:2'],
            'splits.*.label' => ['nullable', 'string', 'max:255'],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'splits.*.method' => ['required', \App\Support\PaymentMethods::inRule()],
        ]);

        // Regroup guard: a paid split is anchored to a committed payment,
        // so replacing it is refund territory, not an edit. The service
        // would also refuse (any payment blocks splitInvoice), but we make
        // it a 422 with a precise message here so the modal's error isn't
        // the generic "invoice has payments" one.
        if ($invoice->splits()->where('paid', true)->exists()) {
            throw ValidationException::withMessages([
                'splits' => 'لا يمكن تعديل التقسيم بعد دفع أحد الأجزاء — ألغِ دفعة الجزء أولاً (عبر إلغاء الدفعة) أو أكمل تحصيل بقية الأجزاء.',
            ]);
        }

        try {
            $this->billing->splitInvoice($invoice, $data['splits']);

            return $this->redirectAfterInvoiceAction(
                $request,
                'تم تقسيم الفاتورة',
                fn () => back()->with('success', 'تم تقسيم الفاتورة'),
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function paySplit(Request $request, Invoice $invoice, \App\Models\InvoiceSplit $split)
    {
        $this->authorize('create', Payment::class);
        abort_unless($split->invoice_id === $invoice->id, 404);
        // Optional per-split reference (card slip / bank transfer number) —
        // flows into payments.reference like the single-payment form.
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:255']]);
        try {
            $this->billing->paySplit($split, auth()->id(), $data['reference'] ?? null);

            return $this->redirectAfterInvoiceAction(
                $request,
                "تم دفع جزء {$split->label}",
                fn () => back()->with('success', "تم دفع جزء {$split->label}"),
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function clearSplits(Request $request, Invoice $invoice)
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

        return $this->redirectAfterInvoiceAction(
            $request,
            'تم إلغاء التقسيم',
            fn () => back()->with('success', 'تم إلغاء التقسيم'),
        );
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

    /**
     * Where to land the cashier after a deliberate money action (settle /
     * unpark / cancel / write-off).
     *
     * The live Volt dashboard posts these classic forms with a hidden
     * `return_session` (a TableSession id). When it's present AND resolves to
     * a real session, bounce back to the ONE merged screen with that session's
     * checkout pre-selected (?session=ID) — the route is rebuilt server-side
     * from the integer id ALONE, so a raw/forged URL in the form is never
     * trusted or followed.
     *
     * With no (or an unknown) `return_session` — the classic show page keeps
     * POSTing these same forms — the caller's own redirect stands via
     * `$fallback`, so the old page keeps working exactly as before.
     */
    protected function redirectAfterInvoiceAction(Request $request, string $successMessage, \Closure $fallback): \Illuminate\Http\RedirectResponse
    {
        $sessionId = $request->integer('return_session');

        if ($sessionId > 0 && TableSession::whereKey($sessionId)->exists()) {
            return redirect()
                ->route('admin.cashier.index', ['session' => $sessionId])
                ->with('success', $successMessage);
        }

        return $fallback();
    }
}
