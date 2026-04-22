<?php

namespace App\Helpers;

class Money
{
    public static function format(float|int|string $amount, ?string $symbol = null): string
    {
        $symbol = $symbol ?? config('restaurant.currency_symbol', 'د.أ');
        return number_format((float) $amount, 2, '.', ',').' '.$symbol;
    }

    public static function round(float $amount): float
    {
        return round($amount, 2);
    }

    public static function applyTax(float $subtotal, ?float $rate = null): array
    {
        $rate = $rate ?? (float) config('restaurant.tax.rate', 16);
        $enabled = config('restaurant.tax.enabled', true);

        if (! $enabled || $rate <= 0) {
            return ['tax' => 0.0, 'rate' => 0.0];
        }

        if (config('restaurant.tax.inclusive')) {
            $tax = $subtotal - ($subtotal / (1 + $rate / 100));
        } else {
            $tax = $subtotal * ($rate / 100);
        }

        return ['tax' => self::round($tax), 'rate' => $rate];
    }

    public static function applyService(float $subtotal, ?float $rate = null): array
    {
        $rate = $rate ?? (float) config('restaurant.service_charge.rate', 10);
        $enabled = config('restaurant.service_charge.enabled', false);

        if (! $enabled || $rate <= 0) {
            return ['service' => 0.0, 'rate' => 0.0];
        }

        return ['service' => self::round($subtotal * ($rate / 100)), 'rate' => $rate];
    }
}
