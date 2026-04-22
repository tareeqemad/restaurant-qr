<?php

return [
    'name' => env('RESTAURANT_NAME', 'مطعم QR'),
    'currency' => env('RESTAURANT_CURRENCY', 'JOD'),
    'currency_symbol' => env('RESTAURANT_CURRENCY_SYMBOL', 'د.أ'),

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
        'primary' => '#2d5a3d',         // warm clear green (less blue-teal)
        'dark' => '#1a3a26',            // deep warm forest
        'header' => '#2d5a3d',
        'accent' => '#b8872a',          // olive gold
        'menu' => '#faf5eb',            // cream sidebar (for menu_style=light)
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
];
