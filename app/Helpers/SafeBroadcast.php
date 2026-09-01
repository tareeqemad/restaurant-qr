<?php

namespace App\Helpers;

use App\Support\LiveRefreshPulse;
use Illuminate\Support\Facades\Log;

class SafeBroadcast
{
    /** Touch the polling token without coupling order writes to a socket server. */
    public static function dispatch(object $event): void
    {
        try {
            LiveRefreshPulse::touch($event);
        } catch (\Throwable $e) {
            // A cache outage must never turn a notification optimisation into
            // a failed order/payment write. The periodic safety refresh still
            // catches changes until cache is healthy again.
            Log::warning('Live refresh pulse failed: '.$e->getMessage(), [
                'event' => get_class($event),
            ]);
        }

    }
}
