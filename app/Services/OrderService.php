<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\OrderItemStatusChanged;
use App\Events\OrderStatusChanged;
use App\Enums\OrderSource;
use App\Helpers\Money;
use App\Helpers\SafeBroadcast;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Setting;
use App\Models\TableSession;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(protected InventoryService $inventory) {}

    protected function configuredTaxRate(): float
    {
        $enabled = (bool) Setting::get('tax_enabled', config('restaurant.tax.enabled', true));

        return $enabled ? (float) Setting::get('tax_rate', config('restaurant.tax.rate', 16)) : 0.0;
    }

    protected function configuredServiceRate(): float
    {
        $enabled = (bool) Setting::get('service_enabled', config('restaurant.service_charge.enabled', false));

        return $enabled ? (float) Setting::get('service_rate', config('restaurant.service_charge.rate', 10)) : 0.0;
    }

    /**
     * Create an order from customer cart.
     * Cart shape: [ ['menu_item_id' => int, 'quantity' => float, 'modifier_ids' => [int,...], 'notes' => string], ... ]
     */
    public function createFromCart(TableSession $session, array $cart, ?int $createdByUserId = null, ?string $customerNotes = null): Order
    {
        $order = DB::transaction(function () use ($session, $cart, $createdByUserId, $customerNotes) {
            $session = TableSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->invoice()->where('status', '!=', 'cancelled')->exists()) {
                throw new \RuntimeException('الفاتورة صدرت بالفعل. أي طلب إضافي يحتاج إلغاء الفاتورة الحالية من الكاشير أولاً.');
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
            \App\Helpers\SafeBroadcast::dispatch(new OrderCreated($order));
            return $order;
        });

        // Notify admins/cashiers/waiters AFTER commit — never inside the
        // transaction, otherwise a rollback leaves orphan notifications.
        app(NotifyService::class)->newOrder($order);

        return $order;
    }

    /**
     * Create an order placed from the customer portal — no table, no session.
     *
     * Type is one of:
     *   - 'takeaway'  → customer picks up at the branch (no delivery_address)
     *   - 'delivery'  → restaurant delivers (delivery_address required)
     *
     * Inventory is pre-checked the same way as dine-in via the calling
     * controller; this service only persists the order. The new row is
     * stamped with `order_source = portal` so reports + KDS badges can
     * distinguish it from dine-in tickets at a glance.
     *
     * @param array<int,array<string,mixed>> $cart  same shape as createFromCart
     * @param array{customer_notes?:?string, delivery_address?:?string, customer_address_id?:?int, scheduled_for?:?string} $opts
     */
    public function createRemoteOrder(
        Customer $customer,
        Branch   $branch,
        string   $type,
        array    $cart,
        array    $opts = [],
    ): Order {
        if (! in_array($type, ['takeaway', 'delivery'], true)) {
            throw new \InvalidArgumentException("Order type must be 'takeaway' or 'delivery'.");
        }
        if ($type === 'delivery' && empty($opts['delivery_address'])) {
            throw new \InvalidArgumentException("Delivery orders require an address.");
        }

        // Pin the branch so BelongsToBranch stamps the order + items correctly
        // — this matters because the customer's request flow doesn't go
        // through SetActiveBranch (no admin auth).
        $order = BranchContext::forBranch($branch->id, function () use ($customer, $branch, $type, $cart, $opts) {
            return DB::transaction(function () use ($customer, $branch, $type, $cart, $opts) {
                // Snapshot the delivery fee at creation time — see migration
                // header for why we don't read it back from settings later.
                $deliveryFee = $type === 'delivery' ? $branch->deliveryFee() : 0.0;

                $order = Order::create([
                    'table_id'           => null,
                    'table_session_id'   => null,
                    'customer_id'        => $customer->id,
                    'customer_name'      => $customer->name,
                    'customer_phone'     => $customer->phone,
                    'customer_address_id'=> $opts['customer_address_id'] ?? null,
                    'order_type'         => $type,
                    'order_source'       => OrderSource::Portal->value,
                    'status'             => OrderStatus::Pending->value,
                    'created_by_user_id' => null,           // placed by the customer themselves
                    'customer_notes'     => $opts['customer_notes']  ?? null,
                    'delivery_address'   => $opts['delivery_address'] ?? null,
                    'scheduled_for'      => $opts['scheduled_for']   ?? null,
                    'submitted_at'       => now(),
                    'delivery_fee'       => $deliveryFee,
                    'tax_rate'     => $this->configuredTaxRate(),
                    'service_rate' => 0,                    // no service charge on remote orders
                ]);

                foreach ($cart as $row) {
                    $this->addItem($order, $row);
                }

                $this->recalculateTotals($order);
                $this->refreshEta($order);

                ActivityLog::log('order.created',
                    "طلب {$type} عبر التطبيق #{$order->number} للزبون {$customer->name}",
                    $order,
                    [
                        'items_count' => $order->items()->count(),
                        'subtotal'    => (float) $order->subtotal,
                        'delivery_fee'=> $deliveryFee,
                        'type'        => $type,
                    ]
                );

                $order = $order->refresh()->load('items', 'customer');
                \App\Helpers\SafeBroadcast::dispatch(new OrderCreated($order));
                return $order;
            });
        });

        app(NotifyService::class)->newOrder($order);

        return $order;
    }

    /**
     * Create a staff-entered order that is not attached to a table: phone,
     * takeaway counter, delivery, or third-party platform. The kitchen/bar
     * receive it like any other order, while billing can issue an invoice
     * directly against the order instead of a table session.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param array{
     *   customer_name?:?string, customer_phone?:?string, customer_address_id?:?int, customer_notes?:?string,
     *   delivery_address?:?string, delivery_fee?:float|string|null,
     *   external_reference?:?string, platform_commission_pct?:float|string|null,
     *   scheduled_for?:?string
     * } $opts
     */
    public function createCashierOrder(
        ?Customer $customer,
        Branch    $branch,
        string    $type,
        string    $source,
        array     $cart,
        array     $opts = [],
        ?int      $createdByUserId = null,
    ): Order {
        if (! in_array($type, ['takeaway', 'delivery'], true)) {
            throw new \InvalidArgumentException("Order type must be 'takeaway' or 'delivery'.");
        }

        $orderSource = OrderSource::tryFrom($source) ?? OrderSource::Other;

        if ($type === 'delivery' && empty($opts['delivery_address'])) {
            throw new \InvalidArgumentException('طلبات الدليفري تحتاج عنوان واضح.');
        }

        $order = BranchContext::forBranch($branch->id, function () use ($customer, $branch, $type, $orderSource, $cart, $opts, $createdByUserId) {
            return DB::transaction(function () use ($customer, $branch, $type, $orderSource, $cart, $opts, $createdByUserId) {
                $deliveryFee = $type === 'delivery'
                    ? Money::round((float) ($opts['delivery_fee'] ?? $branch->deliveryFee()))
                    : 0.0;

                $commission = $opts['platform_commission_pct'] ?? null;
                if ($commission === null || $commission === '') {
                    $commission = $orderSource->defaultCommission();
                }

                $order = Order::create([
                    'table_id'                => null,
                    'table_session_id'        => null,
                    'customer_id'             => $customer?->id,
                    'customer_name'           => $customer?->name ?? ($opts['customer_name'] ?? null),
                    'customer_phone'          => $customer?->phone ?? ($opts['customer_phone'] ?? null),
                    'customer_address_id'     => $opts['customer_address_id'] ?? null,
                    'order_type'              => $type,
                    'order_source'            => $orderSource->value,
                    'external_reference'      => $opts['external_reference'] ?? null,
                    'platform_commission_pct' => (float) $commission,
                    'status'                  => OrderStatus::Pending->value,
                    'created_by_user_id'      => $createdByUserId,
                    'customer_notes'          => $opts['customer_notes'] ?? null,
                    'delivery_address'        => $opts['delivery_address'] ?? null,
                    'scheduled_for'           => $opts['scheduled_for'] ?? null,
                    'submitted_at'            => now(),
                    'delivery_fee'            => $deliveryFee,
                    'tax_rate'                => $this->configuredTaxRate(),
                    'service_rate'            => 0,
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

    public function addItem(Order $order, array $row): OrderItem
    {
        $item = MenuItem::with(['category', 'station', 'recipeItems.ingredient'])->findOrFail($row['menu_item_id']);

        if (! $item->is_available) {
            throw new \RuntimeException("الصنف {$item->name} غير متوفر حالياً");
        }

        $quantity = max(1, (float) ($row['quantity'] ?? 1));
        $modifierIds = $row['modifier_ids'] ?? [];
        $modifiers = Modifier::with('group')->whereIn('id', $modifierIds)->get();

        $modifiersTotal = (float) $modifiers->sum('price_delta');
        $unitPrice = (float) $item->price;
        $subtotal = ($unitPrice + $modifiersTotal) * $quantity;

        $oi = OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $item->id,
            'station_id' => $item->resolvedStationId(),
            'name_snapshot' => $item->name,
            'name_en_snapshot' => $item->name_en,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'modifiers_total' => $modifiersTotal,
            'subtotal' => $subtotal,
            'notes' => $row['notes'] ?? null,
            'course' => $row['course'] ?? 'main',
            'fire_order' => $row['fire_order'] ?? 0,
            'status' => OrderItemStatus::Pending->value,
        ]);

        foreach ($modifiers as $m) {
            OrderItemModifier::create([
                'order_item_id' => $oi->id,
                'modifier_id' => $m->id,
                'name_snapshot' => $m->name,
                'price_delta' => $m->price_delta,
            ]);
        }

        return $oi;
    }

    public function recalculateTotals(Order $order): Order
    {
        $subtotal = (float) $order->items()->where('status', '!=', OrderItemStatus::Cancelled->value)->sum('subtotal');
        $discountTotal = (float) $order->discounts()->sum('amount');

        $subAfterDiscount = max(0, $subtotal - $discountTotal);

        $tax = Money::applyTax($subAfterDiscount, (float) $order->tax_rate);
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
        $prep   = max(1, $maxItemPrep + $buffer);

        $start = $order->scheduled_for ?? $order->submitted_at ?? now();
        $readyAt = $start->copy()->addMinutes($prep);

        $deliveredAt = null;
        if ($order->order_type === 'delivery' && $branch) {
            $deliveredAt = $readyAt->copy()->addMinutes($branch->deliveryMinutes());
        }

        return [
            'prepMinutes' => $prep,
            'readyAt'     => $readyAt,
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
            'estimated_ready_at'     => $eta['readyAt'],
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

            // ── Safety: verify every tracked ingredient has enough stock BEFORE
            // we start deducting. If not, throw with a clear message listing which
            // ingredients are short. The transaction rollback leaves nothing changed.
            $issues = $this->inventory->validateStockForOrder($order);
            if (!empty($issues) && (bool) Setting::get('strict_stock', config('restaurant.inventory.strict_stock', true))) {
                $this->inventory->throwIfInsufficient($issues);
            }

            $previous = $order->status;
            $items = $order->items()->where('status', OrderItemStatus::Pending->value)->get();

            foreach ($items as $oi) {
                $oi->update([
                    'status' => OrderItemStatus::Approved->value,
                    'approved_at' => now(),
                ]);
                $this->inventory->deductForOrderItem($oi);
            }

            $order->update([
                'status' => OrderStatus::Approved->value,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
            ]);

            if ($order->tableSession) {
                $sessionUpdates = [];
                if (empty($order->tableSession->assigned_waiter_id)) {
                    $sessionUpdates['assigned_waiter_id'] = $userId;
                }
                if ($sessionUpdates) {
                    $order->tableSession->update($sessionUpdates);
                }
                $order->tableSession->touch();
            }

            ActivityLog::log('order.approved', "اعتماد طلب {$order->number}", $order);

            $order = $order->refresh()->load('items.station', 'table', 'tableSession');
            \App\Helpers\SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));

            foreach ($items as $oi) {
                \App\Helpers\SafeBroadcast::dispatch(new OrderItemStatusChanged($oi->refresh()->load('order.table', 'order.tableSession', 'station'), OrderItemStatus::Pending->value));
            }

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

        if (!in_array($target, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid target status: {$target}");
        }

        return DB::transaction(function () use ($order, $target, $userId) {
            $previous = $order->status;
            if ($previous === OrderStatus::Cancelled->value) {
                throw new \RuntimeException('لا يمكن تغيير حالة طلب ملغى.');
            }

            $order->update([
                'status' => $target,
                match ($target) {
                    OrderStatus::Ready->value     => 'ready_at',
                    OrderStatus::Delivered->value => 'delivered_at',
                    OrderStatus::Completed->value => 'completed_at',
                } => now(),
            ]);

            ActivityLog::log("order.{$target}", "تحديث حالة الطلب {$order->number} إلى {$target}", $order);
            $order = $order->refresh()->load('items.station', 'table', 'tableSession');
            \App\Helpers\SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));

            return $order;
        });
    }

    public function cancel(Order $order, ?int $userId, string $reason): Order
    {
        $order = DB::transaction(function () use ($order, $userId, $reason) {
            $this->assertInvoiceCanStillChange($order);

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
            \App\Helpers\SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));
            return $order;
        });

        app(NotifyService::class)->orderCancelled($order, $reason);

        return $order;
    }

    public function cancelItem(OrderItem $item, ?int $userId, string $reason, bool $skipRecalculate = false): OrderItem
    {
        return DB::transaction(function () use ($item, $userId, $reason, $skipRecalculate) {
            $this->assertInvoiceCanStillChange($item->order);

            if ($item->status === OrderItemStatus::Cancelled->value) return $item;
            $userId = $userId ?: null;
            $previousItemStatus = $item->status;

            // Return stock if previously deducted
            if (in_array($item->status, [OrderItemStatus::Approved->value, OrderItemStatus::Preparing->value, OrderItemStatus::Ready->value, OrderItemStatus::Served->value])) {
                $this->inventory->returnForOrderItem($item);
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

            ActivityLog::log('order_item.cancelled', "إلغاء صنف {$item->name_snapshot} من الطلب {$item->order->number}", $item, ['reason' => $reason]);
            return $item->refresh();
        });
    }

    public function startPreparing(OrderItem $item, int $userId): OrderItem
    {
        $previous = $item->status;
        $item->update([
            'status' => OrderItemStatus::Preparing->value,
            'prep_started_at' => now(),
            'prepared_by_user_id' => $userId,
        ]);
        $this->syncOrderStatus($item->order);
        $this->broadcastItemChange($item, $previous);
        return $item->refresh();
    }

    public function markItemReady(OrderItem $item): OrderItem
    {
        $previous = $item->status;
        $item->update([
            'status' => OrderItemStatus::Ready->value,
            'ready_at' => now(),
        ]);
        $this->syncOrderStatus($item->order);
        $this->broadcastItemChange($item, $previous);
        return $item->refresh();
    }

    public function markItemServed(OrderItem $item, int $userId): OrderItem
    {
        $previous = $item->status;
        $item->update([
            'status' => OrderItemStatus::Served->value,
            'served_at' => now(),
            'served_by_user_id' => $userId,
        ]);
        $this->syncOrderStatus($item->order);
        $this->broadcastItemChange($item, $previous);
        return $item->refresh();
    }

    protected function broadcastItemChange(OrderItem $item, string $previous): void
    {
        \App\Helpers\SafeBroadcast::dispatch(new OrderItemStatusChanged(
            $item->refresh()->load('order.table', 'order.tableSession', 'station'),
            $previous
        ));
    }

    protected function syncOrderStatus(Order $order): void
    {
        $order->refresh();
        $previous = $order->status;
        $active = $order->items()->whereNotIn('status', [OrderItemStatus::Cancelled->value])->get();
        if ($active->isEmpty()) return;

        $newStatus = null;
        if ($active->every(fn($i) => $i->status === OrderItemStatus::Served->value)) {
            $order->update(['status' => OrderStatus::Delivered->value, 'delivered_at' => now()]);
            $newStatus = OrderStatus::Delivered->value;
        } elseif ($active->every(fn($i) => in_array($i->status, [OrderItemStatus::Ready->value, OrderItemStatus::Served->value]))) {
            $order->update(['status' => OrderStatus::Ready->value, 'ready_at' => $order->ready_at ?? now()]);
            $newStatus = OrderStatus::Ready->value;
        } elseif ($active->contains(fn($i) => $i->status === OrderItemStatus::Preparing->value)) {
            $order->update(['status' => OrderStatus::Preparing->value]);
            $newStatus = OrderStatus::Preparing->value;

            // Stamp prep_started_at + estimated_ready_at the first time
            // we enter Preparing. OrderTimingService is idempotent — a
            // late second item triggering this code path won't reset the
            // baseline. Customer countdown + kitchen elapsed counters
            // both anchor on prep_started_at.
            app(\App\Services\OrderTimingService::class)->stampPrepStart($order);
        }

        if ($newStatus && $newStatus !== $previous) {
            $order = $order->load('items.station', 'table', 'tableSession');
            \App\Helpers\SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));
        }
    }

    protected function assertInvoiceCanStillChange(Order $order): void
    {
        $order->loadMissing('tableSession.invoice', 'invoice');
        $invoice = $order->tableSession?->invoice ?? $order->invoice;

        if ($invoice && ! in_array($invoice->status, ['cancelled'], true)) {
            throw new \RuntimeException('لا يمكن تعديل الطلب بعد إصدار الفاتورة. ألغِ الفاتورة أولاً من الكاشير ثم عدّل الطلب.');
        }
    }
}
