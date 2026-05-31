<?php

namespace App\Enums;

use App\Models\Setting;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Localised label shown on badges + dropdowns. Restaurants can
     * override the wording from /admin/settings without redeploying
     * (e.g. shorter labels for cramped POS screens). Empty/missing
     * setting falls back to the original Arabic default below.
     */
    public function label(): string
    {
        $override = trim((string) Setting::get('order_status_'.$this->value.'_label'));
        if ($override !== '') {
            return $override;
        }

        return $this->defaultLabel();
    }

    /**
     * Bootstrap badge color (primary|secondary|success|danger|warning|
     * info|light|dark). Override from settings same as label().
     */
    public function color(): string
    {
        $override = trim((string) Setting::get('order_status_'.$this->value.'_color'));
        if (in_array($override, self::ALLOWED_COLORS, true)) {
            return $override;
        }

        return $this->defaultColor();
    }

    public const ALLOWED_COLORS = [
        'primary', 'secondary', 'success', 'danger',
        'warning', 'info', 'light', 'dark',
    ];

    public function defaultLabel(): string
    {
        return match ($this) {
            self::Pending => __('ui.customer_order.status_pending_approval'),
            self::Approved => __('ui.customer_order.status_approved_full'),
            self::Preparing => __('ui.customer_order.status_preparing_full'),
            self::Ready => __('ui.customer_order.status_ready'),
            self::Delivered => __('ui.customer_order.status_delivered'),
            self::Completed => __('ui.customer_order.status_completed'),
            self::Cancelled => __('ui.customer_order.status_cancelled'),
        };
    }

    public function defaultColor(): string
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
