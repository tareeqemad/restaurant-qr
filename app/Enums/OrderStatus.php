<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الموافقة',
            self::Approved => 'تمت الموافقة',
            self::Preparing => 'قيد التحضير',
            self::Ready => 'جاهز',
            self::Delivered => 'تم التسليم',
            self::Completed => 'مكتمل',
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
            self::Delivered => 'dark',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public static function active(): array
    {
        return [
            self::Pending->value,
            self::Approved->value,
            self::Preparing->value,
            self::Ready->value,
            self::Delivered->value,
        ];
    }
}
