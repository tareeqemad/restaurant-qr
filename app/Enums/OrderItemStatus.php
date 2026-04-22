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
            self::Pending => 'بانتظار',
            self::Approved => 'معتمد',
            self::Preparing => 'قيد التحضير',
            self::Ready => 'جاهز',
            self::Served => 'تم التقديم',
            self::Cancelled => 'ملغى',
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
