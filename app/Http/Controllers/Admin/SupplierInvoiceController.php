<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Money;
use App\Helpers\Qty;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\ExchangeRateService;
use App\Services\SupplierInvoiceService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SupplierInvoiceController extends Controller
{
    public function __construct(protected SupplierInvoiceService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SupplierInvoice::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'scope' => ['nullable', Rule::in([
                'all',
                'open',
                'overdue',
                'due_week',
                'this_month',
                'unpaid',
                'partially_paid',
                'paid',
                'cancelled',
            ])],
            'status' => ['nullable', Rule::in(['unpaid', 'partially_paid', 'paid', 'cancelled'])],
            'overdue' => ['nullable'],
            'date_field' => ['nullable', Rule::in(['invoice_date', 'due_date', 'created_at'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $filters['scope'] = $filters['scope']
            ?? ($request->filled('overdue') ? 'overdue' : ($filters['status'] ?? 'all'));
        $filters['date_field'] = $filters['date_field'] ?? 'invoice_date';

        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['from'] > $filters['to']) {
            [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
        }

        $q = SupplierInvoice::with(['supplier', 'purchaseOrder', 'branch'])
            ->orderByDesc($filters['date_field'])
            ->orderByDesc('id');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $q->where(function ($query) use ($search) {
                $query->where('number', 'like', "%{$search}%")
                    ->orWhereHas('purchaseOrder', fn ($po) => $po->where('number', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['supplier_id'])) {
            $q->where('supplier_id', $filters['supplier_id']);
        }

        match ($filters['scope']) {
            'open' => $q->whereNotIn('status', ['paid', 'cancelled']),
            'overdue' => $q->whereNotIn('status', ['paid', 'cancelled'])
                ->whereDate('due_date', '<', today()),
            'due_week' => $q->whereNotIn('status', ['paid', 'cancelled'])
                ->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()]),
            'this_month' => $q->whereMonth('invoice_date', today()->month)
                ->whereYear('invoice_date', today()->year)
                ->where('status', '!=', 'cancelled'),
            'unpaid', 'partially_paid', 'paid', 'cancelled' => $q->where('status', $filters['scope']),
            default => null,
        };

        $dateField = $filters['date_field'];
        if (! empty($filters['from'])) {
            $q->whereDate($dateField, '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate($dateField, '<=', $filters['to']);
        }

        $filteredQuery = clone $q;
        $invoices = $q->paginate(20)->withQueryString();

        $filteredStats = [
            'count' => (clone $filteredQuery)->count(),
            'total' => (float) (clone $filteredQuery)->sum(DB::raw('total * exchange_rate')),
            'paid' => (float) (clone $filteredQuery)->sum(DB::raw('paid_total * exchange_rate')),
            'balance' => (float) (clone $filteredQuery)->sum(DB::raw('balance * exchange_rate')),
            'overdue' => (clone $filteredQuery)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->whereDate('due_date', '<', today())
                ->count(),
        ];

        $stats = [
            'total_ap' => (float) SupplierInvoice::whereNotIn('status', ['cancelled'])->sum(DB::raw('balance * exchange_rate')),
            'overdue' => SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                ->whereDate('due_date', '<', now())->count(),
            'due_this_week' => SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                ->count(),
            'this_month' => (float) SupplierInvoice::whereMonth('invoice_date', now()->month)
                ->whereYear('invoice_date', now()->year)
                ->where('status', '!=', 'cancelled')
                ->sum(DB::raw('total * exchange_rate')),
        ];

        $user = auth()->user();
        $baseCurrencyCode = app(ExchangeRateService::class)->baseCurrencyCode();
        $showBranch = (bool) session('view_all_branches');

        $invoices->through(function (SupplierInvoice $invoice) use ($user, $baseCurrencyCode, $showBranch) {
            $currencyCode = $invoice->currency_code ?: $baseCurrencyCode;
            $isOpen = ! in_array($invoice->status, ['paid', 'cancelled'], true);
            $isOverdue = $invoice->isOverdue();
            $dueInDays = $isOpen && $invoice->due_date && ! $isOverdue
                ? (int) today()->diffInDays($invoice->due_date)
                : null;

            return [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'supplier' => $invoice->supplier?->name ?? 'بدون مورد',
                'purchaseOrder' => $invoice->purchaseOrder ? [
                    'number' => $invoice->purchaseOrder->number,
                    'url' => route('admin.purchase-orders.show', $invoice->purchaseOrder),
                ] : null,
                'currency' => $currencyCode,
                'amounts' => [
                    'total' => $invoice->formatMoney($invoice->total),
                    'paid' => $invoice->formatMoney($invoice->paid_total),
                    'balance' => $invoice->formatMoney($invoice->balance),
                    'rawBalance' => round((float) $invoice->balance, 2),
                    'baseBalance' => $currencyCode !== $baseCurrencyCode
                        ? Money::format((float) $invoice->balance * (float) ($invoice->exchange_rate ?: 1), $baseCurrencyCode)
                        : null,
                ],
                'invoiceDate' => $invoice->invoice_date?->format('Y-m-d'),
                'dueDate' => $invoice->due_date?->format('Y-m-d'),
                'overdue' => $isOverdue,
                'overdueDays' => $isOverdue ? (int) $invoice->due_date->diffInDays(today()) : null,
                'dueInDays' => $dueInDays,
                'status' => $invoice->status,
                'statusLabel' => $invoice->statusLabel(),
                'statusColor' => $invoice->statusColor(),
                'attachmentUrl' => $invoice->attachment_path ? Storage::url($invoice->attachment_path) : null,
                'branch' => $showBranch && $invoice->branch ? [
                    'name' => $invoice->branch->localizedName(),
                    'hue' => ($invoice->branch->id * 47) % 360,
                ] : null,
                'payment' => [
                    'needsExchangeRate' => $currencyCode !== $baseCurrencyCode,
                    'lastExchangeRate' => (float) ($invoice->exchange_rate ?: 1),
                ],
                'can' => [
                    'pay' => (bool) $user?->can('pay', $invoice),
                    'cancel' => (bool) $user?->can('cancel', $invoice),
                ],
                'urls' => [
                    'show' => route('admin.supplier-invoices.show', $invoice),
                    'pay' => route('admin.supplier-invoices.pay', $invoice),
                    'cancel' => route('admin.supplier-invoices.cancel', $invoice),
                ],
            ];
        });

        return AdminShell::render('Admin/SupplierInvoices/Index', [
            'invoices' => $invoices,
            'stats' => [
                'totalAp' => Money::format($stats['total_ap']),
                'overdue' => $stats['overdue'],
                'dueThisWeek' => $stats['due_this_week'],
                'thisMonth' => Money::format($stats['this_month']),
            ],
            'filteredStats' => [
                'count' => $filteredStats['count'],
                'total' => Money::format($filteredStats['total']),
                'paid' => Money::format($filteredStats['paid']),
                'balance' => Money::format($filteredStats['balance']),
                'rawBalance' => $filteredStats['balance'],
                'overdue' => $filteredStats['overdue'],
            ],
            'filters' => [
                'search' => (string) ($filters['search'] ?? ''),
                'supplierId' => (string) ($filters['supplier_id'] ?? ''),
                'scope' => (string) ($filters['scope'] ?? 'all'),
                'dateField' => (string) ($filters['date_field'] ?? 'invoice_date'),
                'from' => (string) ($filters['from'] ?? ''),
                'to' => (string) ($filters['to'] ?? ''),
            ],
            'suppliers' => Supplier::where('active', true)
                ->when(BranchContext::current(), fn ($q, $branchId) => $q->servingBranch((int) $branchId))
                ->orderBy('name')->get()->map(fn (Supplier $supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                ])->values(),
            'scopeOptions' => [
                ['value' => 'all', 'label' => 'كل الفواتير'],
                ['value' => 'open', 'label' => 'المفتوحة'],
                ['value' => 'overdue', 'label' => 'المتأخرة'],
                ['value' => 'due_week', 'label' => 'تستحق خلال 7 أيام'],
                ['value' => 'this_month', 'label' => 'هذا الشهر'],
                ['value' => 'unpaid', 'label' => 'غير مدفوعة'],
                ['value' => 'partially_paid', 'label' => 'مدفوعة جزئياً'],
                ['value' => 'paid', 'label' => 'مدفوعة'],
                ['value' => 'cancelled', 'label' => 'ملغاة'],
            ],
            'dateFieldOptions' => [
                ['value' => 'invoice_date', 'label' => 'تاريخ الفاتورة'],
                ['value' => 'due_date', 'label' => 'تاريخ الاستحقاق'],
                ['value' => 'created_at', 'label' => 'تاريخ الإدخال'],
            ],
            'paymentDefaults' => [
                'date' => today()->toDateString(),
                'baseCurrency' => $baseCurrencyCode,
            ],
            'can' => [
                'create' => (bool) $user?->can('create', SupplierInvoice::class),
            ],
            'urls' => [
                'index' => route('admin.supplier-invoices.index'),
                'create' => route('admin.supplier-invoices.create'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', SupplierInvoice::class);
        $user = $request->user();
        $accessibleBranchIds = $user->accessibleBranchIds();
        $po = $request->filled('po')
            ? PurchaseOrder::withoutGlobalScopes()->whereKey($request->get('po'))->first()
            : null;
        if ($po && ! $user->belongsToBranch((int) $po->branch_id)) {
            abort(403);
        }
        $selectedBranchId = (int) ($po?->branch_id
            ?: $request->integer('branch')
            ?: BranchContext::current()
            ?: $user->primaryBranch()?->id
            ?: ($accessibleBranchIds[0] ?? 0));
        if (! $selectedBranchId || ! $user->belongsToBranch($selectedBranchId)) {
            abort(403, 'اختر فرعاً مسموحاً لك قبل تسجيل فاتورة المورد.');
        }
        // Eager-load receiptItems so the invoice form can show the ACTUAL
        // received price (which the cashier may have edited at receipt
        // time) instead of the original PO ordered price. Without this
        // the form silently fell back to `items.unit_price` (= ordered)
        // and the manager would re-record the supplier invoice at the
        // wrong price, breaking weighted-average + variance reports.
        $po?->load([
            'supplier',
            'items.ingredient.baseUnit',
            'items.unit',
            'items.receiptItems',  // latest is the source of truth
            'items.supplierInvoiceItems.supplierInvoice',
        ]);
        $baseCurrencyCode = app(ExchangeRateService::class)->baseCurrencyCode();
        $suppliers = Supplier::with('branches:id')->where('active', true)
            ->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $accessibleBranchIds))
            ->orderBy('name')->get();
        $purchaseOrders = PurchaseOrder::withoutGlobalScopes()->with([
            'supplier',
            'items.supplierInvoiceItems.supplierInvoice',
        ])
            ->whereIn('branch_id', $accessibleBranchIds)
            ->whereIn('status', ['received', 'partially_received'])
            ->latest()->limit(100)->get()
            ->reject(fn (PurchaseOrder $order) => $order->isFullyInvoiced())
            ->values();

        $branches = Branch::active()->whereIn('id', $accessibleBranchIds)
            ->orderBy('display_order')->get(['id', 'name']);

        $lines = $po?->items->map(function ($line) {
            $receipts = $line->receiptItems ?? collect();
            if ($receipts->isNotEmpty()) {
                $totalQty = (float) $receipts->sum('quantity_received');
                $totalValue = (float) $receipts->sum(fn ($receipt) => (float) $receipt->quantity_received * (float) $receipt->unit_price
                );
                $receivedPrice = $totalQty > 0
                    ? $totalValue / $totalQty
                    : (float) $receipts->sortByDesc('id')->first()->unit_price;
            } else {
                $receivedPrice = (float) $line->unit_price;
            }

            $alreadyInvoiced = (float) $line->supplierInvoiceItems
                ->filter(fn ($item) => $item->supplierInvoice?->status !== 'cancelled')
                ->sum(fn ($item) => (float) ($item->received_qty ?? $item->quantity));
            $uninvoicedReceived = max(0, (float) $line->quantity_received - $alreadyInvoiced);

            return [
                'purchase_order_item_id' => $line->id,
                'ingredient_id' => $line->ingredient_id,
                'unit_id' => $line->unit_id,
                'description' => $line->ingredient?->name ?? '—',
                'unitCode' => $line->unit?->code ?? $line->ingredient?->baseUnit?->code,
                'receivedQty' => $uninvoicedReceived,
                'fullyReceived' => $line->isFullyReceived(),
                // Reopening a partially invoiced PO must offer only the
                // remaining received quantity. Prefilling the cumulative
                // receipt made the next invoice fail validation as an
                // apparent over-bill.
                'quantity' => $uninvoicedReceived,
                'unit_price' => round($receivedPrice, 4),
                'orderedPrice' => (float) $line->unit_price,
                'tax_total' => 0,
                'notes' => '',
            ];
        })->filter(fn (array $line) => $line['quantity'] > 0.0001)->values() ?? collect();

        $selectedSubtotal = (float) $lines->sum(
            fn (array $line) => (float) $line['quantity'] * (float) $line['unit_price']
        );

        return AdminShell::render('Admin/SupplierInvoices/Create', [
            'suppliers' => $suppliers->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'paymentTermsDays' => $supplier->payment_terms_days,
                'branchIds' => $supplier->branches->pluck('id')->map(fn ($id) => (int) $id)->values(),
            ])->values(),
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => (int) $branch->id,
                'name' => $branch->localizedName(),
            ])->values(),
            'selectedBranchId' => $selectedBranchId,
            'purchaseOrders' => $purchaseOrders->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'branchId' => (int) $order->branch_id,
                'number' => $order->number,
                'supplierId' => $order->supplier_id,
                'supplierName' => $order->supplier?->name,
                'totalLabel' => Money::format($order->total, $order->currency_code),
            ])->values(),
            'selectedPo' => $po ? [
                'id' => $po->id,
                'branchId' => (int) $po->branch_id,
                'number' => $po->number,
                'supplierId' => $po->supplier_id,
                'subtotal' => round($selectedSubtotal, 4),
                'total' => round($selectedSubtotal, 4),
                'currencyCode' => $po->currency_code ?: $baseCurrencyCode,
                'exchangeRate' => (float) ($po->exchange_rate ?: 1),
            ] : null,
            'lines' => $lines,
            'currencies' => Currency::where('is_active', true)
                ->orderBy('display_order')->get()->map(fn ($currency) => [
                    'code' => $currency->code,
                    'label' => $currency->code.' — '.$currency->name,
                    'rate' => $currency->rate_updated_at ? (float) $currency->rate_to_base : null,
                ])->values(),
            'defaults' => [
                'date' => today()->toDateString(),
                'baseCurrency' => $baseCurrencyCode,
            ],
            'urls' => [
                'index' => route('admin.supplier-invoices.index'),
                'create' => route('admin.supplier-invoices.create'),
                'store' => route('admin.supplier-invoices.store'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', SupplierInvoice::class);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:60'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0.01'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'lines' => ['nullable', 'array'],
            'lines.*.purchase_order_item_id' => ['nullable', 'exists:purchase_order_items,id'],
            'lines.*.ingredient_id' => ['nullable', 'exists:ingredients,id'],
            'lines.*.unit_id' => ['nullable', 'exists:units,id'],
            'lines.*.description' => ['required_with:lines', 'string', 'max:255'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.tax_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('supplier-invoices', 'public');
        }

        try {
            $inv = $this->service->create($data, auth()->id());

            return redirect()
                ->route('admin.supplier-invoices.show', $inv)
                ->with('success', "تم تسجيل فاتورة المورد {$inv->number}");
        } catch (\Throwable $e) {
            if (! empty($data['attachment_path'])) {
                Storage::disk('public')->delete($data['attachment_path']);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(SupplierInvoice $supplierInvoice)
    {
        $this->authorize('view', $supplierInvoice);
        $supplierInvoice->load([
            'supplier',
            'purchaseOrder',
            'items.ingredient.baseUnit',
            'items.unit',
            'items.purchaseOrderItem.ingredient.baseUnit',
            'payments.payer',
            'creator',
        ]);
        $invoice = $supplierInvoice;
        $baseCurrencyCode = app(ExchangeRateService::class)->baseCurrencyCode();
        $user = auth()->user();

        return AdminShell::render('Admin/SupplierInvoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'supplierName' => $invoice->supplier?->name ?? 'بدون مورد',
                'status' => $invoice->status,
                'statusLabel' => $invoice->statusLabel(),
                'statusColor' => $invoice->statusColor(),
                'currency' => $invoice->currency_code ?: $baseCurrencyCode,
                'exchangeRate' => (float) ($invoice->exchange_rate ?: 1),
                'baseCurrency' => $baseCurrencyCode,
                'invoiceDate' => $invoice->invoice_date?->toDateString(),
                'dueDate' => $invoice->due_date?->toDateString(),
                'paidAt' => $invoice->paid_at?->format('Y-m-d H:i'),
                'creatorName' => $invoice->creator?->name,
                'notes' => $invoice->notes,
                'attachmentUrl' => $invoice->attachment_path ? Storage::url($invoice->attachment_path) : null,
                'purchaseOrder' => $invoice->purchaseOrder ? [
                    'number' => $invoice->purchaseOrder->number,
                    'url' => route('admin.purchase-orders.show', $invoice->purchaseOrder),
                ] : null,
                'amounts' => [
                    'subtotal' => $invoice->formatMoney($invoice->subtotal),
                    'tax' => $invoice->formatMoney($invoice->tax_total),
                    'taxRaw' => (float) $invoice->tax_total,
                    'total' => $invoice->formatMoney($invoice->total),
                    'paid' => $invoice->formatMoney($invoice->paid_total),
                    'balance' => $invoice->formatMoney($invoice->balance),
                    'balanceRaw' => round((float) $invoice->balance, 2),
                ],
            ],
            'items' => $invoice->items->map(function ($item) use ($invoice) {
                $qtyVariance = (float) ($item->variance_qty ?? 0);
                $totalVariance = (float) ($item->variance_total ?? 0);

                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'unit' => $item->unit?->code ?? $item->ingredient?->baseUnit?->code,
                    'quantity' => Qty::format($item->quantity),
                    'receivedQty' => $item->received_qty !== null ? Qty::format($item->received_qty) : '—',
                    'total' => $invoice->formatMoney($item->total),
                    'qtyVariance' => $item->variance_qty !== null ? Qty::format($qtyVariance) : '—',
                    'totalVariance' => $item->variance_total !== null ? $invoice->formatMoney($totalVariance) : '—',
                    'hasQtyVariance' => abs($qtyVariance) > 0.0001,
                    'hasTotalVariance' => abs($totalVariance) > 0.01,
                ];
            })->values(),
            'payments' => $invoice->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'date' => $payment->paid_on?->toDateString(),
                'amount' => $invoice->formatMoney($payment->amount),
                'method' => $payment->methodLabel(),
                'reference' => $payment->reference,
                'payer' => $payment->payer?->name ?? '—',
                'notes' => $payment->notes,
            ])->values(),
            'can' => [
                'pay' => $invoice->balance > 0 && (bool) $user?->can('pay', $invoice),
                'cancel' => (bool) $user?->can('cancel', $invoice),
            ],
            'defaults' => ['paymentDate' => today()->toDateString()],
            'urls' => [
                'index' => route('admin.supplier-invoices.index'),
                'pay' => route('admin.supplier-invoices.pay', $invoice),
                'cancel' => route('admin.supplier-invoices.cancel', $invoice),
            ],
        ]);
    }

    public function pay(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->authorize('pay', $supplierInvoice);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank_transfer'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->recordPayment($supplierInvoice, $data, auth()->id());

            return back()->with('success', 'تم تسجيل الدفعة وتحديث رصيد الفاتورة.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->authorize('cancel', $supplierInvoice);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($supplierInvoice, $data['reason'], auth()->id());

            return back()->with('success', 'تم إلغاء الفاتورة.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(SupplierInvoice $supplierInvoice)
    {
        $this->authorize('delete', $supplierInvoice);
        if ($supplierInvoice->attachment_path) {
            Storage::disk('public')->delete($supplierInvoice->attachment_path);
        }
        $supplierInvoice->delete();

        return redirect()->route('admin.supplier-invoices.index')->with('success', 'تم حذف الفاتورة');
    }
}
