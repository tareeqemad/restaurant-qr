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
use App\Models\Refund;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Support\BranchContext;
use App\Support\PaymentMethods;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    /** Sticky banner showing the just-created customer's PIN. Lives across
     *  Livewire renders (unlike session flash which Livewire eats on the
     *  first morph). Cleared when the cashier dismisses it. */
    public ?array $pinAlert = null;

    // Staff-entered no-table order state
    public string $itemSearch = '';
    public string $selectedCategoryId = '';
    public string $orderType = 'takeaway';
    public string $orderSource = 'other';
    public string $customerName = '';
    public string $customerPhone = '';
    public string $deliveryAddress = '';
    public string $deliveryFee = '0';
    public string $externalReference = '';
    public string $deliveryReceiver = '';
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

    /** Idempotency token for recordPayment — Livewire has no per-render form
     *  token like the classic pay form's `_idem`, so the component carries
     *  one. Both requests of a double-click send the SAME token; the first
     *  Cache::add claims it, the second is bounced. Rotated after every
     *  successful payment so the next (legitimate) payment gets a new token. */
    public string $paymentIdem = '';

    // Refund form state
    public bool $refundOpen = false;
    public string $refundAmount = '';
    public string $refundMethod = 'cash';
    public string $refundReason = '';

    // Discount form state — driven from the totals card. Open/close flips a
    // local panel (no Bootstrap modal, no nested form) so it morphs cleanly
    // alongside the rest of the invoice block on each Livewire round trip.
    public bool $discountOpen = false;
    public string $discountType = 'percent';
    public string $discountValue = '';
    public ?int $discountCategoryLookupId = null;
    public string $discountReason = '';
    public string $discountName = '';

    public function mount(?int $session = null): void
    {
        $this->authorize('viewAny', Payment::class);

        $this->selectedSessionId = $session;
        $this->platformCommissionPct = '0';
        $this->paymentMethod = $this->defaultPaymentMethod();
        $this->refundMethod = $this->defaultRefundMethod();
        $this->paymentIdem = (string) Str::uuid();
    }

    // ─── Computed (reactive) ──────────────────────────────────────────

    #[Computed]
    public function sessions()
    {
        $query = TableSession::with(['table', 'orders', 'invoice', 'customer'])
            ->with(['customer' => fn ($q) => $q->withCount('tableSessions')->with('loyaltyCustomer')])
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

        $session = TableSession::with([
            'table',
            'customer.loyaltyCustomer',
            'orders.items.modifiers',
            'invoice.payments', 'invoice.refunds.processor', 'invoice.splits',
        ])->find($this->selectedSessionId);

        // Count the linked customer's lifetime visits — one extra query, only
        // when a session is opened in the detail panel, so the cashier sees
        // "this is their 7th visit" without a perf hit on the list view.
        $session?->customer?->loadCount('tableSessions');

        return $session;
    }

    #[Computed]
    public function selectedRemoteOrder(): ?Order
    {
        if (!$this->selectedRemoteOrderId) return null;

        return Order::with([
            'customer', 'items.modifiers',
            'invoice.payments', 'invoice.refunds.processor', 'invoice.splits',
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

        if ($this->orderSource === 'phone'
            && trim($this->customerPhone) === '') {
            $warnings[] = 'أدخل هاتف الزبون';
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
        $methodTotals = Payment::query()
            ->whereDate('created_at', today())
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($total) => (float) $total)
            ->all();

        foreach (array_keys(PaymentMethods::catalog()) as $method) {
            $methodTotals[$method] ??= 0.0;
        }

        // Net the CASH line of same-day completed cash refunds so the drawer
        // figure reflects what's actually in it. Adjusted in place so the
        // per-method breakdown card and the headline stay consistent.
        $cashRefunds = (float) \App\Models\Refund::whereDate('refunded_at', today())
            ->where('method', 'cash')->where('status', 'completed')->sum('amount');
        $methodTotals['cash'] = max(0, ($methodTotals['cash'] ?? 0.0) - $cashRefunds);

        return [
            'invoices' => Invoice::whereDate('created_at', today())->count(),
            'revenue'  => (float) Invoice::whereDate('created_at', today())
                                ->where('status', 'paid')->sum('paid_total'),
            'cash'     => $methodTotals['cash'] ?? 0.0,
            'non_cash' => collect($methodTotals)->except('cash')->sum(),
            'methods'  => $methodTotals,
        ];
    }

    #[Computed]
    public function paymentMethods(): array
    {
        return collect(PaymentMethods::catalog())
            ->filter(fn (array $meta) => (bool) $meta['enabled'])
            ->all();
    }

    #[Computed]
    public function refundMethods(): array
    {
        return collect(Refund::ACTIVE_METHODS)
            ->mapWithKeys(fn (string $method) => [$method => Refund::METHODS[$method] ?? $method])
            ->all();
    }

    protected function enabledPaymentMethods(): array
    {
        return PaymentMethods::enabled() ?: ['cash'];
    }

    protected function defaultPaymentMethod(): string
    {
        return $this->enabledPaymentMethods()[0] ?? 'cash';
    }

    protected function defaultRefundMethod(): string
    {
        return Refund::ACTIVE_METHODS[0] ?? 'cash';
    }

    // ─── Action guard (same pattern as the kitchen board) ─────────────

    /**
     * Shared wrapper for money-touching wire actions. Workflow guards throw
     * RuntimeException with an Arabic message — surface it as a toast instead
     * of Livewire's full-screen English error modal. Real authorization
     * failures (authorize/abort 403) and validation errors rethrow so their
     * native handling (status page / inline @error) stays intact.
     */
    protected function guardAction(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            // Symfony's HttpException IS a RuntimeException — rethrow before
            // the catch below swallows a real 403/404.
            throw $e;
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());
        } catch (\Throwable $e) {
            // QueryException & friends: report for the log, keep the screen
            // Arabic — never paint a raw SQL message at the register.
            report($e);
            $this->dispatch('toast', type: 'error', message: 'حدث خطأ غير متوقع — أعد المحاولة أو حدّث الصفحة.');
        }
    }

    // ─── Actions ──────────────────────────────────────────────────────

    public function setViewMode(string $mode): void
    {
        if (! in_array($mode, ['tables', 'remote', 'new'], true)) {
            return;
        }

        $this->viewMode = $mode;
        $this->reset(['search', 'paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen', 'refundAmount', 'refundReason']);
        $this->paymentMethod = $this->defaultPaymentMethod();
        $this->refundMethod = $this->defaultRefundMethod();

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
        $this->paymentMethod = $this->defaultPaymentMethod();
        $this->refundMethod = $this->defaultRefundMethod();
        // NEVER pre-fill the amount: a pre-armed full-balance value plus a
        // default cash method meant one stray tap took a full payment. The
        // «المتبقي» preset button is the deliberate one-tap filler instead.
    }

    public function selectRemoteOrder(int $id): void
    {
        $this->viewMode = 'remote';
        $this->selectedRemoteOrderId = $id;
        $this->selectedSessionId = null;
        $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen', 'refundAmount', 'refundReason']);
        $this->paymentMethod = $this->defaultPaymentMethod();
        $this->refundMethod = $this->defaultRefundMethod();
        // No amount pre-fill — see selectSession for the WHY.
    }

    public function clearSelection(): void
    {
        $this->selectedSessionId = null;
        $this->selectedRemoteOrderId = null;
        $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen']);
        $this->paymentMethod = $this->defaultPaymentMethod();
        $this->refundMethod = $this->defaultRefundMethod();
    }

    public function setAmount(string|float $amount): void
    {
        $this->paymentAmount = (string) $amount;
    }

    public function setMethod(string $method): void
    {
        if (! in_array($method, $this->enabledPaymentMethods(), true)) {
            return;
        }

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
        $this->platformCommissionPct = '0';
        $this->externalReference = '';

        if ($value !== 'delivery') {
            $this->deliveryReceiver = '';
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
        if (! in_array($source, ['other', 'phone', 'delivery'], true)) {
            return;
        }

        $this->orderSource = $source;
        $this->issueInvoiceAfterCreate = $source !== 'phone';

        if ($source === 'delivery' && $this->orderType !== 'delivery') {
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
            'externalReference', 'deliveryReceiver', 'orderNotes',
        ]);
        $this->orderType = 'takeaway';
        $this->orderSource = 'other';
        $this->deliveryFee = '0';
        $this->platformCommissionPct = '0';
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
            'orderSource' => ['required', 'in:other,phone,delivery'],
            'customerName' => ['nullable', 'string', 'max:120'],
            'customerPhone' => ['nullable', 'string', 'max:32'],
            'deliveryAddress' => ['nullable', 'string', 'max:500'],
            'deliveryFee' => ['nullable', 'numeric', 'min:0'],
            'deliveryReceiver' => ['nullable', 'string', 'max:120'],
            'orderNotes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'orderType' => 'نوع الطلب',
            'orderSource' => 'مصدر الطلب',
            'customerName' => 'اسم الزبون',
            'customerPhone' => 'هاتف الزبون',
            'deliveryAddress' => 'عنوان التوصيل',
            'deliveryFee' => 'رسوم التوصيل',
            'deliveryReceiver' => 'الجهة المستلمة',
        ]);

        if ($this->orderType === 'delivery' && trim($this->deliveryAddress) === '') {
            $this->addError('deliveryAddress', 'عنوان التوصيل مطلوب لطلبات الدليفري.');
            return;
        }

        if ($this->orderSource === 'phone' && trim($this->customerPhone) === '') {
            $this->addError('customerPhone', 'أدخل رقم هاتف الزبون.');
            return;
        }

        $this->guardAction(function () use ($orders, $billing) {
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
                    'delivery_receiver' => trim($this->deliveryReceiver) ?: null,
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
                // Invoice issued, amount left EMPTY on purpose — the cashier
                // arms the payment via the «المتبقي» preset, never by default.
                $billing->issueInvoiceForOrder($order, auth()->id());
            }

            $this->selectedRemoteOrderId = $order->id;
            $this->selectedSessionId = null;
            $this->viewMode = 'remote';
            $this->resetNewOrder();
            unset($this->remoteOrders, $this->selectedRemoteOrder);

            $this->dispatch('toast', type: 'success', message: "تم إنشاء الطلب {$order->number}");
        });
    }

    public function issueRemoteInvoice(BillingService $billing): void
    {
        $this->guardAction(function () use ($billing) {
            $this->authorize('create', Payment::class);

            $order = $this->selectedRemoteOrder;
            // A cancelled invoice doesn't block re-issue — BillingService
            // skips cancelled rows when looking for an existing invoice, and
            // it refuses (Arabic error) when the ORDER itself is cancelled.
            if (! $order || ($order->invoice && $order->invoice->status !== 'cancelled')) return;

            $billing->issueInvoiceForOrder($order, auth()->id());
            unset($this->selectedRemoteOrder, $this->remoteOrders);
            $this->dispatch('toast', type: 'success', message: 'تم إصدار الفاتورة');
        });
    }

    public function issueInvoice(BillingService $billing): void
    {
        $this->guardAction(function () use ($billing) {
            $this->authorize('create', Payment::class);

            $session = $this->selectedSession;
            // Same re-issue-after-cancel rule as issueRemoteInvoice above.
            if (!$session || ($session->invoice && $session->invoice->status !== 'cancelled')) return;

            $billing->issueInvoice($session, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'تم إصدار الفاتورة');
            unset($this->selectedSession, $this->sessions);   // bust cache
            // Amount stays empty — the «المتبقي» preset is the deliberate filler.
        });
    }

    public function recordPayment(BillingService $billing): void
    {
        $this->guardAction(function () use ($billing) {
            $this->authorize('create', Payment::class);

            $this->validate([
                'paymentAmount' => ['required', 'numeric', 'min:0.01'],
                'paymentMethod' => ['required', Rule::in($this->enabledPaymentMethods())],
            ], attributes: [
                'paymentAmount' => 'قيمة الدفعة',
                'paymentMethod' => 'طريقة الدفع',
            ]);

            $invoice = $this->activeInvoice();
            if (!$invoice) {
                $this->dispatch('toast', type: 'error', message: 'لم تُصدر الفاتورة بعد');
                return;
            }

            // Split invoices are collected ONLY via the per-split buttons on
            // the classic page — a direct payment here would shrink the
            // balance and strand the (now-too-large) splits as unpayable.
            if ($invoice->splits()->exists()) {
                $this->dispatch('toast', type: 'warning', message: 'الفاتورة مقسّمة — حصّل من أزرار الأجزاء في صفحة الجلسة.');
                return;
            }

            // Idempotency — mirrors CashierController::pay's `_idem` guard. A
            // genuine double-submit of ONE partial payment posts twice and
            // neither exceeds the balance, so nothing else rejects the dupe.
            // First request claims the token; the second bounces here.
            $token = $this->paymentIdem;
            if ($token !== '' && ! Cache::add('idem:pay:'.$token, true, now()->addMinutes(10))) {
                $this->dispatch('toast', type: 'warning', message: 'تم تسجيل هذه الدفعة بالفعل — مُنع إرسال مكرر.');
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
            } catch (\Throwable $e) {
                // Release the claimed token so a legitimate retry after a
                // failure (e.g. amount > balance) isn't bounced as a dupe.
                if ($token !== '') Cache::forget('idem:pay:'.$token);
                throw $e;
            }

            $this->paymentIdem = (string) Str::uuid();   // next payment = fresh token
            $this->dispatch('toast', type: 'success', message: "تم تسجيل دفعة بقيمة {$this->paymentAmount}");
            $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes']);
            unset($this->selectedSession, $this->selectedRemoteOrder, $this->remoteOrders);
            // NO balance re-fill after a partial payment — the cashier arms
            // the next payment deliberately via the «المتبقي» preset.
        });
    }

    public function openRefund(): void
    {
        $invoice = $this->activeInvoice();
        if (!$invoice) return;

        $this->refundOpen = true;
        $refundable = max(0, (float) $invoice->paid_total - (float) ($invoice->refunded_total ?? 0));
        $this->refundAmount = (string) number_format($refundable, 2, '.', '');
        $this->refundMethod = $this->defaultRefundMethod();
        $this->refundReason = '';
    }

    public function closeRefund(): void
    {
        $this->refundOpen = false;
        $this->reset(['refundAmount', 'refundMethod', 'refundReason']);
    }

    public function submitRefund(RefundService $refundService): void
    {
        $this->guardAction(function () use ($refundService) {
            // Same gate as the controller path (RefundController::store) —
            // this was the only refund surface without it. abort_unless is
            // the hard fallback in case the policy mapping ever goes missing.
            abort_unless(auth()->user()?->can('create', Refund::class), 403);
            $this->authorize('create', Refund::class);

            $this->validate([
                'refundAmount' => ['required', 'numeric', 'min:0.01'],
                'refundMethod' => ['required', Rule::in(Refund::ACTIVE_METHODS)],
                'refundReason' => ['required', 'string', 'max:500'],
            ], attributes: [
                'refundAmount' => 'المبلغ',
                'refundMethod' => 'طريقة الاسترداد',
                'refundReason' => 'السبب',
            ]);

            $invoice = $this->activeInvoice();
            if (!$invoice) return;

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
        });
    }

    // ─── Discount (cashier-applied) ───────────────────────────────────

    public function openDiscount(): void
    {
        $this->authorize('apply', \App\Models\OrderDiscount::class);
        $this->discountOpen = true;
        $this->discountType = 'percent';
        $this->discountValue = '';
        // Default to the first category in the catalog (typically "regular
        // customer" by display_order). Falls back to null if the lookup is
        // empty — the form will render with no preselected category.
        $first = \App\Models\Lookup::for('discount_categories')->first();
        $this->discountCategoryLookupId = $first?->id;
        $this->discountReason = '';
        $this->discountName = '';
    }

    public function closeDiscount(): void
    {
        $this->discountOpen = false;
        $this->reset(['discountValue', 'discountReason', 'discountName']);
    }

    /**
     * Apply the discount to whatever the cashier is looking at — the
     * standalone portal/takeaway order if one is selected, otherwise the
     * whole table session (fans out one OrderDiscount per non-cancelled
     * order, prorated for fixed amounts).
     */
    public function submitDiscount(\App\Services\OrderDiscountService $service): void
    {
        $this->authorize('apply', \App\Models\OrderDiscount::class);

        $this->validate([
            'discountType'              => ['required', 'in:percent,fixed'],
            'discountValue'             => ['required', 'numeric', 'min:0.01'],
            'discountReason'            => ['required', 'string', 'max:500'],
            'discountCategoryLookupId'  => ['nullable', 'integer', 'exists:lookups,id'],
            'discountName'              => ['nullable', 'string', 'max:120'],
        ], attributes: [
            'discountValue'            => 'قيمة الخصم',
            'discountReason'           => 'سبب الخصم',
            'discountCategoryLookupId' => 'تصنيف الخصم',
        ]);

        $payload = [
            'type'               => $this->discountType,
            'value'              => (float) $this->discountValue,
            'reason'             => $this->discountReason,
            'category_lookup_id' => $this->discountCategoryLookupId,
            'name'               => $this->discountName,
        ];

        $this->guardAction(function () use ($service, $payload) {
            if ($this->selectedRemoteOrderId) {
                $order = $this->selectedRemoteOrder;
                if (! $order) throw new \RuntimeException('الطلب غير محدد.');
                $service->applyToOrder($order, $payload, auth()->user());
            } elseif ($this->selectedSessionId) {
                $session = $this->selectedSession;
                if (! $session) throw new \RuntimeException('الجلسة غير محددة.');
                $service->applyToSession($session, $payload, auth()->user());
            } else {
                throw new \RuntimeException('لا يوجد طلب أو جلسة محددة.');
            }

            $this->dispatch('toast', type: 'success', message: 'تم تطبيق الخصم');
            $this->closeDiscount();

            // Bust the cached computed properties so the totals refresh from
            // the new order/invoice state on the next render. The typed
            // amount is cleared (NOT re-filled) — it now points at a stale
            // balance and re-arming must stay a deliberate cashier action.
            unset($this->selectedSession, $this->selectedRemoteOrder, $this->remoteOrders);
            $this->paymentAmount = '';
        });
    }

    public function removeDiscount(int $discountId, \App\Services\OrderDiscountService $service): void
    {
        $discount = \App\Models\OrderDiscount::find($discountId);
        if (! $discount) {
            $this->dispatch('toast', type: 'error', message: 'الخصم غير موجود.');
            return;
        }
        $this->authorize('remove', $discount);

        $this->guardAction(function () use ($discount, $service) {
            $service->remove($discount, auth()->user());
            $this->dispatch('toast', type: 'success', message: 'تم إزالة الخصم');
            unset($this->selectedSession, $this->selectedRemoteOrder, $this->remoteOrders);
            // Clear (don't re-fill) — the balance just changed under the
            // typed amount; see submitDiscount for the WHY.
            $this->paymentAmount = '';
        });
    }

    /**
     * Cap (max percent / max fixed) for the current cashier's role. Returned
     * to the view so the form can show "حد دورك: 10%" and validate inline
     * before round-tripping. Null means uncapped (owner-level).
     */
    #[Computed]
    public function userDiscountCap(): ?array
    {
        return app(\App\Services\OrderDiscountService::class)
            ->userCap(auth()->user());
    }

    /**
     * Combined list of discounts already applied to the active selection,
     * with each row's order number folded in for the multi-order session
     * case (a 10% session-wide discount shows up as four separate rows when
     * the table has four orders — the order number disambiguates them).
     */
    #[Computed]
    public function activeDiscounts()
    {
        if ($this->selectedRemoteOrderId) {
            $order = $this->selectedRemoteOrder;
            if (! $order) return collect();
            return $order->discounts()->with(['appliedBy', 'categoryLookup'])->get()
                ->map(fn ($d) => tap($d, fn () => $d->setAttribute('_order_number', $order->number)));
        }

        $session = $this->selectedSession;
        if (! $session) return collect();

        $orderIds = $session->orders->pluck('id');
        return \App\Models\OrderDiscount::with(['appliedBy', 'categoryLookup', 'order'])
            ->whereIn('order_id', $orderIds)
            ->latest()
            ->get()
            ->map(fn ($d) => tap($d, fn () => $d->setAttribute('_order_number', $d->order?->number)));
    }

    /**
     * Categories for the discount-reason picker, pulled from the
     * `discount_categories` lookup group so admins can edit the catalog
     * from /admin/lookups without touching code.
     */
    #[Computed]
    public function discountCategories()
    {
        return \App\Models\Lookup::for('discount_categories');
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

        $this->guardAction(function () use ($billing, $session) {
            $billing->closeSessionWithoutBilling($session, auth()->id(), 'إغلاق من الكاشير');
            $this->dispatch('toast', type: 'success', message: 'تم إغلاق الجلسة وتحرير الطاولة');
            $this->clearSelection();
            unset($this->sessions, $this->selectedSession);
        });
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
    #[On('echo-private:cashiers,.pending_transfer.declared')]
    #[On('echo-private:waiters,.order.created')]
    #[On('echo-private:waiters,.table.status_changed')]
    public function refreshFromBroadcast(): void
    {
        unset($this->sessions, $this->selectedSession, $this->billStats, $this->pendingTransfersCount, $this->hasOpenShift);
    }

    /**
     * One-click "create account" for a guest session that already has a
     * phone number on file. The cashier sees the green guest banner with
     * the unrecognised phone, taps "إنشاء حساب", and we:
     *
     *   1. Create a Customer record with a random 6-digit PIN
     *   2. Link the open session to it (so future orders accumulate)
     *   3. Flash the PIN so the cashier can hand it to the diner
     *
     * If somehow the phone now matches an existing customer (e.g. another
     * cashier created one a moment ago on a different table), we just link
     * to the existing record — never duplicate.
     */
    public function createCustomerForSession(int $sessionId): void
    {
        $this->authorize('create', Payment::class);

        $session = TableSession::with('table.branch')->findOrFail($sessionId);

        if ($session->customer_id) {
            $this->dispatch('toast', type: 'warning', message: 'هذه الجلسة مرتبطة بحساب أصلاً.');
            return;
        }
        if (empty($session->customer_phone)) {
            $this->dispatch('toast', type: 'error', message: 'لا يوجد رقم جوال محفوظ لهذه الجلسة.');
            return;
        }

        $this->guardAction(function () use ($session) {
            $existing = Customer::findForLogin($session->customer_phone);
            if ($existing) {
                $session->update(['customer_id' => $existing->id]);
                $this->dispatch('toast', type: 'success', message: "ربط الجلسة بحساب موجود: {$existing->name}");
            } else {
                [$customer, $pin] = Customer::createFromCashier(
                    name: $session->customer_name ?: 'زبون',
                    phone: $session->customer_phone,
                    defaultBranchId: $session->table?->branch_id,
                );
                $session->update(['customer_id' => $customer->id]);
                // PIN is the ONLY chance the cashier has to give the diner
                // their first password — keep it on a public property so it
                // survives Livewire morphs. Cleared via dismissPinAlert().
                $this->pinAlert = [
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'pin' => $pin,
                ];
                $this->dispatch('toast', type: 'success', message: "أُنشئ حساب لـ{$customer->name} — رمز الدخول: {$pin}");
            }

            $this->refreshFromBroadcast();
        });
    }

    public function dismissPinAlert(): void
    {
        $this->pinAlert = null;
    }

    /**
     * Tally bill requests by how long they've been waiting. The cashier
     * sound + visual alarms hang off these counts: a number going up is
     * the trigger to chime / beep / pulse.
     *
     * Thresholds match the kitchen ready strip (3/8 min) so the whole
     * restaurant escalates on the same clock — no surprise where one screen
     * is calm while another is on fire.
     */
    #[Computed]
    public function billStats(): array
    {
        $green = $amber = $red = 0;
        $remoteUnpaid = 0;

        foreach ($this->sessions as $s) {
            if (! $s->bill_requested_at || $s->invoice) continue;
            $waitMin = (int) $s->bill_requested_at->diffInMinutes(now());
            if     ($waitMin >= 8) $red++;
            elseif ($waitMin >= 3) $amber++;
            else                   $green++;
        }

        // Remote orders waiting for collection — phone/delivery orders that
        // arrived but haven't been billed/paid yet. Treated as a separate
        // signal so the sound can use a distinct tone.
        $remoteUnpaid = $this->remoteOrders
            ->filter(fn($o) => ! $o->invoice || ($o->invoice->balance ?? 0) > 0)
            ->count();

        return [
            'requests_total' => $green + $amber + $red,
            'green' => $green,
            'amber' => $amber,
            'red'   => $red,
            'remote_unpaid' => $remoteUnpaid,
        ];
    }

    /** Bank transfers waiting for the cashier to confirm against the bank app.
     *  Branch-scoped via the PendingTransfer BelongsToBranch global scope. */
    #[Computed]
    public function pendingTransfersCount(): int
    {
        return \App\Models\PendingTransfer::where('status', 'pending')->count();
    }

    /**
     * True when the current user has an open shift to attribute payments to.
     * Mirrors the exact lookup BillingService::addPayment stamps shift_id
     * with. Display-only: payments stay allowed without a shift (they land
     * with shift_id = NULL and never show in any drawer/X-report), the
     * banner just makes that consequence loud before the money moves.
     */
    #[Computed]
    public function hasOpenShift(): bool
    {
        // User-keyed and UNSCOPED — a cashier has at most one open drawer
        // anywhere, and BillingService::addPayment resolves that shift the
        // same way. A branch-scoped check here would flash "no open shift"
        // while the operator's drawer (opened under another branch context)
        // silently absorbs the payment — the two must agree.
        return \App\Models\Shift::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('user_id', auth()->id())
            ->where('status', 'open')
            ->exists();
    }
}
?>

