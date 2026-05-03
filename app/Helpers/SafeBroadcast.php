<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SafeBroadcast
{
    /**
     * Short-term circuit breaker. Once a broadcast fails, we remember it for
     * BACKOFF_SECONDS so subsequent dispatches in the same request (or nearby
     * requests) return instantly instead of re-tripping the TCP timeout.
     *
     * Before this guard a bulk "mark all ready" on a 5-item order blocked
     * the HTTP request for ~10s (5 × 2s connect timeout), which made the
     * UI look like nothing saved until the user refreshed.
     */
    protected const BACKOFF_SECONDS = 30;
    protected const CACHE_KEY = 'broadcast:unavailable';

    public static function dispatch(object $event): void
    {
        if (Cache::has(self::CACHE_KEY)) {
            // Recent failure — skip this attempt entirely. The Livewire action
            // returns quickly, the UI refreshes, and live notifications simply
            // aren't delivered until the operator brings Reverb back up.
            return;
        }

        try {
            event($event);
        } catch (\Throwable $e) {
            Cache::put(self::CACHE_KEY, 1, self::BACKOFF_SECONDS);
            Log::warning('Broadcast dispatch failed: '.$e->getMessage(), [
                'event' => get_class($event),
            ]);
        }
    }
}
