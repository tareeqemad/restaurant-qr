<?php

$marketProfile = env('MARKET_PROFILE', 'palestine');
$isUsMarket = $marketProfile === 'us';
$envOrMarket = static function (string $key, mixed $default, mixed $legacyDefault = null) use ($isUsMarket): mixed {
    $value = env($key);

    if ($value === null || $value === '') {
        return $default;
    }

    if ($isUsMarket && $legacyDefault !== null && (string) $value === (string) $legacyDefault) {
        return $default;
    }

    return $value;
};

return [
    'market' => $marketProfile,
    'name' => $envOrMarket('RESTAURANT_NAME', $isUsMarket ? 'Restaurant QR' : 'مطعم QR', 'مطعم QR'),
    'currency' => $envOrMarket('RESTAURANT_CURRENCY', $isUsMarket ? 'USD' : 'ILS', 'ILS'),
    'currency_symbol' => $envOrMarket('RESTAURANT_CURRENCY_SYMBOL', $isUsMarket ? '$' : '₪', '₪'),

    // Base URL the customer QR codes resolve to. On a local/on-prem server
    // set MENU_BASE_URL to the LAN address the restaurant WiFi can reach
    // (e.g. http://relax.local or http://192.168.1.10) so customers can scan
    // and order even while the internet is down. Falls back to APP_URL when
    // unset — keep it empty for pure cloud deployments.
    'menu_base_url' => env('MENU_BASE_URL'),

    // Customer-facing feature flags
    'customer' => [
        // Show a currency switcher in the customer topbar (dropdown).
        // Default OFF — most restaurants only need one currency.
        // Enable via env when you actually want multi-currency UX.
        'currency_switcher' => env('CUSTOMER_CURRENCY_SWITCHER', false),
    ],

    'tax' => [
        'label' => $envOrMarket('RESTAURANT_TAX_LABEL', $isUsMarket ? 'Sales tax' : 'الضريبة', 'الضريبة'),
        'number_label' => $envOrMarket('RESTAURANT_TAX_NUMBER_LABEL', $isUsMarket ? 'Sales tax ID' : 'الرقم الضريبي', 'الرقم الضريبي'),
        'enabled' => env('RESTAURANT_TAX_ENABLED', true),
        'rate' => (float) $envOrMarket('RESTAURANT_TAX_RATE', $isUsMarket ? 0 : 16, 16),
        'inclusive' => env('RESTAURANT_TAX_INCLUSIVE', false),
    ],

    'service_charge' => [
        'label' => $envOrMarket('RESTAURANT_SERVICE_LABEL', $isUsMarket ? 'Gratuity' : 'الخدمة', 'الخدمة'),
        'enabled' => env('RESTAURANT_SERVICE_ENABLED', false),
        'rate' => (float) $envOrMarket('RESTAURANT_SERVICE_RATE', $isUsMarket ? 0 : 10, 10),
    ],

    'phone_country' => $envOrMarket('RESTAURANT_PHONE_COUNTRY', $isUsMarket ? 'US' : 'PS', 'PS'),

    'theme' => [
        'primary' => '#166534',
        'dark' => '#14532d',
        'header' => '#ffffff',
        'accent' => '#d97706',
        'menu' => '#ffffff',
        'menu_style' => 'brand',
        'header_style' => 'light',
    ],

    'order' => [
        'auto_approve' => env('RESTAURANT_AUTO_APPROVE', false),
        'customer_cancel_window_seconds' => 120,
        'session_ttl_minutes' => 240,
    ],

    'staff_meals' => [
        // Whether service charge stays on the staff member's tab. By
        // default it's stripped — an employee shouldn't be paying a
        // tip to their own colleagues. Operators that pool service
        // restaurant-wide and pay it out separately can flip this on.
        // Override at runtime via Settings → "بدل وجبات الموظفين".
        'include_service' => env('RESTAURANT_STAFF_MEAL_INCLUDE_SERVICE', false),
    ],

    'inventory' => [
        // When true, approving an order is BLOCKED if any tracked ingredient
        // would go negative. Throws a detailed error listing the shortages.
        // Set to false to allow over-selling (not recommended — demos only).
        'strict_stock' => env('RESTAURANT_STRICT_STOCK', true),

        // At which workflow stage do we physically decrement stock?
        //   'approve'   — waiter accepts the ticket (default, original behavior)
        //   'preparing' — chef/barista actually starts cooking (matches reality)
        //   'ready'     — item finished and ready to pick up
        //   'served'    — item delivered to the customer
        // A safety net at invoice settlement guarantees deduction regardless,
        // so picking a later stage just spares us deduct→return churn on
        // orders that get cancelled before the kitchen touches them.
        'deduction_stage' => env('RESTAURANT_DEDUCTION_STAGE', 'approve'),
    ],

    // Sidebar visibility for OPTIONAL back-of-house screens. A small,
    // single-branch restaurant rarely needs these, so they're hidden by
    // default to keep the inventory/purchasing menu lean. Flip a flag on
    // (here or via the matching env var) when you actually start using it.
    //
    // Hiding a screen only removes it from the menu — the underlying feature
    // keeps working (batches still cost FIFO, units still convert). Branch
    // transfers are NOT listed here: they auto-appear the moment a second
    // active branch exists, and auto-hide while you run a single branch.
    'nav' => [
        'vendor_price_compare' => env('NAV_VENDOR_PRICE_COMPARE', false),
        'batch_expiry'         => env('NAV_BATCH_EXPIRY', false),
        'units_management'     => env('NAV_UNITS_MANAGEMENT', false),
    ],

    // Cashier-applied discounts. Caps are role-keyed defaults; the running
    // value comes from the Settings table when present (key:
    // `discount_cap_<role>_pct` / `..._fixed`) so admins can tune them at
    // runtime from /admin/settings without redeploying. Owner-level
    // (super_admin/partner) is uncapped — `OrderDiscountService::userCap()`
    // returns null for them. The `categories` catalog itself lives in the
    // `lookups` table (group: `discount_categories`).
    'discounts' => [
        'caps' => [
            'cashier' => ['percent' => 10, 'fixed' => 5],
            'waiter' => ['percent' => 5,  'fixed' => 3],
            'manager' => ['percent' => 25, 'fixed' => 50],
            'admin' => ['percent' => 100, 'fixed' => 9999],
        ],
    ],
];
