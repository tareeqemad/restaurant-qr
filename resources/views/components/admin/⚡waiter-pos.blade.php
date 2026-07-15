<?php

use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Category;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\StaffMealService;
use App\Support\BranchContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Waiter POS — live "new order for a table" screen (Livewire single-file).
 *
 * Replaces the old server-rendered create.blade.php whose every «إضافة» did a
 * native form POST + full page reload. Here the tile grid, search, category
 * filter, cart stepper and submit are all reactive wire:click actions — no
 * reload.
 *
 * ── Cart persistence contract ────────────────────────────────────────────
 * The component's `$cart` is the source of truth for the live UX, BUT it is
 * mirrored to the SAME session key the controller used
 * (`waiter_cart.{userId}.{sessionId}`) on every mutation. This matters because
 * the customer-link and staff-mode panels are still native <form>s (rendered
 * by the wrapper view) that POST to the existing controller routes and
 * redirect back — which re-mounts THIS component and wipes public $cart. By
 * loading the cart from the session on mount() and writing it back on every
 * add/remove/qty change, a customer-link reload never loses the in-progress
 * cart. It also keeps the existing session-cart contract intact for the
 * backward-compat controller routes.
 *
 * Branch context: a Livewire update request does not always carry the admin
 * `branch` middleware's BranchContext, so stock/promo evaluation and order
 * creation are explicitly pinned to the session's branch (mirrors the cashier
 * dashboard + the "pin to order branch" cross-branch safety pattern).
 */
