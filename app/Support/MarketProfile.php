<?php

namespace App\Support;

/**
 * Arabic presentation defaults shared by Blade views, exports and reports.
 *
 * This is deliberately not a selectable "market profile". The application is
 * Arabic/RTL; restaurants may still configure their currency and invoice labels.
 */
class MarketProfile
{
    public static function fontUrl(): string
    {
        return (string) config('market.font_url', '/assets/fonts/fonts.css');
    }

    public static function fontFamily(): string
    {
        return (string) config('market.font_family', "'Tajawal', system-ui, -apple-system, 'Segoe UI', sans-serif");
    }

    public static function timezone(): string
    {
        return (string) config('market.timezone', config('app.timezone', 'Asia/Hebron'));
    }

    public static function currency(): string
    {
        return (string) config('restaurant.currency', config('market.currency', 'ILS'));
    }

    public static function currencySymbol(): string
    {
        return (string) config('restaurant.currency_symbol', config('market.currency_symbol', '₪'));
    }

    public static function taxLabel(): string
    {
        return (string) config('restaurant.tax.label', config('market.tax_label', 'الضريبة'));
    }

    public static function taxNumberLabel(): string
    {
        return (string) config('restaurant.tax.number_label', config('market.tax_number_label', 'الرقم الضريبي'));
    }

    public static function serviceLabel(): string
    {
        return (string) config('restaurant.service_charge.label', config('market.service_label', 'الخدمة'));
    }
}
