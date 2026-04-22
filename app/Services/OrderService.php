<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\OrderItemStatusChanged;
use App\Events\OrderStatusChanged;
use App\Helpers\Money;
use App\Models\ActivityLog;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * Create an order from customer cart.
     * Cart shape: [ ['menu_item_id' => int, 'quantity' => float, 'modifier_ids' => [int,...], 'notes' => string], ... ]
     */
    public function createFromCart(TableSession $session, array $cart, ?int $createdByUserId = null, ?string $customerNotes = null): Order
    {
        return DB::transaction(function () use ($session, $cart, $createdByUserId, $customerNotes) {
            $order = Order::create([
                'table_id' => $session->table_id,
                'table_session_id' => $session->id,
                'order_type' => 'dine_in',
                'status' => OrderStatus::Pending->value,
                'created_by_user_id' => $createdByUserId,
                'customer_notes' => $customerNotes,
                'submitted_at' => now(),
                'tax_rate' => (float) config('restaurant.tax.rate', 16),
                'service_rate' => config('restaurant.service_charge.enabled') ? (float) config('restaurant.service_charge.rate', 10) : 0,
            ]);

            foreach ($cart as $row) {
                $this->addItem($order, $row);
            }

            $this->recalculateTotals($order);

            ActivityLog::log('order.created', "إنشاء طلب {$order->number} - طاولة {$session->table->number}", $order, [
                'items_count' => $order->items()->count(),
                'subtotal' => (float) $order->subtotal,
            ]);

            $order = $order->refresh()->load('items', 'table', 'tableSession');
            \App\Helpers\SafeBroadcast::dispatch(new OrderCreated($order));
            return $order;
        });
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

        $total = $subAfterDiscount + $tax['tax'] + $service['service'] + (float) $order->tip;

        $order->update([
            'subtotal' => Money::round($subtotal),
            'discount_total' => Money::round($discountTotal),
            'tax_total' => $tax['tax'],
            'service_total' => $service['service'],
            'total' => Money::round($total),
        ]);

        return $order;
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
            if (!empty($issues) && config('restaurant.inventory.strict_stock', true)) {
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
        return DB::transaction(function () use ($order, $userId, $reason) {
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
    }

    public function cancelItem(OrderItem $item, ?int $userId, string $reason, bool $skipRecalculate = false): OrderItem
    {
        return DB::transaction(function () use ($item, $userId, $reason, $skipRecalculate) {
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
        }

        if ($newStatus && $newStatus !== $previous) {
            $order = $order->load('items.station', 'table', 'tableSession');
            \App\Helpers\SafeBroadcast::dispatch(new OrderStatusChanged($order, $previous));
        }
    }
}
