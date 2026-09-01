<?php

namespace App\Enums;

/**
 * The restaurant-owned channel that created the order.
 */
enum OrderSource: string
{
    case DineIn = 'dine_in';
    case Phone = 'phone';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => __('ui.customer_order.source_dine_in'),
            self::Phone => __('ui.customer_order.source_phone'),
            self::Other => __('ui.customer_order.source_other'),
        };
    }

    /**
     * Brand color for source badges on cards/tables.
     */
    public function color(): string
    {
        return match ($this) {
            self::DineIn => '#1f4733',
            self::Phone => '#6366f1',
            self::Other => '#6b7280',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DineIn => 'bi-grid-3x3-gap-fill',
            self::Phone => 'bi-telephone-fill',
            self::Other => 'bi-lightning-charge-fill',
        };
    }
}
