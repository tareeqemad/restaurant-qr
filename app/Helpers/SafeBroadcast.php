<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class SafeBroadcast
{
    /**
     * Dispatch a broadcast event without letting a Reverb/Pusher outage break the request.
     */
    public static function dispatch(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('Broadcast dispatch failed: '.$e->getMessage(), [
                'event' => get_class($event),
            ]);
        }
    }
}
