<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\OrderChangeRequestChanged;
use App\Helpers\SafeBroadcast;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Scopes\BranchScope;
use App\Models\Setting;
use App\Models\TableSession;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

class OrderChangeRequestService
{
    public function __construct(
        protected OrderService $orders,
        protected InventoryService $inventory,
        protected NotifyService $notifications,
    ) {}

    public function request(Order $order, TableSession $session, array $data): OrderChangeRequest
    {
        $changeRequest = BranchContext::forBranch((int) $order->branch_id, function () use ($order, $session, $data) {
            return DB::transaction(function () use ($order, $session, $data) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ((int) $order->table_session_id !== (int) $session->id) {
                    abort(403);
                }
                if (! in_array($order->status, [
                    OrderStatus::Pending->value,
                    OrderStatus::Approved->value,
                    OrderStatus::Preparing->value,
                    OrderStatus::Ready->value,
                ], true)) {
                    throw new \RuntimeException('لا يمكن تغيير طلب تم تسليمه أو إغلاقه. اطلب مساعدة الجرسون.');
                }
                if ($order->tableSession?->invoice()->where('status', '!=', 'cancelled')->exists()) {
                    throw new \RuntimeException('صدرت الفاتورة بالفعل. اطلب من الكاشير إلغاءها أولاً قبل تعديل الطلب.');
                }
                if ($order->changeRequests()->where('status', OrderChangeRequest::STATUS_PENDING)->exists()) {
                    throw new \RuntimeException('لديك طلب تعديل قيد المعالجة بالفعل. انتظر رد الجرسون أولاً.');
                }

                $type = $data['type'];
                $item = null;
                if (in_array($type, ['change_item', 'cancel_item'], true)) {
                    $item = $order->items()->whereKey($data['order_item_id'] ?? 0)->lockForUpdate()->first();
                    if (! $item || in_array($item->status, [OrderItemStatus::Cancelled->value, OrderItemStatus::Served->value], true)) {
                        throw new \RuntimeException('هذا الصنف لم يعد قابلاً للتعديل أو الإلغاء.');
                    }
                }

                $requestedQuantity = isset($data['requested_quantity']) && $data['requested_quantity'] !== ''
                    ? (float) $data['requested_quantity']
                    : null;
                $note = trim((string) ($data['request_note'] ?? '')) ?: null;

                if ($type === 'change_item'
                    && $requestedQuantity === null
                    && $note === null) {
                    throw new \RuntimeException('اكتب التعديل المطلوب أو اختر كمية جديدة.');
                }
                if ($type === 'change_item'
                    && $note === null
                    && $requestedQuantity !== null
                    && abs($requestedQuantity - (float) $item?->quantity) < 0.0001) {
                    throw new \RuntimeException('الكمية الجديدة هي نفسها. غيّر الكمية أو اكتب الملاحظة المطلوبة.');
                }
                if ($type === 'cancel_order'
                    && $order->items()->where('status', OrderItemStatus::Served->value)->exists()) {
                    throw new \RuntimeException('تم تقديم جزء من هذه الجولة. ألغِ صنفاً لم يُقدّم بعد أو اطلب مساعدة الجرسون.');
                }

                $request = OrderChangeRequest::create([
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'order_item_id' => $item?->id,
                    'requested_by_customer_id' => $session->customer_id,
                    'type' => $type,
                    'requested_quantity' => $requestedQuantity,
                    'request_note' => $note,
                    'status' => OrderChangeRequest::STATUS_PENDING,
                ]);

                ActivityLog::log(
                    'order.change_requested',
                    "طلب الزبون {$request->typeLabel()} للطلب {$order->number}",
                    $request,
                    ['order_id' => $order->id, 'order_item_id' => $item?->id, 'note' => $note]
                );

                return $request->load('order.tableSession', 'orderItem');
            });
        });

        SafeBroadcast::dispatch(new OrderChangeRequestChanged($changeRequest));
        $this->notifications->orderChangeRequested($changeRequest);

