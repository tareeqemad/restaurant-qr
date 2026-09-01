<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientSupplierPrice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\ExchangeRateService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(protected PurchaseOrderService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $q = PurchaseOrder::with(['supplier', 'items'])
            ->latest();
        if ($s = $request->get('status'))      $q->where('status', $s);
        if ($sid = $request->get('supplier_id'))$q->where('supplier_id', $sid);
        if ($s = $request->get('search'))      $q->where('number', 'like', "%$s%");
        if ($d = $request->get('from'))        $q->whereDate('created_at', '>=', $d);
        if ($d = $request->get('to'))          $q->whereDate('created_at', '<=', $d);

        $pos = $q->paginate(20)->withQueryString();

        $stats = [
            'draft'     => PurchaseOrder::where('status', 'draft')->count(),
            'sent'      => PurchaseOrder::where('status', 'sent')->count(),
            'partial'   => PurchaseOrder::where('status', 'partially_received')->count(),
            'this_month'=> (float) PurchaseOrder::whereMonth('created_at', now()->month)
                                                ->whereYear('created_at', now()->year)
                                                ->whereNotIn('status', ['cancelled'])
                                                ->sum(DB::raw('total * exchange_rate')),
        ];

        // Base currency resolved ONCE — the Blade called the service inside
        // the row loop, so a 20-row page hit the setting twenty times.
        $baseCurrency = app(ExchangeRateService::class)->baseCurrencyCode();
        $user = $request->user();

        $pos->through(function (PurchaseOrder $po) use ($baseCurrency, $user) {
            $receivedValue    = $po->receivedValue();
            $outstandingValue = $po->outstandingValue();

            return [
                'id'     => $po->id,
                'number' => $po->number,
                'supplierName' => $po->supplier?->name ?? '—',
                'itemsCount'   => $po->items->count(),
                'totalLabel'   => $po->formatMoney($po->total),
                // Only shown when the PO isn't in the accounting base
                // currency — otherwise it's the same number twice.
                'baseTotalLabel' => $po->currency_code !== $baseCurrency
                    ? \App\Helpers\Money::formatAccounting($po->baseTotal())
                    : null,
                'receivedLabel'    => \App\Helpers\Money::format($receivedValue),
                'receivedAny'      => $receivedValue > 0,
                'outstandingLabel' => \App\Helpers\Money::format($outstandingValue),
                'outstandingOpen'  => $outstandingValue > 0.01,
                'statusLabel' => $po->statusLabel(),
                'statusColor' => $po->statusColor(),
                'createdAt'   => $po->created_at?->format('Y-m-d'),
                'expectedAt'  => $po->expected_at?->format('Y-m-d'),
                // The Blade gated this button on state ALONE, so a user
                // without purchase_orders.receive saw a button the server
                // would 403. The policy now rides along.
                'canReceive'  => $po->isReceivable() && (bool) $user?->can('receive', $po),
                'urls' => [
                    'show'    => route('admin.purchase-orders.show', $po),
                    'receive' => route('admin.purchase-orders.receive-form', $po),
                ],
            ];
        });

        return \App\Support\AdminShell::render('Admin/PurchaseOrders/Index', [
            'pos'   => $pos,
            'stats' => [
                'draft'          => $stats['draft'],
                'sent'           => $stats['sent'],
                'partial'        => $stats['partial'],
                'thisMonth'      => $stats['this_month'],
                'thisMonthLabel' => \App\Helpers\Money::format($stats['this_month']),
            ],
            'suppliers' => Supplier::where('active', true)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
                ->values()->all(),
            'statusOptions' => [
                ['value' => 'draft',              'label' => 'مسودة'],
                ['value' => 'sent',               'label' => 'مُرسل'],
                ['value' => 'partially_received', 'label' => 'مستلم جزئياً'],
                ['value' => 'received',           'label' => 'مستلم'],
                ['value' => 'cancelled',          'label' => 'ملغي'],
            ],
            'filters' => [
                'search'      => $request->get('search'),
                'status'      => $request->get('status'),
                'supplier_id' => $request->get('supplier_id'),
                'from'        => $request->get('from'),
                'to'          => $request->get('to'),
            ],
            'hasFilters' => $request->hasAny(['status', 'supplier_id', 'search', 'from', 'to']),
            'can' => [
                'create' => (bool) $user?->can('create', PurchaseOrder::class),
            ],
            'urls' => [
                'index'  => route('admin.purchase-orders.index'),
                'create' => route('admin.purchase-orders.create'),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', PurchaseOrder::class);

        return \App\Support\AdminShell::render('Admin/PurchaseOrders/Form', $this->builderProps());
    }

    public function store(Request $request)
    {
        $this->authorize('create', PurchaseOrder::class);
        $data  = $this->validateHeader($request);
        $lines = $this->validateLines($request);

        $po = $this->service->create($data, $lines, auth()->id());
        return redirect()
            ->route('admin.purchase-orders.show', $po)
            ->with('success', "تم إنشاء أمر الشراء {$po->number}");
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);
        $purchaseOrder->load([
            'supplier',
            'items.ingredient.baseUnit',
            'items.unit',
            // The retired Blade read `$line->ingredientUnit` per row without
            // eager-loading it — one extra query per line.
            'items.ingredientUnit',
            'items.receiptItems',
            'items.supplierInvoiceItems',
            'creator',
            'approver',
            'receiver',
            'receipts.items.ingredient.baseUnit',
            'receipts.items.unit',
            'receipts.receiver',
            'supplierInvoices.items.ingredient.baseUnit',
        ]);

        $po = $purchaseOrder;
        $user = $request->user();
        $money = fn ($v) => \App\Helpers\Money::format($v);

        // ── Workflow gates ───────────────────────────────────────────────
        // Policy alone is NOT enough: BasePolicy::before bypasses every
        // check for owner-level, so without the state guard a super-admin
        // would be handed every button at once. The Blade's @elseif chain
        // is reproduced here so exactly one primary action can ever ship.
        $canApprove = $po->isApprovable() && (bool) $user?->can('approve', $po);
        $canSend    = ! $po->isApprovable() && $po->isSendable() && (bool) $user?->can('send', $po);
        $canReceive = ! $po->isApprovable() && ! $po->isSendable()
            && $po->isReceivable() && (bool) $user?->can('receive', $po);
        $canEdit    = $po->isEditable() && (bool) $user?->can('update', $po);
        $canCancel  = $po->isCancellable() && (bool) $user?->can('cancel', $po);

        $invoiceable   = $po->isInvoiceable();
        $fullyInvoiced = $po->isFullyInvoiced();
        // Once the PO is fully received, registering the supplier invoice is
        // the only remaining step — promote it to the primary CTA.
        $invoicePrimary = $po->status === 'received';

        // ── Facts panel — only rows with a value are rendered ────────────
        $factRows = [
            ['رقم PO', $po->number, 'bi-hash', true],
            ['المورد', $po->supplier?->name, 'bi-truck', false],
            ['الحالة', $po->statusLabel(), 'bi-info-circle', false],
            ['تاريخ الإنشاء', $po->created_at?->format('Y-m-d H:i'), 'bi-calendar', false],
            ['اعتمد بواسطة', $po->approver?->name, 'bi-person-check', false],
            ['تاريخ الاعتماد', $po->approved_at?->format('Y-m-d H:i'), 'bi-check2-circle', false],
            ['تاريخ الإرسال', $po->sent_at?->format('Y-m-d H:i'), 'bi-send', false],
            ['التسليم المتوقع', optional($po->expected_at)->format('Y-m-d'), 'bi-calendar-check', false],
            ['تاريخ الاستلام الكامل', $po->received_at?->format('Y-m-d H:i'), 'bi-box-arrow-in-down', false],
            ['أنشأ بواسطة', $po->creator?->name, 'bi-person', false],
            ['استلم بواسطة', $po->receiver?->name, 'bi-person-check', false],
        ];
        $facts = [];
        foreach ($factRows as [$label, $value, $icon, $mono]) {
            if (! empty($value)) {
                $facts[] = ['label' => $label, 'value' => (string) $value, 'icon' => $icon, 'mono' => $mono];
            }
        }

        // ── Lines ────────────────────────────────────────────────────────
        // Effective price = weighted average of what ACTUALLY arrived; it
        // falls back to the ordered price while nothing is received. That is
        // the number that drives the supplier-invoice default and the WAC,
        // so the screen shows it — with the PO price as the delta note.
        $effectiveTotal = 0.0;
        $orderedTotal   = (float) $po->total;

        $lines = $po->items->map(function ($line) use ($po, $money, &$effectiveTotal) {
            $orderedPrice    = (float) $line->unit_price;
            $orderedSubtotal = (float) $line->subtotal;
            $receipts        = $line->receiptItems ?? collect();
            $totalRecvQty    = (float) $receipts->sum('quantity_received');
            $totalRecvValue  = (float) $receipts->sum(fn ($r) =>
                (float) $r->quantity_received * (float) $r->unit_price
            );
            $effectivePrice    = $totalRecvQty > 0 ? $totalRecvValue / $totalRecvQty : $orderedPrice;
            $effectiveSubtotal = $totalRecvQty > 0 ? $totalRecvValue : $orderedSubtotal;
            $priceDelta   = $effectivePrice - $orderedPrice;
            $hasReceipt   = $totalRecvQty > 0;
            $priceChanged = $hasReceipt && abs($priceDelta) > 0.0001;

            $effectiveTotal += $effectiveSubtotal;

            $invoicedQty = (float) $line->supplierInvoiceItems->sum('quantity');
            $receivedQty = (float) $line->quantity_received;

            // Display unit: the pack name when the line was ordered in an
            // alternate unit (carton/case), else the global unit code.
            $orderUnitLabel = $line->ingredientUnit?->name ?? ($line->unit?->code ?? '');
            $baseCode = $line->ingredient?->baseUnit?->code;
            $baseQty  = $line->ingredientUnit
                ? (float) $line->quantity_ordered * (float) $line->ingredientUnit->factor_to_base
                : null;

            return [
                'id'   => $line->id,
                'name' => $line->ingredient?->name ?? '—',
                'notes' => $line->notes,
                'unitLabel' => $orderUnitLabel,

                'orderedLabel' => \App\Helpers\Qty::format($line->quantity_ordered).' '.$orderUnitLabel,
                'orderedTitle' => $baseQty !== null
                    ? "{$line->quantity_ordered} {$orderUnitLabel} = ".\App\Helpers\QuantityFormatter::smart($baseQty, $baseCode)
                    : null,

                'receivedLabel' => \App\Helpers\Qty::format($line->quantity_received).' '.$orderUnitLabel,
                'receivedColor' => $line->isFullyReceived()
                    ? 'success'
                    : ($receivedQty > 0 ? 'warning' : 'secondary'),
                'receivedTitle' => $line->ingredientUnit
                    ? "{$line->quantity_received} {$line->ingredientUnit->name} = ".\App\Helpers\QuantityFormatter::smart(
                        $receivedQty * (float) $line->ingredientUnit->factor_to_base, $baseCode)
                    : null,

                'invoicedLabel'   => \App\Helpers\Qty::format($invoicedQty).' '.$orderUnitLabel,
                'invoicedCovered' => $invoicedQty >= $receivedQty && $invoicedQty > 0,

                'priceLabel'        => $money($effectivePrice),
                'priceChanged'      => $priceChanged,
                'priceUp'           => $priceDelta > 0,
                'priceDeltaLabel'   => ($priceDelta > 0 ? '+' : '').$money($priceDelta),
                'orderedPriceTitle' => 'السعر في الـ PO الأصلي كان '.$money($orderedPrice),
                'hasReceipt'        => $hasReceipt,

                'subtotalLabel'        => $po->formatMoney($effectiveSubtotal),
                'orderedSubtotalLabel' => $po->formatMoney($orderedSubtotal),

                'percent'      => $line->receivedPercent(),
                'percentLabel' => number_format($line->receivedPercent(), 0),
            ];
        })->values()->all();

        $totalDelta  = $effectiveTotal - $orderedTotal;
        $totalsMatch = abs($totalDelta) < 0.01;

        // Stat-rail colour map: the model's bootstrap colour names don't all
        // exist in the rail's palette.
        $railColor = match ($po->statusColor()) {
            'success' => 'success',
            'danger'  => 'danger',
            'warning' => 'accent',
            'info'    => 'primary',
            default   => 'muted',
        };

        return \App\Support\AdminShell::render('Admin/PurchaseOrders/Show', [
            'po' => [
                'id'     => $po->id,
                'number' => $po->number,
                'supplierName' => $po->supplier?->name,
                'status'       => $po->status,
                'statusLabel'  => $po->statusLabel(),
                'statusColor'  => $po->statusColor(),
                'railColor'    => $railColor,
                'approved'     => (bool) $po->approved_at,
                'itemsCount'   => $po->items->count(),
                'totalLabel'   => $po->formatMoney($po->total),
                'receiptsCount' => $po->receipts->count(),
                'invoicesCount' => $po->supplierInvoices->count(),
                'notes'         => $po->notes,
                'cancelReason'  => $po->cancel_reason,
                'partiallyReceived' => $po->status === 'partially_received',
            ],
            'facts' => $facts,
            'lines' => $lines,
            'totals' => [
                'effectiveLabel' => $money($effectiveTotal),
                'orderedLabel'   => $money($orderedTotal),
                'match'          => $totalsMatch,
                'deltaUp'        => $totalDelta > 0,
                'deltaLabel'     => ($totalDelta > 0 ? '+' : '').$po->formatMoney($totalDelta),
            ],
            'receipts' => $po->receipts->sortByDesc('received_at')->map(fn ($r) => [
                'id'         => $r->id,
                'number'     => $r->number,
                'receivedAt' => $r->received_at?->format('Y-m-d H:i'),
                'itemsCount' => $r->items->count(),
                'receiverName' => $r->receiver?->name ?? '—',
            ])->values()->all(),
            'invoices' => $po->supplierInvoices->map(fn ($i) => [
                'id'           => $i->id,
                'number'       => $i->number,
                'totalLabel'   => $i->formatMoney($i->total),
                'balanceLabel' => $money($i->balance),
                'balanceOpen'  => (float) $i->balance > 0,
                'statusLabel'  => $i->statusLabel(),
                'statusColor'  => $i->statusColor(),
                'url'          => route('admin.supplier-invoices.show', $i),
            ])->values()->all(),
            'can' => [
                'approve' => $canApprove,
                'send'    => $canSend,
                'receive' => $canReceive,
                'edit'    => $canEdit,
                'cancel'  => $canCancel,
                'showStatusBadge'  => $po->isCancellable(),
                'invoice'          => $invoiceable && ! $fullyInvoiced,
                'fullyInvoiced'    => $invoiceable && $fullyInvoiced,
            ],
            'labels' => [
                'receive' => $po->status === 'partially_received' ? 'استلام دفعة جديدة' : 'استلام البضاعة',
                'invoice' => $invoicePrimary ? 'تسجيل فاتورة المورد' : 'فاتورة المورد',
            ],
            'invoicePrimary' => $invoicePrimary,
            'urls' => [
                'index'   => route('admin.purchase-orders.index'),
                'approve' => route('admin.purchase-orders.approve', $po),
                'send'    => route('admin.purchase-orders.send', $po),
                'receive' => route('admin.purchase-orders.receive-form', $po),
                'edit'    => route('admin.purchase-orders.edit', $po),
                'cancel'  => route('admin.purchase-orders.cancel', $po),
                'invoice' => route('admin.supplier-invoices.create', ['po' => $po->id]),
            ],
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);
        $purchaseOrder->load('items.ingredient', 'items.unit');

        return \App\Support\AdminShell::render('Admin/PurchaseOrders/Form',
            $this->builderProps($purchaseOrder));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        $data  = $this->validateHeader($request);
        $lines = $this->validateLines($request);
        [$currencyCode, $exchangeRate] = $this->resolveCurrency($data);

        $this->service->assertSupplierServesBranch(
            (int) $data['supplier_id'],
            (int) $purchaseOrder->branch_id
        );

        $purchaseOrder->update([
            'supplier_id' => $data['supplier_id'],
            'currency_code' => $currencyCode,
            'exchange_rate' => $exchangeRate,
            'expected_at' => $data['expected_at'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ]);
        $this->service->updateLines($purchaseOrder, $lines);

        return redirect()
            ->route('admin.purchase-orders.show', $purchaseOrder)
            ->with('success', 'تم تحديث أمر الشراء');
    }

    /** Transition draft → sent */
    public function approve(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('approve', $purchaseOrder);
        try {
            $this->service->approve($purchaseOrder, auth()->id());
            return back()->with('success', "تم اعتماد أمر الشراء {$purchaseOrder->number}");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function send(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('send', $purchaseOrder);
        try {
            $this->service->send($purchaseOrder);
            return back()->with('success', "تم إرسال أمر الشراء {$purchaseOrder->number}");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Open the Goods Receipt form */
    /**
     * The goods-receipt form — Inertia/Vue since Wave 4.
     *
     * Destination locations are resolved from the PO's OWN branch (bypassing
     * BranchScope) so a user standing in another branch context can't route
     * Khan-Yunis stock into a Gaza freezer.
     */
    public function receiveForm(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('receive', $purchaseOrder);
        $purchaseOrder->load('items.ingredient.baseUnit', 'items.unit', 'supplier');

        $locations = \App\Support\BranchContext::unscoped(fn () => \App\Models\StorageLocation::query()
            ->where('active', true)
            ->where('branch_id', $purchaseOrder->branch_id)
            ->orderByDesc('is_default')->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'label' => trim($l->name.($l->code ? " ({$l->code})" : '')),
                'isDefault' => (bool) $l->is_default,
            ])->all());

        return \App\Support\AdminShell::render('Admin/PurchaseOrders/Receive', [
            'po' => [
                'id' => $purchaseOrder->id,
                'number' => $purchaseOrder->number,
                'supplierName' => $purchaseOrder->supplier?->name,
                'currencyCode' => $purchaseOrder->currency_code,
            ],
            'lines' => $purchaseOrder->items->map(fn ($l) => [
                'id' => $l->id,
                'ingredientName' => $l->ingredient?->name ?? '—',
                'unitCode' => $l->unit?->code ?? '',
                'ordered' => (float) $l->quantity_ordered,
                'received' => (float) $l->quantity_received,
                'outstanding' => (float) $l->outstandingQty(),
                'unitPrice' => (float) $l->unit_price,
                'isFull' => $l->isFullyReceived(),
                'tracksExpiry' => (bool) $l->ingredient?->tracks_expiry,
                'shelfLifeDays' => $l->ingredient?->default_shelf_life_days,
            ])->values()->all(),
            'locations' => $locations,
            'urls' => [
                'submit' => route('admin.purchase-orders.receive', $purchaseOrder),
                'back' => route('admin.purchase-orders.show', $purchaseOrder),
            ],
        ]);
    }

    /** Process goods receipt */
    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('receive', $purchaseOrder);

        $data = $request->validate([
            'receipts'            => ['required', 'array'],
            'receipts.*'          => ['nullable', 'numeric', 'min:0'],
            'storage_location_id' => ['nullable', 'exists:storage_locations,id'],
            'batch_numbers'       => ['nullable', 'array'],
            'batch_numbers.*'     => ['nullable', 'string', 'max:100'],
            'expiry_dates'        => ['nullable', 'array'],
            'expiry_dates.*'      => ['nullable', 'date', 'after_or_equal:today'],
            // The actual invoiced price, when it differs from what the PO
            // said. It drives the weighted-average cost and the supplier
            // price history, so it is validated like money, not trusted.
            'unit_prices'         => ['nullable', 'array'],
            'unit_prices.*'       => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $meta = [];
            foreach ($data['receipts'] as $lineId => $qty) {
                if ((float) $qty <= 0) continue;

                $price = (float) ($data['unit_prices'][$lineId] ?? 0);
                $meta[(int) $lineId] = [
                    'storage_location_id' => $data['storage_location_id'] ?? null,
                    'batch_number' => $data['batch_numbers'][$lineId] ?? null,
                    'expiry_date' => $data['expiry_dates'][$lineId] ?? null,
                    // null lets the service fall back to the PO line's price.
                    'actual_unit_price' => $price > 0 ? $price : null,
                ];
            }

            $this->service->receive($purchaseOrder, $data['receipts'], auth()->id(), $meta);
            return redirect()
                ->route('admin.purchase-orders.show', $purchaseOrder)
                ->with('success', 'تم استلام البضاعة وتحديث المخزون والأسعار.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('cancel', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($purchaseOrder, $data['reason'], auth()->id());
            return back()->with('success', 'تم إلغاء أمر الشراء');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('delete', $purchaseOrder);
        $purchaseOrder->delete();
        return redirect()->route('admin.purchase-orders.index')->with('success', 'تم حذف أمر الشراء');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Everything the Vue line builder needs, for BOTH create and edit —
     * one page, one component, `po` null on create. Ingredients carry
     * their purchase packs (a "carton of 12" is a pack whose factor turns
     * ordered packs into base units at RECEIVING time; the PO total is
     * always packs × price-per-pack, so the factor never enters here).
     */
    protected function builderProps(?PurchaseOrder $po = null): array
    {
        $data = $this->formData();

        return [
            'po' => $po ? [
                'id' => $po->id,
                'number' => $po->number,
                'supplierId' => $po->supplier_id,
                'currencyCode' => $po->currency_code,
                'exchangeRate' => (float) ($po->exchange_rate ?: 1),
                'expectedAt' => $po->expected_at?->toDateString(),
                'notes' => $po->notes,
                'lines' => $po->items->map(fn ($i) => [
                    'ingredient_id' => (int) $i->ingredient_id,
                    'unit_id' => (int) $i->unit_id,
                    'ingredient_unit_id' => $i->ingredient_unit_id ? (int) $i->ingredient_unit_id : null,
                    'quantity_ordered' => (float) $i->quantity_ordered,
                    'unit_price' => (float) $i->unit_price,
                    'notes' => (string) ($i->notes ?? ''),
                ])->values()->all(),
                'submitUrl' => route('admin.purchase-orders.update', $po),
            ] : [
                'id' => null,
                'number' => null,
                'supplierId' => null,
                'currencyCode' => null,
                'exchangeRate' => 1.0,
                'expectedAt' => null,
                'notes' => null,
                'lines' => [],
                'submitUrl' => route('admin.purchase-orders.store'),
            ],
            'suppliers' => $data['suppliers']->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name,
            ])->values()->all(),
            'ingredients' => $data['ingredients']->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'sku' => $i->sku,
                'baseUnitId' => (int) $i->base_unit_id,
                'baseUnitCode' => $i->baseUnit?->code ?? '',
                'fallbackCost' => (float) $i->cost_per_unit,
                'packs' => $i->units->where('active', true)->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'factorToBase' => (float) $u->factor_to_base,
                    'purchasePrice' => $u->purchase_price !== null ? (float) $u->purchase_price : null,
                    'isDefaultPurchase' => (bool) $u->is_default_purchase,
                ])->values()->all(),
            ])->values()->all(),
            'units' => $data['units']->map(fn ($u) => [
                'id' => $u->id, 'label' => "{$u->name} ({$u->code})",
            ])->values()->all(),
            'priceInsights' => $data['priceInsights'],
            'currencies' => $data['currencies']->map(fn ($c) => [
                'code' => $c->code, 'label' => trim($c->name.' ('.$c->symbol.')'),
            ])->values()->all(),
            'baseCurrencyCode' => $data['baseCurrencyCode'],
            'urls' => [
                'index' => route('admin.purchase-orders.index'),
            ],
        ];
    }

    protected function formData(): array
    {
        $supQuery = Supplier::where('active', true);

        // Branch-aware: branch users see only suppliers serving their branch.
        // Owner-level users (Super Admin / Partner) see all.
        $user = auth()->user();
        if ($user) {
            $branchId = \App\Support\BranchContext::current()
                ?? (! $user->isOwnerLevel() ? optional($user->primaryBranch())->id : null);
            if ($branchId) {
                $supQuery->servingBranch((int) $branchId);
            }
        }

        $latestPriceIds = IngredientSupplierPrice::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('ingredient_id', 'supplier_id', 'currency_code')
            ->pluck('id');

        $priceInsights = $latestPriceIds->isEmpty()
            ? collect()
            : IngredientSupplierPrice::with('supplier', 'unit')
                ->whereIn('id', $latestPriceIds)
                ->get()
                ->map(fn ($row) => [
                    'ingredient_id'       => (int) $row->ingredient_id,
                    'supplier_id'         => (int) $row->supplier_id,
                    'supplier_name'       => $row->supplier?->name ?? '—',
                    'unit_id'             => (int) $row->unit_id,
                    'unit_name'           => $row->unit?->code ?? '',
                    'unit_price'          => (float) $row->unit_price,
                    'currency_code'       => $row->currency_code,
                    'exchange_rate'       => (float) ($row->exchange_rate ?: 1),
                    'unit_price_in_base'  => (float) $row->unit_price_in_base,
                    'change_pct'          => $row->change_pct !== null ? (float) $row->change_pct : null,
                    'observed_at'         => $row->observed_at?->toDateString(),
                ])
                ->values();

        return [
            'suppliers'   => $supQuery->orderBy('name')->get(),
            // `units` = the ingredient's purchase packs (carton/box/…), which
            // the line builder needs to offer pack ordering.
            'ingredients' => Ingredient::with('baseUnit', 'supplier', 'units')->orderBy('name')->get(),
            'units'       => Unit::orderBy('name')->get(),
            'priceInsights' => $priceInsights,
            'currencies' => \App\Models\Currency::where('is_active', true)->orderBy('display_order')->get(),
            'baseCurrencyCode' => app(ExchangeRateService::class)->baseCurrencyCode(),
        ];
    }

    protected function validateHeader(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'expected_at' => ['nullable', 'date'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);
    }

    protected function resolveCurrency(array $data): array
    {
        $exchangeRates = app(ExchangeRateService::class);
        $baseCurrency = $exchangeRates->baseCurrencyCode();
        $currencyCode = $exchangeRates->normalizeCode($data['currency_code'] ?? $baseCurrency);
        $rate = $currencyCode === $baseCurrency
            ? 1.0
            : (float) ($data['exchange_rate'] ?? $exchangeRates->rateFor($currencyCode, $baseCurrency, now()));

        return [$currencyCode, $rate];
    }

    protected function validateLines(Request $request): array
    {
        $request->validate([
            'lines'                      => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id'      => ['required', 'exists:ingredients,id'],
            'lines.*.unit_id'            => ['required', 'exists:units,id'],
            'lines.*.quantity_ordered'   => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price'         => ['required', 'numeric', 'min:0'],
            'lines.*.notes'              => ['nullable', 'string', 'max:500'],
        ]);

        // Filter out blank lines (user may have deleted some client-side)
        return array_values(array_filter($request->input('lines', []), function ($l) {
            return !empty($l['ingredient_id']) && ((float) ($l['quantity_ordered'] ?? 0) > 0);
        }));
    }
}