new class extends Component
{
    use AuthorizesRequests;

    public int $tableId;
    public int $sessionId;
    public ?int $branchId = null;

    /** Live cart — each row:
     *  id, menu_item_id, name, unit_price (promo-aware), modifiers_total,
     *  quantity, modifier_ids[], modifiers[{id,name,price_delta}],
     *  modifier_labels[], line_notes, notes, subtotal. */
    public array $cart = [];

    public string $search = '';
    public string $selectedCategoryId = '';   // '' = all

    /** General order note sent to the kitchen on submit. */
    public string $submitNotes = '';

    // ── Modifier modal state ──────────────────────────────────────────────
    public ?int $modItemId = null;      // which item's modifier modal is open
    public array $modSelected = [];     // chosen modifier ids (mixed string/int)
    public int $modQty = 1;
    public string $modNotes = '';

    public function mount(int $tableId): void
    {
        $this->authorize('create', Order::class);

        $table = Table::findOrFail($tableId);
        $this->tableId = $table->id;

        $session = $this->ensureActiveSession($table);
        $this->sessionId = $session->id;
        $this->branchId = $session->branch_id;

        // Hydrate the cart from the session so a customer-link / staff-mode
        // native-form redirect (which re-mounts this component) keeps it.
        $this->cart = array_values(array_map(
            fn ($row) => $this->normalizeRow($row),
            session($this->cartKey(), []),
        ));
        $this->persistCart();   // write the normalized shape straight back
    }

    // ─── Session resolution (mirrors WaiterOrderController::ensureActiveSession) ─

    protected function ensureActiveSession(Table $table): TableSession
    {
        $session = $table->activeSession;
        if ($session) {
            return $session;
        }

        $session = TableSession::create([
            'branch_id' => $table->branch_id ?? BranchContext::current(),
            'table_id' => $table->id,
            'token' => Str::uuid()->toString(),
            'status' => 'active',
            'opened_at' => now(),
            'cover_count' => 1,
            'assigned_waiter_id' => auth()->id(),
        ]);

        if ($table->status !== 'occupied') {
            $previousStatus = $table->status;
            $table->update(['status' => 'occupied']);
            SafeBroadcast::dispatch(
                new TableStatusChanged($table->refresh(), $previousStatus)
            );
        }

        return $session;
    }

    protected function session(): TableSession
    {
        return TableSession::findOrFail($this->sessionId);
    }

    // ─── Session cart / staff-mode keys (mirror the controller) ───────────

    protected function cartKey(): string
    {
        return 'waiter_cart.'.auth()->id().'.'.$this->sessionId;
    }

    protected function staffModeKey(): string
    {
        return 'waiter_staff_mode.'.auth()->id().'.'.$this->sessionId;
    }

    protected function persistCart(): void
    {
        session()->put($this->cartKey(), $this->cart);
    }

    protected function clearCart(): void
    {
        $this->cart = [];
        session()->forget($this->cartKey());
    }

    /**
     * Fill in / repair a cart row so both an old controller-written row and a
     * fresh component row render + submit identically. Recomputes subtotal.
     */
    protected function normalizeRow(array $row): array
    {
        $unit = (float) ($row['unit_price'] ?? 0);
        $modsTotal = isset($row['modifiers_total'])
            ? (float) $row['modifiers_total']
            : (float) collect($row['modifiers'] ?? [])->sum('price_delta');
        $qty = max(1, (int) ($row['quantity'] ?? 1));
        $modifiers = array_values($row['modifiers'] ?? []);
        $labels = $row['modifier_labels'] ?? collect($modifiers)->pluck('name')->all();
        $notes = $row['line_notes'] ?? ($row['notes'] ?? null);

        return [
            'id' => (string) ($row['id'] ?? uniqid('wp', true)),
            'menu_item_id' => (int) $row['menu_item_id'],
            'name' => (string) ($row['name'] ?? ''),
            'unit_price' => $unit,
            'modifiers_total' => $modsTotal,
            'quantity' => $qty,
            'modifier_ids' => array_values(array_map('intval', $row['modifier_ids'] ?? [])),
            'modifiers' => $modifiers,
            'modifier_labels' => array_values($labels),
            'line_notes' => $notes,
            'notes' => $notes,
            'subtotal' => ($unit + $modsTotal) * $qty,
        ];
    }

    // ─── Computed menu ────────────────────────────────────────────────────

    /**
     * Available menu items for the session's branch, decorated with a
     * promo-aware price + live stock flag. Evaluated inside the session's
     * branch context so promotions and stock read the right branch even on a
     * bare Livewire update request.
     */
    #[Computed]
    public function menuItems()
    {
        return BranchContext::forBranch($this->branchId, function () {
            $query = MenuItem::query()
                ->where('is_available', true)
                ->with(['category', 'modifierGroups.modifiers', 'recipeItems.ingredient'])
                ->orderBy('display_order')
                ->orderBy('name');

            if ($this->selectedCategoryId !== '') {
                $query->where('category_id', (int) $this->selectedCategoryId);
            }

            if (strlen(trim($this->search)) > 0) {
                $s = trim($this->search);
                $query->where(function ($lookup) use ($s) {
                    $lookup->where('name', 'like', "%{$s}%")
                        ->orWhere('name_en', 'like', "%{$s}%");
                });
            }

            $inventory = app(InventoryService::class);

            // Short-circuit the per-item promo lookup: activePromotion() runs
            // one MenuPromotion query PER item (~78 on first paint). Check once
            // whether any promo could apply in this branch — the common case
            // (no live promos) then costs a single existence query instead of 78.
            $hasPromos = \App\Models\MenuPromotion::where('active', true)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $this->branchId))
                ->exists();

            return $query->get()->each(function (MenuItem $item) use ($inventory, $hasPromos) {
                $promo = $hasPromos ? $item->activePromotion() : null;
                $price = $promo ? (float) $promo->applyTo((float) $item->price) : (float) $item->price;

                $item->setAttribute('wp_price', $price);
                $item->setAttribute('wp_original', (float) $item->price);
                $item->setAttribute('wp_has_promo', $promo && $price < (float) $item->price);
                $item->setAttribute('wp_in_stock', $this->itemInStock($inventory, $item));
                $item->setAttribute('wp_has_mods', $item->modifierGroups->count() > 0);
            });
        });
    }

    /**
     * Whether one unit of the item is in stock — computed IN MEMORY from the
     * already-eager-loaded recipeItems.ingredient. The old path
     * ($item->stockShortages) funneled through checkStockForOrderPreview,
     * which did a fresh `MenuItem::with(recipe)->find()` PER item — ~78
     * round-trips on first paint (the "seconds to open" report). previewDeduction
     * ForItem reads the passed item's loaded relations instead, so the whole
     * grid's stock resolves with (near) zero extra queries. Items with no
     * recipe (wifi cards) preview empty → always in stock.
     */
    protected function itemInStock(InventoryService $inventory, MenuItem $item): bool
    {
        $need = [];
        foreach ($inventory->previewDeductionForItem($item, 1.0) as $line) {
            $id = $line['ingredient_id'];
            $need[$id] ??= ['ingredient' => $line['ingredient'], 'qty' => 0.0];
            $need[$id]['qty'] += (float) $line['quantity_in_base'];
        }

        foreach ($need as $n) {
            if ($n['ingredient']->track_stock && $n['qty'] > (float) $n['ingredient']->current_stock) {
                return false;
            }
        }

        return true;
    }

    /** Category filter chips with available-item counts. */
    #[Computed]
    public function categories()
    {
        return Category::where('active', true)
            ->whereHas('menuItems', fn ($q) => $q->where('is_available', true))
            ->withCount(['menuItems as available_items_count' => fn ($q) => $q->where('is_available', true)])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    /** The item whose modifier modal is currently open (or null). */
    #[Computed]
    public function modItem(): ?MenuItem
    {
        if (! $this->modItemId) {
            return null;
        }

        return BranchContext::forBranch($this->branchId, function () {
            $item = MenuItem::with('modifierGroups.modifiers')->find($this->modItemId);
            if (! $item) {
                return null;
            }
            $promo = $item->activePromotion();
            $price = $promo ? (float) $promo->applyTo((float) $item->price) : (float) $item->price;
            $item->setAttribute('wp_price', $price);
            $item->setAttribute('wp_original', (float) $item->price);
            $item->setAttribute('wp_has_promo', $promo && $price < (float) $item->price);

            return $item;
        });
    }

    #[Computed]
    public function cartTotal(): float
    {
        return (float) collect($this->cart)->sum('subtotal');
    }

    // ─── Stock helpers ────────────────────────────────────────────────────

    /**
     * Cumulative stock check for a projected cart (same guard the controller's
     * addItem ran). Returns the shortage list — empty means it's safe to add.
     *
     * @param  array<int,array{menu_item_id:int,quantity:float,modifier_ids:array}>  $projected
     */
    protected function stockIssues(array $projected): array
    {
        return BranchContext::forBranch($this->branchId, fn () => app(InventoryService::class)
            ->checkStockForOrderPreview($projected));
    }

    /** Map the live cart to the InventoryService preview shape. */
    protected function cartAsPreview(): array
    {
        return collect($this->cart)->map(fn ($r) => [
            'menu_item_id' => (int) $r['menu_item_id'],
            'quantity' => (float) $r['quantity'],
            'modifier_ids' => $r['modifier_ids'] ?? [],
        ])->all();
    }

    protected function toastStockShort(array $issues): void
    {
        $short = collect($issues)
            ->map(fn ($i) => $i['ingredient'].' (متاح '.rtrim(rtrim(number_format($i['available'], 2), '0'), '.').')')
            ->take(3)
            ->join('، ');
        $this->dispatch('toast', type: 'warning', message: "نفد المخزون من: {$short}.");
    }

    // ─── Add / modify / remove ────────────────────────────────────────────

    /**
     * Tap a tile. Items with modifier groups open the modifier modal; plain
     * items increment their existing line or push a fresh qty-1 line.
     */
    public function addItem(int $menuItemId): void
    {
        $item = MenuItem::with('modifierGroups.modifiers', 'recipeItems.ingredient')
            ->where('is_available', true)
            ->find($menuItemId);
        if (! $item) {
            return;
        }

        if ($item->modifierGroups->count() > 0) {
            $this->openModifiers($menuItemId);
            return;
        }

        // Would-be cart after adding one more of this plain item.
        $projected = $this->cartAsPreview();
        $existingIndex = collect($this->cart)->search(fn ($r) => (int) $r['menu_item_id'] === $item->id
            && empty($r['modifier_ids'])
            && empty($r['line_notes']));

        if ($existingIndex !== false) {
            $projected[$existingIndex]['quantity'] = (float) $this->cart[$existingIndex]['quantity'] + 1;
        } else {
            $projected[] = ['menu_item_id' => $item->id, 'quantity' => 1.0, 'modifier_ids' => []];
        }

        $issues = $this->stockIssues($projected);
        if (! empty($issues)) {
            $this->toastStockShort($issues);
            return;
        }

        if ($existingIndex !== false) {
            $this->cart[$existingIndex]['quantity']++;
            $this->cart[$existingIndex]['subtotal'] =
                ($this->cart[$existingIndex]['unit_price'] + $this->cart[$existingIndex]['modifiers_total'])
                * $this->cart[$existingIndex]['quantity'];
        } else {
            $price = BranchContext::forBranch($this->branchId, function () use ($item) {
                $promo = $item->activePromotion();

                return $promo ? (float) $promo->applyTo((float) $item->price) : (float) $item->price;
            });
            $this->cart[] = $this->normalizeRow([
                'menu_item_id' => $item->id,
                'name' => $item->name,
                'unit_price' => $price,
                'modifiers_total' => 0.0,
                'quantity' => 1,
                'modifier_ids' => [],
                'modifiers' => [],
                'modifier_labels' => [],
                'line_notes' => null,
            ]);
        }

        $this->persistCart();
    }

    public function openModifiers(int $menuItemId): void
    {
        $this->modItemId = $menuItemId;
        $this->modSelected = [];
        $this->modQty = 1;
        $this->modNotes = '';
    }

    public function closeModifiers(): void
    {
        $this->reset(['modItemId', 'modSelected', 'modQty', 'modNotes']);
    }

    /** Confirm the modifier modal → push a configured line into the cart. */
    public function addWithModifiers(): void
    {
        $item = MenuItem::with('modifierGroups.modifiers', 'recipeItems.ingredient')
            ->where('is_available', true)
            ->find($this->modItemId);
        if (! $item) {
            $this->closeModifiers();
            return;
        }

        $qty = max(1, (int) $this->modQty);
        $selected = collect($this->modSelected)->map(fn ($id) => (int) $id)->filter()->unique();

        // Constrain to this item's real modifiers + enforce group min/max.
        $allowed = $item->modifierGroups->flatMap(fn ($g) => $g->modifiers->pluck('id'))
            ->map(fn ($id) => (int) $id);
        $selected = $selected->intersect($allowed)->values();

        foreach ($item->modifierGroups as $group) {
            $count = $group->modifiers->pluck('id')->map(fn ($id) => (int) $id)
                ->intersect($selected)->count();
            if ($group->required && $count < (int) $group->min_select) {
                $this->dispatch('toast', type: 'warning', message: "«{$item->name}»: اختر {$group->min_select} من {$group->name}.");
                return;
            }
            if ((int) $group->max_select > 0 && $count > (int) $group->max_select) {
                $this->dispatch('toast', type: 'warning', message: "«{$item->name}»: الحد الأعلى في {$group->name} هو {$group->max_select}.");
                return;
            }
        }

        $modifierIds = $selected->all();

        // Cumulative stock check with the new line included.
        $projected = $this->cartAsPreview();
        $projected[] = ['menu_item_id' => $item->id, 'quantity' => (float) $qty, 'modifier_ids' => $modifierIds];
        $issues = $this->stockIssues($projected);
        if (! empty($issues)) {
            $this->toastStockShort($issues);
            return;
        }

        $modifiers = Modifier::whereIn('id', $modifierIds)->get();
        $modifiersTotal = (float) $modifiers->sum('price_delta');
        $price = BranchContext::forBranch($this->branchId, function () use ($item) {
            $promo = $item->activePromotion();

            return $promo ? (float) $promo->applyTo((float) $item->price) : (float) $item->price;
        });

        $this->cart[] = $this->normalizeRow([
            'menu_item_id' => $item->id,
            'name' => $item->name,
            'unit_price' => $price,
            'modifiers_total' => $modifiersTotal,
            'quantity' => $qty,
            'modifier_ids' => $modifierIds,
            'modifiers' => $modifiers->map(fn ($m) => [
                'id' => (int) $m->id,
                'name' => $m->name,
                'price_delta' => (float) $m->price_delta,
            ])->values()->all(),
            'modifier_labels' => $modifiers->pluck('name')->all(),
            'line_notes' => trim($this->modNotes) ?: null,
        ]);

        $this->persistCart();
        $this->closeModifiers();
    }

    /** Stepper. delta that would drop qty below 1 removes the line. */
    public function changeQty(int $index, int $delta): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        $newQty = (int) $this->cart[$index]['quantity'] + $delta;
        if ($newQty < 1) {
            $this->removeLine($index);
            return;
        }

        if ($delta > 0) {
            $projected = $this->cartAsPreview();
            $projected[$index]['quantity'] = (float) $newQty;
            $issues = $this->stockIssues($projected);
            if (! empty($issues)) {
                $this->toastStockShort($issues);
                return;
            }
        }

        $this->cart[$index]['quantity'] = $newQty;
        $this->cart[$index]['subtotal'] =
            ($this->cart[$index]['unit_price'] + $this->cart[$index]['modifiers_total']) * $newQty;
        $this->persistCart();
    }

    public function removeLine(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
        $this->persistCart();
    }

    public function setCategory(string $id): void
    {
        $this->selectedCategoryId = $id;
    }

    // ─── Submit (mirrors WaiterOrderController::submit) ───────────────────

    public function submit(OrderService $orders, StaffMealService $staffMeals)
    {
        $this->authorize('create', Order::class);

        if (empty($this->cart)) {
            $this->dispatch('toast', type: 'error', message: 'السلة فاضية.');
            return;
        }

        // Re-check stock against the WHOLE cart at submit time. $cart is a
        // public Livewire property (client round-tripped), so the add-time
        // guards aren't authoritative — a forged snapshot could smuggle an
        // out-of-stock line past them. createFromCart never re-checks stock,
        // so this is the last gate before the order commits.
        $submitIssues = $this->stockIssues($this->cartAsPreview());
        if (! empty($submitIssues)) {
            $this->toastStockShort($submitIssues);
            return;
        }

        try {
            // Inside the try so a closed/removed session degrades to a toast
            // instead of an uncaught 500.
            $session = $this->session();

            [$order, $staffMember, $autoApproved] = BranchContext::forBranch(
                $session->branch_id,
                function () use ($orders, $staffMeals, $session) {
                    // Reuse the cart-shape OrderService already understands.
                    $orderInput = collect($this->cart)->map(fn ($r) => [
                        'menu_item_id' => (int) $r['menu_item_id'],
                        'quantity' => (float) $r['quantity'],
                        'modifier_ids' => $r['modifier_ids'] ?? [],
                        'notes' => $r['line_notes'] ?? null,
                    ])->all();

                    $order = $orders->createFromCart(
                        session: $session,
                        cart: $orderInput,
                        createdByUserId: auth()->id(),
                        customerNotes: trim($this->submitNotes) ?: null,
                    );

                    // Staff-meal mode: stamp the consumer + record the charge.
                    $staffMode = session($this->staffModeKey(), ['user_id' => null]);
                    $staffMember = null;
                    if (! empty($staffMode['user_id'])) {
                        $order->update(['staff_consumer_user_id' => $staffMode['user_id']]);
                        $staffMeals->chargeOrder($order->fresh(), auth()->id());
                        $staffMember = User::find($staffMode['user_id']);
                        session()->put($this->staffModeKey(), ['user_id' => null]);   // reset for next order
                    }

                    // Auto-approve when the operator trusts waiters to push
                    // straight to the kitchen (same setting the controller reads).
                    $autoApproved = false;
                    if (Setting::get('auto_approve', config('restaurant.order.auto_approve', false))) {
                        try {
                            $orders->approve($order->fresh(), auth()->id());
                            $autoApproved = true;
                        } catch (\Throwable $e) {
                            session()->flash('warning', 'تعذّر الاعتماد التلقائي: '.$e->getMessage());
                        }
                    }

                    return [$order, $staffMember, $autoApproved];
                }
            );
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
            return;
        }

        $this->clearCart();
        $this->submitNotes = '';

        $statusNote = $autoApproved ? 'وأُرسل للمطبخ.' : 'بانتظار الاعتماد.';
        $msg = $staffMember
            ? "تم إنشاء طلب موظف #{$order->number} للموظف «{$staffMember->name}» بقيمة "
                .number_format((float) $order->total, 2).' ش.إ. متبقي هذا الشهر: '
                .number_format($staffMember->fresh()->staffMealRemainingThisMonth() ?? 0, 2).' ش.إ.'
            : "تم إنشاء الطلب #{$order->number} على طاولة {$session->table?->number}. {$statusNote}";

        return redirect()->route('admin.waiter-orders.index')->with('success', $msg);
    }
}
?>

