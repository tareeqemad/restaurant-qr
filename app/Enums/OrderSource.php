<?php

namespace App\Enums;

/**
 * Where an order came from — drives platform-aware reporting & UI badges.
 */
enum OrderSource: string
{
    case DineIn   = 'dine_in';
    case Talabat  = 'talabat';
    case Careem   = 'careem';
    case UberEats = 'uber_eats';
    case Phone    = 'phone';
    case Other    = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DineIn   => 'من الطاولة',
            self::Talabat  => 'طلبات',
            self::Careem   => 'كريم ناو',
            self::UberEats => 'أوبر إيتس',
            self::Phone    => 'اتصال هاتفي',
            self::Other    => 'أخرى',
        };
    }

    /**
     * Brand color for platform badges on cards/tables.
     */
    public function color(): string
    {
        return match ($this) {
            self::DineIn   => '#1f4733',   // forest green
            self::Talabat  => '#ff5a00',   // Talabat orange
            self::Careem   => '#49b24c',   // Careem green
            self::UberEats => '#06c167',   // Uber Eats green
            self::Phone    => '#6366f1',   // indigo
            self::Other    => '#6b7280',   // slate
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DineIn   => 'bi-grid-3x3-gap-fill',
            self::Talabat  => 'bi-bag-fill',
            self::Careem   => 'bi-scooter',
            self::UberEats => 'bi-truck',
            self::Phone    => 'bi-telephone-fill',
            self::Other    => 'bi-box',
        };
    }

    /**
     * Commissions typical for each platform. Used as a DEFAULT, editable per-order.
     */
    public function defaultCommission(): float
    {
        return match ($this) {
            self::Talabat  => 25.0,
            self::Careem   => 22.0,
            self::UberEats => 30.0,
            default        => 0.0,
        };
    }

    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function externalPlatforms(): array
    {
        return ['talabat', 'careem', 'uber_eats', 'phone'];
    }
}
