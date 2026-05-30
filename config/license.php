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

    // Must be identical on cloud and branch nodes. APP_KEY is a convenient
    // dev fallback; production installs should set LICENSE_SIGNING_SECRET.
    'signing_secret' => env('LICENSE_SIGNING_SECRET', env('APP_KEY')),

    'timeout' => (int) env('LICENSE_TIMEOUT', 8),
    'warning_days' => (int) env('LICENSE_WARNING_DAYS', 30),
    'grace_days' => (int) env('LICENSE_GRACE_DAYS', 14),
    'refresh_hours' => (int) env('LICENSE_REFRESH_HOURS', 12),
    'clock_skew_days' => (int) env('LICENSE_CLOCK_SKEW_DAYS', 1),
];
