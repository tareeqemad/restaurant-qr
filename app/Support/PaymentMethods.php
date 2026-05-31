<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Per-restaurant enabled payment methods.
 *
 * The DB enum (payments.method) keeps every method we've ever shipped
 * (cash / card / transfer / app / credit). Which ones a given install
 * is allowed to USE is a settings-level decision so the restaurant can
 * turn off whatever it doesn't accept — small cash-only shops, places
 * without a POS terminal, branches that don't extend credit, etc.
 *
 * Defaults mirror the pre-existing hardcoded set (cash + card + transfer)
 * so an upgrade keeps behaviour identical until the admin opts in.
 */
class PaymentMethods
{
    /** All methods the schema supports, with their labels and icons. */
    protected const CATALOG = [
        'cash'     => ['label_key' => 'admin.payment_methods.cash',     'icon' => 'bi-cash-stack',   'default' => true],
        'card'     => ['label_key' => 'admin.payment_methods.card',     'icon' => 'bi-credit-card',  'default' => true],
        'transfer' => ['label_key' => 'admin.payment_methods.transfer', 'icon' => 'bi-bank',         'default' => true],
        'app'      => ['label_key' => 'admin.payment_methods.app',      'icon' => 'bi-phone',        'default' => false],
        'credit'   => ['label_key' => 'admin.payment_methods.credit',   'icon' => 'bi-journal-text', 'default' => false],
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

        return is_string($key) ? __($key) : $code;
    }

    /** Validation `in:` rule built from the currently-enabled methods. */
    public static function inRule(): string
    {
        $codes = self::enabled();
        return 'in:'.implode(',', $codes ?: ['cash']);
    }
}
