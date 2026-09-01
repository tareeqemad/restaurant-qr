<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderCreated;
use App\Events\OrderItemStatusChanged;
use App\Events\OrderStatusChanged;
use App\Events\TableStatusChanged;
use App\Helpers\Money;
use App\Helpers\SafeBroadcast;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\OrderItem;
use App\Models\OrderItemIngredientExclusion;
use App\Models\OrderItemModifier;
use App\Models\PendingTransfer;
use App\Models\Scopes\BranchScope;
use App\Models\Setting;
use App\Models\StaffMealCharge;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(protected InventoryService $inventory) {}

    protected function configuredTaxRate(): float
    {
        return app(SalesTaxService::class)->rateForBranch(BranchContext::current());
    }

    protected function configuredServiceRate(): float
    {
        $enabled = (bool) Setting::get('service_enabled', config('restaurant.service_charge.enabled', false));

        return $enabled ? (float) Setting::get('service_rate', config('restaurant.service_charge.rate', 10)) : 0.0;
    }

    /**
     * The lifecycle stage at which stock is decremented. Read once per
     * request so the same value drives approve / startPreparing /
     * markItemReady / markItemServed without four trips to the cache.
     */
    public function inventoryDeductionStage(): string
    {
        $stage = (string) Setting::get('inventory_deduction_stage', config('restaurant.inventory.deduction_stage', 'preparing'));

        return in_array($stage, ['approve', 'preparing', 'ready', 'served'], true)
            ? $stage
            : 'preparing';
    }

    protected function lockOrderItemForWorkflow(OrderItem $item): OrderItem
    {
        $locked = OrderItem::with(['order.tableSession.invoice', 'order.invoice'])
            ->whereKey($item->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->order_id) {
            $order = Order::with(['tableSession.invoice', 'invoice'])
                ->whereKey($locked->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->setRelation('order', $order);
        }

        return $locked;
    }

    protected function assertItemCanMove(OrderItem $item, string $expectedStatus, string $actionKey): void
    {
        $orderStatus = $item->order?->status;
        $action = __("ui.customer_order.workflow_action_{$actionKey}");

        if (in_array($orderStatus, [OrderStatus::Cancelled->value, OrderStatus::Completed->value], true)) {
            throw new \RuntimeException(__('ui.customer_order.workflow_order_closed', [
                'action' => $action,
                'status' => $orderStatus,
            ]));
        }

        if ($item->status !== $expectedStatus) {
            throw new \RuntimeException(__('ui.customer_order.workflow_item_wrong_status', [
                'action' => $action,
                'status' => $item->status,
            ]));
        }

        $hasPendingChange = OrderChangeRequest::query()
            ->where('order_id', $item->order_id)
            ->where('status', OrderChangeRequest::STATUS_PENDING)
            ->where(function ($query) use ($item) {
                $query->whereNull('order_item_id')
                    ->orWhere('order_item_id', $item->id);
            })
            ->exists();

        if ($hasPendingChange) {
            throw new \RuntimeException('يوجد طلب تعديل من الزبون لهذا الصنف. انتظر قرار الجرسون قبل متابعة التنفيذ.');
        }
    }

    protected function itemHasInventoryDeduction(OrderItem $item): bool
    {
        // Unscoped on purpose: movements carry the ORDER's branch, and this
        // check must not lie when the operator stands on another branch
        // (reference_id is exact, so nothing can leak across orders).
        return InventoryMovement::withoutGlobalScopes()
            ->where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)
            ->where('type', 'out')
            ->exists();
    }

    protected function assertOrderCanTransitionTo(Order $order, string $target): void
    {
        $activeItems = $order->items->where('status', '!=', OrderItemStatus::Cancelled->value);

        if ($activeItems->isEmpty()) {
            throw new \RuntimeException(__('ui.customer_order.workflow_order_has_no_active_items'));
        }

        if ($target === OrderStatus::Ready->value
            && ! $activeItems->every(fn ($item) => in_array($item->status, [
                OrderItemStatus::Ready->value,
                OrderItemStatus::Served->value,
            ], true))
        ) {
            throw new \RuntimeException(__('ui.customer_order.workflow_order_ready_blocked'));
        }

        if ($target === OrderStatus::Delivered->value
            && ! $activeItems->every(fn ($item) => $item->status === OrderItemStatus::Served->value)
        ) {
            throw new \RuntimeException(__('ui.customer_order.workflow_order_delivered_blocked'));
        }

        if ($target === OrderStatus::Completed->value) {
            $invoice = $order->tableSession?->invoice ?? $order->invoice;

            if (! $activeItems->every(fn ($item) => $item->status === OrderItemStatus::Served->value)) {
                throw new \RuntimeException(__('ui.customer_order.workflow_order_completed_items_blocked'));
            }

            if (! $invoice || ! in_array($invoice->status, ['paid', 'unpaid_writeoff'], true)) {
                throw new \RuntimeException(__('ui.customer_order.workflow_order_completed_invoice_blocked'));
            }
        }
    }

    /**
     * Create an order from customer cart.
     * Cart shape: [ ['menu_item_id' => int, 'quantity' => float, 'modifier_ids' => [int,...], 'notes' => string], ... ]
     */
    /**
     * A required modifier group must actually be satisfied before the ticket
     * can exist.
     *
     * This lives in the service rather than in each screen because "required"
     * was only ever honoured in ONE of the three entry points: the waiter POS
     * blocks it in its own modal, the cashier rendered a warning next to a
     * button that worked anyway, and the customer cart never looked at all. An
     * order that reaches the pass missing a required choice ("which size?") is
     * not a UI blemish — it's a ticket the cook cannot make, and the guest is
     * already waiting on it.
     *
     * The rule mirrors the POS's own check exactly (required + min_select, then
     * max_select), so no screen has to change its behaviour — the two that were
     * already correct just stop being the only thing standing between a bad
     * cart and the kitchen.
     *
     * @param  array<int, array<string, mixed>>  $cart
     */
    protected function assertModifierRulesSatisfied(array $cart): void
    {
        $itemIds = collect($cart)
            ->pluck('menu_item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($itemIds->isEmpty()) {
            return;
        }

        $items = MenuItem::with('modifierGroups.modifiers')->whereKey($itemIds)->get()->keyBy('id');

        foreach ($cart as $line) {
            $item = $items->get((int) ($line['menu_item_id'] ?? 0));
            if (! $item) {
                continue;
            }

            $selected = collect($line['modifier_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique();

            foreach ($item->modifierGroups as $group) {
                $count = $group->modifiers->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->intersect($selected)
                    ->count();

                if ($group->required && $count < (int) $group->min_select) {
                    throw new \RuntimeException("«{$item->name}»: اختر {$group->min_select} من {$group->name}.");
                }

                if ((int) $group->max_select > 0 && $count > (int) $group->max_select) {
                    throw new \RuntimeException("«{$item->name}»: الحد الأعلى في {$group->name} هو {$group->max_select}.");
                }
            }
        }
    }

    public function createFromCart(
        TableSession $session,
        array $cart,
        ?int $createdByUserId = null,
        ?string $customerNotes = null,
        ?string $orderingDeviceHash = null,
    ): Order {
        $this->assertModifierRulesSatisfied($cart);

        $tableStatusBefore = null;
        $order = DB::transaction(function () use ($session, $cart, $createdByUserId, $customerNotes, $orderingDeviceHash, &$tableStatusBefore) {
            $session = TableSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            // Two phones may build drafts before either submits. Claiming is
            // therefore done under the same row lock and transaction as the
            // order. The losing phone gets no order and no partial claim.
            if ($orderingDeviceHash) {
                if ($session->ordering_device_hash
                    && ! hash_equals($session->ordering_device_hash, $orderingDeviceHash)) {
                    throw new \RuntimeException(
                        'هذه الجلسة تُدار من الهاتف الذي أرسل أول طلب. اطلب الإضافة من صاحب الهاتف أو من الجرسون.',
                        409,
                    );
                }

                if (! $session->ordering_device_hash) {
                    $session->update(['ordering_device_hash' => $orderingDeviceHash]);
                }
            }

            if ($session->invoice()->where('status', '!=', 'cancelled')->exists()) {
                throw new \RuntimeException('الفاتورة صدرت بالفعل. أي طلب إضافي يحتاج إلغاء الفاتورة الحالية من الكاشير أولاً.');
            }
            if (PendingTransfer::where('table_session_id', $session->id)->pending()->exists()) {
                throw new \RuntimeException('يوجد تحويل بنكي بانتظار التحقق. لا يمكن تغيير الحساب قبل أن يؤكده أو يرفضه الكاشير.');
            }

            // A QR scan only opens a browsing session. The first REAL order
            // atomically claims the physical table. The session passed to
            // this service remains the source of truth for the bill; legacy
            // data may contain another active session, so occupying the table
            // must not invalidate an otherwise valid current order.
            $table = Table::withoutGlobalScopes()->whereKey($session->table_id)->lockForUpdate()->firstOrFail();
            if ($table->status === 'out_of_service') {
                throw new \RuntimeException('هذه الطاولة خارج الخدمة حاليًا. اطلب مساعدة الجرسون.');
            }

            $previousTableStatus = $session->engage($createdByUserId);
            $tableStatusBefore = $previousTableStatus !== 'occupied' ? $previousTableStatus : null;

            // A later round reopens a bill request that has not produced an
            // invoice yet. All rounds remain on the same table visit.
            if ($session->bill_requested_at) {
                $session->update([
                    'bill_requested_at' => null,
                    'bill_request_note' => null,
                ]);
            }

            $order = Order::create([
                'table_id' => $session->table_id,
                'table_session_id' => $session->id,
                'order_type' => 'dine_in',
                'status' => OrderStatus::Pending->value,
                'created_by_user_id' => $createdByUserId,
                'customer_notes' => $customerNotes,
                'submitted_at' => now(),
                'tax_rate' => $this->configuredTaxRate(),
                'service_rate' => $this->configuredServiceRate(),
            ]);

            // Eager-load customer ONCE so the per-item promo resolver
            // (inside addItem) reads from a cached relation instead of
            // firing one customer query per cart line (the G7 fix).
            if ($order->customer_id) {
                $order->load('customer');
            }

            foreach ($cart as $row) {
                $this->addItem($order, $row);
            }

            $this->recalculateTotals($order);
            $this->refreshEta($order);
            $session->touch();

            ActivityLog::log('order.created', "إنشاء طلب {$order->number} - طاولة {$session->table->number}", $order, [
                'items_count' => $order->items()->count(),
                'subtotal' => (float) $order->subtotal,
            ]);

            $order = $order->refresh()->load('items', 'table', 'tableSession');
            SafeBroadcast::dispatch(new OrderCreated($order));

            return $order;
        });

        // Notify the responsible floor waiter AFTER commit — never inside the
        // transaction, otherwise a rollback leaves orphan notifications.
        if ($tableStatusBefore !== null && $order->table) {
            SafeBroadcast::dispatch(new TableStatusChanged($order->table->refresh(), $tableStatusBefore));
        }
        app(NotifyService::class)->newOrder($order);

        return $order;
    }

    /**
     * Create a staff-entered phone order that is not attached to a table. The
     * kitchen/bar receive it like any other order, while billing can issue an
     * invoice directly against the order instead of a table session.
     *
     * @param  array<int,array<string,mixed>>  $cart
     * @param array{
     *   customer_name?:?string, customer_phone?:?string, customer_address_id?:?int, customer_notes?:?string,
     *   delivery_address?:?string, delivery_fee?:float|string|null,
     *   scheduled_for?:?string
     * } $opts
     */
    public function createCashierOrder(
        ?Customer $customer,
        Branch $branch,
        string $type,
        string $source,
        array $cart,
        array $opts = [],
        ?int $createdByUserId = null,
    ): Order {
        if (! in_array($type, ['takeaway', 'delivery'], true)) {
            throw new \InvalidArgumentException("Order type must be 'takeaway' or 'delivery'.");
        }

        $this->assertModifierRulesSatisfied($cart);

        $orderSource = OrderSource::tryFrom($source) ?? OrderSource::Other;

        if ($type === 'delivery' && empty($opts['delivery_address'])) {
            throw new \InvalidArgumentException('طلبات الدليفري تحتاج عنوان واضح.');
        }

        $order = BranchContext::forBranch($branch->id, function () use ($customer, $branch, $type, $orderSource, $cart, $opts, $createdByUserId) {
            return DB::transaction(function () use ($customer, $branch, $type, $orderSource, $cart, $opts, $createdByUserId) {
                $deliveryFee = $type === 'delivery'
                    ? Money::round((float) ($opts['delivery_fee'] ?? $branch->deliveryFee()))
                    : 0.0;

                $order = Order::create([
                    'table_id' => null,
                    'table_session_id' => null,
                    'customer_id' => $customer?->id,
                    'customer_name' => $customer?->name ?? ($opts['customer_name'] ?? null),
                    'customer_phone' => $customer?->phone ?? ($opts['customer_phone'] ?? null),
                    'customer_address_id' => $opts['customer_address_id'] ?? null,
                    'order_type' => $type,
                    'order_source' => $orderSource->value,
                    'status' => OrderStatus::Pending->value,
                    'created_by_user_id' => $createdByUserId,
                    'customer_notes' => $opts['customer_notes'] ?? null,
                    'delivery_address' => $opts['delivery_address'] ?? null,
                    'scheduled_for' => $opts['scheduled_for'] ?? null,
                    'submitted_at' => now(),
                    'delivery_fee' => $deliveryFee,
                    'tax_rate' => $this->configuredTaxRate(),
                    'service_rate' => 0,
                ]);

                foreach ($cart as $row) {
                    $this->addItem($order, $row);
                }

                $this->recalculateTotals($order);
                $this->refreshEta($order);

                ActivityLog::log(
                    'order.created_by_cashier',
                    "إنشاء طلب {$type} من الكاشير #{$order->number}",
                    $order,
                    [
                        'source' => $orderSource->value,
                        'items_count' => $order->items()->count(),
                        'total' => (float) $order->total,
                    ]
                );

                $order = $order->refresh()->load('items', 'customer');
                SafeBroadcast::dispatch(new OrderCreated($order));

                return $order;
            });
        });

        app(NotifyService::class)->newOrder($order);

        return $order;
    }

    /**
     * Replace a pending QR round with the version confirmed at the table,
     * then fire that exact version to its stations in the same transaction.
     *
     * @param  array<int,array<string,mixed>>  $cart
     * @param  array<int,int>  $expectedChangeRequestIds
     */
    public function reviewAndApprovePending(
        Order $order,
        array $cart,
        ?string $customerNotes,
        int $userId,
        string $expectedVersion,
        array $expectedChangeRequestIds = [],
    ): Order {
        $this->assertModifierRulesSatisfied($cart);

        return DB::transaction(function () use (
            $order,
            $cart,
            $customerNotes,
            $userId,
            $expectedVersion,
            $expectedChangeRequestIds,
        ) {
            $locked = Order::with([
                'items.modifiers',
                'items.exclusions',
                'tableSession.invoice',
                'changeRequests' => fn ($query) => $query
                    ->where('status', OrderChangeRequest::STATUS_PENDING),
            ])->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Pending->value) {
                throw new \RuntimeException('عولجت هذه الجولة من موظف آخر. حدّث لوحة الصالة.', 409);
            }

            try {
                $expected = Carbon::parse($expectedVersion);
            } catch (\Throwable) {
                throw new \RuntimeException('نسخة الطلب غير صالحة. أعد فتح المراجعة.', 409);
            }

            if (! $locked->updated_at || ! $locked->updated_at->equalTo($expected)) {
                throw new \RuntimeException('تغيّرت الجولة أثناء المراجعة. أعد فتحها لترى النسخة الأحدث.', 409);
            }

            $actualChangeIds = $locked->changeRequests->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $expectedIds = collect($expectedChangeRequestIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($actualChangeIds !== $expectedIds) {
                throw new \RuntimeException('وصل تعديل جديد من الزبون أثناء المراجعة. أعد فتح الجولة وراجعه معه.', 409);
            }

            if ($locked->tableSession?->invoice && $locked->tableSession->invoice->status !== 'cancelled') {
                throw new \RuntimeException('صدرت فاتورة للجلسة. ألغِ الفاتورة من الكاشير قبل تعديل الطلب.');
            }

            $existing = $locked->items
                ->where('status', OrderItemStatus::Pending->value)
                ->keyBy('id');
            $keptIds = [];

            // Add replacements before removing their originals. This keeps a
            // promotion claimed by the same order from consuming a second use.
            foreach ($cart as $row) {
                $existingId = (int) ($row['order_item_id'] ?? 0);
                $line = $existingId > 0 ? $existing->get($existingId) : null;

                if ($existingId > 0 && ! $line) {
                    throw new \RuntimeException('تغيّر أحد الأصناف أثناء المراجعة. أعد فتح الجولة.', 409);
                }

                $requestedModifierIds = collect($row['modifier_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
                $existingModifierIds = $line
                    ? $line->modifiers->pluck('modifier_id')->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all()
                    : [];
                $requestedExclusionIds = collect($row['excluded_ingredient_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
                $existingExclusionIds = $line
                    ? $line->exclusions->pluck('ingredient_id')->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all()
                    : [];
                $sameConfiguration = $line
                    && (int) $line->menu_item_id === (int) $row['menu_item_id']
                    && $existingModifierIds === $requestedModifierIds
                    && $existingExclusionIds === $requestedExclusionIds;

                if ($sameConfiguration) {
                    $quantity = max(1, (int) $row['quantity']);
                    $line->update([
                        'quantity' => $quantity,
                        'notes' => $row['notes'] ?? null,
                        'subtotal' => ((float) $line->unit_price + (float) $line->modifiers_total) * $quantity,
                    ]);
                    $keptIds[] = (int) $line->id;

                    continue;
                }

                $created = $this->addItem($locked, $row);
                $keptIds[] = (int) $created->id;
            }

            foreach ($existing as $line) {
                if (in_array((int) $line->id, $keptIds, true)) {
                    continue;
                }

                $line->modifiers()->delete();
                $line->exclusions()->delete();
                $line->delete();
            }

            if ($locked->items()->where('status', '!=', OrderItemStatus::Cancelled->value)->count() === 0) {
                throw new \RuntimeException('لا يمكن اعتماد جولة فارغة. أضف صنفاً واحداً على الأقل.');
            }

            if ($actualChangeIds !== []) {
                OrderChangeRequest::whereIn('id', $actualChangeIds)->update([
                    'status' => OrderChangeRequest::STATUS_APPROVED,
                    'handled_by_user_id' => $userId,
                    'resolution_note' => 'تم استيعاب التعديل ضمن النسخة التي راجعها الجرسون مع الزبون على الطاولة.',
                    'handled_at' => now(),
                ]);
            }

            $locked->update(['customer_notes' => $customerNotes]);
            $this->recalculateTotals($locked);
            $this->refreshEta($locked);

            ActivityLog::log(
                'order.reviewed_at_table',
                "مراجعة واعتماد الطلب {$locked->number} مع الزبون على الطاولة",
                $locked,
                [
                    'items_count' => $locked->items()->where('status', '!=', OrderItemStatus::Cancelled->value)->count(),
                    'merged_change_requests' => $actualChangeIds,
                ],
            );

            $approved = $this->approve($locked->fresh()->load('items', 'tableSession'), $userId);

            return $approved->fresh()->load('items.modifiers', 'items.exclusions', 'tableSession');
        });
    }

    public function addItem(Order $order, array $row): OrderItem
    {
        $this->assertStaffMealAllocationOpen($order);

        $item = MenuItem::with(['category', 'station', 'recipeItems.ingredient'])->findOrFail($row['menu_item_id']);

        if (! $item->is_available) {
            throw new \RuntimeException("الصنف {$item->name} غير متوفر حالياً");
        }

        $quantity = max(1, (float) ($row['quantity'] ?? 1));
        $modifierIds = $row['modifier_ids'] ?? [];
        $modifiers = Modifier::with('group')->whereIn('id', $modifierIds)->get();
        $requestedExclusionIds = collect($row['excluded_ingredient_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();
        $removableIngredients = $item->recipeItems
            ->filter(fn ($recipe) => $recipe->ingredient !== null)
            ->keyBy(fn ($recipe) => (int) $recipe->ingredient_id);
        $unknownExclusions = $requestedExclusionIds->diff($removableIngredients->keys()->map(fn ($id) => (int) $id));
        if ($unknownExclusions->isNotEmpty()) {
            throw new \RuntimeException("أحد المكوّنات المستبعدة لا ينتمي إلى وصفة «{$item->name}». أعد فتح الصنف.");
        }

        $modifiersTotal = (float) $modifiers->sum('price_delta');
        // Snapshot the EFFECTIVE price at order time. If a promo is live,
        // we record both the original menu price (for receipts + reports)
        // and the active promotion's id (for the audit trail). Once
        // snapshotted, the order keeps this price forever — promo
        // expiring or changing later doesn't drift the placed order.
        //
        // Promotion lookup is channel- AND customer-aware: "QR-only"
        // promos never apply to a cashier line, and "birthday month"
        // promos only fire for the identified customer.
        //
        // The customer is resolved through the Eloquent relation —
        // callers (createFromCart, addItems) pre-load it on the Order
        // instance so multi-item carts hit the customers table at most
        // once, not N times. Direct `$order->customer` reads from the
        // loaded relation when present.
        $menuPrice = (float) $item->price;
        $orderCustomer = $order->relationLoaded('customer')
            ? $order->customer
            : ($order->customer_id ? $order->loadMissing('customer')->customer : null);
        $promotion = app(PromotionService::class)
            ->resolveForItem($item, null, $order->branch_id, $order->order_source, $orderCustomer);

        // Min-subtotal guard: if the promo requires a cart total of X
        // and the order — INCLUDING this new line — wouldn't reach X,
        // skip the discount for this line. Once we've cleared the
        // threshold, every fresh line picks up the promo as usual.
        if ($promotion && $promotion->min_subtotal !== null && (float) $promotion->min_subtotal > 0) {
            $projectedSubtotal = (float) $order->items()
                ->where('status', '!=', OrderItemStatus::Cancelled->value)
                ->sum('subtotal')
                + (($menuPrice + $modifiersTotal) * $quantity);
            if (! $promotion->meetsMinSubtotal($projectedSubtotal)) {
                $promotion = null;
            }
        }

        // Usage limit (#11): the promo has a hard cap on distinct orders.
        // If the limit is set, we need an ATOMIC check-and-increment.
        // The increment fires once per order — subsequent lines that
        // hit the same promo on the same order do NOT re-increment.
        if ($promotion && $promotion->usage_limit !== null) {
            $alreadyClaimedByThisOrder = $order->items()
                ->where('promotion_id', $promotion->id)
                ->exists();
            if (! $alreadyClaimedByThisOrder) {
                // Atomic: update only if usage_count is still below the
                // limit. Affected rows = 1 means we claimed a slot.
                $affected = MenuPromotion::where('id', $promotion->id)
                    ->where(function ($q) {
                        $q->whereNull('usage_limit')
                            ->orWhereColumn('usage_count', '<', 'usage_limit');
                    })
                    ->update(['usage_count' => \DB::raw('usage_count + 1')]);
                if ($affected === 0) {
                    // Exhausted between resolveForItem and now — drop the promo.
                    $promotion = null;
                }
            }
        }

        $unitPrice = $promotion ? $promotion->applyTo($menuPrice) : $menuPrice;
        $hasPromo = $promotion !== null && $unitPrice < $menuPrice;
        $subtotal = ($unitPrice + $modifiersTotal) * $quantity;

        $oi = OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $item->id,
            'station_id' => $item->resolvedStationId(),
            'name_snapshot' => $item->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_price_original' => $hasPromo ? $menuPrice : null,
            'promotion_id' => $hasPromo ? $promotion->id : null,
            'modifiers_total' => $modifiersTotal,
            'subtotal' => $subtotal,
            'notes' => $row['notes'] ?? null,
            'course' => $row['course'] ?? 'main',
            'fire_order' => $row['fire_order'] ?? 0,
            'status' => OrderItemStatus::Pending->value,
        ]);

        // Free-modifier list: when the active promotion lists a modifier
        // id as "free with this item", we still record the modifier on
        // the order (so the kitchen sees it + the receipt explains the
        // perk) but charge price_delta=0. The audit trail keeps the
        // modifier's original price for clarity later — AccountingService
        // uses `price_delta_original` to credit the savings as a real
        // sales discount instead of silently dropping revenue.
        $freeModifierIds = $promotion?->free_modifier_ids ?? [];
        foreach ($modifiers as $m) {
            $isFree = in_array((int) $m->id, array_map('intval', $freeModifierIds), true);
            OrderItemModifier::create([
                'order_item_id' => $oi->id,
                'modifier_id' => $m->id,
                'name_snapshot' => $isFree ? $m->name.' (هدية مع العرض)' : $m->name,
                'price_delta' => $isFree ? 0 : $m->price_delta,
                'price_delta_original' => $isFree ? $m->price_delta : null,
            ]);
        }
        foreach ($requestedExclusionIds as $ingredientId) {
            $recipe = $removableIngredients->get((int) $ingredientId);
            OrderItemIngredientExclusion::create([
                'order_item_id' => $oi->id,
                'ingredient_id' => (int) $ingredientId,
                'name_snapshot' => $recipe->ingredient->localizedName(),
            ]);
        }
        // If any modifier was zeroed out, re-sum + re-snapshot the line
        // so the subtotal matches what the customer pays.
        if (! empty($freeModifierIds)) {
            $effectiveModTotal = (float) $oi->modifiers()->sum('price_delta');
            $oi->update([
                'modifiers_total' => $effectiveModTotal,
                'subtotal' => ($oi->unit_price + $effectiveModTotal) * $oi->quantity,
            ]);
        }

        return $oi;
    }

    public function recalculateTotals(Order $order): Order
    {
        // Buy-N-Get-M promos discount the cart as a SET (not per item),
        // so they're applied here — once item snapshots are stable — as
        // a synthetic OrderDiscount row. Idempotent: re-running this
        // method removes any previous BXGY discount and recomputes.
        app(PromotionService::class)->applyBxgyToOrder($order);

        $subtotalGross = (float) $order->items()->where('status', '!=', OrderItemStatus::Cancelled->value)->sum('subtotal');
        $taxRate = (float) $order->tax_rate;

        // Tax-inclusive menus quote prices WITH tax baked in. Strip it out
        // up-front so discount, tax, service and the ledger posting all work on
        // the NET base — otherwise the embedded tax was added AGAIN on top (the
        // customer paid tax twice and the issuance entry couldn't balance).
        $subtotal = ($taxRate > 0 && config('restaurant.tax.inclusive'))
            ? Money::round($subtotalGross / (1 + $taxRate / 100))
            : $subtotalGross;

        // Cap total discounts at the sale value. Stacked cashier + BXGY
        // discounts could otherwise exceed the subtotal, making the issuance
        // entry unbalanced (DR discounts > CR revenue) so the invoice could
        // never be issued and the table stayed stuck.
        $discountTotal = min((float) $order->discounts()->sum('amount'), $subtotal);
        $subAfterDiscount = max(0, $subtotal - $discountTotal);

        // Tax is now always ADDED on top of the net base (inclusive was
        // normalised above), so compute it directly instead of via
        // Money::applyTax's inclusive-extraction branch.
        $tax = $taxRate > 0
            ? ['tax' => Money::round($subAfterDiscount * $taxRate / 100), 'rate' => $taxRate]
            : ['tax' => 0.0, 'rate' => 0.0];
        $service = Money::applyService($subAfterDiscount, (float) $order->service_rate);

        // Delivery fee is a flat add-on, frozen at order creation time —
        // recalculation just respects whatever was stamped on the order.
        $deliveryFee = (float) $order->delivery_fee;

        $total = $subAfterDiscount + $tax['tax'] + $service['service'] + $deliveryFee + (float) $order->tip;

        $order->update([
            'subtotal' => Money::round($subtotal),
            'discount_total' => Money::round($discountTotal),
            'tax_total' => $tax['tax'],
            'service_total' => $service['service'],
            'total' => Money::round($total),
        ]);

        return $order;
    }

    /**
     * Compute the ETA for an order based on its line items + branch settings.
     *
     * Kitchen-time model: stations work in parallel, so the bottleneck is
     * the SLOWEST item (max prep_time across non-cancelled items), not the
     * sum. A small buffer accounts for plating + cross-station handoff.
     *
     * For delivery orders, the branch's `delivery_estimated_minutes` is
     * tacked onto ready time. For dine-in / takeaway, it's just prep time.
     *
     * Returns:
     *   prepMinutes   : int     buffer-adjusted prep window
     *   readyAt       : Carbon  when the kitchen finishes
     *   deliveredAt   : Carbon|null  arrival time for delivery, null otherwise
     */
    public function computeEta(Order $order): array
    {
        $order->loadMissing('items.menuItem', 'branch');

        $maxItemPrep = (int) $order->items
            ->where('status', '!=', OrderItemStatus::Cancelled->value)
            ->map(fn ($it) => (int) ($it->menuItem?->prep_time_minutes ?? 0))
            ->max();

        $branch = $order->branch;
        $buffer = $branch?->prepBufferMinutes() ?? 5;
        $prep = max(1, $maxItemPrep + $buffer);

        $start = $order->scheduled_for ?? $order->submitted_at ?? now();
        $readyAt = $start->copy()->addMinutes($prep);

        $deliveredAt = null;
        if ($order->order_type === 'delivery' && $branch) {
            $deliveredAt = $readyAt->copy()->addMinutes($branch->deliveryMinutes());
        }

        return [
            'prepMinutes' => $prep,
            'readyAt' => $readyAt,
            'deliveredAt' => $deliveredAt,
        ];
    }

    /**
     * Recompute the ETA snapshot on an order — used after items change
     * (customer edit, cashier swap) so the displayed time stays truthful.
     */
    public function refreshEta(Order $order): Order
    {
        $eta = $this->computeEta($order);
        $order->update([
            'estimated_prep_minutes' => $eta['prepMinutes'],
            'estimated_ready_at' => $eta['readyAt'],
            'estimated_delivered_at' => $eta['deliveredAt'],
        ]);

        return $order->refresh();
    }

    public function approve(Order $order, int $userId): Order
    {
        return DB::transaction(function () use ($order, $userId) {
            if ($order->status !== OrderStatus::Pending->value) {
                throw new \RuntimeException('لا يمكن اعتماد طلب حالته: '.$order->statusLabel());
            }

            if ($order->changeRequests()->where('status', OrderChangeRequest::STATUS_PENDING)->exists()) {
                throw new \RuntimeException('الزبون طلب تعديلاً على هذه الجولة. عالج التعديل أولاً ثم اعتمد الطلب.');
            }

            // ── Safety: verify every tracked ingredient has enough stock BEFORE
            // we start deducting. If not, throw with a clear message listing which
            // ingredients are short. The transaction rollback leaves nothing changed.
            $issues = $this->inventory->validateStockForOrder($order);
            if (! empty($issues) && (bool) Setting::get('strict_stock', config('restaurant.inventory.strict_stock', true))) {
                $this->inventory->throwIfInsufficient($issues);
            }

            $previous = $order->status;
            $items = $order->items()->where('status', OrderItemStatus::Pending->value)->get();
            $deductNow = $this->inventoryDeductionStage() === 'approve';

            foreach ($items as $oi) {
                $oi->update([
                    'status' => OrderItemStatus::Approved->value,
                    'approved_at' => now(),
                ]);
                if ($deductNow) {
                    $this->inventory->ensureDeducted($oi);
                }
            }

            $order->update([
                'status' => OrderStatus::Approved->value,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
            ]);

            if ($order->tableSession) {
                $sessionUpdates = [];
                $approverIsWaiter = User::query()
                    ->whereKey($userId)
                    ->where('role', UserRole::Waiter->value)
                    ->exists();
                if (empty($order->tableSession->assigned_waiter_id) && $approverIsWaiter) {
                    $sessionUpdates['assigned_waiter_id'] = $userId;
                }
                if ($sessionUpdates) {
                    $order->tableSession->update($sessionUpdates);
                }
                $order->tableSession->touch();
            }

            // ── Auto-ready instant lines (بطاقات النت وأمثالها): zero prep
            // time AND no station means no KDS ever shows them, so no cook
            // can tap them forward — an order containing one could never
            // reach Ready. They fulfil DIRECTLY approved→ready, deliberately
            // NOT through startPreparing: walking them through 'preparing'
            // flipped the whole order to Preparing at approval and stamped
            // the customer prep clock/ETA before the kitchen touched
            // anything. No prep stamps here — prep_started_at stays NULL,
            // which is also what keeps them off the KDS boards. Deduction
            // stages 'preparing'/'ready' both collapse to "now" for a line
            // that skips the kitchen (ensureDeducted is idempotent).
            $order->load('items.menuItem.category');
            $autoReadied = false;
            foreach ($order->items as $oi) {
                if ($oi->status !== OrderItemStatus::Approved->value || $oi->station_id !== null) {
                    continue;
                }
                $menu = $oi->menuItem;
                if ($menu && (int) ($menu->prep_time_minutes ?? 0) === 0 && $menu->resolvedStationId() === null) {
                    $oi->update([
                        'status' => OrderItemStatus::Ready->value,
                        'ready_at' => now(),
                    ]);
                    if (in_array($this->inventoryDeductionStage(), ['preparing', 'ready'], true)) {
                        $this->inventory->ensureDeducted($oi);
                    }
                    $autoReadied = true;
                }
            }
            if ($autoReadied) {
                // One recompute, muted: a pure instant order flips to Ready;
                // a mixed order stays Approved (food lines untouched). The
                // order-level broadcast below carries the final state.
                $this->syncOrderStatus($order, broadcast: false);
            }

            ActivityLog::log('order.approved', "اعتماد طلب {$order->number}", $order);

            $order = $order->refresh()->load('items.station', 'table', 'tableSession');
            SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));

            foreach ($items as $oi) {
                SafeBroadcast::dispatch(new OrderItemStatusChanged($oi->refresh()->load('order.table', 'order.tableSession', 'station'), OrderItemStatus::Pending->value));
            }

            return $order;
        });
    }

    /**
     * Reverse an approval back to Pending — the waiter's "fك الاعتماد".
     *
     * Only allowed while the kitchen hasn't physically started: every active
     * item must still be Approved (none Preparing / Ready / Served). Any stock
     * deducted at approval is returned so the books stay honest, and the items
     * roll back to Pending so the order can be edited or re-approved cleanly.
     */
    public function unapprove(Order $order, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Approved->value) {
                throw new \RuntimeException('لا يمكن فك اعتماد طلب حالته: '.$order->statusLabel());
            }

            $activeItems = $order->items->where('status', '!=', OrderItemStatus::Cancelled->value);

            // Instant lines (بطاقات النت: no station, never had a prep clock)
            // are auto-readied AT approval — no cook ever touched them, so
            // they must not count as "kitchen started" and they roll back
            // with everything else.
            $isInstantLine = fn ($oi) => $oi->status === OrderItemStatus::Ready->value
                && $oi->station_id === null
                && $oi->prep_started_at === null;

            // If the kitchen already started any item, it's too late to unapprove.
            $started = $activeItems->first(fn ($oi) => ! in_array($oi->status, [
                OrderItemStatus::Pending->value,
                OrderItemStatus::Approved->value,
            ], true) && ! $isInstantLine($oi));
            if ($started) {
                throw new \RuntimeException('بدأ المطبخ تحضير الطلب — لا يمكن فك الاعتماد. ألغِ الطلب بدلاً من ذلك.');
            }

            $previous = $order->status;

            foreach ($activeItems as $oi) {
                // Return any stock deducted at approval before reverting state.
                $this->inventory->returnForOrderItem($oi);
                $oi->update([
                    'status' => OrderItemStatus::Pending->value,
                    'approved_at' => null,
                    // Instant lines got ready_at stamped at approval — clear it.
                    'ready_at' => null,
                ]);
            }

            $order->update([
                'status' => OrderStatus::Pending->value,
                'approved_by_user_id' => null,
                'approved_at' => null,
            ]);

            ActivityLog::log('order.unapproved', "فك اعتماد طلب {$order->number}", $order);

            $order = $order->refresh()->load('items.station', 'table', 'tableSession');
            SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));

            return $order;
        });
    }

    /**
     * Manually roll the order forward to the next workflow state.
     * Called from the orders Kanban when a manager confirms physical progress.
     *
     * Pending → Approved is handled by approve(). This method handles:
     *   Approved/Preparing → Ready
     *   Ready               → Delivered
     *   Delivered           → Completed
     */
    public function transitionTo(Order $order, string $target, ?int $userId = null): Order
    {
        $allowed = [
            OrderStatus::Ready->value,
            OrderStatus::Delivered->value,
            OrderStatus::Completed->value,
        ];

        if (! in_array($target, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid target status: {$target}");
        }

        return DB::transaction(function () use ($order, $target) {
            $order = Order::with(['items', 'tableSession.invoice', 'invoice'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $order->status;
            if ($previous === OrderStatus::Cancelled->value) {
                throw new \RuntimeException('لا يمكن تغيير حالة طلب ملغى.');
            }

            $this->assertOrderCanTransitionTo($order, $target);

            $order->update([
                'status' => $target,
                match ($target) {
                    OrderStatus::Ready->value => 'ready_at',
                    OrderStatus::Delivered->value => 'delivered_at',
                    OrderStatus::Completed->value => 'completed_at',
                } => now(),
            ]);

            // Prepaid takeaway/delivery whose money already landed in full:
            // handing it over was the LAST milestone, close the ticket now
            // (payment no longer completes undelivered orders — see
            // BillingService::addPayment).
            if ($target === OrderStatus::Delivered->value) {
                if (! $this->completeStaffMealIfDelivered($order)) {
                    $this->completeIfPrepaid($order);
                }
            }

            ActivityLog::log("order.{$target}", "تحديث حالة الطلب {$order->number} إلى {$target}", $order);
            $order = $order->refresh()->load('items.station', 'table', 'tableSession');
            SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));

            return $order;
        });
    }

    public function cancel(Order $order, ?int $userId, string $reason): Order
    {
        $order = DB::transaction(function () use ($order, $userId, $reason) {
            $this->assertInvoiceCanStillChange($order);
            $this->assertStaffMealAllocationOpen($order);

            $previous = $order->status;
            $userId = $userId ?: null;

            foreach ($order->items as $oi) {
                $this->cancelItem($oi, $userId, $reason, skipRecalculate: true);
            }

            $order->update([
                'status' => OrderStatus::Cancelled->value,
                'cancelled_by_user_id' => $userId,
                'cancelled_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            $this->recalculateTotals($order);

            ActivityLog::log('order.cancelled', "إلغاء طلب {$order->number}: {$reason}", $order);
            $order = $order->refresh()->load('items.station', 'table', 'tableSession');
            SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));

            return $order;
        });

        app(NotifyService::class)->orderCancelled($order, $reason);

        return $order;
    }

    /**
     * Cancel one order item.
     *
     * `$disposition` decides what happens to the ingredients that were
     * already deducted from inventory:
     *   - 'return'  → put them back on the shelf (default, lossless).
     *                 Use when the kitchen hadn't actually touched the
     *                 item yet — the customer changed their mind in time.
     *   - 'waste'   → keep stock decremented and ALSO log a waste
     *                 movement so the loss shows up in the waste report
     *                 and end-of-day. Use when the chef had already
     *                 prepped (opened a bag of flour, fried the chicken)
     *                 and the food can't be sold to anyone else.
     *
     * Items that were never deducted (still Pending) skip both — there's
     * nothing on/off the shelf to undo.
     */
    public function cancelItem(
        OrderItem $item,
        ?int $userId,
        string $reason,
        bool $skipRecalculate = false,
        string $disposition = 'return',
        ?string $wasteReason = null,
    ): OrderItem {
        if (! in_array($disposition, ['return', 'waste'], true)) {
            throw new \InvalidArgumentException("disposition must be 'return' or 'waste', got: {$disposition}");
        }

        return DB::transaction(function () use ($item, $userId, $reason, $skipRecalculate, $disposition, $wasteReason) {
            $item = $this->lockOrderItemForWorkflow($item);
            $this->assertInvoiceCanStillChange($item->order);

            if ($item->status === OrderItemStatus::Cancelled->value) {
                return $item;
            }
            $this->assertStaffMealAllocationOpen($item->order);
            $userId = $userId ?: null;
            $previousItemStatus = $item->status;

            $wasDeducted = $this->itemHasInventoryDeduction($item);

            if ($disposition === 'waste'
                && ! $wasDeducted
                && $item->status !== OrderItemStatus::Pending->value
            ) {
                $this->inventory->ensureDeducted($item);
                $wasDeducted = $this->itemHasInventoryDeduction($item);
            }

            if ($wasDeducted) {
                if ($disposition === 'return') {
                    $this->inventory->returnForOrderItem($item);
                } else {
                    // Waste path: leave stock decremented (the food is
                    // truly gone) but log waste movements so reports
                    // correctly attribute the loss.
                    $this->inventory->convertOrderItemToWaste(
                        $item,
                        $wasteReason ?: $reason,
                        $userId,
                    );
                }
            }

            $item->update([
                'status' => OrderItemStatus::Cancelled->value,
                'cancelled_by_user_id' => $userId,
                'cancelled_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            $this->broadcastItemChange($item, $previousItemStatus);

            if (! $skipRecalculate) {
                $this->recalculateTotals($item->order);
            }

            ActivityLog::log(
                'order_item.cancelled',
                "إلغاء صنف {$item->name_snapshot} من الطلب {$item->order->number}".
                ($wasDeducted ? " ({$disposition})" : ''),
                $item,
                ['reason' => $reason, 'disposition' => $disposition, 'was_deducted' => $wasDeducted],
            );

            // ── Zombie guard: cancelling the LAST live line must take the
            // order down with it, otherwise it floats on the waiter/KDS
            // boards forever with nothing left to cook. Reuse the normal
            // order-cancel path (status, timestamps, broadcast, log). Bulk
            // cancels (cancel()) pass skipRecalculate=true and close the
            // order themselves, so only single-item cancels come through.
            if (! $skipRecalculate
                && $item->order->status !== OrderStatus::Cancelled->value
                && ! $item->order->items()
                    ->where('status', '!=', OrderItemStatus::Cancelled->value)
                    ->exists()
            ) {
                $this->cancel($item->order->refresh(), $userId, $reason);
            }

            return $item->refresh();
        });
    }

    /**
     * `$broadcast = false` mutes the per-item AND order-level socket events
     * (DB writes, status recompute and deduction still happen). Bulk KDS
     * actions («ابدأ الكل» / «الكل جاهز») loop with false then fire ONE
     * broadcastOrderRefresh() — otherwise every screen re-renders N×M times.
     */
    public function startPreparing(OrderItem $item, int $userId, bool $broadcast = true): OrderItem
    {
        return DB::transaction(function () use ($item, $userId, $broadcast) {
            $item = $this->lockOrderItemForWorkflow($item);
            $this->assertItemCanMove($item, OrderItemStatus::Approved->value, 'start_preparing');

            $previous = $item->status;
            $item->update([
                'status' => OrderItemStatus::Preparing->value,
                'prep_started_at' => now(),
                'prepared_by_user_id' => $userId,
            ]);
            if ($this->inventoryDeductionStage() === 'preparing') {
                $this->inventory->ensureDeducted($item);
            }
            $this->syncOrderStatus($item->order, $broadcast);
            if ($broadcast) {
                $this->broadcastItemChange($item, $previous);
            }

            return $item->refresh();
        });
    }

    /** @see startPreparing() for the $broadcast batching contract. */
    public function markItemReady(OrderItem $item, bool $broadcast = true): OrderItem
    {
        $item = DB::transaction(function () use ($item, $broadcast) {
            $item = $this->lockOrderItemForWorkflow($item);
            $this->assertItemCanMove($item, OrderItemStatus::Preparing->value, 'mark_ready');

            $previous = $item->status;
            $item->update([
                'status' => OrderItemStatus::Ready->value,
                'ready_at' => now(),
            ]);
            if ($this->inventoryDeductionStage() === 'ready') {
                $this->inventory->ensureDeducted($item);
            }
            $this->syncOrderStatus($item->order, $broadcast);
            if ($broadcast) {
                $this->broadcastItemChange($item, $previous);
            }

            return $item->refresh();
        });

        if ($broadcast) {
            app(NotifyService::class)->orderReady(
                $item->order->refresh()->load('items.station', 'table')
            );
        }

        return $item;
    }

    // ─── KDS undo (mis-tap reverts) ───────────────────────────────────────

    /**
     * Undo a mis-tapped «جاهز» — ready → preparing.
     *
     * Inventory is deliberately NOT returned: the food is physically cooked
     * whether or not the tap was right, and ensureDeducted is idempotent so
     * the re-ready that follows won't double-deduct.
     */
    public function revertItemReady(OrderItem $item, int $userId): OrderItem
    {
        return DB::transaction(function () use ($item, $userId) {
            $item = $this->lockOrderItemForWorkflow($item);
            $this->assertItemCanRevert($item, OrderItemStatus::Ready->value);

            $previous = $item->status;
            // Keep prep_started_at / prepared_by_user_id — the item really
            // is still cooking, only the "ready" stamp was premature.
            $item->update([
                'status' => OrderItemStatus::Preparing->value,
                'ready_at' => null,
            ]);

            ActivityLog::log(
                'order_item.revert_ready',
                "تراجع عن جاهزية «{$item->name_snapshot}» في الطلب {$item->order->number}",
                $item,
                ['by_user_id' => $userId],
            );

            // An order that was Ready drops back to Preparing here.
            $this->syncOrderStatus($item->order);
            $this->broadcastItemChange($item, $previous);

            return $item->refresh();
        });
    }

    /**
     * Undo a mis-tapped «ابدأ» — preparing → approved.
     *
     * Stock deducted at the 'preparing' stage stays deducted on purpose:
     * ensureDeducted is idempotent, so the inevitable re-start right after
     * a mis-tap won't deduct twice, while returning-then-re-deducting here
     * would only churn movement rows.
     */
    public function revertItemStart(OrderItem $item, int $userId): OrderItem
    {
        return DB::transaction(function () use ($item, $userId) {
            $item = $this->lockOrderItemForWorkflow($item);
            $this->assertItemCanRevert($item, OrderItemStatus::Preparing->value);

            $previous = $item->status;
            // Clear the prep stamps — elapsed-time counters anchor on
            // prep_started_at and a mis-tap must not keep the clock running.
            $item->update([
                'status' => OrderItemStatus::Approved->value,
                'prep_started_at' => null,
                'prepared_by_user_id' => null,
            ]);

            ActivityLog::log(
                'order_item.revert_start',
                "تراجع عن بدء تحضير «{$item->name_snapshot}» في الطلب {$item->order->number}",
                $item,
                ['by_user_id' => $userId],
            );

            $this->syncOrderStatus($item->order);
            $this->broadcastItemChange($item, $previous);

            return $item->refresh();
        });
    }

    /**
     * Shared guard for the KDS undo actions. Reverting is only safe while
     * the ticket is still live on the boards: once the order is Delivered /
     * Completed / Cancelled — or an invoice exists — rolling an item back
     * would desync billing and history.
     */
    protected function assertItemCanRevert(OrderItem $item, string $expectedStatus): void
    {
        $orderStatus = $item->order?->status;

        if (in_array($orderStatus, [
            OrderStatus::Delivered->value,
            OrderStatus::Completed->value,
            OrderStatus::Cancelled->value,
        ], true)) {
            throw new \RuntimeException('لا يمكن التراجع — حالة الطلب: '.($item->order?->statusLabel() ?? $orderStatus).'.');
        }

        if ($item->status !== $expectedStatus) {
            throw new \RuntimeException('لا يمكن التراجع — حالة الصنف الحالية: '.$item->statusLabel().'.');
        }

        // Server-side ceiling on the undo window. The board renders the
        // «تراجع» button for 60/120s, but that's client cosmetics — without
        // this cap a forged/stale call could rewind a line long after the
        // food left the pass. 10 minutes is deliberately looser than the UI
        // so a legitimate tap that raced the poll still lands.
        $anchor = $expectedStatus === OrderItemStatus::Ready->value
            ? $item->ready_at
            : $item->prep_started_at;
        if ($anchor && $anchor->diffInMinutes(now()) > 10) {
            throw new \RuntimeException('انتهت مهلة التراجع لهذا الصنف.');
        }

        $this->assertInvoiceCanStillChange($item->order);
    }

    /** @see startPreparing() for the $broadcast batching contract. */
    public function markItemServed(OrderItem $item, int $userId, bool $broadcast = true): OrderItem
    {
        return DB::transaction(function () use ($item, $userId, $broadcast) {
            $item = $this->lockOrderItemForWorkflow($item);
            $this->assertItemCanMove($item, OrderItemStatus::Ready->value, 'mark_served');

            $previous = $item->status;
            $item->update([
                'status' => OrderItemStatus::Served->value,
                'served_at' => now(),
                'served_by_user_id' => $userId,
            ]);
            if ($this->inventoryDeductionStage() === 'served') {
                $this->inventory->ensureDeducted($item);
            }
            $this->syncOrderStatus($item->order, $broadcast);
            if ($broadcast) {
                $this->broadcastItemChange($item, $previous);
            }

            return $item->refresh();
        });
    }

    protected function broadcastItemChange(OrderItem $item, string $previous): void
    {
        SafeBroadcast::dispatch(new OrderItemStatusChanged(
            $item->refresh()->load('order.table', 'order.tableSession', 'station'),
            $previous
        ));
    }

    /**
     * One order-level "refresh" broadcast. Bulk KDS actions run the item
     * transitions with $broadcast=false, then call this ONCE so every
     * listening screen re-renders a single time instead of N×M.
     */
    public function broadcastOrderRefresh(Order $order): void
    {
        $order = $order->refresh()->load('items.station', 'table', 'tableSession');
        SafeBroadcast::dispatch(new OrderStatusChanged($order, $order->status));
    }

    protected function syncOrderStatus(Order $order, bool $broadcast = true): void
    {
        $order->refresh();
        $previous = $order->status;
        $active = $order->items()->whereNotIn('status', [OrderItemStatus::Cancelled->value])->get();
        if ($active->isEmpty()) {
            return;
        }

        $newStatus = null;
        if ($active->every(fn ($i) => $i->status === OrderItemStatus::Served->value)) {
            $order->update(['status' => OrderStatus::Delivered->value, 'delivered_at' => now()]);
            $newStatus = OrderStatus::Delivered->value;
            if ($this->completeStaffMealIfDelivered($order)) {
                $newStatus = OrderStatus::Completed->value;
            }
            // Prepaid takeaway/delivery: the money was collected in full
            // BEFORE the kitchen finished (addPayment leaves the ticket on
            // its kitchen lifecycle instead of completing it). Serving the
            // last line was the final milestone — close the ticket so the
            // diner sees «مكتمل» and the boards drop it.
            if ($newStatus !== OrderStatus::Completed->value && $this->completeIfPrepaid($order)) {
                $newStatus = OrderStatus::Completed->value;
            }
        } elseif ($active->every(fn ($i) => in_array($i->status, [OrderItemStatus::Ready->value, OrderItemStatus::Served->value]))) {
            $order->update(['status' => OrderStatus::Ready->value, 'ready_at' => $order->ready_at ?? now()]);
            $newStatus = OrderStatus::Ready->value;
        } elseif ($active->contains(fn ($i) => $i->status === OrderItemStatus::Preparing->value)) {
            $order->update([
                'status' => OrderStatus::Preparing->value,
                // Dropping back from Ready (only reachable via the KDS
                // «تراجع» on the last ready line) — the premature order-level
                // ready stamp must go with it, or the next flip to Ready
                // keeps the OLD timestamp and the pass age lies.
                'ready_at' => $previous === OrderStatus::Ready->value ? null : $order->ready_at,
            ]);
            $newStatus = OrderStatus::Preparing->value;

            // Stamp prep_started_at + estimated_ready_at the first time
            // we enter Preparing. OrderTimingService is idempotent — a
            // late second item triggering this code path won't reset the
            // baseline. Customer countdown + kitchen elapsed counters
            // both anchor on prep_started_at.
            app(OrderTimingService::class)->stampPrepStart($order);
        } elseif ($previous === OrderStatus::Preparing->value
            && $active->every(fn ($i) => in_array($i->status, [
                OrderItemStatus::Pending->value,
                OrderItemStatus::Approved->value,
            ], true))
        ) {
            // Only reachable via revertItemStart: the single started line
            // went back to Approved, so the order isn't "preparing" anymore.
            // Clear the order-level prep stamps too — the elapsed clock and
            // customer ETA anchored on them and the mis-tap must not keep
            // them running (they re-stamp cleanly on the real start).
            $order->update([
                'status' => OrderStatus::Approved->value,
                'prep_started_at' => null,
                'estimated_ready_at' => null,
            ]);
            $newStatus = OrderStatus::Approved->value;
        }

        if ($newStatus && $newStatus !== $previous && $broadcast) {
            $order = $order->load('items.station', 'table', 'tableSession');
            SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));
        }
    }

    /**
     * Close a fully-delivered order whose invoice is already settled.
     * Payment deliberately no longer completes an undelivered
     * order (BillingService::addPayment gates on Delivered — completing a
     * paid-but-uncooked ticket made it vanish from the KDS/waiter boards),
     * so the serve-side transitions call this to finish the job once the
     * food is actually handed over.
     *
     * For dine-in, serving the final line closes the paid session and frees
     * the table. For takeaway/delivery, only this order is completed.
     *
     * Returns TRUE when the order was promoted to Completed.
     */
    protected function completeIfPrepaid(Order $order): bool
    {
        if ($order->table_session_id) {
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->where('table_session_id', $order->table_session_id)
                ->where(function ($query) {
                    $query->whereIn('status', ['paid', 'unpaid_writeoff'])
                        ->orWhereNotNull('settled_on_account_at');
                })
                ->latest()
                ->first();

            if (! $invoice) {
                return false;
            }

            return app(BillingService::class)->closeSettledSessionIfFulfilled($invoice);
        }

        // Unscoped on purpose: the invoice carries the ORDER's branch and
        // the serving tap may come from an operator pinned to another
        // branch (order_id is an exact key, nothing can leak across orders).
        // 'unpaid_writeoff' counts as settled — a manager already decided
        // nothing more will be collected, same as assertOrderCanTransitionTo.
        $settled = Invoice::withoutGlobalScope(BranchScope::class)
            ->where('order_id', $order->id)
            ->whereIn('status', ['paid', 'unpaid_writeoff'])
            ->exists();
        if (! $settled) {
            return false;
        }

        $order->update([
            'status' => OrderStatus::Completed->value,
            'completed_at' => now(),
        ]);

        ActivityLog::log(
            'order.completed',
            "اكتمال الطلب {$order->number} تلقائياً — مدفوع مسبقاً وسُلِّم بالكامل",
            $order,
        );

        return true;
    }

    /**
     * A staff meal has no customer invoice. Once every line is handed over,
     * freeze its allowance/debt allocation and close the operational ticket.
     * This keeps it visible to the kitchen until it is actually prepared and
     * prevents the old bug where charging at submit made the order disappear.
     */
    protected function completeStaffMealIfDelivered(Order $order): bool
    {
        if (! $order->isStaffMeal()) {
            return false;
        }

        app(StaffMealService::class)->chargeOrder(
            $order->refresh(),
            settledByUserId: $order->created_by_user_id,
            approverUserId: $order->approved_by_user_id ?: $order->created_by_user_id,
        );

        $order->update([
            'status' => OrderStatus::Completed->value,
            'completed_at' => now(),
        ]);

        ActivityLog::log(
            'staff_meal.completed',
            "اكتملت وجبة الموظف في الطلب {$order->number} بعد تسليم كل الأصناف",
            $order,
        );

        return true;
    }

    protected function assertInvoiceCanStillChange(Order $order): void
    {
        $order->loadMissing('tableSession.invoice', 'invoice');
        $invoice = $order->tableSession?->invoice ?? $order->invoice;

        if ($invoice && ! in_array($invoice->status, ['cancelled'], true)) {
            throw new \RuntimeException('لا يمكن تعديل الطلب بعد إصدار الفاتورة. ألغِ الفاتورة أولاً من الكاشير ثم عدّل الطلب.');
        }
    }

    /**
     * Once a staff meal is physically served, its allowance/debt allocation
     * and inventory expense are frozen together. Subsequent corrections use
     * settlement/waiver; reopening the food order would desynchronise stock.
     */
    protected function assertStaffMealAllocationOpen(Order $order): void
    {
        if (! $order->isStaffMeal()) {
            return;
        }

        if (StaffMealCharge::where('order_id', $order->id)->exists()) {
            throw new \RuntimeException('تم تسليم وجبة الموظف وإقفال أثرها المخزني. استخدم التسوية أو الإعفاء لتصحيح المستحق، ولا تعدّل الطلب المسلّم.');
        }
    }
}
