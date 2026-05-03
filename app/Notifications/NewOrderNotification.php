<?php

namespace App\Notifications;

use App\Models\Order;

class NewOrderNotification extends BaseNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
        // Override the auto-snapshot with the order's own branch — covers the
        // queue case where dispatch happens outside any branch context.
        $this->branchId = $order->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'order.new'; }
    public function severity(): string { return 'info'; }
    public function icon(): string    { return 'bi-bag-plus-fill'; }

    public function title(): string
    {
        return "طلب جديد: {$this->order->number}";
    }

    public function body(): string
    {
        $who   = $this->order->customer_name ?: 'بدون اسم';
        $where = $this->order->table?->number
            ? "طاولة {$this->order->table->number}"
            : ($this->order->order_type ?: 'بدون مكان');
        $total = number_format((float) $this->order->total, 2);
        return "{$who} · {$where} · {$total} ₪";
    }

    public function actionUrl(): string
    {
        return route('admin.orders.show', $this->order);
    }

    public function actionLabel(): string
    {
        return 'افتح الطلب';
    }

    public function extra(): array
    {
        return [
            'order_id'     => $this->order->id,
            'order_number' => $this->order->number,
        ];
    }
}
