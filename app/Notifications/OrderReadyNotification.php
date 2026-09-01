<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\OrderRoundContext;

class OrderReadyNotification extends BaseNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
        $this->branchId = $order->branch_id ?? $this->branchId;
    }

    public function typeKey(): string
    {
        return 'order.ready';
    }

    public function severity(): string
    {
        return 'success';
    }

    public function icon(): string
    {
        return 'bi-bell-fill';
    }

    public function title(): string
    {
        $round = OrderRoundContext::for($this->order);
        $where = $this->order->table?->number
            ? 'طاولة '.$this->order->table->number
            : $this->order->sourceLabel();

        return 'استلم من التحضير — '.$where
            .($round['number'] ? ' · جولة '.$round['number'] : '');
    }

    public function body(): string
    {
        $ready = $this->order->items->where('status', 'ready');
        $stations = $ready->pluck('station.name')->filter()->unique()->implode('، ');
        $names = $ready->pluck('name_snapshot')->take(3)->implode('، ');

        return trim(($stations ?: 'محطة التحضير').' · '.$names);
    }

    public function actionUrl(): string
    {
        return route('admin.orders.index', [
            'focus' => 'ready',
            'table_id' => $this->order->table_id,
        ]);
    }

    public function actionLabel(): string
    {
        return 'افتح مهمة التسليم';
    }

    public function extra(): array
    {
        return ['order_id' => $this->order->id, 'table_id' => $this->order->table_id];
    }
}
