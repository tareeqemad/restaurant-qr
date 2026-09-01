<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Per-restaurant enabled payment methods.
 *
 * Direct bank and Visa receipts post to the bank immediately. Wallet receipts
 * remain in their own asset account until the accountant transfers them.
 */
class PaymentMethods
{
    public const CUSTOMER_ADVANCE = 'customer_advance';

    /** All methods the schema supports, with their labels and icons. */
    protected const CATALOG = [
        'cash' => ['label_key' => 'admin.payment_methods.cash',       'icon' => 'bi-cash-stack', 'default' => true,  'description' => 'تحصيل نقدي على مسؤولية المستخدم الذي أكّد الدفعة.'],
        'transfer' => ['label_key' => 'admin.payment_methods.transfer',   'icon' => 'bi-bank',       'default' => true,  'description' => 'تحويل مباشر إلى حساب البنك بعد التحقق من الوصل.'],
        'card' => ['label_key' => 'admin.payment_methods.card',       'icon' => 'bi-credit-card', 'default' => false, 'description' => 'دفعة فيزا تصل إلى البنك مباشرة بلا حساب مقاصة.'],
        'palpay' => ['label_key' => 'admin.payment_methods.palpay',     'icon' => 'bi-wallet2',    'default' => false, 'description' => 'تُثبت في رصيد PalPay حتى يحولها المحاسب إلى البنك.'],
        'jawwal_pay' => ['label_key' => 'admin.payment_methods.jawwal_pay', 'icon' => 'bi-phone',      'default' => false, 'description' => 'تُثبت في رصيد Jawwal Pay حتى يحولها المحاسب إلى البنك.'],
    ];

    private const HISTORICAL_LABELS = [
        'card' => 'بطاقة (قديم)',
        'app' => 'محفظة (قديم)',
        'credit' => 'آجل (قديم)',
        self::CUSTOMER_ADVANCE => 'رصيد مقدم للزبون',
    ];

    /** All known methods + metadata, including their current enabled state. */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => $meta) {
            $out[$code] = $meta + [
                'label' => self::label($code),
                'enabled' => self::isEnabled($code),
            ];
        }

        return $out;
    }

    /** Method codes currently enabled for this installation. */
    public static function enabled(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => $meta) {
            if (self::isEnabled($code)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /** Settings key for a single method's toggle. */
    public static function settingKey(string $code): string
    {
        return 'payment_method_'.$code.'_enabled';
    }

    /** True if the given method is allowed in this installation. */
    public static function isEnabled(string $code): bool
    {
        if (! isset(self::CATALOG[$code])) {
            return false;
        }
        $default = self::CATALOG[$code]['default'];

        return (bool) Setting::get(self::settingKey($code), $default);
    }

    /** Localized label for a method (falls back to the raw code). */
    public static function label(string $code): string
    {
        $key = self::CATALOG[$code]['label_key'] ?? null;

        return is_string($key) ? __($key) : (self::HISTORICAL_LABELS[$code] ?? $code);
    }

    /** Validation `in:` rule built from the currently-enabled methods. */
    public static function inRule(): string
    {
        $codes = self::enabled();

        return 'in:'.implode(',', $codes ?: ['cash']);
    }
}
