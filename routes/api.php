<?php

use App\Http\Controllers\Api\LicenseCheckController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sync API (cloud side)
|--------------------------------------------------------------------------
|
| Node-to-node endpoints the branches dial into. Guarded by the shared sync
| token (sync.token middleware). See docs/offline-sync-architecture.md.
|
*/
Route::middleware('sync.token')->prefix('sync')->group(function () {
    Route::get('ping', [SyncController::class, 'ping']);
    Route::get('pull', [SyncController::class, 'pull']);
    Route::post('push', [SyncController::class, 'push']);
});

Route::post('license/check', LicenseCheckController::class);
