<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer-debt ledger.
 *
 * The ledger has no dedicated table — every open debt IS an unpaid
 * invoice with `settled_on_account_at` set. This controller is the
 * read/write surface around that idea:
 *
 *  - index(): the cashier-facing "who owes us money?" board
 *  - show(): per-customer detail with open invoices + payment history
 *  - recordPayment(): take cash now, allocate across debts FIFO + (optional)
 *    current visit invoice via BillingService::payCustomerDebt
 *  - updateCreditLimit(): manager-only adjustment of a customer's ceiling
 */
class CustomerDebtController extends Controller
{
    public function __construct(protected BillingService $billing) {}

    /**
     * Top-level debt board — one row per customer with an open balance,
     * sorted by total descending so the largest debts surface first. The
     * cashier scans this page to know who to chase before the table fills
     * up.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Customer::class);

        // Build the aggregation in SQL — avoids loading every invoice
        // into PHP just to sum them. Group by customer, then re-attach
        // customer details via a single IN query so the view has access
        // to the name + credit_limit without an N+1.
        $aggQuery = Invoice::query()
            ->select([
                'customer_id',
                DB::raw('SUM(balance) as debt_total'),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('MIN(settled_on_account_at) as oldest_settled_at'),
                DB::raw('MAX(settled_on_account_at) as newest_settled_at'),
            ])
            ->whereNotNull('settled_on_account_at')
            ->whereNotNull('customer_id')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->groupBy('customer_id')
            ->orderByDesc('debt_total');

        if ($search = trim((string) $request->get('search'))) {
            $matchingIds = Customer::query()
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                })
                ->pluck('id');
            $aggQuery->whereIn('customer_id', $matchingIds);
        }

        $rows = $aggQuery->paginate(25)->withQueryString();

        $customers = Customer::whereIn('id', $rows->pluck('customer_id'))
            ->get()->keyBy('id');

        // Headline numbers for the stat rail
        $totals = Invoice::query()
            ->whereNotNull('settled_on_account_at')
            ->whereNotNull('customer_id')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->selectRaw('
                COUNT(DISTINCT customer_id) as customers_owing,
                COUNT(*) as open_invoices,
                COALESCE(SUM(balance), 0) as total_debt
            ')
            ->first();

        return view('admin.customers.debts.index', [
            'rows'      => $rows,
            'customers' => $customers,
            'stats'     => [
                'customers_owing' => (int) ($totals->customers_owing ?? 0),
                'open_invoices'   => (int) ($totals->open_invoices ?? 0),
                'total_debt'      => (float) ($totals->total_debt ?? 0),
            ],
            'search'    => $search ?? '',
        ]);
    }

    /**
     * Per-customer debt detail. Shows open invoices first (the actionable
     * list), then the recent payment history so the cashier can see
     * "they've been paying steadily" vs. "this is going nowhere".
     */
    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);

        $openInvoices = $customer->invoices()
            ->whereNotNull('settled_on_account_at')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->orderBy('settled_on_account_at')
            ->get();

        // Payment history limited to debt-related transactions — payments
        // received on invoices that ARE/WERE settled on account. Recent
        // first; capped to a digestible window.
        $debtPayments = \App\Models\Payment::query()
            ->whereHas('invoice', fn ($q) => $q
                ->where('customer_id', $customer->id)
                ->whereNotNull('settled_on_account_at'))
            ->with('invoice:id,number,total,balance,settled_on_account_at')
            ->latest('paid_at')
            ->limit(50)
            ->get();

        return view('admin.customers.debts.show', [
            'customer'      => $customer,
            'openInvoices'  => $openInvoices,
            'debtPayments'  => $debtPayments,
            'outstanding'   => $customer->outstandingDebt(),
            'creditAvail'   => $customer->creditAvailable(),
        ]);
    }

    /**
     * Record a payment from a customer toward their open debts (and
     * optionally a current visit invoice). The form posts: amount, method,
     * reference, notes, optional `primary_invoice_id` (current visit).
     *
     * The allocation algorithm lives in BillingService — see
     * `payCustomerDebt` for the FIFO rules.
     */
    public function recordPayment(Request $request, Customer $customer)
    {
        $this->authorize('create', \App\Models\Payment::class);

        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'method'    => ['required', 'in:cash,card,transfer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes'     => ['nullable', 'string', 'max:500'],
            'primary_invoice_id' => ['nullable', 'integer'],
        ], [
            'method.in' => 'طرق الدفع المتاحة: نقدا، فيزا، أو تحويل بنكي فقط.',
        ]);

        // Guard the primary invoice — must exist and belong to this same
        // customer. Without this check the form could siphon money toward
        // another customer's invoice.
        $primary = null;
        if (! empty($data['primary_invoice_id'])) {
            $primary = Invoice::where('id', $data['primary_invoice_id'])
                ->where('customer_id', $customer->id)
                ->first();
            if (! $primary) {
                return back()->with('error', 'الفاتورة المختارة غير موجودة أو لا تعود لهذا الزبون.');
            }
        }

        try {
            $allocations = $this->billing->payCustomerDebt(
                customer:       $customer,
                amount:         (float) $data['amount'],
                method:         $data['method'],
                userId:         auth()->id(),
                primaryInvoice: $primary,
                reference:      $data['reference'] ?? null,
                notes:          $data['notes'] ?? null,
            );

            $count = count($allocations);
            return back()->with('success', "سُجِّلت دفعة موزّعة على {$count} فاتورة. الرصيد الحالي: ".number_format($customer->refresh()->outstandingDebt(), 2));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Quick lookup for the cashier's "pay debt without a new invoice"
     * widget — phone in, debt snapshot out (JSON). Powers the inline
     * pay form on the cashier dashboard so the staffer doesn't have to
     * leave the screen they're working on.
     *
     * Returns 404 when the phone isn't on file so the UI can prompt
     * "register first" (less ambiguous than an empty 200).
     */
    public function quickLookup(Request $request)
    {
        $this->authorize('create', \App\Models\Payment::class);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $customer = Customer::findForLogin($data['phone']);
        if (! $customer) {
            return response()->json([
                'ok'      => false,
                'error'   => 'not_found',
                'message' => 'لم نجد زبوناً بهذا الرقم. سجّله من شاشة العملاء أولاً.',
            ], 404);
        }

        $openInvoices = $customer->invoices()
            ->whereNotNull('settled_on_account_at')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->orderBy('settled_on_account_at')
            ->get(['id', 'number', 'balance', 'settled_on_account_at']);

        return response()->json([
            'ok'       => true,
            'customer' => [
                'id'    => $customer->id,
                'name'  => $customer->name,
                'phone' => $customer->phone,
            ],
            'outstanding'    => $customer->outstandingDebt(),
            'credit_limit'   => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
            'open_invoices'  => $openInvoices->map(fn ($inv) => [
                'id'      => $inv->id,
                'number'  => $inv->number,
                'balance' => (float) $inv->balance,
                'age_days'=> (int) ($inv->settled_on_account_at?->diffInDays(now()) ?? 0),
            ])->values(),
            'pay_url' => route('admin.customers.debts.payment', $customer),
        ]);
    }

    /**
     * Manager updates the credit ceiling. Setting it to null/empty
     * removes the cap entirely (returning the customer to "no limit").
     */
    public function updateCreditLimit(Request $request, Customer $customer)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager']), 403);

        $data = $request->validate([
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $previous = $customer->credit_limit;
        $customer->update(['credit_limit' => $data['credit_limit'] ?? null]);

        \App\Models\ActivityLog::log(
            'customer.credit_limit_changed',
            "تعديل الحد الائتماني للزبون {$customer->name}",
            $customer,
            ['previous' => $previous, 'new' => $customer->credit_limit],
        );

        return back()->with('success', 'تم تحديث الحد الائتماني');
    }
}