<div class="wp-root" wire:key="waiter-pos-{{ $sessionId }}"
     style="--wp-primary:#166534;--wp-primary-d:#0f4731;">
    <div class="wp-layout">
        {{-- ─── MAIN: search + category chips + tile grid ─────────────── --}}
        <div class="wp-main">
            <div class="wp-toolbar">
                <div class="wp-search">
                    <i class="bi bi-search"></i>
                    <input type="text" wire:model.live.debounce.300ms="search"
                           placeholder="ابحث عن صنف..." autocomplete="off" inputmode="search">
                    @if($search !== '')
                        <button type="button" class="wp-search-clear" wire:click="$set('search', '')" title="مسح">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    @endif
                </div>
                <div class="wp-cats">
                    <button type="button"
                            class="wp-cat {{ $selectedCategoryId === '' ? 'is-active' : '' }}"
                            wire:click="setCategory('')">
                        الكل
                    </button>
                    @foreach($this->categories as $cat)
                        <button type="button"
                                class="wp-cat {{ $selectedCategoryId === (string) $cat->id ? 'is-active' : '' }}"
                                wire:click="setCategory('{{ $cat->id }}')">
                            {{ $cat->name }}
                            <span>{{ $cat->available_items_count }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="wp-grid">
                @forelse($this->menuItems as $item)
                    @php
                        $cartQty = collect($cart)->where('menu_item_id', $item->id)->sum('quantity');
                    @endphp
                    <button type="button"
                            wire:key="wp-tile-{{ $item->id }}"
                            @if($item->wp_in_stock)
                                wire:click="addItem({{ $item->id }})"
                            @else
                                disabled
                            @endif
                            class="wp-tile {{ $item->wp_in_stock ? '' : 'is-out' }} {{ $cartQty > 0 ? 'is-in-cart' : '' }}">
                        @if($cartQty > 0)
                            <span class="wp-tile-qty">{{ $cartQty }}</span>
                        @endif
                        <strong class="wp-tile-name">{{ $item->name }}</strong>
                        <span class="wp-tile-foot">
                            @if($item->wp_has_promo)
                                <span class="wp-tile-old">{{ \App\Helpers\Money::format($item->wp_original) }}</span>
                                <b class="wp-tile-promo">
                                    <i class="bi bi-tag-fill"></i> {{ \App\Helpers\Money::format($item->wp_price) }}
                                </b>
                            @else
                                <b>{{ \App\Helpers\Money::format($item->wp_price) }}</b>
                            @endif
                        </span>
                        <span class="wp-tile-badges">
                            @if(! $item->wp_in_stock)
                                <em class="wp-badge-out"><i class="bi bi-box-seam"></i> نفد</em>
                            @elseif($item->wp_has_mods)
                                <em class="wp-badge-mods"><i class="bi bi-sliders2"></i> خيارات</em>
                            @else
                                <em class="wp-badge-add"><i class="bi bi-plus-lg"></i></em>
                            @endif
                        </span>
                    </button>
                @empty
                    <div class="wp-empty-grid">
                        <i class="bi bi-search"></i>
                        <strong>لا يوجد صنف مطابق</strong>
                        <span>جرّب اسم أقصر أو غيّر القسم.</span>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ─── SIDE: live cart ────────────────────────────────────────── --}}
        <aside class="wp-cart">
            <div class="wp-cart-head">
                <strong><i class="bi bi-cart3"></i> سلة الطلب</strong>
                <span class="wp-cart-count">{{ count($cart) }}</span>
            </div>

            @if(count($cart))
                <div class="wp-cart-lines">
                    @foreach($cart as $i => $row)
                        <div class="wp-line" wire:key="wp-line-{{ $i }}-{{ $row['id'] }}">
                            <div class="wp-line-info">
                                <div class="wp-line-name">{{ $row['name'] }}</div>
                                @if(! empty($row['modifier_labels']))
                                    <div class="wp-line-mods">{{ implode('، ', $row['modifier_labels']) }}</div>
                                @endif
                                @if(! empty($row['line_notes']))
                                    <div class="wp-line-note"><i class="bi bi-sticky"></i> {{ $row['line_notes'] }}</div>
                                @endif
                                <div class="wp-line-unit">
                                    {{ \App\Helpers\Money::format($row['unit_price'] + $row['modifiers_total']) }} / وحدة
                                </div>
                            </div>
                            <div class="wp-line-side">
                                <div class="wp-stepper">
                                    <button type="button" wire:click="changeQty({{ $i }}, -1)" aria-label="تقليل">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <span>{{ $row['quantity'] }}</span>
                                    <button type="button" wire:click="changeQty({{ $i }}, 1)" aria-label="زيادة">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                <div class="wp-line-total">{{ \App\Helpers\Money::format($row['subtotal']) }}</div>
                                <button type="button" class="wp-line-remove" wire:click="removeLine({{ $i }})" title="حذف">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="wp-cart-foot">
                    <div class="wp-total-row">
                        <span>المجموع</span>
                        <strong>{{ \App\Helpers\Money::format($this->cartTotal) }}</strong>
                    </div>
                    <small class="wp-tax-note">
                        <i class="bi bi-info-circle"></i>
                        الضريبة والخدمة تُحسب عند إرسال الطلب حسب الإعدادات.
                    </small>
                    <input type="text" wire:model="submitNotes" class="wp-note-input"
                           maxlength="1000" placeholder="ملاحظة عامة (اختياري)">
                    <button type="button" class="wp-submit" wire:click="submit"
                            wire:confirm="إرسال الطلب لهذه الطاولة؟"
                            wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">
                            <i class="bi bi-send-check"></i> إرسال الطلب
                        </span>
                        <span wire:loading wire:target="submit">
                            <i class="bi bi-hourglass-split"></i> جارٍ الإرسال...
                        </span>
                    </button>
                </div>
            @else
                <div class="wp-cart-empty">
                    <i class="bi bi-cart-x"></i>
                    <p>السلة فاضية — اختر أصناف من القائمة</p>
                </div>
            @endif
        </aside>
    </div>

    {{-- ─── Modifier modal (Livewire-controlled, no Bootstrap JS) ─────── --}}
    @if($this->modItem)
        @php $mi = $this->modItem; @endphp
        @php
            $modTotal = collect($this->modSelected)
                ->map(fn ($id) => (int) $id)
                ->pipe(function ($ids) use ($mi) {
                    $map = [];
                    foreach ($mi->modifierGroups as $g) {
                        foreach ($g->modifiers as $m) { $map[(int) $m->id] = (float) $m->price_delta; }
                    }
                    return $ids->sum(fn ($id) => $map[$id] ?? 0);
                });
            $modLineTotal = ($mi->wp_price + $modTotal) * max(1, (int) $this->modQty);
        @endphp
        <div class="wp-modal-backdrop" wire:key="wp-modal-{{ $mi->id }}">
            <div class="wp-modal">
                <div class="wp-modal-head">
                    <div>
                        <strong>{{ $mi->name }}</strong>
                        <span class="wp-modal-price">
                            @if($mi->wp_has_promo)
                                <span class="wp-tile-old">{{ \App\Helpers\Money::format($mi->wp_original) }}</span>
                            @endif
                            {{ \App\Helpers\Money::format($mi->wp_price) }}
                        </span>
                    </div>
                    <button type="button" class="wp-modal-close" wire:click="closeModifiers"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="wp-modal-body">
                    <div class="wp-modal-qty">
                        <label>الكمية</label>
                        <div class="wp-stepper">
                            <button type="button" wire:click="$set('modQty', Math.max(1, {{ (int) $this->modQty }} - 1))" aria-label="تقليل"><i class="bi bi-dash"></i></button>
                            <span>{{ (int) $this->modQty }}</span>
                            <button type="button" wire:click="$set('modQty', {{ (int) $this->modQty }} + 1)" aria-label="زيادة"><i class="bi bi-plus"></i></button>
                        </div>
                    </div>

                    @foreach($mi->modifierGroups as $group)
                        <div class="wp-modgroup">
                            <div class="wp-modgroup-title">
                                {{ $group->name }}
                                @if($group->required)
                                    <span class="wp-req">مطلوب</span>
                                @else
                                    <span class="wp-opt">اختياري</span>
                                @endif
                            </div>
                            <div class="wp-modgroup-opts">
                                @foreach($group->modifiers as $mod)
                                    <label class="wp-modopt">
                                        <input type="checkbox" value="{{ $mod->id }}" wire:model.live="modSelected">
                                        <span>{{ $mod->name }}</span>
                                        @if((float) $mod->price_delta !== 0.0)
                                            <small>{{ $mod->price_delta > 0 ? '+' : '' }}{{ \App\Helpers\Money::format($mod->price_delta) }}</small>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <label class="wp-modnote">
                        <span>ملاحظات (اختياري)</span>
                        <input type="text" wire:model="modNotes" maxlength="500" placeholder="مثلاً: بدون بصل">
                    </label>
                </div>
                <div class="wp-modal-foot">
                    <button type="button" class="wp-btn-light" wire:click="closeModifiers">إلغاء</button>
                    <button type="button" class="wp-submit wp-submit-sm" wire:click="addWithModifiers">
                        <i class="bi bi-plus-lg"></i> إضافة للطلب — {{ \App\Helpers\Money::format($modLineTotal) }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

@script
<script>
    // Bridge component toasts to the shared SweetAlert helper (admin layout).
    $wire.on('toast', (payload) => {
        const d = Array.isArray(payload) ? (payload[0] || {}) : (payload || {});
        if (window.showToast) window.showToast(d.message, d.type || 'info');
    });
</script>
@endscript

@once
@endonce
{{-- Styles live in public/assets/dashtic/css/waiter-pos.css, loaded in the
     <head> by the create.blade.php wrapper (@push('styles')) so tiles are
     styled on first paint — a component-body <style> flashed unstyled. --}}
