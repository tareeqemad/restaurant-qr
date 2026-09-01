<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\OrderRoundContext;

class NewOrderNotification extends BaseNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
        // Override the auto-snapshot with the order's own branch — covers the
        // queue case where dispatch happens outside any branch context.
        $this->branchId = $order->branch_id ?? $this->branchId;
    }

    public function typeKey(): string
    {
        return 'order.new';
    }

    public function severity(): string
    {
        return 'info';
    }

    public function icon(): string
    {
        return 'bi-bag-plus-fill';
    }

    public function title(): string
    {
        $round = OrderRoundContext::for($this->order);
        $where = $this->order->table?->number
            ? "طاولة {$this->order->table->number}"
            : $this->order->sourceLabel();

        return $round['number']
            ? "{$where} · جولة {$round['number']}"
            : "طلب جديد · {$where}";
    }

    public function body(): string
    {
        $items = $this->order->items;
        $pieces = $items->sum(fn ($item) => (float) $item->quantity);
        $names = $items->pluck('name_snapshot')->filter()->take(2)->join('، ');
        $more = max(0, $items->count() - 2);

        return $this->formatQty((float) $pieces).' قطع: '.($names ?: 'راجع الأصناف')
            .($more > 0 ? " +{$more}" : '');
    }

    public function actionUrl(): string
    {
        if ($this->order->table_id) {
            return route('admin.waiter-orders.create', [
                'table' => $this->order->table_id,
                'review_order' => $this->order->id,
            ]);
        }

        return route('admin.orders.index', ['order_id' => $this->order->id]);
    }

    public function actionLabel(): string
    {
        return 'راجع الجولة';
    }

    public function extra(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->number,
        ];
    }

    protected function formatQty(float $quantity): string
    {
        return $quantity == floor($quantity)
            ? (string) (int) $quantity
            : rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }
}