        return $changeRequest;
    }

    public function resolve(
        OrderChangeRequest $changeRequest,
        int $userId,
        string $decision,
        string $disposition = 'return',
        ?string $resolutionNote = null,
        ?bool $expectedStarted = null,
    ): OrderChangeRequest {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new \InvalidArgumentException('Invalid change-request decision.');
        }
        if (! in_array($disposition, ['return', 'waste'], true)) {
            throw new \InvalidArgumentException('Invalid stock disposition.');
        }

        $raw = OrderChangeRequest::withoutGlobalScope(BranchScope::class)->findOrFail($changeRequest->id);

        $resolved = BranchContext::forBranch((int) $raw->branch_id, function () use ($raw, $userId, $decision, $disposition, $resolutionNote, $expectedStarted) {
            return DB::transaction(function () use ($raw, $userId, $decision, $disposition, $resolutionNote, $expectedStarted) {
                $request = OrderChangeRequest::whereKey($raw->id)->lockForUpdate()->firstOrFail();
                if (! $request->isPending()) {
                    throw new \RuntimeException('تمت معالجة طلب التغيير مسبقاً.');
                }

                $order = Order::with(['items.modifiers', 'tableSession.invoice'])
                    ->whereKey($request->order_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($decision === 'reject') {
                    $request->update([
                        'status' => OrderChangeRequest::STATUS_REJECTED,
                        'handled_by_user_id' => $userId,
                        'resolution_note' => trim((string) $resolutionNote) ?: 'تعذر تنفيذ التغيير المطلوب.',
                        'handled_at' => now(),
                    ]);

                    ActivityLog::log('order.change_rejected', "رفض طلب تغيير {$order->number}", $request);

                    return $request->refresh()->load('order.tableSession', 'orderItem', 'handledBy');
                }

                if ($order->tableSession?->invoice && $order->tableSession->invoice->status !== 'cancelled') {
                    throw new \RuntimeException('لا يمكن تنفيذ التغيير بعد إصدار الفاتورة. ألغِ الفاتورة أولاً من الكاشير.');
                }

                $startedNow = $this->requestHasStartedItems($request, $order);
                if ($expectedStarted !== null && $startedNow !== $expectedStarted) {
                    throw new \RuntimeException($startedNow
                        ? 'بدأ المطبخ أو البار بالصنف أثناء المراجعة. راجع حالته الآن واختر: مواد قابلة للرجوع أو هدر.'
                        : 'تغيّرت حالة الصنف أثناء المراجعة. حدّث البطاقة ثم نفّذ القرار من جديد.');
                }

                $reason = 'طلب الزبون #'.$request->id.($request->request_note ? ': '.$request->request_note : '');
                $replacementId = null;

                if ($request->type === 'cancel_order') {
                    $this->cancelWholeOrder($order, $userId, $reason, $disposition);
                } elseif ($request->type === 'cancel_item') {
                    $item = $order->items->firstWhere('id', $request->order_item_id);
                    $this->cancelRequestedItem($item, $userId, $reason, $disposition);
                } else {
                    $item = $order->items->firstWhere('id', $request->order_item_id);
                    $replacementId = $this->replaceRequestedItem($order, $item, $request, $userId, $disposition)->id;
                }

                $request->update([
                    'status' => OrderChangeRequest::STATUS_APPROVED,
                    'replacement_order_item_id' => $replacementId,
                    'handled_by_user_id' => $userId,
                    'disposition' => $disposition,
                    'resolution_note' => trim((string) $resolutionNote) ?: 'تم تنفيذ طلب الزبون.',
                    'handled_at' => now(),
                ]);

                ActivityLog::log(
                    'order.change_approved',
                    "تنفيذ طلب تغيير {$order->number}",
                    $request,
                    ['disposition' => $disposition, 'replacement_order_item_id' => $replacementId]
                );

                return $request->refresh()->load('order.tableSession', 'orderItem', 'replacementOrderItem', 'handledBy');
            });
        });

        SafeBroadcast::dispatch(new OrderChangeRequestChanged($resolved));

        return $resolved;
    }

    protected function requestHasStartedItems(OrderChangeRequest $request, Order $order): bool
    {
        $scope = $request->type === 'cancel_order'
            ? $order->items
            : $order->items->where('id', $request->order_item_id);

        return $scope->contains(fn (OrderItem $item) => in_array($item->status, [
            OrderItemStatus::Preparing->value,
            OrderItemStatus::Ready->value,
            OrderItemStatus::Served->value,
        ], true));
    }

    protected function cancelWholeOrder(Order $order, int $userId, string $reason, string $disposition): void
    {
        $active = $order->items->where('status', '!=', OrderItemStatus::Cancelled->value);
        if ($active->contains(fn (OrderItem $item) => $item->status === OrderItemStatus::Served->value)) {
            throw new \RuntimeException('لا يمكن إلغاء الطلب بالكامل بعد تقديم أحد الأصناف. ألغِ الأصناف المتبقية فقط.');
        }

        foreach ($active as $item) {
            $physicalDisposition = in_array($item->status, [OrderItemStatus::Preparing->value, OrderItemStatus::Ready->value], true)
                ? $disposition
                : 'return';
            $this->orders->cancelItem($item, $userId, $reason, true, $physicalDisposition, $reason);
        }

        $this->orders->cancel($order->refresh(), $userId, $reason);
    }

    protected function cancelRequestedItem(?OrderItem $item, int $userId, string $reason, string $disposition): void
    {
        if (! $item || in_array($item->status, [OrderItemStatus::Cancelled->value, OrderItemStatus::Served->value], true)) {
            throw new \RuntimeException('هذا الصنف لم يعد قابلاً للإلغاء.');
        }

        $physicalDisposition = in_array($item->status, [OrderItemStatus::Preparing->value, OrderItemStatus::Ready->value], true)
            ? $disposition
            : 'return';
        $this->orders->cancelItem($item, $userId, $reason, disposition: $physicalDisposition, wasteReason: $reason);
    }

    protected function replaceRequestedItem(
        Order $order,
        ?OrderItem $item,
        OrderChangeRequest $request,
        int $userId,
        string $disposition,
    ): OrderItem {
        if (! $item || in_array($item->status, [OrderItemStatus::Cancelled->value, OrderItemStatus::Served->value], true)) {
            throw new \RuntimeException('هذا الصنف لم يعد قابلاً للتعديل.');
        }

        $oldItemStatus = $item->status;
        $oldOrderStatus = $order->status;
        $modifierSnapshots = $item->modifiers->map(fn ($modifier) => [
            'modifier_id' => $modifier->modifier_id,
            'name_snapshot' => $modifier->name_snapshot,
            'price_delta' => $modifier->price_delta,
            'price_delta_original' => $modifier->price_delta_original,
        ]);
        $physicalDisposition = in_array($oldItemStatus, [OrderItemStatus::Preparing->value, OrderItemStatus::Ready->value], true)
            ? $disposition
            : 'return';

        $this->orders->cancelItem(
            $item,
            $userId,
            'استبدال الصنف بناءً على طلب الزبون #'.$request->id,
            true,
            $physicalDisposition,
            $request->request_note,
        );

        $notes = collect([$item->notes, $request->request_note])->filter()->unique()->implode("\n");
        $quantity = $request->requested_quantity ?: $item->quantity;

        // Clone the original sale snapshots rather than resolving today's
        // menu/promotion again. A guest edit must not change the price or
        // accidentally consume a promotion quota that started mid-session.
        $replacement = OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $item->menu_item_id,
            'station_id' => $item->station_id,
            'name_snapshot' => $item->name_snapshot,
            'quantity' => $quantity,
            'unit_price' => $item->unit_price,
            'unit_price_original' => $item->unit_price_original,
            'promotion_id' => $item->promotion_id,
            'modifiers_total' => $item->modifiers_total,
            'subtotal' => ((float) $item->unit_price + (float) $item->modifiers_total) * (float) $quantity,
            'notes' => $notes ?: null,
            'course' => $item->course,
            'fire_order' => $item->fire_order,
            'status' => OrderItemStatus::Pending->value,
        ]);

        foreach ($modifierSnapshots as $snapshot) {
            OrderItemModifier::create($snapshot + ['order_item_id' => $replacement->id]);
        }

        if ($oldItemStatus !== OrderItemStatus::Pending->value) {
            $issues = $this->inventory->validateStockForOrder($order->refresh());
            if (! empty($issues) && (bool) Setting::get(
                'strict_stock',
                config('restaurant.inventory.strict_stock', true)
            )) {
                $this->inventory->throwIfInsufficient($issues);
            }

            $replacement->update([
                'status' => OrderItemStatus::Approved->value,
                'approved_at' => now(),
            ]);

            if ($this->orders->inventoryDeductionStage() === 'approve') {
                $this->inventory->ensureDeducted($replacement);
            }

            $otherActive = $order->items()
                ->where('id', '!=', $replacement->id)
                ->where('status', '!=', OrderItemStatus::Cancelled->value)
                ->get();
            $nextStatus = $otherActive->contains(fn (OrderItem $active) => in_array($active->status, [
                OrderItemStatus::Preparing->value,
                OrderItemStatus::Ready->value,
                OrderItemStatus::Served->value,
            ], true)) ? OrderStatus::Preparing->value : OrderStatus::Approved->value;
            $order->update([
                'status' => $nextStatus,
                'ready_at' => $oldOrderStatus === OrderStatus::Ready->value ? null : $order->ready_at,
            ]);
        }

        $this->orders->recalculateTotals($order);
        $this->orders->refreshEta($order);
        $this->orders->broadcastOrderRefresh($order);

        return $replacement->refresh();
    }
}
