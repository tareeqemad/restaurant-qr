<?php

namespace App\Enums;

/**
 * Where an order came from — drives platform-aware reporting & UI badges.
 */
enum OrderSource: string
{
    case DineIn   = 'dine_in';
    case Portal   = 'portal';      // customer placed via /portal (own pickup or delivery)
    case Delivery = 'delivery';    // restaurant's own delivery — receiver name stored on the order
    case Phone    = 'phone';
    case Other    = 'other';
    // Legacy external-platform cases — kept for historical orders, removed from the cashier UI.
    case Talabat  = 'talabat';
    case Careem   = 'careem';
    case UberEats = 'uber_eats';

    public function label(): string
    {
        return match ($this) {
            self::DineIn   => 'من الطاولة',
            self::Portal   => 'تطبيق المطعم',
            self::Delivery => 'دليفري',
            self::Phone    => 'اتصال هاتفي',
            self::Other    => 'مباشر',
            self::Talabat  => 'طلبات',
            self::Careem   => 'كريم ناو',
            self::UberEats => 'أوبر إيتس',
        };
    }

    /**
     * Brand color for source badges on cards/tables.
     */
    public function color(): string
    {
        return match ($this) {
            self::DineIn   => '#1f4733',   // forest green
            self::Portal   => '#0ea5e9',   // sky blue — our brand channel
            self::Delivery => '#b97818',   // accent — own delivery
            self::Phone    => '#6366f1',   // indigo
            self::Other    => '#6b7280',   // slate
            self::Talabat  => '#ff5a00',
            self::Careem   => '#49b24c',
            self::UberEats => '#06c167',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DineIn   => 'bi-grid-3x3-gap-fill',
            self::Portal   => 'bi-phone-fill',
            self::Delivery => 'bi-truck',
            self::Phone    => 'bi-telephone-fill',
            self::Other    => 'bi-lightning-charge-fill',
            self::Talabat  => 'bi-bag-fill',
            self::Careem   => 'bi-scooter',
            self::UberEats => 'bi-truck',
        };
    }

    /**
     * Default commission % per source. Zero everywhere now that platform
     * integrations are gone — kept as an editable per-order field in case
     * the restaurant uses an outside courier with a fee.
     */
    public function defaultCommission(): float
    {
        return 0.0;
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }
}
