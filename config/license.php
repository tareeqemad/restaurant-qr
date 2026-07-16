<?php

return [
    /*
    |--------------------------------------------------------------------------
    | License enforcement
    |--------------------------------------------------------------------------
    |
    | The central/cloud node owns license records and renewals. Branch nodes
    | keep a signed local cache so they can keep working during short internet
    | outages, then refresh from the cloud whenever connectivity returns.
    |
    */

    'enabled' => (bool) env('LICENSE_ENABLED', false),

    // 'cloud' manages licenses and serves /api/license/check.
    // 'branch' consumes the cloud license status.
    // Defaults to SYNC_ROLE so sync + licensing describe the same topology.
    'role' => env('LICENSE_ROLE', env('SYNC_ROLE', 'standalone')),

    // Branch-side settings.
    'cloud_url' => env('LICENSE_CLOUD_URL', env('SYNC_CLOUD_URL')),
    'key' => env('LICENSE_KEY'),

    // Production signing should be asymmetric: only the cloud node gets the
    // private key, while branch/customer nodes get the public key only.
    // Values may be literal PEM strings, "\n"-escaped PEM strings, or paths.
    'private_key' => env('LICENSE_PRIVATE_KEY'),
    'private_key_path' => env('LICENSE_PRIVATE_KEY_PATH'),
    'public_key' => env('LICENSE_PUBLIC_KEY'),
    'public_key_path' => env('LICENSE_PUBLIC_KEY_PATH'),

    // Legacy/dev fallback. Do not put this on customer nodes when using
    // LICENSE_PUBLIC_KEY / LICENSE_PUBLIC_KEY_PATH.
    'signing_secret' => env('LICENSE_SIGNING_SECRET', env('APP_KEY')),

    'timeout' => (int) env('LICENSE_TIMEOUT', 8),
    'connect_timeout' => (int) env('LICENSE_CONNECT_TIMEOUT', 3),
    'warning_days' => (int) env('LICENSE_WARNING_DAYS', 30),
    'grace_days' => (int) env('LICENSE_GRACE_DAYS', 14),
    'refresh_hours' => (int) env('LICENSE_REFRESH_HOURS', 12),
    'clock_skew_days' => (int) env('LICENSE_CLOCK_SKEW_DAYS', 1),
];
