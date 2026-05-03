<?php

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Support\BranchContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Cashier Dashboard (Livewire)
 *
 * A single-screen cashier experience:
 *   left side  = searchable list of active table sessions
 *   right side = selected session's orders, invoice, payment keypad, history
 *
 * All actions (issue invoice / record payment / refund) run without a full
 * page reload. Broadcasted updates from other cashiers auto-refresh the view.
 */
new class extends Component
{
    use AuthorizesRequests;

    public string $search = '';
    public string $viewMode = 'tables';
    public ?int $selectedSessionId = null;
    public ?int $selectedRemoteOrderId = null;

    // Staff-entered no-table order state
    public string $itemSearch = '';
    public string $selectedCategoryId = '';
    public string $orderType = 'takeaway';
    public string $orderSource = 'phone';
    public string $customerName = '';
    public string $customerPhone = '';
    public string $deliveryAddress = '';
    public string $deliveryFee = '0';
    public string $externalReference = '';
    public string $platformCommissionPct = '0';
    public string $orderNotes = '';
    public bool $sendToKitchen = true;
    public bool $issueInvoiceAfterCreate = false;
    public array $newOrderItems = [
        ['menu_item_id' => '', 'quantity' => 1, 'modifier_ids' => [], 'notes' => ''],
    ];

    // Payment form state
    public string $paymentAmount = '';
    public string $paymentMethod = 'cash';
    public string $paymentReference = '';
    public string $paymentNotes = '';

    // Refund form state
    public bool $refundOpen = false;
    public string $refundAmount = '';
    public string $refundMethod = 'cash';
    public string $refundReason = '';

    public function mount(?int $session = null): void
    {
        $this->authorize('viewAny', Payment::class);

        $this->selectedSessionId = $session;
        $this->platformCommissionPct = (string) (OrderSource::tryFrom($this->orderSource)?->defaultCommission() ?? 0);
    }

    // ─── Computed (reactive) ──────────────────────────────────────────

    #[Computed]
    public function sessions()
    {
        $query = TableSession::with(['table', 'orders', 'invoice', 'customer'])
            ->where('status', 'active')
            ->orderByDesc('bill_requested_at')
            ->orderByDesc('last_activity_at');

        if (strlen(trim($this->search)) > 0) {
            $s = trim($this->search);
            $query->where(function ($lookup) use ($s) {
                $lookup->whereHas('table', fn($q) => $q->where('number', 'like', "%{$s}%"))
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn($q) => $q->where('name', 'like', "%{$s}%")
                        ->orWhere('phone', 'like', "%{$s}%"));
            });
        }

        return $query->get();
    }

    #[Computed]
    public function remoteOrders()
    {
        $query = Order::with(['customer', 'items.modifiers', 'invoice.payments'])
            ->whereNull('table_session_id')
            ->where(function ($q) {
                $q->whereIn('status', OrderStatus::active())
                    ->orWhereHas('invoice', fn($invoice) => $invoice->whereDate('created_at', today()));
            })
            ->latest('submitted_at')
            ->limit(60);

        if (strlen(trim($this->search)) > 0) {
            $s = trim($this->search);
            $digits = preg_replace('/\D+/', '', $s) ?? '';
            $query->where(function ($lookup) use ($s, $digits) {
                $lookup->where('number', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('customer_phone', 'like', "%{$s}%")
                    ->orWhere('external_reference', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn($q) => $q->where('name', 'like', "%{$s}%")
                        ->orWhere('phone', 'like', "%{$s}%"));
                if ($digits !== '' && $digits !== $s) {
                    $lookup->orWhere('customer_phone', 'like', "%{$digits}%")
                        ->orWhereHas('customer', fn($q) => $q->where('phone', 'like', "%{$digits}%"));
                }
            });
        }

        return $query->get();
    }

    #[Computed]
    public function selectedSession(): ?TableSession
    {
        if (!$this->selectedSessionId) return null;
        return TableSession::with([
            'table', 'customer', 'orders.items.modifiers',
            'invoice.payments', 'invoice.refunds.processor',
        ])->find($this->selectedSessionId);
    }

    #[Computed]
    public function selectedRemoteOrder(): ?Order
    {
        if (!$this->selectedRemoteOrderId) return null;

        return Order::with([
            'customer', 'items.modifiers',
            'invoice.payments', 'invoice.refunds.processor',
        ])->find($this->selectedRemoteOrderId);
    }

    #[Computed]
    public function menuCategories()
    {
        return Category::where('active', true)
            ->whereHas('menuItems', fn($q) => $q->where('is_available', true))
            ->withCount(['menuItems as available_items_count' => fn($q) => $q->where('is_available', true)])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function menuItems()
    {
        $query = MenuItem::with(['category', 'modifierGroups.modifiers'])
            ->where('is_available', true)
            ->orderBy('display_order')
            ->orderBy('name');

        if ($this->selectedCategoryId !== '') {
            $query->where('category_id', (int) $this->selectedCategoryId);
        }

        if (strlen(trim($this->itemSearch)) > 0) {
            $s = trim($this->itemSearch);
            $query->where(function ($lookup) use ($s) {
                $lookup->where('name', 'like', "%{$s}%")
                    ->orWhere('name_en', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%");
            });
        }

        return $query->limit(40)->get();
    }

    #[Computed]
    public function cartSummary(): array
    {
        $ids = collect($this->newOrderItems)
            ->pluck('menu_item_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $items = MenuItem::with('modifierGroups.modifiers')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $subtotal = 0.0;
        $quantity = 0.0;
        $selectedLines = 0;
        $missingLines = 0;
        $warnings = [];

        foreach ($this->newOrderItems as $line) {
            $itemId = (int) ($line['menu_item_id'] ?? 0);
            if ($itemId <= 0) {
                $missingLines++;
                continue;
            }

            $item = $items->get($itemId);
            if (! $item) {
                $missingLines++;
                continue;
            }

            $qty = max(1, (float) ($line['quantity'] ?? 1));
            $selected = collect($line['modifier_ids'] ?? [])
                ->map(fn($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();

            $allModifiers = $item->modifierGroups->flatMap(fn($group) => $group->modifiers);
            $modifiersTotal = $allModifiers
                ->whereIn('id', $selected)
                ->sum(fn($modifier) => (float) $modifier->price_delta);

            foreach ($item->modifierGroups as $group) {
                $count = $group->modifiers
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->intersect($selected)
                    ->count();

                if ($group->required && $count < (int) $group->min_select) {
                    $warnings[] = "{$item->name}: {$group->name} مطلوب";
                }

                if ((int) $group->max_select > 0 && $count > (int) $group->max_select) {
                    $warnings[] = "{$item->name}: تجاوزت حد {$group->name}";
                }
            }

            $subtotal += ((float) $item->price + (float) $modifiersTotal) * $qty;
            $quantity += $qty;
            $selectedLines++;
        }

        $deliveryFee = $this->orderType === 'delivery'
            ? \App\Helpers\Money::round((float) $this->deliveryFee)
            : 0.0;

        $taxRate = (bool) \App\Models\Setting::get('tax_enabled', config('restaurant.tax.enabled', true))
            ? (float) \App\Models\Setting::get('tax_rate', config('restaurant.tax.rate', 16))
            : 0.0;
        $taxTotal = \App\Helpers\Money::round($subtotal * ($taxRate / 100));

        if ($this->orderType === 'delivery' && trim($this->deliveryAddress) === '') {
            $warnings[] = 'عنوان التوصيل ناقص';
        }

        if (in_array($this->orderSource, ['talabat', 'careem', 'uber_eats'], true)
            && trim($this->externalReference) === '') {
            $warnings[] = 'مرجع المنصة ناقص';
        }

        if (in_array($this->orderSource, ['phone', 'talabat', 'careem', 'uber_eats'], true)
            && trim($this->customerPhone) === ''
            && trim($this->externalReference) === '') {
            $warnings[] = 'أدخل هاتف أو مرجع للطلب';
        }

        if ($selectedLines === 0) {
            $warnings[] = 'أضف صنف واحد على الأقل';
        } elseif ($missingLines > 0) {
            $warnings[] = 'يوجد سطر بدون صنف';
        }

        return [
            'lines' => $selectedLines,
            'quantity' => $quantity,
            'subtotal' => \App\Helpers\Money::round($subtotal),
            'tax_rate' => $taxRate,
            'tax_total' => $taxTotal,
            'delivery_fee' => $deliveryFee,
            'total' => \App\Helpers\Money::round($subtotal + $taxTotal + $deliveryFee),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    #[Computed]
    public function todayStats(): array
    {
        return [
            'invoices' => Invoice::whereDate('created_at', today())->count(),
            'revenue'  => (float) Invoice::whereDate('created_at', today())
                                ->where('status', 'paid')->sum('paid_total'),
            'cash'     => (float) \App\Models\Payment::whereDate('created_at', today())
                                ->where('method', 'cash')->sum('amount'),
            'card'     => (float) \App\Models\Payment::whereDate('created_at', today())
                                ->where('method', 'card')->sum('amount'),
        ];
    }

    // ─── Actions ──────────────────────────────────────────────────────

    public function setViewMode(string $mode): void
    {
        if (! in_array($mode, ['tables', 'remote', 'new'], true)) {
            return;
        }

        $this->viewMode = $mode;
        $this->reset(['search', 'paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen', 'refundAmount', 'refundReason']);
        $this->paymentMethod = 'cash';

        if ($mode === 'tables') {
            $this->selectedRemoteOrderId = null;
        } elseif ($mode === 'remote') {
            $this->selectedSessionId = null;
        } else {
            $this->selectedSessionId = null;
            $this->selectedRemoteOrderId = null;
            $this->resetNewOrder();
        }
    }

    public function selectSession(int $id): void
    {
        $this->viewMode = 'tables';
        $this->selectedSessionId = $id;
        $this->selectedRemoteOrderId = null;
        $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen', 'refundAmount', 'refundReason']);
        $this->paymentMethod = 'cash';

        // Pre-fill amount with balance on the next render
        $session = $this->selectedSession;
        if ($session?->invoice && $session->invoice->balance > 0) {
            $this->paymentAmount = (string) number_format((float) $session->invoice->balance, 2, '.', '');
        }
    }

    public function selectRemoteOrder(int $id): void
    {
        $this->viewMode = 'remote';
        $this->selectedRemoteOrderId = $id;
        $this->selectedSessionId = null;
        $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen', 'refundAmount', 'refundReason']);
        $this->paymentMethod = 'cash';

        $order = $this->selectedRemoteOrder;
        if ($order?->invoice && $order->invoice->balance > 0) {
            $this->paymentAmount = (string) number_format((float) $order->invoice->balance, 2, '.', '');
        }
    }

    public function clearSelection(): void
    {
        $this->selectedSessionId = null;
        $this->selectedRemoteOrderId = null;
        $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen']);
    }

    public function setAmount(string|float $amount): void
    {
        $this->paymentAmount = (string) $amount;
    }

    public function setMethod(string $method): void
    {
        $this->paymentMethod = $method;
    }

    protected function activeInvoice(): ?Invoice
    {
        if ($this->selectedRemoteOrderId) {
            return $this->selectedRemoteOrder?->invoice;
        }

        return $this->selectedSession?->invoice;
    }

    protected function activeBranch(): Branch
    {
        $branchId = BranchContext::current()
            ?? auth()->user()?->branches()->wherePivot('is_primary', true)->value('branches.id')
            ?? auth()->user()?->branches()->orderBy('branches.display_order')->value('branches.id')
            ?? Branch::active()->orderBy('display_order')->value('id');

        if (! $branchId) {
            throw new \RuntimeException('لا يوجد فرع نشط لإنشاء الطلب.');
        }

        return Branch::findOrFail($branchId);
    }

    public function updatedOrderSource(string $value): void
    {
        $this->platformCommissionPct = (string) (OrderSource::tryFrom($value)?->defaultCommission() ?? 0);

        if (! in_array($value, ['talabat', 'careem', 'uber_eats'], true)) {
            $this->externalReference = '';
        }

        if ($value === 'other' && $this->orderType === 'takeaway') {
            $this->customerName = '';
            $this->customerPhone = '';
        }
    }

    public function updatedOrderType(string $value): void
    {
        if ($value !== 'delivery') {
            $this->deliveryFee = '0';
            $this->deliveryAddress = '';
            return;
        }

        if ((float) $this->deliveryFee <= 0) {
            try {
                $this->deliveryFee = (string) $this->activeBranch()->deliveryFee();
            } catch (\Throwable) {
                $this->deliveryFee = '0';
            }
        }
    }

    public function setOrderType(string $type): void
    {
        if (! in_array($type, ['takeaway', 'delivery'], true)) {
            return;
        }

        $this->orderType = $type;
        $this->updatedOrderType($type);
    }

    public function setOrderSource(string $source): void
    {
        if (! in_array($source, ['other', 'phone', 'talabat', 'careem', 'uber_eats'], true)) {
            return;
        }

        $isPlatform = in_array($source, ['talabat', 'careem', 'uber_eats'], true);

        $this->orderSource = $source;
        $this->issueInvoiceAfterCreate = $source !== 'phone';

        if ($isPlatform && $this->orderType !== 'delivery') {
            $this->orderType = 'delivery';
            $this->updatedOrderType('delivery');
        }

        $this->updatedOrderSource($source);
    }

    public function addOrderLine(): void
    {
        $this->newOrderItems[] = ['menu_item_id' => '', 'quantity' => 1, 'modifier_ids' => [], 'notes' => ''];
    }

    public function addMenuItemToOrder(int $menuItemId): void
    {
        $item = MenuItem::where('is_available', true)->find($menuItemId);
        if (! $item) {
            return;
        }

        foreach ($this->newOrderItems as $index => $line) {
            $sameItem = (int) ($line['menu_item_id'] ?? 0) === $item->id;
            $hasModifiers = ! empty($line['modifier_ids']);
            $hasNotes = trim((string) ($line['notes'] ?? '')) !== '';

            if ($sameItem && ! $hasModifiers && ! $hasNotes) {
                $this->newOrderItems[$index]['quantity'] = max(1, (int) ($line['quantity'] ?? 1)) + 1;
                return;
            }
        }

        foreach ($this->newOrderItems as $index => $line) {
            if (empty($line['menu_item_id'])) {
                $this->newOrderItems[$index] = [
                    'menu_item_id' => (string) $item->id,
                    'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                    'modifier_ids' => [],
                    'notes' => trim((string) ($line['notes'] ?? '')),
                ];
                return;
            }
        }

        $this->newOrderItems[] = [
            'menu_item_id' => (string) $item->id,
            'quantity' => 1,
            'modifier_ids' => [],
            'notes' => '',
        ];
    }

    public function changeLineQuantity(int $index, int $delta): void
    {
        if (! isset($this->newOrderItems[$index])) {
            return;
        }

        $this->newOrderItems[$index]['quantity'] = max(1, (int) ($this->newOrderItems[$index]['quantity'] ?? 1) + $delta);
    }

    public function clearItemSearch(): void
    {
        $this->itemSearch = '';
    }

    public function removeOrderLine(int $index): void
    {
        unset($this->newOrderItems[$index]);
        $this->newOrderItems = array_values($this->newOrderItems);

        if (count($this->newOrderItems) === 0) {
            $this->addOrderLine();
        }
    }

    public function resetNewOrder(): void
    {
        $this->reset([
            'itemSearch', 'selectedCategoryId', 'customerName', 'customerPhone', 'deliveryAddress',
            'externalReference', 'orderNotes',
        ]);
        $this->orderType = 'takeaway';
        $this->orderSource = 'other';
        $this->deliveryFee = '0';
        $this->platformCommissionPct = (string) (OrderSource::Other->defaultCommission());
        $this->sendToKitchen = true;
        $this->issueInvoiceAfterCreate = true;
        $this->newOrderItems = [
            ['menu_item_id' => '', 'quantity' => 1, 'modifier_ids' => [], 'notes' => ''],
        ];
    }

    protected function cartRowsForSubmit(): array
    {
        $cart = [];

        foreach ($this->newOrderItems as $index => $line) {
            $itemId = (int) ($line['menu_item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $item = MenuItem::with('modifierGroups.modifiers')->findOrFail($itemId);
            $selected = collect($line['modifier_ids'] ?? [])
                ->map(fn($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();

            $allowed = $item->modifierGroups
                ->flatMap(fn($group) => $group->modifiers->pluck('id'))
                ->map(fn($id) => (int) $id)
                ->all();

            $selected = $selected->intersect($allowed)->values();

            foreach ($item->modifierGroups as $group) {
                $count = $group->modifiers
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->intersect($selected)
                    ->count();

                if ($group->required && $count < (int) $group->min_select) {
                    throw new \RuntimeException("الصنف {$item->name}: اختر {$group->min_select} من {$group->name}.");
                }

                if ($count > (int) $group->max_select) {
                    throw new \RuntimeException("الصنف {$item->name}: الحد الأعلى في {$group->name} هو {$group->max_select}.");
                }
            }

            $cart[] = [
                'menu_item_id' => $itemId,
                'quantity' => max(1, (float) ($line['quantity'] ?? 1)),
                'modifier_ids' => $selected->all(),
                'notes' => trim((string) ($line['notes'] ?? '')) ?: null,
            ];
        }

        if (empty($cart)) {
            throw new \RuntimeException('أضف صنف واحد على الأقل للطلب.');
        }

        return $cart;
    }

    public function createStandaloneOrder(OrderService $orders, BillingService $billing): void
    {
        $this->authorize('create', Order::class);

        $this->validate([
            'orderType' => ['required', 'in:takeaway,delivery'],
            'orderSource' => ['required', 'in:'.implode(',', OrderSource::options())],
            'customerName' => ['nullable', 'string', 'max:120'],
            'customerPhone' => ['nullable', 'string', 'max:32'],
            'deliveryAddress' => ['nullable', 'string', 'max:500'],
            'deliveryFee' => ['nullable', 'numeric', 'min:0'],
            'externalReference' => ['nullable', 'string', 'max:80'],
            'platformCommissionPct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'orderNotes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'orderType' => 'نوع الطلب',
            'orderSource' => 'مصدر الطلب',
            'customerName' => 'اسم الزبون',
            'customerPhone' => 'هاتف الزبون',
            'deliveryAddress' => 'عنوان التوصيل',
            'deliveryFee' => 'رسوم التوصيل',
        ]);

        if ($this->orderType === 'delivery' && trim($this->deliveryAddress) === '') {
            $this->addError('deliveryAddress', 'عنوان التوصيل مطلوب لطلبات الدليفري.');
            return;
        }

        if (in_array($this->orderSource, ['phone', 'talabat', 'careem', 'uber_eats'], true)
            && trim($this->customerPhone) === ''
            && trim($this->externalReference) === '') {
            $this->addError('customerPhone', 'أدخل رقم هاتف أو رقم مرجعي للطلب.');
            return;
        }

        try {
            $branch = $this->activeBranch();
            $cart = $this->cartRowsForSubmit();
            $customer = null;

            if (trim($this->customerPhone) !== '') {
                $customer = Customer::findForLogin($this->customerPhone);
                if (! $customer && trim($this->customerName) !== '') {
                    [$customer] = Customer::createFromCashier(
                        name: $this->customerName,
                        phone: $this->customerPhone,
                        defaultBranchId: $branch->id,
                    );
                }
            }

            $order = $orders->createCashierOrder(
                customer: $customer,
                branch: $branch,
                type: $this->orderType,
                source: $this->orderSource,
                cart: $cart,
                opts: [
                    'customer_name' => trim($this->customerName) ?: null,
                    'customer_phone' => trim($this->customerPhone) ?: null,
                    'customer_notes' => trim($this->orderNotes) ?: null,
                    'delivery_address' => trim($this->deliveryAddress) ?: null,
                    'delivery_fee' => (float) $this->deliveryFee,
                    'external_reference' => trim($this->externalReference) ?: null,
                    'platform_commission_pct' => $this->platformCommissionPct,
                ],
                createdByUserId: auth()->id(),
            );

            if ($this->sendToKitchen) {
                try {
                    $this->authorize('approve', $order);
                    $orders->approve($order, auth()->id());
                    $order->refresh();
                } catch (\Throwable $e) {
                    $this->dispatch('toast', type: 'warning', message: 'تم إنشاء الطلب لكنه بقي بانتظار الموافقة: '.$e->getMessage());
                }
            }

            if ($this->issueInvoiceAfterCreate) {
                $this->authorize('create', Payment::class);
                $invoice = $billing->issueInvoiceForOrder($order, auth()->id());
                $this->paymentAmount = (string) number_format((float) $invoice->balance, 2, '.', '');
            }

            $this->selectedRemoteOrderId = $order->id;
            $this->selectedSessionId = null;
            $this->viewMode = 'remote';
            $this->resetNewOrder();
            unset($this->remoteOrders, $this->selectedRemoteOrder);

            $this->dispatch('toast', type: 'success', message: "تم إنشاء الطلب {$order->number}");
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function issueRemoteInvoice(BillingService $billing): void
    {
        $this->authorize('create', Payment::class);

        $order = $this->selectedRemoteOrder;
        if (! $order || $order->invoice) return;

        try {
            $invoice = $billing->issueInvoiceForOrder($order, auth()->id());
            $this->paymentAmount = (string) number_format((float) $invoice->balance, 2, '.', '');
            unset($this->selectedRemoteOrder, $this->remoteOrders);
            $this->dispatch('toast', type: 'success', message: 'تم إصدار الفاتورة');
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function issueInvoice(BillingService $billing): void
    {
        $this->authorize('create', Payment::class);

        $session = $this->selectedSession;
        if (!$session || $session->invoice) return;

        try {
            $billing->issueInvoice($session, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'تم إصدار الفاتورة');
            unset($this->selectedSession);   // bust cache
            $session = $this->selectedSession;
            if ($session?->invoice) {
                $this->paymentAmount = (string) number_format((float) $session->invoice->balance, 2, '.', '');
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function recordPayment(BillingService $billing): void
    {
        $this->authorize('create', Payment::class);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentMethod' => ['required', 'in:cash,card,transfer,app,credit'],
        ], attributes: [
            'paymentAmount' => 'قيمة الدفعة',
            'paymentMethod' => 'طريقة الدفع',
        ]);

        $invoice = $this->activeInvoice();
        if (!$invoice) {
            $this->dispatch('toast', type: 'error', message: 'لم تُصدر الفاتورة بعد');
            return;
        }

        try {
            $billing->addPayment(
                $invoice,
                (float) $this->paymentAmount,
                $this->paymentMethod,
                auth()->id(),
                $this->paymentReference ?: null,
                $this->paymentNotes ?: null,
            );
            $this->dispatch('toast', type: 'success', message: "تم تسجيل دفعة بقيمة {$this->paymentAmount}");
            $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes']);
            unset($this->selectedSession, $this->selectedRemoteOrder, $this->remoteOrders);

            // Pre-fill with new balance (in case of partial payment, customer may pay again)
            $invoice = $this->activeInvoice();
            if ($invoice && (float) $invoice->balance > 0) {
                $this->paymentAmount = (string) number_format((float) $invoice->balance, 2, '.', '');
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function openRefund(): void
    {
        $invoice = $this->activeInvoice();
        if (!$invoice) return;

        $this->refundOpen = true;
        $refundable = max(0, (float) $invoice->paid_total - (float) ($invoice->refunded_total ?? 0));
        $this->refundAmount = (string) number_format($refundable, 2, '.', '');
        $this->refundMethod = 'cash';
        $this->refundReason = '';
    }

    public function closeRefund(): void
    {
        $this->refundOpen = false;
        $this->reset(['refundAmount', 'refundMethod', 'refundReason']);
    }

    public function submitRefund(RefundService $refundService): void
    {
        $this->validate([
            'refundAmount' => ['required', 'numeric', 'min:0.01'],
            'refundMethod' => ['required', 'in:cash,card,transfer,app,credit,other'],
            'refundReason' => ['required', 'string', 'max:500'],
        ], attributes: [
            'refundAmount' => 'المبلغ',
            'refundMethod' => 'طريقة الاسترداد',
            'refundReason' => 'السبب',
        ]);

        $invoice = $this->activeInvoice();
        if (!$invoice) return;

        try {
            $refundService->issue(
                $invoice,
                (float) $this->refundAmount,
                $this->refundMethod,
                $this->refundReason,
                auth()->id(),
            );
            $this->dispatch('toast', type: 'success', message: "تم تسجيل استرداد {$this->refundAmount}");
            $this->closeRefund();
            unset($this->selectedSession, $this->selectedRemoteOrder, $this->remoteOrders);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    /**
     * Close a stuck session — all orders cancelled, no invoice possible.
     * The UI only exposes this action when `canCloseWithoutBilling` is true,
     * so the cashier can't accidentally skip a real payment.
     */
    public function closeSessionWithoutBilling(BillingService $billing): void
    {
        $session = $this->selectedSession;
        if (! $session) return;

        try {
            $billing->closeSessionWithoutBilling($session, auth()->id(), 'إغلاق من الكاشير');
            $this->dispatch('toast', type: 'success', message: 'تم إغلاق الجلسة وتحرير الطاولة');
            $this->clearSelection();
            unset($this->sessions, $this->selectedSession);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    /**
     * True when the selected session has nothing billable — all orders are
     * cancelled (or the session has no orders). Exposed to the view so the
     * "close without billing" button can appear only in the edge case it
     * was designed for, never when real money is owed.
     */
    #[Computed]
    public function canCloseWithoutBilling(): bool
    {
        $session = $this->selectedSession;
        if (! $session || $session->status !== 'active') return false;

        // If an invoice already exists, the normal flow handles it.
        if ($session->invoice) return false;

        $billable = (float) $session->orders
            ->where('status', '!=', \App\Enums\OrderStatus::Cancelled->value)
            ->sum('total');

        return $billable <= 0.001;
    }

    /**
     * Event-driven refresh — no polling. Subscribes to the PRIVATE staff
     * channels (auth enforced in routes/channels.php). Any change from any
     * device (another cashier processes a payment, a waiter seats a table,
     * kitchen completes an order) pushes here and re-renders instantly.
     */
    #[On('echo-private:cashiers,.order.status_changed')]
    #[On('echo-private:cashiers,.invoice.paid')]
    #[On('echo-private:waiters,.order.created')]
    #[On('echo-private:waiters,.table.status_changed')]
    public function refreshFromBroadcast(): void
    {
        unset($this->sessions, $this->selectedSession);
    }
}
?>

{{-- Livewire polling mode (works on shared hosting). 10s is a good balance:
     cashier needs to see new sessions/paid invoices, but sessions aren't
     as time-critical as a hot dish. `visible` saves resources on background tabs. --}}
<div class="cashier-dashboard" wire:poll.visible.10s="refreshFromBroadcast">
    {{-- ═════════════════ Today KPI rail ═════════════════ --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="cx-kpi cx-kpi-primary">
                <div class="cx-kpi-icon"><i class="bi bi-receipt-cutoff"></i></div>
                <div>
                    <div class="cx-kpi-label">فواتير اليوم</div>
                    <div class="cx-kpi-value">{{ $this->todayStats['invoices'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="cx-kpi cx-kpi-success">
                <div class="cx-kpi-icon"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="cx-kpi-label">إيراد اليوم</div>
                    <div class="cx-kpi-value">{{ \App\Helpers\Money::format($this->todayStats['revenue']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="cx-kpi cx-kpi-accent">
                <div class="cx-kpi-icon"><i class="bi bi-wallet2"></i></div>
                <div>
                    <div class="cx-kpi-label">نقدي</div>
                    <div class="cx-kpi-value">{{ \App\Helpers\Money::format($this->todayStats['cash']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="cx-kpi cx-kpi-info">
                <div class="cx-kpi-icon"><i class="bi bi-credit-card"></i></div>
                <div>
                    <div class="cx-kpi-label">بطاقات</div>
                    <div class="cx-kpi-value">{{ \App\Helpers\Money::format($this->todayStats['card']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="cx-mode-tabs">
        <button type="button" wire:click="setViewMode('tables')" class="cx-mode-tab {{ $viewMode === 'tables' ? 'is-active' : '' }}">
            <i class="bi bi-grid-3x3-gap-fill"></i> الطاولات
            <span>{{ $this->sessions->count() }}</span>
        </button>
        <button type="button" wire:click="setViewMode('remote')" class="cx-mode-tab {{ $viewMode === 'remote' ? 'is-active' : '' }}">
            <i class="bi bi-telephone-fill"></i> طلبات بدون طاولة
            <span>{{ $this->remoteOrders->count() }}</span>
        </button>
        <button type="button" wire:click="setViewMode('new')" class="cx-mode-tab cx-mode-tab-primary {{ $viewMode === 'new' ? 'is-active' : '' }}">
            <i class="bi bi-plus-lg"></i> طلب جديد
        </button>
    </div>

    @if($viewMode === 'new')
        <div class="cx-compose">
            <div class="cx-compose-main">
                @php
                    $platformSources = ['talabat', 'careem', 'uber_eats'];
                    $isPlatformSource = in_array($orderSource, $platformSources, true);
                    $needsCustomerContact = $orderSource === 'phone' || $isPlatformSource || $orderType === 'delivery';
                    $sourceChoices = [
                        'other' => ['bi-lightning-charge-fill', 'مباشر'],
                        'phone' => ['bi-telephone-fill', 'تلفون'],
                        'talabat' => ['bi-bag-fill', 'طلبات'],
                        'careem' => ['bi-scooter', 'كريم'],
                        'uber_eats' => ['bi-truck', 'Uber'],
                    ];
                @endphp

                <div class="cx-section">
                    <div class="cx-section-head"><strong><i class="bi bi-sliders"></i> بيانات الطلب</strong></div>
                    <div class="cx-form-grid">
                        <div class="cx-choice-field">
                            <span>نوع الطلب</span>
                            <div class="cx-choice-row">
                                <button type="button"
                                    wire:click="setOrderType('takeaway')"
                                    class="cx-choice {{ $orderType === 'takeaway' ? 'is-active' : '' }}">
                                    <i class="bi bi-bag-check"></i>
                                    <strong>استلام</strong>
                                    <small>سفري / من الكاشير</small>
                                </button>
                                <button type="button"
                                    wire:click="setOrderType('delivery')"
                                    class="cx-choice {{ $orderType === 'delivery' ? 'is-active' : '' }}">
                                    <i class="bi bi-truck"></i>
                                    <strong>توصيل</strong>
                                    <small>عنوان ورسوم توصيل</small>
                                </button>
                            </div>
                        </div>
                        <div class="cx-choice-field">
                            <span>مصدر الطلب</span>
                            <div class="cx-source-grid">
                                @foreach($sourceChoices as $sourceValue => [$sourceIcon, $sourceLabel])
                                    <button type="button"
                                        wire:click="setOrderSource('{{ $sourceValue }}')"
                                        class="cx-source-choice {{ $orderSource === $sourceValue ? 'is-active' : '' }}">
                                        <i class="bi {{ $sourceIcon }}"></i>
                                        <span>{{ $sourceLabel }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <div class="cx-order-context cx-field-wide">
                            <i class="bi {{ $orderType === 'delivery' ? 'bi-truck' : 'bi-bag-check' }}"></i>
                            <span>
                                {{ $orderType === 'delivery' ? 'طلب توصيل' : 'طلب استلام' }}
                                ·
                                {{ $sourceChoices[$orderSource][1] ?? 'مصدر غير معروف' }}
                            </span>
                        </div>
                        @if($needsCustomerContact)
                            <label class="cx-field">
                                <span>اسم الزبون</span>
                                <input type="text" wire:model.blur="customerName" class="form-control" placeholder="اختياري">
                            </label>
                            <label class="cx-field">
                                <span>هاتف الزبون</span>
                                <input type="text" wire:model.blur="customerPhone" class="form-control" placeholder="05xxxxxxxx" dir="ltr">
                                @error('customerPhone') <small class="text-danger">{{ $message }}</small> @enderror
                            </label>
                        @endif
                        @if($orderType === 'delivery')
                            <label class="cx-field cx-field-wide">
                                <span>عنوان التوصيل</span>
                                <textarea wire:model.blur="deliveryAddress" class="form-control" rows="2" placeholder="الحي، الشارع، رقم البناية، علامة مميزة"></textarea>
                                @error('deliveryAddress') <small class="text-danger">{{ $message }}</small> @enderror
                            </label>
                            <label class="cx-field">
                                <span>رسوم التوصيل</span>
                                <input type="number" step="0.01" min="0" wire:model.blur="deliveryFee" class="form-control">
                            </label>
                        @endif
                        @if($isPlatformSource)
                            <label class="cx-field">
                                <span>مرجع المنصة</span>
                                <input type="text" wire:model.blur="externalReference" class="form-control" placeholder="#12345">
                            </label>
                            <label class="cx-field">
                                <span>عمولة المنصة %</span>
                                <input type="number" step="0.01" min="0" max="100" wire:model.blur="platformCommissionPct" class="form-control">
                            </label>
                        @endif
                        <label class="cx-field cx-field-wide">
                            <span>ملاحظات للطلب</span>
                            <textarea wire:model.blur="orderNotes" class="form-control" rows="2" placeholder="مثلاً: بدون بصل، الاتصال عند الوصول..."></textarea>
                        </label>
                    </div>
                </div>

                <div class="cx-section cx-items-workspace">
                    <div class="cx-section-head cx-section-head-row">
                        <strong><i class="bi bi-basket2-fill"></i> الأصناف</strong>
                        <button type="button" wire:click="addOrderLine" class="btn btn-sm btn-light">
                            <i class="bi bi-plus"></i>
                            سطر مخصص
                        </button>
                    </div>

                    <div class="cx-item-picker-shell">
                        <div class="cx-search-wrap cx-search-wrap-compact cx-search-wrap-items">
                            <i class="bi bi-search"></i>
                            {{-- 500ms debounce: the menu-items query needs the server (40-row LIMIT
                                 + category filter), but waiting until the user pauses typing
                                 keeps each character feeling instant. --}}
                            <input type="text"
                                wire:model.live.debounce.500ms="itemSearch"
                                class="cx-search"
                                placeholder="ابحث بالاسم أو SKU ثم اضغط على الصنف...">
                            @if($itemSearch)
                                <button type="button" wire:click="clearItemSearch" class="cx-search-clear">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            @endif
                        </div>

                        <div class="cx-category-strip">
                            <button type="button"
                                wire:click="$set('selectedCategoryId', '')"
                                class="cx-category-pill {{ $selectedCategoryId === '' ? 'is-active' : '' }}">
                                الكل
                            </button>
                            @foreach($this->menuCategories as $category)
                                <button type="button"
                                    wire:click="$set('selectedCategoryId', '{{ $category->id }}')"
                                    class="cx-category-pill {{ $selectedCategoryId === (string) $category->id ? 'is-active' : '' }}">
                                    {{ $category->name }}
                                    <span>{{ $category->available_items_count }}</span>
                                </button>
                            @endforeach
                        </div>

                        <div class="cx-pos-layout">
                            <div class="cx-menu-picker">
                                <div class="cx-menu-grid">
                                    @forelse($this->menuItems as $item)
                                        @php
                                            $cartQty = collect($newOrderItems)
                                                ->filter(fn($line) => (int) ($line['menu_item_id'] ?? 0) === $item->id)
                                                ->sum(fn($line) => max(1, (int) ($line['quantity'] ?? 1)));
                                            $hasRequiredChoices = $item->modifierGroups->contains(fn($group) => (bool) $group->required);
                                        @endphp
                                        <button type="button"
                                            wire:click="addMenuItemToOrder({{ $item->id }})"
                                            class="cx-menu-item-card {{ $cartQty > 0 ? 'is-in-cart' : '' }}"
                                            wire:key="cashier-menu-item-{{ $item->id }}">
                                            <span class="cx-menu-item-cat">{{ $item->category?->name ?? 'بدون قسم' }}</span>
                                            <strong>{{ $item->name }}</strong>
                                            @if($item->name_en)
                                                <small>{{ $item->name_en }}</small>
                                            @endif
                                            <span class="cx-menu-item-foot">
                                                <b>{{ \App\Helpers\Money::format($item->price) }}</b>
                                                @if($hasRequiredChoices)
                                                    <em>خيارات</em>
                                                @endif
                                                @if($cartQty > 0)
                                                    <i>{{ $cartQty }}</i>
                                                @endif
                                            </span>
                                        </button>
                                    @empty
                                        <div class="cx-menu-empty">
                                            <i class="bi bi-search"></i>
                                            <strong>لا يوجد صنف مطابق</strong>
                                            <span>جرّب اسم أقصر أو غيّر القسم.</span>
                                        </div>
                                    @endforelse
                                </div>
                            </div>

                            <div class="cx-order-lines cx-order-lines-cart">
                                @foreach($newOrderItems as $i => $line)
                                    @php
                                        $selectedItem = $this->menuItems->firstWhere('id', (int) ($line['menu_item_id'] ?? 0));
                                        if (!$selectedItem && !empty($line['menu_item_id'])) {
                                            $selectedItem = \App\Models\MenuItem::with('category', 'modifierGroups.modifiers')->find((int) $line['menu_item_id']);
                                        }
                                        $selectedModifierIds = collect($line['modifier_ids'] ?? [])
                                            ->map(fn($id) => (int) $id)
                                            ->filter()
                                            ->unique()
                                            ->values()
                                            ->all();

                                        // Modifier price-delta map for instant Alpine line-total math
                                        $modifierPriceMap = [];
                                        if ($selectedItem) {
                                            foreach ($selectedItem->modifierGroups as $g) {
                                                foreach ($g->modifiers as $m) {
                                                    $modifierPriceMap[$m->id] = (float) $m->price_delta;
                                                }
                                            }
                                        }
                                    @endphp
                                    <div class="cx-order-line {{ $selectedItem ? 'has-item' : 'is-empty' }}"
                                         wire:key="cashier-line-{{ $i }}"
                                         @if($selectedItem)
                                         x-data='cashierCartLine({
                                             wireIdx: {{ $i }},
                                             itemPrice: {{ (float) $selectedItem->price }},
                                             initialQty: {{ max(1, (int) ($line['quantity'] ?? 1)) }},
                                             initialMods: @json($selectedModifierIds),
                                             modifierPrices: @json($modifierPriceMap),
                                         })'
                                         @endif>
                                        @if($selectedItem)
                                            <div class="cx-cart-line-head">
                                                <div class="cx-cart-line-name">
                                                    <span>{{ $selectedItem->category?->name ?? 'صنف' }}</span>
                                                    <strong>{{ $selectedItem->name }}</strong>
                                                    {{-- Live line total (Alpine — updates instantly on modifier toggle / qty change) --}}
                                                    <small x-text="formattedTotal()"></small>
                                                </div>
                                                <div class="cx-qty-stepper">
                                                    <button type="button"
                                                            @click="qty = Math.max(1, qty - 1); pushQty()"
                                                            aria-label="تقليل">
                                                        <i class="bi bi-dash"></i>
                                                    </button>
                                                    {{-- Local Alpine model for instant feedback; debounced sync to Livewire below. --}}
                                                    <input type="number" min="1" step="1"
                                                           x-model.number="qty"
                                                           @input.debounce.500ms="pushQty()">
                                                    <button type="button"
                                                            @click="qty = Math.max(1, qty + 1); pushQty()"
                                                            aria-label="زيادة">
                                                        <i class="bi bi-plus"></i>
                                                    </button>
                                                </div>
                                                <button type="button" wire:click="removeOrderLine({{ $i }})" class="cx-line-remove-icon" title="حذف">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>

                                            <label class="cx-line-note">
                                                <i class="bi bi-chat-left-text"></i>
                                                <input type="text"
                                                    wire:model.blur="newOrderItems.{{ $i }}.notes"
                                                    placeholder="ملاحظة للصنف: بدون بصل، زيادة صوص...">
                                            </label>

                                            @if($selectedItem?->modifierGroups?->count())
                                                <div class="cx-modifier-grid">
                                                    @foreach($selectedItem->modifierGroups as $group)
                                                        <div class="cx-modifier-group">
                                                            <div class="cx-modifier-title">
                                                                {{ $group->name }}
                                                                <span>{{ $group->required ? 'مطلوب' : 'اختياري' }}</span>
                                                            </div>
                                                            <div class="cx-modifier-options">
                                                                @foreach($group->modifiers as $modifier)
                                                                    {{-- Alpine x-model for INSTANT toggle.
                                                                         Server sync is debounced 500ms via @change so cartSummary
                                                                         eventually catches up without per-click round-trips. --}}
                                                                    <label class="cx-modifier-option">
                                                                        <input type="checkbox"
                                                                            value="{{ $modifier->id }}"
                                                                            x-model.number="mods"
                                                                            @change.debounce.500ms="pushMods()">
                                                                        <span>{{ $modifier->name }}</span>
                                                                        @if((float) $modifier->price_delta !== 0.0)
                                                                            <small>{{ number_format((float) $modifier->price_delta, 2) }}</small>
                                                                        @endif
                                                                    </label>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        @else
                                            <div class="cx-cart-empty-line">
                                                <i class="bi bi-cursor-fill"></i>
                                                <div>
                                                    <strong>اضغط على صنف من القائمة</strong>
                                                    <span>أو اختره يدوياً إذا كنت تبحث عن شيء محدد.</span>
                                                </div>
                                            </div>
                                            <select wire:model.live="newOrderItems.{{ $i }}.menu_item_id" class="form-select">
                                                <option value="">اختر صنف</option>
                                                @foreach($this->menuItems as $item)
                                                    <option value="{{ $item->id }}">{{ $item->name }} - {{ \App\Helpers\Money::format($item->price) }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="cx-compose-side">
                @php $cartSummary = $this->cartSummary; @endphp
                <div class="cx-section cx-cart-summary">
                    <div class="cx-section-head"><strong><i class="bi bi-receipt-cutoff"></i> ملخص الطلب</strong></div>
                    <div class="cx-summary-meter">
                        <div>
                            <small>الأصناف</small>
                            <strong>{{ $cartSummary['lines'] }}</strong>
                        </div>
                        <div>
                            <small>الكمية</small>
                            <strong>{{ rtrim(rtrim(number_format((float) $cartSummary['quantity'], 2), '0'), '.') }}</strong>
                        </div>
                    </div>
                    <div class="cx-summary-row">
                        <span>الفرعي</span>
                        <strong>{{ \App\Helpers\Money::format($cartSummary['subtotal']) }}</strong>
                    </div>
                    @if($cartSummary['tax_total'] > 0)
                        <div class="cx-summary-row">
                            <span>الضريبة {{ rtrim(rtrim(number_format((float) $cartSummary['tax_rate'], 2), '0'), '.') }}%</span>
                            <strong>{{ \App\Helpers\Money::format($cartSummary['tax_total']) }}</strong>
                        </div>
                    @endif
                    @if($cartSummary['delivery_fee'] > 0)
                        <div class="cx-summary-row">
                            <span>التوصيل</span>
                            <strong>{{ \App\Helpers\Money::format($cartSummary['delivery_fee']) }}</strong>
                        </div>
                    @endif
                    <div class="cx-summary-total">
                        <span>الإجمالي المتوقع</span>
                        <strong>{{ \App\Helpers\Money::format($cartSummary['total']) }}</strong>
                    </div>
                    @if(count($cartSummary['warnings']))
                        <div class="cx-summary-warnings">
                            @foreach(array_slice($cartSummary['warnings'], 0, 4) as $warning)
                                <span><i class="bi bi-exclamation-circle"></i> {{ $warning }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="cx-section">
                    <div class="cx-section-head"><strong><i class="bi bi-send-check-fill"></i> التنفيذ</strong></div>
                    <label class="cx-toggle-row">
                        <input type="checkbox" wire:model="sendToKitchen">
                        <span>اعتماد الطلب وإرساله للمطبخ/البار مباشرة</span>
                    </label>
                    <label class="cx-toggle-row">
                        <input type="checkbox" wire:model="issueInvoiceAfterCreate">
                        <span>إصدار فاتورة فور إنشاء الطلب</span>
                    </label>
                    <button type="button" wire:click="createStandaloneOrder" wire:loading.attr="disabled" class="cx-btn-lg cx-btn-primary">
                        <i class="bi bi-check2-circle"></i>
                        <span wire:loading.remove wire:target="createStandaloneOrder">إنشاء الطلب</span>
                        <span wire:loading wire:target="createStandaloneOrder">جاري الإنشاء...</span>
                    </button>
                    <button type="button" wire:click="resetNewOrder" class="btn btn-light w-100 mt-2">
                        <i class="bi bi-arrow-counterclockwise"></i> تفريغ النموذج
                    </button>
                </div>
            </aside>
        </div>
    @else
    <div class="cx-grid">
        {{-- ═════════════════ LEFT: Sessions list ═════════════════ --}}
        <aside class="cx-sessions">
            <div class="cx-sessions-head">
                <h3 class="cx-title">
                    <i class="bi {{ $viewMode === 'remote' ? 'bi-telephone-fill' : 'bi-people-fill' }}"></i>
                    @if($viewMode === 'remote')
                        طلبات بدون طاولة
                    @else
                    الطاولات النشطة
                    @endif
                    <span class="cx-chip">{{ $viewMode === 'remote' ? $this->remoteOrders->count() : $this->sessions->count() }}</span>
                </h3>
            </div>

            <div class="cx-search-wrap">
                <i class="bi bi-search"></i>
                {{-- 500ms debounce — the sessions/orders query is moderate weight
                     (joins with table, customer, invoice). Longer debounce
                     stops UI flicker as the user types fast. --}}
                <input type="text"
                    wire:model.live.debounce.500ms="search"
                    placeholder="{{ $viewMode === 'remote' ? 'ابحث برقم الطلب أو الهاتف أو المرجع...' : 'ابحث برقم الطاولة أو اسم الزبون...' }}"
                    class="cx-search">
                @if($search)
                    <button type="button" wire:click="$set('search', '')" class="cx-search-clear">
                        <i class="bi bi-x-lg"></i>
                    </button>
                @endif
            </div>

            <div class="cx-sessions-list">
                @if($viewMode === 'remote')
                    @forelse($this->remoteOrders as $order)
                        @php
                            $invoice = $order->invoice;
                            $title = $order->order_type === 'delivery' ? 'دليفري' : 'استلام/سفري';
                            $identity = $order->customer_name ?: $order->customer?->name ?: $order->customer_phone ?: $order->external_reference ?: 'زبون غير محدد';
                        @endphp
                        <button type="button"
                            wire:click="selectRemoteOrder({{ $order->id }})"
                            class="cx-session-card {{ $selectedRemoteOrderId === $order->id ? 'is-active' : '' }} {{ $invoice ? 'has-invoice' : '' }}">
                            <div class="cx-session-head">
                                <div class="cx-table-num">
                                    <i class="bi {{ $order->order_type === 'delivery' ? 'bi-truck' : 'bi-bag-check' }}"></i>
                                    {{ $title }}
                                </div>
                                @if($invoice)
                                    @if($invoice->balance > 0)
                                        <span class="cx-status-pill cx-status-due">{{ \App\Helpers\Money::format($invoice->balance) }} متبقي</span>
                                    @else
                                        <span class="cx-status-pill cx-status-paid"><i class="bi bi-check-circle-fill"></i> مدفوعة</span>
                                    @endif
                                @else
                                    <span class="cx-status-pill cx-status-open">{{ $order->statusLabel() }}</span>
                                @endif
                            </div>
                            <div class="cx-session-meta">
                                <span><i class="bi {{ $order->sourceIcon() }}"></i> {{ $order->sourceLabel() }}</span>
                                <span><i class="bi bi-receipt"></i> {{ $order->items->count() }} صنف</span>
                            </div>
                            <div class="cx-session-meta">
                                <span>{{ $identity }}</span>
                            </div>
                            <div class="cx-session-total">
                                {{ \App\Helpers\Money::format($order->total) }}
                            </div>
                        </button>
                    @empty
                        <div class="cx-empty">
                            <i class="bi bi-telephone"></i>
                            <div>لا توجد طلبات بدون طاولة الآن</div>
                        </div>
                    @endforelse
                @else
                @forelse($this->sessions as $s)
                    @php
                        $invoice = $s->invoice;
                        $billRequested = $s->bill_requested_at && ! $invoice;
                        $ordersTotal = $s->orders->sum('total');
                        $openMin = (int) $s->opened_at->diffInMinutes(now());
                    @endphp
                    <button type="button"
                        wire:click="selectSession({{ $s->id }})"
                        class="cx-session-card {{ $selectedSessionId === $s->id ? 'is-active' : '' }} {{ $invoice ? 'has-invoice' : '' }} {{ $billRequested ? 'has-bill-request' : '' }}">
                        <div class="cx-session-head">
                            <div class="cx-table-num">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                                {{ $s->table?->number ?? '—' }}
                            </div>
                            @if($invoice)
                                @if($invoice->balance > 0)
                                    <span class="cx-status-pill cx-status-due">
                                        {{ \App\Helpers\Money::format($invoice->balance) }} متبقي
                                    </span>
                                @else
                                    <span class="cx-status-pill cx-status-paid">
                                        <i class="bi bi-check-circle-fill"></i> مدفوعة
                                    </span>
                                @endif
                            @elseif($billRequested)
                                <span class="cx-status-pill cx-status-bill">
                                    <i class="bi bi-receipt-cutoff"></i> طلب الفاتورة
                                </span>
                            @else
                                <span class="cx-status-pill cx-status-open">مفتوحة</span>
                            @endif
                        </div>
                        <div class="cx-session-meta">
                            <span><i class="bi bi-receipt"></i> {{ $s->orders->count() }} طلب</span>
                            <span>
                                <i class="bi bi-people"></i> {{ $s->cover_count ?? 1 }}
                            </span>
                            <span><i class="bi bi-clock"></i> {{ $openMin }} د</span>
                        </div>
                        <div class="cx-session-total">
                            {{ \App\Helpers\Money::format($ordersTotal) }}
                        </div>
                    </button>
                @empty
                    <div class="cx-empty">
                        <i class="bi bi-people"></i>
                        <div>ما في طاولات نشطة الآن</div>
                    </div>
                @endforelse
                @endif
            </div>
        </aside>

        {{-- ═════════════════ RIGHT: Session detail ═════════════════ --}}
        <main class="cx-detail">
            @php
                $session = $this->selectedSession;
                $remoteOrder = $this->selectedRemoteOrder;
            @endphp

            @if($viewMode === 'remote')
                @if(!$remoteOrder)
                    <div class="cx-placeholder">
                        <i class="bi bi-telephone-fill"></i>
                        <h4>اختر طلب بدون طاولة</h4>
                        <p>طلبات الهاتف، السفري، الدليفري والمنصات تظهر هنا للفوترة والتحصيل.</p>
                    </div>
                @else
                    @php $invoice = $remoteOrder->invoice; @endphp
                    <div class="cx-detail-head">
                        <div class="d-flex align-items-center gap-3">
                            <div class="cx-detail-table">
                                <i class="bi {{ $remoteOrder->order_type === 'delivery' ? 'bi-truck' : 'bi-bag-check' }}"></i>
                                <span>{{ $remoteOrder->order_type === 'delivery' ? 'دليفري' : 'استلام/سفري' }}</span>
                            </div>
                            <div class="text-muted small">
                                {{ $remoteOrder->sourceLabel() }} · {{ $remoteOrder->number }}
                                @if($remoteOrder->customer_name || $remoteOrder->customer_phone)
                                    · <strong>{{ $remoteOrder->customer_name ?: $remoteOrder->customer_phone }}</strong>
                                @endif
                            </div>
                        </div>
                        <button wire:click="clearSelection" class="btn btn-sm btn-light">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    @if($remoteOrder->delivery_address)
                        <div class="cx-bill-request-alert">
                            <div class="cx-bill-request-alert__icon"><i class="bi bi-geo-alt-fill"></i></div>
                            <div>
                                <strong>عنوان التوصيل</strong>
                                <p>{{ $remoteOrder->delivery_address }}</p>
                            </div>
                        </div>
                    @endif

                    <div class="row g-3">
                        <div class="col-xl-7">
                            <div class="cx-section">
                                <div class="cx-section-head">
                                    <strong><i class="bi bi-list-ul"></i> الأصناف ({{ $remoteOrder->items->count() }})</strong>
                                </div>
                                <div class="cx-orders">
                                    <div class="cx-order">
                                        <div class="cx-order-head">
                                            <strong>{{ $remoteOrder->number }}</strong>
                                            <span class="badge bg-{{ $remoteOrder->statusColor() }}">{{ $remoteOrder->statusLabel() }}</span>
                                        </div>
                                        @foreach($remoteOrder->items as $it)
                                            <div class="cx-item {{ $it->status==='cancelled' ? 'is-cancelled' : '' }}">
                                                <span class="cx-item-qty">×{{ $it->quantity }}</span>
                                                <span class="cx-item-name">{{ $it->name_snapshot }}</span>
                                                @if($it->modifiers->count())
                                                    <small class="text-muted d-block w-100 ps-4">{{ $it->modifiers->pluck('name_snapshot')->join(' · ') }}</small>
                                                @endif
                                                <span class="cx-item-price">{{ \App\Helpers\Money::format($it->subtotal) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-5">
                            @if(!$invoice)
                                <div class="cx-section">
                                    <div class="cx-totals-preview">
                                        <div class="d-flex justify-content-between py-1"><span>الفرعي</span><strong>{{ \App\Helpers\Money::format($remoteOrder->subtotal) }}</strong></div>
                                        @if($remoteOrder->tax_total > 0)<div class="d-flex justify-content-between py-1"><span>الضريبة</span><strong>{{ \App\Helpers\Money::format($remoteOrder->tax_total) }}</strong></div>@endif
                                        @if($remoteOrder->delivery_fee > 0)<div class="d-flex justify-content-between py-1"><span>التوصيل</span><strong>{{ \App\Helpers\Money::format($remoteOrder->delivery_fee) }}</strong></div>@endif
                                        <hr>
                                        <div class="d-flex justify-content-between py-1 cx-grand">
                                            <span>الإجمالي</span>
                                            <strong>{{ \App\Helpers\Money::format($remoteOrder->total) }}</strong>
                                        </div>
                                    </div>
                                    <button wire:click="issueRemoteInvoice" wire:loading.attr="disabled" class="cx-btn-lg cx-btn-primary">
                                        <i class="bi bi-receipt"></i>
                                        <span wire:loading.remove wire:target="issueRemoteInvoice">إصدار الفاتورة</span>
                                        <span wire:loading wire:target="issueRemoteInvoice">جاري...</span>
                                    </button>
                                </div>
                            @else
                                <div class="cx-section">
                                    <div class="cx-invoice-head">
                                        <div>
                                            <small class="text-muted">رقم الفاتورة</small>
                                            <div class="fw-bold" style="font-family: 'Courier New', monospace;">{{ $invoice->number }}</div>
                                        </div>
                                        <span class="badge bg-{{ $invoice->balance > 0 ? 'warning' : 'success' }}">{{ $invoice->status }}</span>
                                    </div>
                                    <div class="cx-totals">
                                        <div class="d-flex justify-content-between"><span>الفرعي</span><strong>{{ \App\Helpers\Money::format($invoice->subtotal) }}</strong></div>
                                        @if($invoice->tax_total > 0)<div class="d-flex justify-content-between"><span>الضريبة</span><strong>{{ \App\Helpers\Money::format($invoice->tax_total) }}</strong></div>@endif
                                        @if($invoice->delivery_fee > 0)<div class="d-flex justify-content-between"><span>التوصيل</span><strong>{{ \App\Helpers\Money::format($invoice->delivery_fee) }}</strong></div>@endif
                                        <div class="cx-grand d-flex justify-content-between mt-2">
                                            <span>الإجمالي</span>
                                            <strong>{{ \App\Helpers\Money::format($invoice->total) }}</strong>
                                        </div>
                                        <div class="d-flex justify-content-between text-success mt-2">
                                            <span>مدفوع</span><strong>{{ \App\Helpers\Money::format($invoice->paid_total) }}</strong>
                                        </div>
                                        @if($invoice->balance > 0)
                                            <div class="cx-balance d-flex justify-content-between mt-2">
                                                <span>المتبقي</span>
                                                <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                @if($invoice->balance > 0)
                                    <div class="cx-section">
                                        <div class="cx-section-head"><strong><i class="bi bi-cash-stack"></i> تسجيل دفعة</strong></div>
                                        <div class="cx-presets">
                                            <button type="button" wire:click="setAmount('{{ number_format((float) $invoice->balance, 2, '.', '') }}')" class="cx-preset cx-preset-primary">
                                                <small>المتبقي</small>
                                                <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong>
                                            </button>
                                        </div>
                                        <div class="cx-amount-wrap">
                                            <input type="number" step="0.01" min="0.01" wire:model.blur="paymentAmount" class="cx-amount-input" placeholder="0.00" autocomplete="off">
                                            <span class="cx-amount-symbol">{{ \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol')) }}</span>
                                        </div>
                                        <div class="cx-methods">
                                            @php
                                                $methods = [
                                                    'cash' => ['bi-cash-stack', 'نقدي'],
                                                    'card' => ['bi-credit-card', 'بطاقة'],
                                                    'transfer' => ['bi-bank', 'تحويل'],
                                                    'app' => ['bi-phone', 'تطبيق'],
                                                    'credit' => ['bi-hourglass', 'دين'],
                                                ];
                                            @endphp
                                            @foreach($methods as $m => [$icon, $label])
                                                <button type="button" wire:click="setMethod('{{ $m }}')" class="cx-method {{ $paymentMethod === $m ? 'is-active' : '' }}">
                                                    <i class="bi {{ $icon }}"></i><span>{{ $label }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                        @if(in_array($paymentMethod, ['card', 'transfer', 'app']))
                                            <input type="text" wire:model.blur="paymentReference" placeholder="رقم المرجع (اختياري)" class="form-control mb-2">
                                        @endif
                                        <button wire:click="recordPayment" wire:loading.attr="disabled" class="cx-btn-lg cx-btn-success">
                                            <i class="bi bi-check-circle-fill"></i>
                                            <span wire:loading.remove wire:target="recordPayment">تأكيد الدفعة</span>
                                            <span wire:loading wire:target="recordPayment">جاري التسجيل...</span>
                                        </button>
                                    </div>
                                @endif

                                @if($invoice->payments->count())
                                    <div class="cx-section">
                                        <div class="cx-section-head"><strong><i class="bi bi-clock-history"></i> سجل الدفعات</strong></div>
                                        @foreach($invoice->payments as $p)
                                            <div class="cx-payment-row">
                                                <strong>{{ \App\Helpers\Money::format($p->amount) }}</strong>
                                                <small class="text-muted">{{ $p->method }} · {{ $p->paid_at?->format('H:i') ?? $p->created_at->format('H:i') }}</small>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="cx-actions">
                                    <a href="{{ route('admin.cashier.print', $invoice) }}" target="_blank" class="btn btn-light btn-sm"><i class="bi bi-printer"></i> طباعة</a>
                                    <a href="{{ route('admin.cashier.pdf', $invoice) }}" class="btn btn-light btn-sm"><i class="bi bi-file-pdf"></i> PDF</a>
                                    @if((float) $invoice->paid_total > (float) ($invoice->refunded_total ?? 0))
                                        <button type="button" wire:click="openRefund" class="btn btn-sm" style="background:rgba(185,28,28,.1); color:#b91c1c; border:1px solid rgba(185,28,28,.3);">
                                            <i class="bi bi-arrow-counterclockwise"></i> استرداد
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @elseif(!$session)
                <div class="cx-placeholder">
                    <i class="bi bi-cursor-fill"></i>
                    <h4>اختر طاولة من القائمة</h4>
                    <p>اضغط على أي طاولة نشطة لبدء إدارة فاتورتها.</p>
                </div>
            @else
                @php $invoice = $session->invoice; @endphp

                {{-- Detail header --}}
                <div class="cx-detail-head">
                    <div class="d-flex align-items-center gap-3">
                        <div class="cx-detail-table">
                            <i class="bi bi-grid-3x3-gap-fill"></i>
                            <span>طاولة {{ $session->table?->number }}</span>
                        </div>
                        <div class="text-muted small">
                            {{ $session->cover_count ?? 1 }} أشخاص · منذ {{ $session->opened_at->diffForHumans() }}
                            @if($session->customer_name)
                                · <strong>{{ $session->customer_name }}</strong>
                            @endif
                        </div>
                    </div>
                    <button wire:click="clearSelection" class="btn btn-sm btn-light">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                @if($session->bill_requested_at && ! $invoice)
                    <div class="cx-bill-request-alert">
                        <div class="cx-bill-request-alert__icon">
                            <i class="bi bi-receipt-cutoff"></i>
                        </div>
                        <div>
                            <strong>الزبون طلب الفاتورة</strong>
                            <span>منذ {{ $session->bill_requested_at->diffForHumans() }}</span>
                            @if($session->bill_request_note)
                                <p>{{ $session->bill_request_note }}</p>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="row g-3">
                    {{-- Left column: orders list --}}
                    <div class="col-xl-7">
                        <div class="cx-section">
                            <div class="cx-section-head">
                                <strong><i class="bi bi-list-ul"></i> الطلبات ({{ $session->orders->count() }})</strong>
                            </div>
                            <div class="cx-orders">
                                @foreach($session->orders as $order)
                                    <div class="cx-order">
                                        <div class="cx-order-head">
                                            <strong>{{ $order->number }}</strong>
                                            <span class="badge bg-{{ $order->statusColor() }}">{{ $order->statusLabel() }}</span>
                                        </div>
                                        @foreach($order->items as $it)
                                            <div class="cx-item {{ $it->status==='cancelled' ? 'is-cancelled' : '' }}">
                                                <span class="cx-item-qty">×{{ $it->quantity }}</span>
                                                <span class="cx-item-name">{{ $it->name_snapshot }}</span>
                                                @if($it->modifiers->count())
                                                    <small class="text-muted d-block w-100 ps-4">{{ $it->modifiers->pluck('name_snapshot')->join(' · ') }}</small>
                                                @endif
                                                <span class="cx-item-price">{{ \App\Helpers\Money::format($it->subtotal) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Right column: invoice + payment --}}
                    <div class="col-xl-5">
                        @if(!$invoice)
                            {{-- No invoice yet → issue button OR close-without-billing if there's nothing to invoice --}}
                            <div class="cx-section">
                                <div class="cx-totals-preview">
                                    @php
                                        $sub = $session->orders->sum('subtotal');
                                        $tax = $session->orders->sum('tax_total');
                                        $svc = $session->orders->sum('service_total');
                                        $tot = $session->orders->sum('total');
                                    @endphp
                                    <div class="d-flex justify-content-between py-1"><span>الفرعي</span><strong>{{ \App\Helpers\Money::format($sub) }}</strong></div>
                                    @if($tax > 0)<div class="d-flex justify-content-between py-1"><span>الضريبة</span><strong>{{ \App\Helpers\Money::format($tax) }}</strong></div>@endif
                                    @if($svc > 0)<div class="d-flex justify-content-between py-1"><span>الخدمة</span><strong>{{ \App\Helpers\Money::format($svc) }}</strong></div>@endif
                                    <hr>
                                    <div class="d-flex justify-content-between py-1 cx-grand">
                                        <span>الإجمالي</span>
                                        <strong>{{ \App\Helpers\Money::format($tot) }}</strong>
                                    </div>
                                </div>

                                @if($this->canCloseWithoutBilling)
                                    {{-- All orders cancelled (or none) — nothing to bill.
                                         The regular "issue invoice" flow would fail. Show
                                         a clear "close session & free the table" button
                                         instead, with confirmation to avoid accidents. --}}
                                    <div class="alert alert-warning d-flex align-items-start gap-2 mb-2" role="alert" style="font-size: .82rem;">
                                        <i class="bi bi-info-circle-fill mt-1"></i>
                                        <div>
                                            لا توجد أصناف للفوترة (كل الطلبات ملغاة أو الإجمالي صفر).
                                            أغلق الجلسة وحرّر الطاولة.
                                        </div>
                                    </div>
                                    <button type="button"
                                            wire:click="closeSessionWithoutBilling"
                                            wire:confirm="هل تريد إغلاق الجلسة وتحرير الطاولة بدون فوترة؟ لا يمكن التراجع."
                                            wire:loading.attr="disabled"
                                            class="cx-btn-lg cx-btn-warning">
                                        <i class="bi bi-door-closed-fill"></i>
                                        <span wire:loading.remove wire:target="closeSessionWithoutBilling">إغلاق الجلسة وتحرير الطاولة</span>
                                        <span wire:loading wire:target="closeSessionWithoutBilling">جارٍ الإغلاق…</span>
                                    </button>
                                @else
                                    <button wire:click="issueInvoice" wire:loading.attr="disabled" class="cx-btn-lg cx-btn-primary">
                                        <i class="bi bi-receipt"></i>
                                        <span wire:loading.remove>إصدار الفاتورة</span>
                                        <span wire:loading>جارٍ...</span>
                                    </button>
                                @endif
                            </div>
                        @else
                            {{-- Invoice totals + payment --}}
                            <div class="cx-section">
                                <div class="cx-invoice-head">
                                    <div>
                                        <small class="text-muted">رقم الفاتورة</small>
                                        <div class="fw-bold" style="font-family: 'Courier New', monospace;">{{ $invoice->number }}</div>
                                    </div>
                                    <span class="badge bg-{{ $invoice->balance > 0 ? 'warning' : 'success' }}">
                                        {{ match($invoice->status) {
                                            'paid'           => 'مدفوعة بالكامل',
                                            'partially_paid' => 'مدفوعة جزئياً',
                                            'issued'         => 'صادرة',
                                            'cancelled'      => 'ملغاة',
                                            'unpaid_writeoff'=> 'شطب',
                                            default          => $invoice->status,
                                        } }}
                                    </span>
                                </div>

                                <div class="cx-totals">
                                    <div class="d-flex justify-content-between"><span>الفرعي</span><strong>{{ \App\Helpers\Money::format($invoice->subtotal) }}</strong></div>
                                    @if($invoice->discount_total > 0)
                                        <div class="d-flex justify-content-between text-success"><span>الخصم</span><strong>−{{ \App\Helpers\Money::format($invoice->discount_total) }}</strong></div>
                                    @endif
                                    @if($invoice->tax_total > 0)
                                        <div class="d-flex justify-content-between"><span>الضريبة</span><strong>{{ \App\Helpers\Money::format($invoice->tax_total) }}</strong></div>
                                    @endif
                                    @if($invoice->service_total > 0)
                                        <div class="d-flex justify-content-between"><span>الخدمة</span><strong>{{ \App\Helpers\Money::format($invoice->service_total) }}</strong></div>
                                    @endif
                                    <div class="cx-grand d-flex justify-content-between mt-2">
                                        <span>الإجمالي</span>
                                        <strong>{{ \App\Helpers\Money::format($invoice->total) }}</strong>
                                    </div>
                                    <div class="d-flex justify-content-between text-success mt-2">
                                        <span>مدفوع</span><strong>{{ \App\Helpers\Money::format($invoice->paid_total) }}</strong>
                                    </div>
                                    @if(($invoice->refunded_total ?? 0) > 0)
                                        <div class="d-flex justify-content-between" style="color:#b91c1c;">
                                            <span>مسترد</span><strong>−{{ \App\Helpers\Money::format($invoice->refunded_total) }}</strong>
                                        </div>
                                    @endif
                                    @if($invoice->balance > 0)
                                        <div class="cx-balance d-flex justify-content-between mt-2">
                                            <span>المتبقي</span>
                                            <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Payment keypad --}}
                            @if($invoice->balance > 0)
                                <div class="cx-section">
                                    <div class="cx-section-head"><strong><i class="bi bi-cash-stack"></i> تسجيل دفعة</strong></div>

                                    {{-- Quick amount presets --}}
                                    <div class="cx-presets">
                                        <button type="button" wire:click="setAmount('{{ number_format((float) $invoice->balance, 2, '.', '') }}')" class="cx-preset cx-preset-primary">
                                            <small>المتبقي</small>
                                            <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong>
                                        </button>
                                        <button type="button" wire:click="setAmount('{{ number_format((float) $invoice->balance / 2, 2, '.', '') }}')" class="cx-preset">
                                            <small>النصف</small>
                                            <strong>{{ \App\Helpers\Money::format($invoice->balance / 2) }}</strong>
                                        </button>
                                        <button type="button" wire:click="setAmount('{{ number_format((float) $invoice->balance / 4, 2, '.', '') }}')" class="cx-preset">
                                            <small>ربع المبلغ</small>
                                            <strong>{{ \App\Helpers\Money::format($invoice->balance / 4) }}</strong>
                                        </button>
                                    </div>

                                    {{-- Amount input — wire:model.blur so digits don't trigger
                                         per-keystroke server round-trips. The "تأكيد الدفعة" click
                                         still commits the latest value automatically. --}}
                                    <div class="cx-amount-wrap">
                                        <input type="number" step="0.01" min="0.01"
                                            wire:model.blur="paymentAmount"
                                            class="cx-amount-input"
                                            placeholder="0.00"
                                            autocomplete="off">
                                        <span class="cx-amount-symbol">{{ \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol')) }}</span>
                                    </div>
                                    @error('paymentAmount') <small class="text-danger d-block mb-2">{{ $message }}</small> @enderror

                                    {{-- Method selector --}}
                                    <div class="cx-methods">
                                        @php
                                            $methods = [
                                                'cash'     => ['bi-cash-stack', 'نقدي'],
                                                'card'     => ['bi-credit-card', 'بطاقة'],
                                                'transfer' => ['bi-bank', 'تحويل'],
                                                'app'      => ['bi-phone', 'تطبيق'],
                                                'credit'   => ['bi-hourglass', 'دين'],
                                            ];
                                        @endphp
                                        @foreach($methods as $m => [$icon, $label])
                                            <button type="button"
                                                wire:click="setMethod('{{ $m }}')"
                                                class="cx-method {{ $paymentMethod === $m ? 'is-active' : '' }}">
                                                <i class="bi {{ $icon }}"></i>
                                                <span>{{ $label }}</span>
                                            </button>
                                        @endforeach
                                    </div>

                                    @if(in_array($paymentMethod, ['card', 'transfer', 'app']))
                                        <input type="text" wire:model.blur="paymentReference"
                                            placeholder="رقم المرجع (اختياري)"
                                            class="form-control mb-2">
                                    @endif

                                    {{-- Submit --}}
                                    <button wire:click="recordPayment" wire:loading.attr="disabled"
                                        class="cx-btn-lg cx-btn-success">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span wire:loading.remove>تأكيد الدفعة</span>
                                        <span wire:loading>جارٍ التسجيل...</span>
                                    </button>
                                </div>
                            @endif

                            {{-- Payment history --}}
                            @if($invoice->payments->count())
                                <div class="cx-section">
                                    <div class="cx-section-head"><strong><i class="bi bi-clock-history"></i> سجل الدفعات</strong></div>
                                    @foreach($invoice->payments as $p)
                                        <div class="cx-payment-row">
                                            <div>
                                                <strong>{{ \App\Helpers\Money::format($p->amount) }}</strong>
                                                <span class="badge bg-secondary ms-1">{{ match($p->method) {
                                                    'cash' => 'نقدي', 'card' => 'بطاقة', 'transfer' => 'تحويل',
                                                    'app' => 'تطبيق', 'credit' => 'دين', default => $p->method,
                                                } }}</span>
                                                @if($p->reference)<small class="text-muted">#{{ $p->reference }}</small>@endif
                                            </div>
                                            <small class="text-muted">{{ $p->paid_at?->format('H:i') ?? $p->created_at->format('H:i') }}</small>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Action buttons --}}
                            <div class="cx-actions">
                                <a href="{{ route('admin.cashier.print', $invoice) }}" target="_blank" class="btn btn-light btn-sm">
                                    <i class="bi bi-printer"></i> طباعة
                                </a>
                                <a href="{{ route('admin.cashier.pdf', $invoice) }}" class="btn btn-light btn-sm">
                                    <i class="bi bi-file-pdf"></i> PDF
                                </a>
                                @if((float) $invoice->paid_total > (float) ($invoice->refunded_total ?? 0))
                                    <button type="button" wire:click="openRefund" class="btn btn-sm" style="background:rgba(185,28,28,.1); color:#b91c1c; border:1px solid rgba(185,28,28,.3);">
                                        <i class="bi bi-arrow-counterclockwise"></i> استرداد
                                    </button>
                                @endif
                            </div>

                            {{-- Refund modal --}}
                            @if($refundOpen)
                                <div class="cx-modal-overlay" wire:click.self="closeRefund">
                                    <div class="cx-modal">
                                        <div class="cx-modal-head">
                                            <strong>استرداد مبلغ</strong>
                                            <button wire:click="closeRefund" class="btn-close"></button>
                                        </div>
                                        <div class="p-3">
                                            <div class="mb-2">
                                                <label class="form-label small">المبلغ</label>
                                                <input type="number" step="0.01" min="0.01"
                                                    wire:model="refundAmount" class="form-control">
                                                @error('refundAmount') <small class="text-danger">{{ $message }}</small> @enderror
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small">الطريقة</label>
                                                <select wire:model="refundMethod" class="form-select">
                                                    <option value="cash">نقدي</option>
                                                    <option value="card">بطاقة</option>
                                                    <option value="transfer">تحويل</option>
                                                    <option value="app">تطبيق</option>
                                                    <option value="credit">رصيد زبون</option>
                                                </select>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small">السبب</label>
                                                <textarea wire:model="refundReason" class="form-control" rows="2" placeholder="سبب الاسترداد..."></textarea>
                                                @error('refundReason') <small class="text-danger">{{ $message }}</small> @enderror
                                            </div>
                                            <div class="d-flex gap-2 mt-3">
                                                <button wire:click="closeRefund" class="btn btn-light flex-grow-1">تراجع</button>
                                                <button wire:click="submitRefund" class="btn btn-danger flex-grow-2" style="flex: 2;">
                                                    <i class="bi bi-arrow-counterclockwise"></i> تأكيد الاسترداد
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        </main>
    </div>
    @endif

    {{-- Toast --}}
    <div x-data="{ show: false, type: 'success', msg: '' }"
        x-on:toast.window="type = $event.detail.type; msg = $event.detail.message; show = true; setTimeout(() => show = false, 3500)"
        x-show="show" x-cloak
        x-transition.duration.200ms
        class="cx-toast"
        :class="`cx-toast-${type}`">
        <i class="bi" :class="type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <span x-text="msg"></span>
    </div>
</div>

{{--
    cashierCartLine() — per-line Alpine helper
    Holds local state (qty + selected mods) for INSTANT visual feedback on
    modifier toggle and quantity stepper. Server is updated only on a 500ms
    debounce so the cartSummary panel eventually catches up — no per-click
    server round-trip.

    The line total displayed inside the line uses the local Alpine math, so
    the user sees their changes reflected immediately (~0ms). The cart
    summary in the side panel updates after the debounce (~500ms) which is
    acceptable since it's in a separate area the user isn't focused on.
--}}
<script>
window.cashierCartLine = function (config) {
    return {
        wireIdx: config.wireIdx,
        itemPrice: Number(config.itemPrice) || 0,
        qty: Number(config.initialQty) || 1,
        // Cast every id to int so checkbox value="" matches via x-model.number
        mods: (config.initialMods || []).map(id => Number(id)),
        modifierPrices: config.modifierPrices || {},

        /** Sum of price-deltas of currently-selected modifiers. */
        modifierTotal() {
            let sum = 0;
            for (const id of this.mods) {
                if (this.modifierPrices[id] !== undefined) sum += Number(this.modifierPrices[id]);
            }
            return sum;
        },
        lineTotal() {
            return (this.itemPrice + this.modifierTotal()) * Math.max(1, Number(this.qty) || 1);
        },
        formattedTotal() {
            return this.lineTotal().toFixed(2) + ' ₪';
        },

        /** Sync the modifier selection to Livewire (debounced via @change). */
        pushMods() {
            const clean = Array.from(new Set(this.mods.map(Number).filter(Boolean)));
            this.$wire.set(`newOrderItems.${this.wireIdx}.modifier_ids`, clean);
        },

        /** Sync quantity to Livewire (debounced via @input or button @click). */
        pushQty() {
            const v = Math.max(1, Number(this.qty) || 1);
            this.qty = v;
            this.$wire.set(`newOrderItems.${this.wireIdx}.quantity`, v);
        },
    };
};
</script>
