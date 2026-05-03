<?php

namespace App\Notifications;

use App\Models\Order;

class OrderCancelledNotification extends BaseNotification
{
    public function __construct(public Order $order, public ?string $reason = null)
    {
        parent::__construct();
        $this->branchId = $order->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'order.cancelled'; }
    public function severity(): string { return 'warning'; }
    public function icon(): string    { return 'bi-x-octagon-fill'; }

    public function title(): string
    {
        return "تم إلغاء الطلب: {$this->order->number}";
    }

    public function body(): string
    {
        $reason = $this->reason ?: $this->order->cancelled_reason ?: 'بدون سبب محدد';
        return "السبب: {$reason}";
    }

    public function actionUrl(): string
    {
        return route('admin.orders.show', $this->order);
    }

    public function actionLabel(): string
    {
        return 'مراجعة الطلب';
    }

    public function extra(): array
    {
        return [
            'order_id'     => $this->order->id,
            'order_number' => $this->order->number,
        ];
    }
}
