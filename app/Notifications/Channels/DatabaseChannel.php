<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel as BaseDatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Custom DB channel — same as Laravel's default, but copies the three
 * filterable keys we care about (branch_id, type_key, severity) out of
 * the `data` JSON and into their own indexed columns.
 *
 * That extra step is what makes "give me my unread for the active branch
 * grouped by type" cheap — without it we'd be scanning JSON in the WHERE.
 */
class DatabaseChannel extends BaseDatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);
        $data    = $payload['data'] ?? [];

        return array_merge($payload, [
            'branch_id' => $data['branch_id'] ?? null,
            'type_key'  => $data['type_key']  ?? null,
            'severity'  => $data['severity']  ?? null,
        ]);
    }
}
