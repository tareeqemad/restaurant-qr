<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Offline-first sync engine
    |--------------------------------------------------------------------------
    |
    | Implements phases 2–3 of docs/offline-sync-architecture.md. The branch
    | (on-prem) node dials OUT to the cloud — the cloud has the stable address,
    | the branch may be behind a flaky connection. So the branch is always the
    | active party: it pulls config DOWN and pushes transactions UP whenever it
    | can reach the cloud. The scheduler retries every minute, so the moment the
    | internet returns the backlog drains on its own (no button needed).
    |
    */

    'enabled' => (bool) env('SYNC_ENABLED', false),

    // 'branch'     — on-prem node that syncs with the cloud (active party).
    // 'cloud'      — central node that receives pushes and serves pulls.
    // 'standalone' — sync disabled (single-node install).
    'role' => env('SYNC_ROLE', 'standalone'),

    // ── Branch-side settings ────────────────────────────────────────────────
    // Base URL of the cloud node, e.g. https://sheikh.disgaza.com
    'cloud_url' => env('SYNC_CLOUD_URL'),
    // Shared secret presented to the cloud on every request.
    'token' => env('SYNC_TOKEN'),
    // Which branch this node is (matches branches.id on the cloud).
    'branch_id' => env('SYNC_BRANCH_ID'),

    // HTTP timeout (seconds) per request and max rows per batch.
    'timeout' => (int) env('SYNC_TIMEOUT', 15),
    'batch' => (int) env('SYNC_BATCH', 200),

    // ── Cloud-side settings ─────────────────────────────────────────────────
    // Token the cloud accepts from branches. For a single shared secret set
    // SYNC_TOKEN on both ends. (Per-branch tokens can replace this later.)
    'accept_token' => env('SYNC_TOKEN'),
];
