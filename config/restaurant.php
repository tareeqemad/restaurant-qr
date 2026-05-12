<?php

return [
    'name' => env('RESTAURANT_NAME', 'مطعم QR'),
    'currency' => env('RESTAURANT_CURRENCY', 'ILS'),
    'currency_symbol' => env('RESTAURANT_CURRENCY_SYMBOL', '₪'),

    // Customer-facing feature flags
    'customer' => [
        // Show a currency switcher in the customer topbar (dropdown).
        // Default OFF — most restaurants only need one currency.
        // Enable via env when you actually want multi-currency UX.
        'currency_switcher' => env('CUSTOMER_CURRENCY_SWITCHER', false),
    ],

    'tax' => [
        'enabled' => env('RESTAURANT_TAX_ENABLED', true),
        'rate' => (float) env('RESTAURANT_TAX_RATE', 16),
        'inclusive' => env('RESTAURANT_TAX_INCLUSIVE', false),
    ],

    'service_charge' => [
        'enabled' => env('RESTAURANT_SERVICE_ENABLED', false),
        'rate' => (float) env('RESTAURANT_SERVICE_RATE', 10),
    ],

    'theme' => [
        'primary' => '#164c37',
        'dark' => '#0f2d22',
        'header' => '#164c37',
        'accent' => '#b97818',
        'menu' => '#f7f8f5',
        'menu_style' => 'brand',
        'header_style' => 'color',
    ],

    'order' => [
        'auto_approve' => env('RESTAURANT_AUTO_APPROVE', false),
        'customer_cancel_window_seconds' => 120,
        'session_ttl_minutes' => 240,
    ],

    'inventory' => [
        // When true, approving an order is BLOCKED if any tracked ingredient
        // would go negative. Throws a detailed error listing the shortages.
        // Set to false to allow over-selling (not recommended — demos only).
        'strict_stock' => env('RESTAURANT_STRICT_STOCK', true),
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
            'waiter'  => ['percent' => 5,  'fixed' => 3],
            'manager' => ['percent' => 25, 'fixed' => 50],
            'admin'   => ['percent' => 100, 'fixed' => 9999],
        ],
    ],
];
