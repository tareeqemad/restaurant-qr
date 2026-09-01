<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\DebtWriteoff;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Scopes\BranchScope;
use App\Services\BillingService;
use App\Services\CreditNoteService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\CollectionWorkspace;
use App\Support\PaymentMethods;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerDebtController extends Controller
{
    public function __construct(
        protected BillingService $billing,
        protected CreditNoteService $creditNotes,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Payment::class);

        // Debt is global per customer, so branch scope must not understate it.
        $aggQuery = Invoice::query()
            ->withoutGlobalScope(BranchScope::class)
            ->select([
                'customer_id',
                DB::raw('SUM(balance) as debt_total'),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('MIN(COALESCE(due_date, DATE(settled_on_account_at))) as oldest_due_at'),
                DB::raw('MAX(settled_on_account_at) as newest_settled_at'),
            ])
            ->whereNotNull('settled_on_account_at')
            ->whereNotNull('customer_id')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->groupBy('customer_id')
            ->orderByDesc('debt_total');

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $matchingIds = Customer::query()
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                ->pluck('id');
            $aggQuery->whereIn('customer_id', $matchingIds);
        }

        $rows = $aggQuery->paginate(25)->withQueryString();
        $customers = Customer::whereIn('id', $rows->getCollection()->pluck('customer_id'))
            ->get()
            ->keyBy('id');
        $canCollect = auth()->user()->can('create', Payment::class);

        $rows->through(function ($row) use ($customers, $canCollect) {
            $customer = $customers->get($row->customer_id);
            if (! $customer) {
                return null;
            }

            $debt = (float) $row->debt_total;
            $limit = $customer->credit_limit !== null ? (float) $customer->credit_limit : null;
            $oldest = Carbon::parse($row->oldest_due_at);
            $newest = Carbon::parse($row->newest_settled_at);

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'invoiceCount' => (int) $row->invoice_count,
                'debt' => $debt,
                'debtFormatted' => Money::format($debt),
                'advanceBalance' => (float) $customer->advance_balance,
                'advanceBalanceFormatted' => Money::format((float) $customer->advance_balance),
                'oldest' => [
                    'date' => $oldest->format('Y-m-d'),
                    'human' => $oldest->diffForHumans(),
                    'days' => (int) $oldest->diffInDays(now()),
                ],
                'newest' => [
                    'date' => $newest->format('Y-m-d'),
                    'human' => $newest->diffForHumans(),
                ],
                'credit' => [
                    'limit' => $limit,
                    'limitFormatted' => $limit !== null ? Money::format($limit) : null,
                    'available' => $limit !== null ? max(0, $limit - $debt) : null,
                    'overLimit' => $limit !== null && $debt > $limit + 0.01,
                ],
                'canCollect' => $canCollect,
                'urls' => [
                    'show' => route('admin.customers.debts.show', $customer),
                    'payment' => route('admin.customers.debts.payment', $customer),
                ],
            ];
        });
        $rows->setCollection($rows->getCollection()->filter()->values());

        $totals = Invoice::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereNotNull('settled_on_account_at')
            ->whereNotNull('customer_id')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->selectRaw('COUNT(DISTINCT customer_id) as customers_owing, COUNT(*) as open_invoices, COALESCE(SUM(balance), 0) as total_debt')
            ->first();

        return AdminShell::render('Admin/CustomerDebts/Index', [
            'debts' => $rows,
            'stats' => [
                'customersOwing' => (int) ($totals->customers_owing ?? 0),
                'openInvoices' => (int) ($totals->open_invoices ?? 0),
                'totalDebt' => (float) ($totals->total_debt ?? 0),
                'totalDebtFormatted' => Money::format((float) ($totals->total_debt ?? 0)),
            ],
            'filters' => ['search' => $search],
            'paymentMethods' => $this->paymentMethods(includeCustomerAdvance: true),
            'collectionNav' => CollectionWorkspace::navigation(),
            'urls' => [
                'index' => route('admin.customers.debts.index'),
                'cashier' => route('admin.cashier.index'),
            ],
        ]);
    }

    public function show(Customer $customer)
    {
        $this->authorize('viewAny', Payment::class);

        // A customer's debt is global across branches. Load the complete
        // invoice set once, then derive the open balance and immutable
        // timeline from explicit actor columns + the audit log.
        $customerInvoices = $customer->invoices()
            ->withoutGlobalScope(BranchScope::class)
            ->with([
                'branch:id,name',
                'settledOnAccountBy:id,name',
            ])
            ->orderByDesc('issued_at')
            ->get();
        $invoiceIds = $customerInvoices->pluck('id')->map(fn ($id) => (int) $id)->all();
        $invoiceMap = $customerInvoices->keyBy('id');

        $auditEvents = ActivityLog::query()
            ->with('causer:id,name')
            ->where(function ($query) use ($customer, $invoiceIds) {
                $query->where(function ($customerQuery) use ($customer) {
                    $customerQuery->where('subject_type', Customer::class)
                        ->where('subject_id', $customer->id)
                        ->whereIn('event', ['customer.credit_limit_changed']);
                });

                if ($invoiceIds !== []) {
                    $query->orWhere(function ($invoiceQuery) use ($invoiceIds) {
                        $invoiceQuery->where('subject_type', Invoice::class)
                            ->whereIn('subject_id', $invoiceIds)
                            ->whereIn('event', [
                                'invoice.settled_on_account',
                                'invoice.unparked_on_account',
                                'invoice.writeoff',
                            ]);
                    });
                }
            })
            ->latest('created_at')
            ->limit(200)
            ->get();

        $openingEvents = $auditEvents->where('event', 'invoice.settled_on_account');
        $debtInvoiceIds = $customerInvoices
            ->whereNotNull('settled_on_account_at')
            ->pluck('id')
            ->merge($openingEvents->pluck('subject_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $settledAtByInvoice = $customerInvoices
            ->whereNotNull('settled_on_account_at')
            ->mapWithKeys(fn (Invoice $invoice) => [
                $invoice->id => $invoice->settled_on_account_at,
            ]);
        foreach ($openingEvents->sortBy('created_at') as $event) {
            $settledAtByInvoice[(int) $event->subject_id] = $event->created_at;
        }

        // Only payments made AFTER the invoice entered the debt ledger are
        // debt collections. A down-payment made before parking must never be
        // shown as if it were a later collection.
        $debtPayments = Payment::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('invoice_id', $debtInvoiceIds)
            ->with([
                'invoice:id,number,customer_id,settled_on_account_at',
                'branch:id,name',
                'receiver:id,name',
            ])
            ->latest('paid_at')
            ->limit(200)
            ->get()
            ->filter(function (Payment $payment) use ($settledAtByInvoice) {
                $settledAt = $settledAtByInvoice->get((int) $payment->invoice_id);

                return $settledAt && $payment->paid_at?->greaterThanOrEqualTo($settledAt);
            })
            ->take(100);

        $debtRefunds = Refund::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('invoice_id', $debtInvoiceIds)
            ->where('status', 'completed')
            ->with([
                'invoice:id,number,customer_id,settled_on_account_at',
                'branch:id,name',
                'processor:id,name',
            ])
            ->latest('refunded_at')
            ->limit(100)
            ->get()
            ->filter(function (Refund $refund) use ($settledAtByInvoice) {
                $settledAt = $settledAtByInvoice->get((int) $refund->invoice_id);

                return $settledAt && $refund->refunded_at?->greaterThanOrEqualTo($settledAt);
            });

        $debtCreditNotes = CreditNote::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('invoice_id', $debtInvoiceIds)
            ->where('kind', 'debt_adjustment')
            ->with(['invoice:id,number,customer_id', 'branch:id,name', 'issuer:id,name', 'reverser:id,name'])
            ->latest('issued_at')
            ->limit(100)
            ->get();
        $debtWriteoffs = DebtWriteoff::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('invoice_id', $debtInvoiceIds)
            ->with(['invoice:id,number,customer_id', 'writer:id,name', 'reverser:id,name'])
            ->latest('written_off_at')
            ->limit(100)
            ->get();

        $timeline = collect();
        foreach ($auditEvents as $event) {
            $invoice = $event->subject_type === Invoice::class
                ? $invoiceMap->get((int) $event->subject_id)
                : null;
            $properties = $event->properties ?? [];
            // New write-offs have their own immutable document below. Keep
            // the legacy audit-only event only for records created before it.
            if ($event->event === 'invoice.writeoff' && ! empty($properties['writeoff_id'])) {
                continue;
            }
            $eventData = match ($event->event) {
                'invoice.settled_on_account' => [
                    'type' => 'debt_opened',
                    'title' => 'تسجيل رصيد كدين',
                    'description' => 'أصبح المتبقي على الفاتورة جزءاً من دفتر الزبون.',
                    'amount' => $properties['balance_carried'] ?? null,
                    'tone' => 'danger',
                    'icon' => 'bi-journal-plus',
                ],
                'invoice.unparked_on_account' => [
                    'type' => 'debt_unparked',
                    'title' => 'إلغاء تسجيل الدين',
                    'description' => 'عادت الفاتورة إلى التحصيل المباشر من دون حذف أثرها السابق.',
                    'amount' => $properties['balance_restored'] ?? null,
                    'tone' => 'warning',
                    'icon' => 'bi-arrow-counterclockwise',
                ],
                'invoice.writeoff' => [
                    'type' => 'debt_written_off',
                    'title' => 'شطب دين غير قابل للتحصيل',
                    'description' => $properties['reason'] ?? $event->description,
                    'amount' => $properties['amount'] ?? null,
                    'tone' => 'dark',
                    'icon' => 'bi-slash-circle',
                ],
                'customer.credit_limit_changed' => [
                    'type' => 'credit_limit_changed',
                    'title' => 'تعديل الحد الائتماني',
                    'description' => 'من '.($properties['previous'] !== null ? Money::format((float) $properties['previous']) : 'بدون حد')
                        .' إلى '.($properties['new'] !== null ? Money::format((float) $properties['new']) : 'بدون حد'),
                    'amount' => null,
                    'tone' => 'primary',
                    'icon' => 'bi-speedometer2',
                ],
                default => null,
            };

            if (! $eventData) {
                continue;
            }

            $timeline->push([
                'key' => 'audit-'.$event->id,
                ...$eventData,
                'amountFormatted' => $eventData['amount'] !== null ? Money::format((float) $eventData['amount']) : null,
                'invoiceNumber' => $invoice?->number,
                'branchName' => $invoice?->branch?->name ?? ($properties['branch_name'] ?? null),
                'performedBy' => $event->causer?->name ?? 'النظام / ترحيل سابق',
                'occurredAt' => $event->created_at?->format('Y-m-d H:i'),
                'reference' => null,
                'notes' => null,
                'sortAt' => $event->created_at?->getTimestamp() ?? 0,
            ]);
        }

        // Opening-balance and legacy invoices may predate ActivityLog. Their
        // explicit actor/timestamp columns still yield a trustworthy origin.
        $loggedOpeningInvoiceIds = $openingEvents->pluck('subject_id')->map(fn ($id) => (int) $id);
        foreach ($customerInvoices->whereNotNull('settled_on_account_at') as $invoice) {
            if ($loggedOpeningInvoiceIds->contains((int) $invoice->id)) {
                continue;
            }
            $collectedAfterOpening = (float) $debtPayments
                ->where('invoice_id', $invoice->id)
                ->sum('amount');
            $refundedAfterOpening = (float) $debtRefunds
                ->where('invoice_id', $invoice->id)
                ->sum('amount');
            $openingAmount = Money::round(max(
                0,
                (float) $invoice->balance + $collectedAfterOpening - $refundedAfterOpening
                    + (float) $debtCreditNotes->where('invoice_id', $invoice->id)->where('status', 'posted')->sum('total')
                    + (float) $debtWriteoffs->where('invoice_id', $invoice->id)->where('status', 'posted')->sum('amount'),
            ));
            $timeline->push([
                'key' => 'invoice-open-'.$invoice->id,
                'type' => 'debt_opened',
                'title' => $invoice->is_opening_balance ? 'رصيد دين افتتاحي' : 'تسجيل رصيد كدين',
                'description' => $invoice->is_opening_balance
                    ? 'رصيد مرحّل عند بدء استخدام النظام.'
                    : 'أصبح المتبقي على الفاتورة جزءاً من دفتر الزبون.',
                'amount' => $openingAmount,
                'amountFormatted' => Money::format($openingAmount),
                'tone' => 'danger',
                'icon' => 'bi-journal-plus',
                'invoiceNumber' => $invoice->number,
                'branchName' => $invoice->branch?->name,
                'performedBy' => $invoice->settledOnAccountBy?->name ?? 'النظام / ترحيل سابق',
                'occurredAt' => $invoice->settled_on_account_at?->format('Y-m-d H:i'),
                'reference' => null,
                'notes' => null,
                'sortAt' => $invoice->settled_on_account_at?->getTimestamp() ?? 0,
            ]);
        }

        foreach ($debtPayments as $payment) {
            $timeline->push([
                'key' => 'payment-'.$payment->id,
                'type' => 'payment',
                'title' => 'تحصيل دفعة دين',
                'description' => 'خُصصت الدفعة لهذه الفاتورة وفق ترتيب الأقدم فالأحدث.',
                'amount' => (float) $payment->amount,
                'amountFormatted' => Money::format((float) $payment->amount),
                'tone' => 'success',
                'icon' => 'bi-cash-coin',
                'invoiceNumber' => $payment->invoice?->number,
                'branchName' => $payment->branch?->name,
                'performedBy' => $payment->receiver?->name ?? 'النظام / ترحيل سابق',
                'occurredAt' => $payment->paid_at?->format('Y-m-d H:i'),
                'method' => PaymentMethods::label($payment->method),
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'sortAt' => $payment->paid_at?->getTimestamp() ?? 0,
            ]);
        }

        foreach ($debtRefunds as $refund) {
            $timeline->push([
                'key' => 'refund-'.$refund->id,
                'type' => 'refund',
                'title' => 'استرداد أعاد مبلغاً للمستحق',
                'description' => $refund->reason ?: 'استرداد مكتمل على فاتورة دخلت دفتر الدين.',
                'amount' => (float) $refund->amount,
                'amountFormatted' => Money::format((float) $refund->amount),
                'tone' => 'warning',
                'icon' => 'bi-arrow-return-left',
                'invoiceNumber' => $refund->invoice?->number,
                'branchName' => $refund->branch?->name,
                'performedBy' => $refund->processor?->name ?? 'النظام / ترحيل سابق',
                'occurredAt' => $refund->refunded_at?->format('Y-m-d H:i'),
                'method' => $refund->methodLabel(),
                'reference' => $refund->reference,
                'notes' => $refund->notes,
                'sortAt' => $refund->refunded_at?->getTimestamp() ?? 0,
            ]);
        }

        foreach ($debtCreditNotes as $note) {
            $timeline->push([
                'key' => 'credit-note-'.$note->id,
                'type' => 'credit_note',
                'title' => $note->status === 'reversed' ? 'عكس تخفيض دين' : 'تخفيض دين بإشعار دائن',
                'description' => $note->status === 'reversed' ? ($note->reversal_reason ?: 'تم عكس الإشعار الدائن.') : $note->reason,
                'amount' => (float) $note->total,
                'amountFormatted' => Money::format((float) $note->total),
                'tone' => $note->status === 'reversed' ? 'warning' : 'primary',
                'icon' => $note->status === 'reversed' ? 'bi-arrow-counterclockwise' : 'bi-file-earmark-minus',
                'invoiceNumber' => $note->invoice?->number,
                'branchName' => $note->branch?->name,
                'performedBy' => $note->status === 'reversed' ? ($note->reverser?->name ?? 'النظام') : ($note->issuer?->name ?? 'النظام'),
                'occurredAt' => ($note->status === 'reversed' ? $note->reversed_at : $note->issued_at)?->format('Y-m-d H:i'),
                'reference' => $note->number,
                'notes' => $note->notes,
                'reverseUrl' => $note->status === 'posted' ? route('admin.customers.debts.credit_notes.reverse', $note) : null,
                'sortAt' => ($note->status === 'reversed' ? $note->reversed_at : $note->issued_at)?->getTimestamp() ?? 0,
            ]);
        }
        foreach ($debtWriteoffs as $writeoff) {
            $timeline->push([
                'key' => 'writeoff-'.$writeoff->id,
                'type' => 'writeoff',
                'title' => $writeoff->status === 'reversed' ? 'عكس شطب دين' : 'شطب دين',
                'description' => $writeoff->status === 'reversed' ? ($writeoff->reversal_reason ?: 'تم عكس الشطب.') : $writeoff->reason,
                'amount' => (float) $writeoff->amount,
                'amountFormatted' => Money::format((float) $writeoff->amount),
                'tone' => $writeoff->status === 'reversed' ? 'warning' : 'dark',
                'icon' => $writeoff->status === 'reversed' ? 'bi-arrow-counterclockwise' : 'bi-slash-circle',
                'invoiceNumber' => $writeoff->invoice?->number,
                'branchName' => null,
                'performedBy' => $writeoff->status === 'reversed'
                    ? ($writeoff->reverser?->name ?? 'النظام')
                    : ($writeoff->writer?->name ?? 'النظام'),
                'occurredAt' => ($writeoff->status === 'reversed' ? $writeoff->reversed_at : $writeoff->written_off_at)?->format('Y-m-d H:i'),
                'reference' => $writeoff->number,
                'notes' => $writeoff->notes,
                'reverseUrl' => $writeoff->status === 'posted' ? route('admin.customers.debts.writeoffs.reverse', $writeoff) : null,
                'sortAt' => ($writeoff->status === 'reversed' ? $writeoff->reversed_at : $writeoff->written_off_at)?->getTimestamp() ?? 0,
            ]);
        }

        $timeline = $timeline
            ->sortByDesc('sortAt')
            ->take(150)
            ->map(fn (array $event) => collect($event)->except(['sortAt', 'amount'])->all())
            ->values();

        $openInvoices = $customerInvoices
            ->whereNotNull('settled_on_account_at')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->sortBy('settled_on_account_at')
            ->values();

        $outstanding = (float) $customer->outstandingDebt();
        $creditAvailable = $customer->creditAvailable();
        $canCollect = auth()->user()->can('create', Payment::class);

        return AdminShell::render('Admin/CustomerDebts/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'advanceBalance' => (float) $customer->advance_balance,
                'advanceBalanceFormatted' => Money::format((float) $customer->advance_balance),
                'creditLimit' => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
                'creditLimitFormatted' => $customer->credit_limit !== null ? Money::format($customer->credit_limit) : null,
                'creditAvailable' => $creditAvailable !== null ? (float) $creditAvailable : null,
                'creditAvailableFormatted' => $creditAvailable !== null ? Money::format($creditAvailable) : null,
            ],
            'stats' => [
                'outstanding' => $outstanding,
                'outstandingFormatted' => Money::format($outstanding),
                'openInvoices' => $openInvoices->count(),
            ],
            'openInvoices' => $openInvoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'totalFormatted' => Money::format($invoice->total),
                'paidFormatted' => Money::format($invoice->paid_total),
                'balance' => (float) $invoice->balance,
                'balanceFormatted' => Money::format($invoice->balance),
                'settledAt' => $invoice->settled_on_account_at?->format('Y-m-d H:i'),
                'dueDate' => $invoice->due_date?->toDateString(),
                'overdueDays' => $invoice->due_date && $invoice->due_date->lt(now()->startOfDay())
                    ? (int) $invoice->due_date->diffInDays(now()->startOfDay()) : 0,
                'creditedFormatted' => Money::format((float) $invoice->credited_total),
                'writtenOffFormatted' => Money::format((float) $invoice->written_off_total),
                'registeredBy' => $invoice->settledOnAccountBy?->name ?? 'النظام / ترحيل سابق',
                'branchName' => $invoice->branch?->name,
                'canUnpark' => auth()->user()->hasPermission('payments.settle_on_account'),
                'unparkUrl' => route('admin.cashier.unpark', $invoice),
            ])->values(),
            'timeline' => $timeline,
            'paymentMethods' => $this->paymentMethods(includeCustomerAdvance: true),
            'can' => [
                'collect' => $canCollect,
                'updateCreditLimit' => auth()->user()->can('manageCredit', $customer),
                'adjust' => auth()->user()->hasPermission('payments.refund'),
                'writeoff' => auth()->user()->hasPermission('payments.writeoff'),
                'reverse' => auth()->user()->hasPermission('payments.writeoff') || auth()->user()->hasPermission('payments.refund'),
            ],
            'collectionNav' => CollectionWorkspace::navigation(),
            'urls' => [
                'index' => route('admin.customers.debts.index'),
                'payment' => route('admin.customers.debts.payment', $customer),
                'creditLimit' => route('admin.customers.debts.credit_limit', $customer),
                'adjustment' => route('admin.customers.debts.adjustment', $customer),
                'writeoff' => route('admin.customers.debts.writeoff', $customer),
            ],
        ]);
    }

    public function adjustDebt(Request $request, Customer $customer)
    {
        abort_unless(auth()->user()?->hasPermission('payments.refund'), 403);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason_type' => ['required', Rule::in(['returned_item', 'billing_correction', 'settlement_discount', 'goodwill'])],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $invoice = Invoice::withoutGlobalScope(BranchScope::class)
            ->whereKey($data['invoice_id'])->where('customer_id', $customer->id)->firstOrFail();

        try {
            $note = $this->creditNotes->issue(
                $invoice, (float) $data['amount'], 'debt_adjustment', $data['reason'], auth()->id(),
                ['notes' => $data['notes'] ?? null, 'metadata' => ['reason_type' => $data['reason_type']]],
            );

            return back()->with('success', "تم تخفيض الدين بإشعار دائن {$note->number}.");
        } catch (\Throwable $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()]);
        }
    }

    public function writeoffDebt(Request $request, Customer $customer)
    {
        abort_unless(auth()->user()?->hasPermission('payments.writeoff'), 403);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $invoice = Invoice::withoutGlobalScope(BranchScope::class)
            ->whereKey($data['invoice_id'])->where('customer_id', $customer->id)->firstOrFail();
        try {
            $this->billing->writeOffInvoice($invoice, auth()->id(), $data['reason'], (float) $data['amount']);

            return back()->with('success', 'تم تسجيل الشطب الجزئي/الكامل مع قيده المحاسبي.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()]);
        }
    }

    public function reverseCreditNote(Request $request, CreditNote $creditNote)
    {
        abort_unless(auth()->user()?->hasPermission('payments.refund'), 403);
        abort_unless($creditNote->kind === 'debt_adjustment', 422, 'إشعار الاسترداد يُعكس من سجل الاستردادات حتى يبقى صرف المال متطابقاً معه.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->creditNotes->reverse($creditNote, auth()->id(), $data['reason']);

        return back()->with('success', 'تم عكس الإشعار الدائن وإعادة رصيد الدين.');
    }

    public function reverseWriteoff(Request $request, DebtWriteoff $writeoff)
    {
        abort_unless(auth()->user()?->hasPermission('payments.writeoff'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->billing->reverseWriteoff($writeoff, auth()->id(), $data['reason']);

        return back()->with('success', 'تم عكس الشطب وإعادة الذمة للفاتورة.');
    }

    public function recordPayment(Request $request, Customer $customer)
    {
        $this->authorize('create', Payment::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in([...PaymentMethods::enabled(), PaymentMethods::CUSTOMER_ADVANCE])],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'primary_invoice_id' => ['nullable', 'integer'],
        ], [
            'method.in' => 'طريقة الدفع غير مفعّلة في إعدادات المطعم.',
        ]);

        if ($data['method'] === PaymentMethods::CUSTOMER_ADVANCE
            && (float) $data['amount'] - (float) $customer->advance_balance > 0.001) {
            return back()->withErrors([
                'amount' => 'المبلغ أكبر من رصيد الزبون المقدم المتاح ('.Money::format((float) $customer->advance_balance).').',
            ]);
        }

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
                customer: $customer,
                amount: (float) $data['amount'],
                method: $data['method'],
                userId: auth()->id(),
                primaryInvoice: $primary,
                reference: $data['reference'] ?? null,
                notes: $data['notes'] ?? null,
            );

            return back()->with('success', 'سُجِّلت دفعة موزّعة على '.count($allocations).' فاتورة. الرصيد الحالي: '.Money::format($customer->refresh()->outstandingDebt()));
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function quickLookup(Request $request)
    {
        $this->authorize('create', Payment::class);

        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $customer = Customer::findByPhone($data['phone']);
        if (! $customer) {
            return response()->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'لم نجد زبوناً بهذا الرقم. سجّله من شاشة العملاء أولاً.',
            ], 404);
        }

        $openInvoices = $customer->invoices()
            ->withoutGlobalScope(BranchScope::class)
            ->whereNotNull('settled_on_account_at')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->orderByRaw('COALESCE(due_date, DATE(settled_on_account_at))')
            ->get(['id', 'number', 'balance', 'settled_on_account_at', 'due_date']);

        return response()->json([
            'ok' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'advance_balance' => (float) $customer->advance_balance,
            ],
            'outstanding' => $customer->outstandingDebt(),
            'credit_limit' => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
            'open_invoices' => $openInvoices->map(fn ($invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'balance' => (float) $invoice->balance,
                'due_date' => $invoice->due_date?->toDateString(),
                'overdue_days' => $invoice->due_date && $invoice->due_date->lt(now()->startOfDay())
                    ? (int) $invoice->due_date->diffInDays(now()->startOfDay()) : 0,
            ])->values(),
            'pay_url' => route('admin.customers.debts.payment', $customer),
        ]);
    }

    public function updateCreditLimit(Request $request, Customer $customer)
    {
        $this->authorize('manageCredit', $customer);

        $data = $request->validate([
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $previous = $customer->credit_limit;
        $customer->update(['credit_limit' => $data['credit_limit'] ?? null]);

        ActivityLog::log(
            'customer.credit_limit_changed',
            "تعديل الحد الائتماني للزبون {$customer->name}",
            $customer,
            [
                'previous' => $previous,
                'new' => $customer->credit_limit,
                'branch_id' => BranchContext::current(),
                'branch_name' => auth()->user()->currentBranch()?->name,
            ],
        );

        return back()->with('success', 'تم تحديث الحد الائتماني');
    }

    private function paymentMethods(bool $includeCustomerAdvance = false): array
    {
        $methods = PaymentMethods::enabled();
        if ($includeCustomerAdvance) {
            $methods[] = PaymentMethods::CUSTOMER_ADVANCE;
        }

        return collect($methods)
            ->map(fn (string $code) => ['value' => $code, 'label' => PaymentMethods::label($code)])
            ->values()
            ->all();
    }
}
