<?php

namespace App\Helpers;

use App\Models\Currency;
use App\Models\Setting;

class Money
{
    public static function format(float|int|string $amount, ?string $symbol = null): string
    {
        if ($symbol === null) {
            $symbol = Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪'));
        }
        $symbol = $symbol ?? config('restaurant.currency_symbol', '₪');
        return number_format((float) $amount, 2, '.', ',').' '.$symbol;
    }

    public static function accountingSymbol(): string
    {
        $code = strtoupper((string) Setting::get('accounting_base_currency', config('restaurant.currency', 'USD')));
        $currency = $code !== '' ? Currency::where('code', $code)->first() : null;

        return (string) ($currency?->symbol
            ?? Setting::get('accounting_currency_symbol', Setting::get('currency_symbol', config('restaurant.currency_symbol', '$'))));
    }

    public static function formatAccounting(float|int|string $amount): string
    {
        return self::format($amount, self::accountingSymbol());
    }

    public static function round(float $amount): float
    {
        return round($amount, 2);
    }

    public static function applyTax(float $subtotal, ?float $rate = null): array
    {
        $hasExplicitRate = $rate !== null;
        $rate = $rate ?? (float) Setting::get('tax_rate', config('restaurant.tax.rate', 16));
        $enabled = $hasExplicitRate ? $rate > 0 : (bool) Setting::get('tax_enabled', config('restaurant.tax.enabled', true));

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
        $hasExplicitRate = $rate !== null;
        $rate = $rate ?? (float) Setting::get('service_rate', config('restaurant.service_charge.rate', 10));
        $enabled = $hasExplicitRate ? $rate > 0 : (bool) Setting::get('service_enabled', config('restaurant.service_charge.enabled', false));

        if (! $enabled || $rate <= 0) {
            return ['service' => 0.0, 'rate' => 0.0];
        }

        return ['service' => self::round($subtotal * ($rate / 100)), 'rate' => $rate];
    }
}
