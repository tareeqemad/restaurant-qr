<?php

namespace App\Support;

class MarketProfile
{
    public static function current(): string
    {
        return (string) config('market.profile', 'palestine');
    }

    public static function isUs(): bool
    {
        return static::current() === 'us';
    }

    public static function lang(): string
    {
        return (string) static::profileValue('locale', 'en');
    }

    public static function direction(): string
    {
        return (string) static::profileValue('direction', 'rtl');
    }

    public static function isRtl(): bool
    {
        return static::direction() === 'rtl';
    }

    public static function bootstrapCssPath(): string
    {
        return static::isRtl()
            ? 'assets/dashtic/libs/bootstrap/css/bootstrap.rtl.min.css'
            : 'assets/dashtic/libs/bootstrap/css/bootstrap.min.css';
    }

    public static function fontUrl(): string
    {
        return (string) config('market.font_url');
    }

    public static function fontFamily(): string
    {
        return (string) config('market.font_family');
    }

    public static function timezone(): string
    {
        return (string) config('market.timezone', config('app.timezone', 'UTC'));
    }

    public static function currency(): string
    {
        return (string) config('restaurant.currency', config('market.currency', 'USD'));
    }

    public static function currencySymbol(): string
    {
        return (string) config('restaurant.currency_symbol', config('market.currency_symbol', '$'));
    }

    public static function taxLabel(): string
    {
        return (string) config('restaurant.tax.label', config('market.tax_label', 'Tax'));
    }

    public static function taxNumberLabel(): string
    {
        return (string) config('restaurant.tax.number_label', config('market.tax_number_label', 'Tax ID'));
    }

    public static function serviceLabel(): string
    {
        return (string) config('restaurant.service_charge.label', config('market.service_label', 'Service charge'));
    }

    private static function profileValue(string $key, mixed $default = null): mixed
    {
        return config('market.profiles.' . static::current() . '.' . $key, config('market.' . $key, $default));
    }
}