{{-- Livewire polling mode (works on shared hosting). 10s is a good balance:
     cashier needs to see new sessions/paid invoices, but sessions aren't
     as time-critical as a hot dish. `visible` saves resources on background tabs. --}}
@php $billStats = $this->billStats; @endphp
<div class="cashier-dashboard"
     wire:poll.visible.10s="refreshFromBroadcast"
     x-data="cashierSound()"
     data-bill-total="{{ $billStats['requests_total'] }}"
     data-bill-amber="{{ $billStats['amber'] }}"
     data-bill-red="{{ $billStats['red'] }}"
     data-remote-unpaid="{{ $billStats['remote_unpaid'] }}"
     data-pending-transfers="{{ $this->pendingTransfersCount }}">
    {{-- Shift-awareness banner — a payment recorded with no open shift gets
         shift_id = NULL and silently vanishes from every drawer/X-report.
         Warn loudly but DON'T block: emergency collection beats bookkeeping
         (blocking is a policy decision, out of scope here). --}}
    @unless($this->hasOpenShift)
        <div class="cx-shift-banner">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>لا توجد وردية مفتوحة — الدفعات لن تُنسب لأي درج.</span>
            <a href="{{ route('admin.shifts.index') }}">افتح وردية الآن ←</a>
        </div>
    @endunless
    {{-- Bank-transfer alert strip — customer/waiter-claimed transfers waiting
         for the cashier to confirm against the bank. Links straight to the
         verification queue so the cashier isn't left hunting for it. --}}
    @if($this->pendingTransfersCount > 0)
        <a href="{{ route('admin.cashier.transfers.queue') }}"
           class="cx-alarm-strip is-amber" style="text-decoration:none">
            <div class="cx-alarm-content">
                <i class="bi bi-bank2"></i>
                <strong>
                    {{ $this->pendingTransfersCount }} تحويل بنكي بانتظار التأكيد
                </strong>
                <span class="cx-alarm-chip cx-alarm-chip--amber">افتح للتأكيد ←</span>
            </div>
        </a>
    @endif
    {{-- Bill-request alarm strip — only renders when something needs the
         cashier's attention. Compact bar with red/amber chips and the sound
         toggle. Hidden when there's nothing pending so a quiet shift looks
         quiet. --}}
    @if($billStats['requests_total'] > 0 || $billStats['remote_unpaid'] > 0)
        <div class="cx-alarm-strip {{ $billStats['red'] > 0 ? 'is-red' : ($billStats['amber'] > 0 ? 'is-amber' : '') }}">
            <div class="cx-alarm-content">
                <i class="bi bi-receipt-cutoff"></i>
                <strong>
                    {{ $billStats['requests_total'] }} طلب فاتورة
                    @if($billStats['red'] > 0)
                        · <span class="cx-alarm-chip cx-alarm-chip--red">{{ $billStats['red'] }} متأخر</span>
                    @endif
                    @if($billStats['amber'] > 0)
                        · <span class="cx-alarm-chip cx-alarm-chip--amber">{{ $billStats['amber'] }} ينتظر</span>
                    @endif
                </strong>
            </div>
            <button type="button" @click="toggleSound()"
                    class="cx-sound-btn"
                    :class="enabled ? 'is-on' : 'is-off'"
                    title="تنبيه صوتي عند طلب فاتورة جديد أو تأخر">
                <i class="bi" :class="enabled ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'"></i>
                <span x-text="enabled ? 'الصوت يعمل' : 'تفعيل الصوت'"></span>
            </button>
        </div>
    @else
        {{-- Render the toggle even when calm so the cashier can pre-arm it
             at shift start. Tucked top-right where it doesn't compete with
             the KPIs. --}}
        <div class="cx-sound-rail">
            <button type="button" @click="toggleSound()"
                    class="cx-sound-btn"
                    :class="enabled ? 'is-on' : 'is-off'"
                    title="تنبيه صوتي عند طلب فاتورة جديد">
                <i class="bi" :class="enabled ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'"></i>
                <span x-text="enabled ? 'الصوت يعمل' : 'تفعيل الصوت'"></span>
            </button>
        </div>
    @endif

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
                    <div class="cx-kpi-label">غير نقدي</div>
                    <div class="cx-kpi-value">{{ \App\Helpers\Money::format($this->todayStats['non_cash']) }}</div>
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
                    $needsCustomerContact = $orderSource === 'phone' || $orderType === 'delivery';
                    $sourceChoices = [
                        'other'    => ['bi-lightning-charge-fill', 'مباشر'],
                        'phone'    => ['bi-telephone-fill', 'اتصال هاتفي'],
                        'delivery' => ['bi-truck', 'دليفري'],
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
                            <div class="cx-source-grid cx-source-grid-3">
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
                        @if($orderSource === 'delivery')
                            <label class="cx-field cx-field-wide">
                                <span>الجهة المستلمة</span>
                                <input type="text"
                                       wire:model.blur="deliveryReceiver"
                                       class="form-control"
                                       placeholder="اسم المستلم أو شركة التوصيل">
                                @error('deliveryReceiver') <small class="text-danger">{{ $message }}</small> @enderror
                            </label>
                        @endif
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
                            wire:key="cx-remote-card-{{ $order->id }}"
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
                        $waitMin = $billRequested ? (int) $s->bill_requested_at->diffInMinutes(now()) : 0;
                        // Same 3/8 thresholds the kitchen uses on its ready
                        // strip — keeps the whole restaurant on one clock.
                        $billUrgency = ! $billRequested ? null
                            : ($waitMin >= 8 ? 'red' : ($waitMin >= 3 ? 'amber' : 'green'));
                        $ordersTotal = $s->orders->sum('total');
                        $openMin = (int) $s->opened_at->diffInMinutes(now());
                    @endphp
                    <button type="button"
                        wire:key="cx-session-card-{{ $s->id }}"
                        wire:click="selectSession({{ $s->id }})"
                        class="cx-session-card {{ $selectedSessionId === $s->id ? 'is-active' : '' }} {{ $invoice ? 'has-invoice' : '' }} {{ $billRequested ? 'has-bill-request' : '' }} {{ $billUrgency ? 'cx-urg-'.$billUrgency : '' }}">
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
                                <span class="cx-status-pill cx-status-bill cx-status-bill--{{ $billUrgency }}">
                                    <i class="bi {{ $billUrgency === 'red' ? 'bi-fire' : 'bi-receipt-cutoff' }}"></i>
                                    @if($billUrgency === 'red')
                                        ينتظر {{ \App\Support\Duration::short($waitMin) }}!
                                    @elseif($billUrgency === 'amber')
                                        فاتورة · {{ \App\Support\Duration::short($waitMin) }}
                                    @else
                                        طلب الفاتورة
                                    @endif
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
                            <span><i class="bi bi-clock"></i> {{ \App\Support\Duration::short($openMin) }}</span>
                            @if($s->customer)
                                <span class="cx-loyalty-chip" title="زبون دائم — {{ $s->customer->name }}">
                                    <i class="bi bi-star-fill"></i>
                                    دائم
                                </span>
                            @endif
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
                        <button type="button" wire:click="clearSelection" class="btn btn-sm btn-light">
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
                                            <div class="cx-item {{ $it->status==='cancelled' ? 'is-cancelled' : '' }}" wire:key="cx-ritem-{{ $it->id }}">
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
                                    <button type="button" wire:click="issueRemoteInvoice" wire:loading.attr="disabled" class="cx-btn-lg cx-btn-primary">
                                        <i class="bi bi-receipt"></i>
                                        <span wire:loading.remove wire:target="issueRemoteInvoice">إصدار الفاتورة</span>
                                        <span wire:loading wire:target="issueRemoteInvoice">جاري...</span>
                                    </button>
                                </div>

                                @include('components.cashier._discount-panel')
                            @elseif($invoice->status === 'cancelled')
                                {{-- Cancelled invoice — used to be a dead panel. Re-issue is
                                     legal while the ORDER is alive (BillingService skips
                                     cancelled invoices); a cancelled order can't be re-billed,
                                     so say that honestly instead of showing a broken button. --}}
                                <div class="cx-section">
                                    <div class="cx-invoice-head">
                                        <div>
                                            <small class="text-muted">رقم الفاتورة</small>
                                            <div class="fw-bold" style="font-family: 'Courier New', monospace;">{{ $invoice->number }}</div>
                                        </div>
                                        <span class="badge bg-{{ $invoice->statusColor() }}">{{ $invoice->statusLabel() }}</span>
                                    </div>
                                    @if($remoteOrder->status === \App\Enums\OrderStatus::Cancelled->value)
                                        <div class="cx-cancelled-note">
                                            <i class="bi bi-slash-circle"></i>
                                            الطلب والفاتورة ملغيان — لا يمكن إعادة إصدار فاتورة لطلب ملغي. أنشئ طلباً جديداً إذا رجع الزبون.
                                        </div>
                                    @else
                                        <div class="cx-cancelled-note">
                                            <i class="bi bi-slash-circle"></i>
                                            هذه الفاتورة ملغاة — أصدر فاتورة جديدة لمتابعة التحصيل.
                                        </div>
                                        <button type="button" wire:click="issueRemoteInvoice"
                                                wire:confirm="إعادة إصدار فاتورة جديدة لهذا الطلب؟"
                                                wire:loading.attr="disabled" class="cx-btn-lg cx-btn-primary">
                                            <i class="bi bi-arrow-repeat"></i>
                                            <span wire:loading.remove wire:target="issueRemoteInvoice">إعادة إصدار الفاتورة</span>
                                            <span wire:loading wire:target="issueRemoteInvoice">جاري...</span>
                                        </button>
                                    @endif
                                </div>
                            @else
                                <div class="cx-section">
                                    <div class="cx-invoice-head">
                                        <div>
                                            <small class="text-muted">رقم الفاتورة</small>
                                            <div class="fw-bold" style="font-family: 'Courier New', monospace;">{{ $invoice->number }}</div>
                                        </div>
                                        <span class="badge bg-{{ $invoice->statusColor() }}">{{ $invoice->statusLabel() }}</span>
                                    </div>
                                    <div class="cx-totals">
                                        <div class="d-flex justify-content-between"><span>الفرعي</span><strong>{{ \App\Helpers\Money::format($invoice->subtotal) }}</strong></div>
                                        @if($invoice->discount_total > 0)
                                            <div class="d-flex justify-content-between text-success"><span>الخصم</span><strong>−{{ \App\Helpers\Money::format($invoice->discount_total) }}</strong></div>
                                        @endif
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

                                @include('components.cashier._discount-panel')

                                @if($invoice->balance > 0)
                                    @if($invoice->splits->isNotEmpty())
                                        {{-- Split invoice — a direct payment here shrinks the balance
                                             and strands the (now-too-large) splits as unpayable, so the
                                             form is replaced by a read-only splits view (same rule as
                                             the classic pay screen). --}}
                                        <div class="cx-section">
                                            <div class="cx-split-notice">
                                                <i class="bi bi-diagram-3-fill"></i>
                                                الفاتورة مقسّمة — حصّل من أزرار الأجزاء.
                                            </div>
                                            <div class="cx-split-list">
                                                @foreach($invoice->splits as $split)
                                                    <div class="cx-split-row {{ $split->paid ? 'is-paid' : '' }}" wire:key="cx-rsplit-{{ $split->id }}">
                                                        <span class="cx-split-label">{{ $split->label ?: 'جزء' }}</span>
                                                        <strong>{{ \App\Helpers\Money::format($split->amount) }}</strong>
                                                        @if($split->paid)
                                                            <span class="cx-status-pill cx-status-paid"><i class="bi bi-check-circle-fill"></i> مدفوع</span>
                                                        @else
                                                            <span class="cx-status-pill cx-status-due">غير مدفوع</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @else
                                    <div class="cx-section"
                                         wire:key="cx-rpayzone-{{ $invoice->id }}-{{ number_format((float) $invoice->balance, 2, '.', '') }}"
                                         x-data="cxPayConfirm({ balance: {{ (float) $invoice->balance }} })">
                                        <div class="cx-section-head"><strong><i class="bi bi-cash-stack"></i> تسجيل دفعة</strong></div>
                                        <div class="cx-presets">
                                            <button type="button" wire:click="setAmount('{{ number_format((float) $invoice->balance, 2, '.', '') }}')" class="cx-preset cx-preset-primary">
                                                <small>المتبقي</small>
                                                <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong>
                                            </button>
                                        </div>
                                        <div class="cx-amount-wrap">
                                            <input type="number" step="0.01" min="0.01"
                                                   wire:model.blur="paymentAmount"
                                                   x-ref="amt"
                                                   @input="amtLive = $event.target.value"
                                                   @keydown.enter.prevent="arm()"
                                                   class="cx-amount-input" placeholder="0.00" autocomplete="off">
                                            <span class="cx-amount-symbol">{{ \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol')) }}</span>
                                        </div>
                                        @error('paymentAmount') <small class="text-danger d-block mb-2">{{ $message }}</small> @enderror
                                        <div class="cx-methods">
                                            @foreach($this->paymentMethods as $m => $meta)
                                                <button type="button" wire:click="setMethod('{{ $m }}')" class="cx-method {{ $paymentMethod === $m ? 'is-active' : '' }}">
                                                    <i class="bi {{ $meta['icon'] }}"></i><span>{{ $meta['label'] }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                        @if($paymentMethod !== 'cash')
                                            <input type="text" wire:model.blur="paymentReference" placeholder="رقم المرجع (اختياري)" class="form-control mb-2">
                                        @else
                                            {{-- Cash tendered → change-due. Display-only: no wire:model,
                                                 never POSTed (same helper as the classic pay screen). --}}
                                            <div class="cx-change-helper">
                                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                                       x-model="tendered" @keydown.enter.prevent="arm()"
                                                       class="form-control" placeholder="المبلغ المستلم نقداً (لحساب الباقي)">
                                                <div class="cx-change-box" x-show="changeDue() > 0" x-cloak>
                                                    <span>الباقي (فكة)</span>
                                                    <strong x-text="changeDue().toFixed(2)"></strong>
                                                </div>
                                            </div>
                                        @endif
                                        {{-- Two-step confirm: this button only ARMS. The payment fires
                                             from the explicit تأكيد below — kills the armed-gun failure
                                             mode where one stray tap took a full cash payment. --}}
                                        <button type="button" x-show="!confirming" @click="arm()" class="cx-btn-lg cx-btn-success">
                                            <i class="bi bi-check-circle-fill"></i>
                                            <span>تأكيد الدفعة</span>
                                        </button>
                                        <div class="cx-pay-confirm" x-show="confirming" x-cloak>
                                            <div class="cx-pay-confirm-msg">
                                                تأكيد قبض <strong x-text="fmtAmt()"></strong> {{ $this->paymentMethods[$paymentMethod]['label'] ?? $paymentMethod }}؟
                                            </div>
                                            <div class="cx-pay-confirm-actions">
                                                <button type="button" @click="confirming = false" class="btn btn-light">إلغاء</button>
                                                <button type="button" wire:click="recordPayment" wire:loading.attr="disabled" wire:target="recordPayment" class="cx-btn-lg cx-btn-success">
                                                    <i class="bi bi-check-circle-fill"></i>
                                                    <span wire:loading.remove wire:target="recordPayment">تأكيد</span>
                                                    <span wire:loading wire:target="recordPayment">جاري التسجيل...</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    @endif
                                @endif

                                @if($invoice->payments->count())
                                    <div class="cx-section">
                                        <div class="cx-section-head"><strong><i class="bi bi-clock-history"></i> سجل الدفعات</strong></div>
                                        @foreach($invoice->payments as $p)
                                            <div class="cx-payment-row" wire:key="cx-rpay-{{ $p->id }}">
                                                <strong>{{ \App\Helpers\Money::format($p->amount) }}</strong>
                                                <small class="text-muted">{{ \App\Support\PaymentMethods::label($p->method) }} · {{ $p->paid_at?->format('H:i') ?? $p->created_at->format('H:i') }}</small>
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
                            {{ $session->cover_count ?? 1 }} أشخاص · {{ \App\Support\Duration::since($session->opened_at) }}
                            @if($session->customer_name && ! $session->customer)
                                · <strong>{{ $session->customer_name }}</strong>
                            @endif
                        </div>
                    </div>
                    <button type="button" wire:click="clearSelection" class="btn btn-sm btn-light">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                {{-- Just-created customer alert. Sticks around (manual dismiss)
                     until the cashier closes it — they need to read the PIN
                     out loud to the diner so they can log in to the portal
                     later. Once dismissed (page action / refresh / move on),
                     the PIN is gone for good — the standard reset flow is
                     the only way to recover it. --}}
                @if($pinAlert)
                    <div class="cx-pin-alert">
                        <div class="cx-pin-alert-icon"><i class="bi bi-key-fill"></i></div>
                        <div class="cx-pin-alert-body">
                            <strong>أُنشئ حساب لـ{{ $pinAlert['name'] }}</strong>
                            <div class="cx-pin-alert-meta">
                                <span><i class="bi bi-telephone"></i> {{ $pinAlert['phone'] }}</span>
                                <span class="cx-pin-code">رمز الدخول · <code>{{ $pinAlert['pin'] }}</code></span>
                            </div>
                            <small>اكتب الرمز للزبون أو اقرأه له. لا يمكن استرجاعه — الزبون يستخدمه أول مرة لتسجيل الدخول للبوابة.</small>
                        </div>
                        <button type="button" wire:click="dismissPinAlert" class="cx-pin-alert-close" title="إغلاق">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                @endif

                {{-- Returning customer panel — only when the session was matched
                     to an existing Customer record, either via portal login
                     or via phone-number lookup at submit time. Shows the real
                     name + phone + visit history + loyalty points so the
                     cashier instantly knows they're dealing with a regular. --}}
                @if($session->customer)
                    @php
                        $cust = $session->customer;
                        $visits = $cust->table_sessions_count ?? null;
                        $points = $cust->loyaltyCustomer?->points ?? 0;
                    @endphp
                    <div class="cx-loyalty-banner">
                        <div class="cx-loyalty-avatar">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <div class="cx-loyalty-body">
                            <div class="cx-loyalty-head">
                                <strong>{{ $cust->name }}</strong>
                                <span class="cx-loyalty-badge">زبون دائم</span>
                            </div>
                            <div class="cx-loyalty-meta">
                                @if($cust->phone)
                                    <span><i class="bi bi-telephone-fill"></i> {{ $cust->phone }}</span>
                                @endif
                                @if($visits !== null)
                                    <span><i class="bi bi-clock-history"></i> {{ $visits }} زيارة</span>
                                @endif
                                @if($points > 0)
                                    <span><i class="bi bi-coin"></i> {{ number_format($points) }} نقطة</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @elseif($session->customer_phone)
                    {{-- Phone provided but no Customer record matched. Surface
                         the number so the cashier can offer to create an
                         account on the spot — the easiest moment to sign
                         someone up is when they're already paying. --}}
                    <div class="cx-loyalty-banner cx-loyalty-banner--guest">
                        <div class="cx-loyalty-avatar">
                            <i class="bi bi-telephone-fill"></i>
                        </div>
                        <div class="cx-loyalty-body">
                            <div class="cx-loyalty-head">
                                <strong>{{ $session->customer_name ?: 'ضيف' }}</strong>
                                <span class="cx-loyalty-badge cx-loyalty-badge--guest">{{ $session->customer_phone }}</span>
                            </div>
                            <div class="cx-loyalty-meta">
                                <span><i class="bi bi-info-circle"></i> رقم جديد — أنشئ حساب الآن ليكسب نقاط ولاء.</span>
                            </div>
                        </div>
                        <button type="button"
                                wire:click="createCustomerForSession({{ $session->id }})"
                                wire:loading.attr="disabled"
                                wire:target="createCustomerForSession"
                                class="cx-loyalty-create-btn"
                                title="إنشاء حساب وربطه بهذه الجلسة">
                            <span wire:loading.remove wire:target="createCustomerForSession"><i class="bi bi-person-plus-fill"></i> إنشاء حساب</span>
                            <span wire:loading wire:target="createCustomerForSession"><span class="spinner-border spinner-border-sm"></span> جاري...</span>
                        </button>
                    </div>
                @endif

                @if($session->bill_requested_at && ! $invoice)
                    <div class="cx-bill-request-alert">
                        <div class="cx-bill-request-alert__icon">
                            <i class="bi bi-receipt-cutoff"></i>
                        </div>
                        <div>
                            <strong>الزبون طلب الفاتورة</strong>
                            <span>{{ \App\Support\Duration::since($session->bill_requested_at) }}</span>
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
                                    <div class="cx-order" wire:key="cx-order-{{ $order->id }}">
                                        <div class="cx-order-head">
                                            <strong>{{ $order->number }}</strong>
                                            <span class="badge bg-{{ $order->statusColor() }}">{{ $order->statusLabel() }}</span>
                                        </div>
                                        @foreach($order->items as $it)
                                            <div class="cx-item {{ $it->status==='cancelled' ? 'is-cancelled' : '' }}" wire:key="cx-item-{{ $it->id }}">
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
                                    <button type="button" wire:click="issueInvoice" wire:loading.attr="disabled" class="cx-btn-lg cx-btn-primary">
                                        <i class="bi bi-receipt"></i>
                                        <span wire:loading.remove>إصدار الفاتورة</span>
                                        <span wire:loading>جارٍ...</span>
                                    </button>
                                @endif
                            </div>

                            @unless($this->canCloseWithoutBilling)
                                @include('components.cashier._discount-panel')
                            @endunless
                        @elseif($invoice->status === 'cancelled')
                            {{-- Cancelled invoice — used to be a dead panel. issueInvoice
                                 permits re-issue: BillingService skips cancelled rows when
                                 looking for the session's existing invoice. --}}
                            <div class="cx-section">
                                <div class="cx-invoice-head">
                                    <div>
                                        <small class="text-muted">رقم الفاتورة</small>
                                        <div class="fw-bold" style="font-family: 'Courier New', monospace;">{{ $invoice->number }}</div>
                                    </div>
                                    <span class="badge bg-{{ $invoice->statusColor() }}">{{ $invoice->statusLabel() }}</span>
                                </div>
                                <div class="cx-cancelled-note">
                                    <i class="bi bi-slash-circle"></i>
                                    هذه الفاتورة ملغاة — أصدر فاتورة جديدة لمتابعة التحصيل.
                                </div>
                                <button type="button" wire:click="issueInvoice"
                                        wire:confirm="إعادة إصدار فاتورة جديدة لهذه الجلسة؟"
                                        wire:loading.attr="disabled" class="cx-btn-lg cx-btn-primary">
                                    <i class="bi bi-arrow-repeat"></i>
                                    <span wire:loading.remove wire:target="issueInvoice">إعادة إصدار الفاتورة</span>
                                    <span wire:loading wire:target="issueInvoice">جارٍ...</span>
                                </button>
                            </div>
                        @else
                            {{-- Invoice totals + payment --}}
                            <div class="cx-section">
                                <div class="cx-invoice-head">
                                    <div>
                                        <small class="text-muted">رقم الفاتورة</small>
                                        <div class="fw-bold" style="font-family: 'Courier New', monospace;">{{ $invoice->number }}</div>
                                    </div>
                                    <span class="badge bg-{{ $invoice->statusColor() }}">{{ $invoice->statusLabel() }}</span>
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

                            @include('components.cashier._discount-panel')

                            {{-- Payment keypad --}}
                            @if($invoice->balance > 0)
                                @if($invoice->splits->isNotEmpty())
                                    {{-- Split invoice — same rule as the classic pay screen: a
                                         direct payment shrinks the balance and strands the
                                         (now-too-large) splits as unpayable. Read-only view +
                                         handoff link; split management stays on the classic page. --}}
                                    <div class="cx-section">
                                        <div class="cx-split-notice">
                                            <i class="bi bi-diagram-3-fill"></i>
                                            الفاتورة مقسّمة — حصّل من أزرار الأجزاء.
                                        </div>
                                        <div class="cx-split-list">
                                            @foreach($invoice->splits as $split)
                                                <div class="cx-split-row {{ $split->paid ? 'is-paid' : '' }}" wire:key="cx-split-{{ $split->id }}">
                                                    <span class="cx-split-label">{{ $split->label ?: 'جزء' }}</span>
                                                    <strong>{{ \App\Helpers\Money::format($split->amount) }}</strong>
                                                    @if($split->paid)
                                                        <span class="cx-status-pill cx-status-paid"><i class="bi bi-check-circle-fill"></i> مدفوع</span>
                                                    @else
                                                        <span class="cx-status-pill cx-status-due">غير مدفوع</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <a href="{{ route('admin.cashier.show', $session) }}" class="cx-split-manage">
                                            <i class="bi bi-sliders"></i> إدارة التقسيم
                                        </a>
                                    </div>
                                @else
                                <div class="cx-section"
                                     wire:key="cx-payzone-{{ $invoice->id }}-{{ number_format((float) $invoice->balance, 2, '.', '') }}"
                                     x-data="cxPayConfirm({ balance: {{ (float) $invoice->balance }} })">
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
                                         per-keystroke server round-trips. Enter ARMS the confirm
                                         step (never submits directly). --}}
                                    <div class="cx-amount-wrap">
                                        <input type="number" step="0.01" min="0.01"
                                            wire:model.blur="paymentAmount"
                                            x-ref="amt"
                                            @input="amtLive = $event.target.value"
                                            @keydown.enter.prevent="arm()"
                                            class="cx-amount-input"
                                            placeholder="0.00"
                                            autocomplete="off">
                                        <span class="cx-amount-symbol">{{ \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol')) }}</span>
                                    </div>
                                    @error('paymentAmount') <small class="text-danger d-block mb-2">{{ $message }}</small> @enderror

                                    {{-- Method selector --}}
                                    <div class="cx-methods">
                                        @foreach($this->paymentMethods as $m => $meta)
                                            <button type="button"
                                                wire:click="setMethod('{{ $m }}')"
                                                class="cx-method {{ $paymentMethod === $m ? 'is-active' : '' }}">
                                                <i class="bi {{ $meta['icon'] }}"></i>
                                                <span>{{ $meta['label'] }}</span>
                                            </button>
                                        @endforeach
                                    </div>

                                    @if($paymentMethod !== 'cash')
                                        <input type="text" wire:model.blur="paymentReference"
                                            placeholder="رقم المرجع (اختياري)"
                                            class="form-control mb-2">
                                    @else
                                        {{-- Cash tendered → change-due. Display-only: no wire:model,
                                             never POSTed (same helper as the classic pay screen). --}}
                                        <div class="cx-change-helper">
                                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                                   x-model="tendered" @keydown.enter.prevent="arm()"
                                                   class="form-control" placeholder="المبلغ المستلم نقداً (لحساب الباقي)">
                                            <div class="cx-change-box" x-show="changeDue() > 0" x-cloak>
                                                <span>الباقي (فكة)</span>
                                                <strong x-text="changeDue().toFixed(2)"></strong>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Two-step confirm: this button only ARMS. The payment fires
                                         from the explicit تأكيد below — kills the armed-gun failure
                                         mode where one stray tap took a full cash payment. --}}
                                    <button type="button" x-show="!confirming" @click="arm()" class="cx-btn-lg cx-btn-success">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>تأكيد الدفعة</span>
                                    </button>
                                    <div class="cx-pay-confirm" x-show="confirming" x-cloak>
                                        <div class="cx-pay-confirm-msg">
                                            تأكيد قبض <strong x-text="fmtAmt()"></strong> {{ $this->paymentMethods[$paymentMethod]['label'] ?? $paymentMethod }}؟
                                        </div>
                                        <div class="cx-pay-confirm-actions">
                                            <button type="button" @click="confirming = false" class="btn btn-light">إلغاء</button>
                                            <button type="button" wire:click="recordPayment" wire:loading.attr="disabled" wire:target="recordPayment" class="cx-btn-lg cx-btn-success">
                                                <i class="bi bi-check-circle-fill"></i>
                                                <span wire:loading.remove wire:target="recordPayment">تأكيد</span>
                                                <span wire:loading wire:target="recordPayment">جارٍ التسجيل...</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                @endif
                            @endif

                            {{-- Payment history --}}
                            @if($invoice->payments->count())
                                <div class="cx-section">
                                    <div class="cx-section-head"><strong><i class="bi bi-clock-history"></i> سجل الدفعات</strong></div>
                                    @foreach($invoice->payments as $p)
                                        <div class="cx-payment-row" wire:key="cx-pay-{{ $p->id }}">
                                            <div>
                                                <strong>{{ \App\Helpers\Money::format($p->amount) }}</strong>
                                                <span class="badge bg-secondary ms-1">{{ \App\Support\PaymentMethods::label($p->method) }}</span>
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

                            {{-- ═══════ Deliberate money actions (merged from the classic page) ═══════
                                 Native <form> POSTs to the SAME already-hardened CashierController
                                 routes — NO new money logic lives in this component. Each carries a
                                 hidden `return_session` so the controller redirects back to THIS
                                 session's checkout (?session=ID) after the full reload. Visibility
                                 rules mirror admin/cashier/show.blade.php exactly. Bootstrap modals
                                 (already loaded) get session-scoped ids so wire:poll morphs never
                                 collide across sessions; wire:ignore.self keeps an open modal from
                                 being reverted by a poll morph mid-typing. --}}

                            {{-- Parked-debt banner + un-park. A settled-on-account invoice stays
                                 partially_paid with balance > 0 (the balance IS the ledger), so
                                 without this it reads like a live half-paid checkout. Un-park
                                 reverses an accidental park — guards live in
                                 BillingService::unparkSettleOnAccount. --}}
                            @if($invoice->settled_on_account_at)
                                <div class="alert alert-warning mt-2 mb-0 py-2 px-2 small" wire:key="cx-parked-{{ $session->id }}">
                                    <i class="bi bi-journal-text"></i>
                                    مؤجّلة <strong>كدين على الزبون</strong> منذ {{ $invoice->settled_on_account_at->format('Y-m-d H:i') }}
                                    — المتبقي <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong> يظهر في سجل ديونه،
                                    وأي دفعة هنا تسدد منه مباشرة.
                                    @can('create', \App\Models\Payment::class)
                                        @if((float) $invoice->balance > 0.001)
                                            <form method="POST" action="{{ route('admin.cashier.unpark', $invoice) }}" class="mt-2 mb-0"
                                                  onsubmit="return confirm('إلغاء تأجيل هذا الدين؟ سترجع الفاتورة إلى التحصيل العادي وتُحذف من سجل ديون الزبون.')">
                                                @csrf
                                                <input type="hidden" name="return_session" value="{{ $session->id }}">
                                                <button class="btn btn-sm btn-outline-dark w-100">
                                                    <i class="bi bi-arrow-counterclockwise"></i> إلغاء التأجيل
                                                </button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            @endif

                            {{-- Settle on account — parks the remaining balance on the customer's
                                 ledger and closes the session. Only when a customer is linked and
                                 at least one payment exists (BillingService::settleOnAccount enforces
                                 both; the UI mirrors that to avoid a button that only bounces). The
                                 whole chain sits inside balance > 0, exactly like the classic page. --}}
                            @if($invoice->balance > 0)
                                @if($invoice->customer_id && (float) $invoice->paid_total > 0.001 && ! $invoice->settled_on_account_at)
                                    <div wire:key="cx-settle-{{ $session->id }}">
                                        <button type="button" class="btn btn-warning w-100 mt-2"
                                                data-bs-toggle="modal" data-bs-target="#settleModal{{ $session->id }}">
                                            <i class="bi bi-journal-text"></i>
                                            تأجيل المتبقي ({{ \App\Helpers\Money::format($invoice->balance) }}) كدين
                                        </button>
                                        <div class="modal fade" id="settleModal{{ $session->id }}" tabindex="-1" wire:ignore.self>
                                            <div class="modal-dialog"><div class="modal-content">
                                                <form action="{{ route('admin.cashier.settle_on_account', $invoice) }}" method="POST">@csrf
                                                    <input type="hidden" name="return_session" value="{{ $session->id }}">
                                                    <div class="modal-header">
                                                        <h5 class="mb-0"><i class="bi bi-journal-text"></i> تأجيل المتبقي كدين</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        @php
                                                            // Preview math: at render time the invoice is NOT flagged yet,
                                                            // so outstandingDebt() is already "debt from OTHER invoices"
                                                            // (settleOnAccount computes the exact same way).
                                                            $settleOtherDebt = $invoice->customer?->outstandingDebt() ?? 0.0;
                                                            $settleResulting = $settleOtherDebt + (float) $invoice->balance;
                                                        @endphp
                                                        <div class="alert alert-info small">
                                                            <strong>الزبون:</strong> {{ $invoice->customer->name ?? '—' }}<br>
                                                            <strong>دينه الحالي (فواتير أخرى):</strong> {{ \App\Helpers\Money::format($settleOtherDebt) }}<br>
                                                            <strong>المبلغ المؤجل من هذه الفاتورة:</strong> {{ \App\Helpers\Money::format($invoice->balance) }}<br>
                                                            <strong>إجمالي الدين بعد التأجيل:</strong>
                                                            <span class="text-danger">{{ \App\Helpers\Money::format($settleResulting) }}</span>
                                                        </div>
                                                        @if($invoice->customer && $invoice->customer->credit_limit !== null)
                                                            @php
                                                                $limit = (float) $invoice->customer->credit_limit;
                                                                // Same 0.01 tolerance as the service guard so the modal never
                                                                // promises what settleOnAccount will refuse.
                                                                $wouldExceed = $settleResulting - $limit > 0.01;
                                                            @endphp
                                                            <div class="alert {{ $wouldExceed ? 'alert-danger' : 'alert-light' }} small">
                                                                <strong>الحد الائتماني:</strong> {{ \App\Helpers\Money::format($limit) }}
                                                                @if($wouldExceed)
                                                                    <br><i class="bi bi-x-octagon-fill"></i> يتجاوز الحد — لن تُقبل العملية. ارفع الحد أولاً أو حصّل نقداً.
                                                                @endif
                                                            </div>
                                                        @endif
                                                        <label class="form-label">ملاحظة (اختياري)</label>
                                                        <textarea name="notes" class="form-control" rows="2" maxlength="500"
                                                                  placeholder="مثلاً: وعد الزبون بالتسديد الأسبوع القادم"></textarea>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                                                        <button class="btn btn-warning">
                                                            <i class="bi bi-check2"></i> تأكيد تأجيل المتبقي
                                                        </button>
                                                    </div>
                                                </form>
                                            </div></div>
                                        </div>
                                    </div>
                                @elseif((float) $invoice->paid_total <= 0.001 && (float) $invoice->balance > 0 && $invoice->customer_id)
                                    {{-- Zero-paid parking is refused by the service (a full ticket on
                                         credit with no cash collected is the write-off path) — hide the
                                         button and explain the rule instead of teasing a bouncing action. --}}
                                    <div class="alert alert-light border mt-2 small mb-0" wire:key="cx-settle-hint-zeropaid-{{ $session->id }}">
                                        <i class="bi bi-info-circle"></i>
                                        <strong>لماذا لا يظهر زر التأجيل؟</strong>
                                        النظام يرفض تأجيل فاتورة <u>بدون أي دفعة</u> كدين — سجّل ولو دفعة جزئية
                                        رمزية أولاً (إثبات نية السداد)، أو استخدم زر "شطب" إن كان الزبون لن يدفع إطلاقاً.
                                    </div>
                                @elseif((float) $invoice->balance > 0 && ! $invoice->customer_id)
                                    <div class="alert alert-light border mt-2 small mb-0" wire:key="cx-settle-hint-nocustomer-{{ $session->id }}">
                                        <i class="bi bi-info-circle"></i>
                                        لتأجيل المتبقي كدين، اربط زبوناً بالجلسة أولاً (من اللوحة فوق).
                                    </div>
                                @endif
                            @endif

                            {{-- Cancel invoice — only while issued and no payments taken (the
                                 service refuses once any payment exists). --}}
                            @if($invoice->status === 'issued')
                                <div wire:key="cx-cancel-{{ $session->id }}">
                                    <button type="button" class="btn btn-sm btn-outline-danger w-100 mt-2"
                                            data-bs-toggle="modal" data-bs-target="#cancelModal{{ $session->id }}">
                                        إلغاء الفاتورة
                                    </button>
                                    <div class="modal fade" id="cancelModal{{ $session->id }}" tabindex="-1" wire:ignore.self>
                                        <div class="modal-dialog"><div class="modal-content">
                                            <form action="{{ route('admin.cashier.cancel', $invoice) }}" method="POST">@csrf
                                                <input type="hidden" name="return_session" value="{{ $session->id }}">
                                                <div class="modal-header">
                                                    <h5 class="mb-0">إلغاء فاتورة</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <textarea name="reason" class="form-control" rows="2" placeholder="السبب" required></textarea>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                                                    <button class="btn btn-danger">تأكيد</button>
                                                </div>
                                            </form>
                                        </div></div>
                                    </div>
                                </div>
                            @endif

                            {{-- Write off — the customer isn't going to pay. issued OR
                                 partially_paid (mirrors the classic condition). --}}
                            @if(in_array($invoice->status, ['issued', 'partially_paid'], true))
                                <div wire:key="cx-writeoff-{{ $session->id }}">
                                    <button type="button" class="btn btn-sm btn-outline-dark w-100 mt-2"
                                            data-bs-toggle="modal" data-bs-target="#writeoffModal{{ $session->id }}">
                                        شطب (الزبون ما دفع)
                                    </button>
                                    <div class="modal fade" id="writeoffModal{{ $session->id }}" tabindex="-1" wire:ignore.self>
                                        <div class="modal-dialog"><div class="modal-content">
                                            <form action="{{ route('admin.cashier.writeoff', $invoice) }}" method="POST">@csrf
                                                <input type="hidden" name="return_session" value="{{ $session->id }}">
                                                <div class="modal-header">
                                                    <h5 class="mb-0">شطب فاتورة</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <textarea name="reason" class="form-control" rows="2" placeholder="السبب" required></textarea>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                                                    <button class="btn btn-dark">تأكيد</button>
                                                </div>
                                            </form>
                                        </div></div>
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

    {{-- Refund modal — component-root level so it works from BOTH the session
         and the remote-order detail (it used to live inside the session branch
         only, leaving the remote «استرداد» button a dead end). --}}
    @if($refundOpen)
        <div class="cx-modal-overlay" wire:click.self="closeRefund">
            <div class="cx-modal">
                <div class="cx-modal-head">
                    <strong>استرداد مبلغ</strong>
                    <button type="button" wire:click="closeRefund" class="btn-close"></button>
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
                            @foreach($this->refundMethods as $method => $label)
                                <option value="{{ $method }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">السبب</label>
                        <textarea wire:model="refundReason" class="form-control" rows="2" placeholder="سبب الاسترداد..."></textarea>
                        @error('refundReason') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" wire:click="closeRefund" class="btn btn-light flex-grow-1">تراجع</button>
                        {{-- Double-submit: Alpine one-shot covers the gap before
                             Livewire flips wire:loading; the 2.5s auto-release keeps
                             a failed validation retryable. Server idempotency is the
                             service layer's job — this is the client half only. --}}
                        <button type="button"
                                x-data="{ armed: false }"
                                @click="if (armed) return; armed = true; setTimeout(() => armed = false, 2500); $wire.submitRefund()"
                                wire:loading.attr="disabled" wire:target="submitRefund"
                                class="btn btn-danger flex-grow-2" style="flex: 2;">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span wire:loading.remove wire:target="submitRefund">تأكيد الاسترداد</span>
                            <span wire:loading wire:target="submitRefund">جارٍ...</span>
                        </button>
                    </div>
                </div>
            </div>
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

    <script>
    /* Cashier sound system. Same architecture as the kitchen + waiter screens
     * (Web Audio synthesis, state on `window` to survive Livewire morphs).
     * Differentiated tones so a multi-screen station can tell at a glance
     * which surface needs attention without looking up.
     *   - new bill request          → C5→G5 ascending two-tone
     *   - new remote/phone order    → soft single chime
     *   - bill request escalates    → low pulse warning (food + customer waiting)
     */
    function cashierSound() {
        return {
            // Reactive Alpine property (so :class="enabled ? ..." updates).
            // Mirror to window so the value survives Livewire morphs that
            // re-init this component, and so the kitchen/waiter screens
            // can read it too if they share the same browser tab.
            enabled: window.__cxSoundEnabled !== false,
            init() {
                if (typeof window.__cxPrev !== 'object') {
                    window.__cxPrev = this.snapshot();
                }
                window.__cxSoundEnabled = this.enabled;
                window.__refreshAudioBanner?.();
                this.checkChanges();

                // MutationObserver on the bill-stat dataset attributes —
                // Livewire v4 dropped the morph.updated event, so we react
                // to DOM changes directly.
                if (this._observer) this._observer.disconnect();
                this._observer = new MutationObserver(() => {
                    this.enabled = window.__cxSoundEnabled !== false;
                    this.checkChanges();
                });
                this._observer.observe(this.$root, {
                    attributes: true,
                    attributeFilter: [
                        'data-bill-total', 'data-bill-amber',
                        'data-bill-red', 'data-remote-unpaid',
                        'data-pending-transfers',
                    ],
                });
            },
            snapshot() {
                const r = this.$root;
                return {
                    total:  parseInt(r.dataset.billTotal        || '0', 10),
                    amber:  parseInt(r.dataset.billAmber        || '0', 10),
                    red:    parseInt(r.dataset.billRed          || '0', 10),
                    remote: parseInt(r.dataset.remoteUnpaid     || '0', 10),
                    transfers: parseInt(r.dataset.pendingTransfers || '0', 10),
                };
            },
            toggleSound() {
                this.enabled = !this.enabled;
                window.__cxSoundEnabled = this.enabled;
                window.__refreshAudioBanner?.();
                if (this.enabled) this.unlockAudio();
            },
            unlockAudio() {
                window.unlockAudioCtx?.();
            },
            checkChanges() {
                const cur = this.snapshot();
                // First mount → baseline only, no beep on page load.
                if (typeof window.__cxPrev !== 'object') {
                    window.__cxPrev = cur;
                    return;
                }
                const prev = window.__cxPrev;
                if (this.enabled) {
                    // Red bumped → a bill request just crossed 8 min, food/customer waiting.
                    if (cur.red > prev.red)            this.playWarning();
                    // New bill request arrived (total bumped, no escalation).
                    else if (cur.total > prev.total)   this.playBillRequest();
                    // Bill request crossed 3 min → soft nudge.
                    else if (cur.amber > prev.amber)   this.playNudge();
                    // New phone/delivery order needing collection.
                    else if (cur.remote > prev.remote) this.playRemoteOrder();

                    // A bank transfer was just claimed — independent signal, so
                    // it chimes even alongside a bill request. Distinct low
                    // two-tone so the cashier hears "money to verify", not "table waiting".
                    if (cur.transfers > prev.transfers) this.playTransfer();
                }
                window.__cxPrev = cur;
            },
            beep(frequency, duration, type = 'sine', volume = 0.25) {
                const ctx = window.__audioCtx;
                if (!ctx || ctx.state !== 'running') return;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = type;
                osc.frequency.setValueAtTime(frequency, ctx.currentTime);
                gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(volume, ctx.currentTime + 0.01);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + duration);
                osc.connect(gain).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + duration);
            },
            playBillRequest() { this.beep(523, 0.18, 'sine', 0.32); setTimeout(() => this.beep(784, 0.22, 'sine', 0.32), 200); },
            playRemoteOrder() { this.beep(659, 0.18, 'sine', 0.26); },
            playWarning()     { this.beep(330, 0.20, 'square', 0.30); setTimeout(() => this.beep(330, 0.20, 'square', 0.30), 280); setTimeout(() => this.beep(330, 0.24, 'square', 0.34), 580); },
            playNudge()       { this.beep(700, 0.12, 'sine', 0.20); },
            playTransfer()    { this.beep(494, 0.16, 'sine', 0.30); setTimeout(() => this.beep(370, 0.22, 'sine', 0.30), 190); },
        };
    }
    </script>
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

{{--
    cxPayConfirm() — payment-zone Alpine helper (two-step confirm + change-due)

    The section's wire:key embeds the invoice BALANCE, so any server-side
    balance change (payment landed, discount applied) replaces the element
    and re-inits this component — confirming/tendered reset for free and
    `balance` is never stale.

    Reads the amount from the DOM input (not a mirrored model) because the
    «المتبقي» preset updates the input via a Livewire morph that fires no
    input event — the DOM value is the single source of truth. `amtLive`
    exists only to give Alpine a reactive dependency while typing.
--}}
<script>
window.cxPayConfirm = function (config) {
    return {
        confirming: false,
        amtLive: '',
        tendered: '',
        balance: Number(config.balance) || 0,

        init() {
            this.amtLive = this.$refs.amt?.value ?? '';
        },

        /** Arm the confirm step — never records anything by itself. */
        arm() {
            const v = parseFloat(this.$refs.amt?.value);
            if (!isFinite(v) || v < 0.01) { this.$refs.amt?.focus(); return; }
            this.amtLive = this.$refs.amt.value;
            this.confirming = true;
        },

        /** Amount shown inside «تأكيد قبض …؟» — the raw typed value (the
         *  server re-validates against the balance anyway). */
        fmtAmt() {
            const v = parseFloat(this.amtLive);
            return isFinite(v) ? v.toFixed(2) : '0.00';
        },

        /** Cash change = tendered − applied, where applied is capped at the
         *  balance (same math as the classic pay screen). Display-only. */
        changeDue() {
            this.amtLive; // reactive dep so the box follows amount edits
            const t = parseFloat(this.tendered);
            const applied = Math.min(parseFloat(this.$refs.amt?.value) || 0, this.balance);
            if (!isFinite(t) || applied <= 0 || t <= applied) return 0;
            return t - applied;
        },
    };
};
</script>
