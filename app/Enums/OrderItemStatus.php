<?php

namespace App\Enums;

enum OrderItemStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('ui.customer_order.status_pending'),
            self::Approved => __('ui.customer_order.status_approved'),
            self::Preparing => __('ui.customer_order.status_preparing_full'),
            self::Ready => __('ui.customer_order.status_ready'),
            self::Served => __('ui.customer_order.status_served'),
            self::Cancelled => __('ui.customer_order.status_cancelled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Preparing => 'primary',
            self::Ready => 'success',
            self::Served => 'dark',
            self::Cancelled => 'danger',
        };
    }
}
