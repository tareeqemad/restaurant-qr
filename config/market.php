<?php

$profile = env('MARKET_PROFILE', 'palestine');

$profiles = [
    'palestine' => [
        'label' => 'Palestine / Arabic',
        'locale' => 'ar',
        'fallback_locale' => 'en',
        'faker_locale' => 'en_US',
        'direction' => 'rtl',
        'timezone' => 'Asia/Hebron',
        'currency' => 'ILS',
        'currency_symbol' => '₪',
        'tax_label' => 'الضريبة',
        'tax_number_label' => 'الرقم الضريبي',
        'default_tax_rate' => 16,
        'tax_inclusive' => false,
        'service_label' => 'الخدمة',
        'default_service_rate' => 10,
        'phone_country' => 'PS',
        'font_url' => 'https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Cinzel:wght@500;600;700&display=swap',
        'font_family' => "'Tajawal', system-ui, -apple-system, 'Segoe UI', sans-serif",
    ],

    'us' => [
        'label' => 'United States',
        'locale' => 'en',
        'fallback_locale' => 'en',
        'faker_locale' => 'en_US',
        'direction' => 'ltr',
        'timezone' => 'America/New_York',
        'currency' => 'USD',
        'currency_symbol' => '$',
        'tax_label' => 'Sales tax',
        'tax_number_label' => 'Sales tax ID',
        'default_tax_rate' => 0,
        'tax_inclusive' => false,
        'service_label' => 'Gratuity',
        'default_service_rate' => 0,
        'phone_country' => 'US',
        'font_url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cinzel:wght@500;600;700&display=swap',
        'font_family' => "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif",
    ],
];

$selected = $profiles[$profile] ?? $profiles['palestine'];
$selected['timezone'] = env('MARKET_TIMEZONE') ?: $selected['timezone'];

return [
    'profile' => array_key_exists($profile, $profiles) ? $profile : 'palestine',
    'profiles' => $profiles,
    ...$selected,
];
