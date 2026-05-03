<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TableSession;
use App\Services\BillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

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
        return view('admin.cashier.show', compact('session'));
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
            'method' => ['required', 'in:cash,card,transfer,app,credit'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);
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
        $this->billing->writeOffInvoice($invoice, auth()->id(), $data['reason']);
        return back()->with('success', 'تم شطب الفاتورة');
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
            'splits.*.method' => ['required', 'in:cash,card,transfer,app,credit'],
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
        if ($invoice->payments()->exists()) {
            return back()->with('error', 'لا يمكن إزالة التقسيم بعد تسجيل دفعات');
        }
        $invoice->splits()->delete();
        return back()->with('success', 'تم إلغاء التقسيم');
    }
}
