<?php

namespace App\Support;

use App\Events\InvoicePaid;
use App\Events\OrderChangeRequestChanged;
use App\Events\OrderCreated;
use App\Events\OrderItemStatusChanged;
use App\Events\OrderStatusChanged;
use App\Events\PendingTransferDeclared;
use App\Events\TableStatusChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A cheap change token for operational Vue screens. Their visible-tab poll
 * reads it first and skips an expensive page refresh when nothing changed.
 */
final class LiveRefreshPulse
{
    private const GLOBAL_KEY = 'live-refresh:global';

    public static function touch(object $event): void
    {
        $version = (string) Str::uuid();

        Cache::forever(self::GLOBAL_KEY, $version);

        if (($branchId = self::branchId($event)) !== null) {
            Cache::forever(self::branchKey($branchId), $version);
        }

        if (($sessionId = self::sessionId($event)) !== null) {
            Cache::forever(self::sessionKey($sessionId), $version);
        }
    }

    public static function version(?int $branchId = null): string
    {
        $key = $branchId ? self::branchKey($branchId) : self::GLOBAL_KEY;

        return (string) Cache::get($key, '0');
    }

    public static function sessionVersion(int $sessionId): string
    {
        return (string) Cache::get(self::sessionKey($sessionId), '0');
    }

    public static function touchSession(int $sessionId): void
    {
        Cache::forever(self::sessionKey($sessionId), (string) Str::uuid());
    }

    private static function branchKey(int $branchId): string
    {
        return 'live-refresh:branch:'.$branchId;
    }

    private static function sessionKey(int $sessionId): string
    {
        return 'live-refresh:session:'.$sessionId;
    }

    private static function branchId(object $event): ?int
    {
        $branchId = match (true) {
            $event instanceof OrderCreated,
            $event instanceof OrderStatusChanged => $event->order->branch_id,
            $event instanceof OrderItemStatusChanged => $event->item->order?->branch_id,
            $event instanceof InvoicePaid => $event->invoice->branch_id,
            $event instanceof PendingTransferDeclared => $event->transfer->branch_id,
            $event instanceof OrderChangeRequestChanged => $event->changeRequest->branch_id,
            $event instanceof TableStatusChanged => $event->table->branch_id,
            default => null,
        };

        return $branchId ? (int) $branchId : null;
    }

    private static function sessionId(object $event): ?int
    {
        $sessionId = match (true) {
            $event instanceof OrderCreated,
            $event instanceof OrderStatusChanged => $event->order->table_session_id,
            $event instanceof OrderItemStatusChanged => $event->item->order?->table_session_id,
            $event instanceof InvoicePaid => $event->invoice->table_session_id
                ?: $event->invoice->order?->table_session_id,
            $event instanceof PendingTransferDeclared => $event->transfer->table_session_id,
            $event instanceof OrderChangeRequestChanged => $event->changeRequest->order?->table_session_id,
            default => null,
        };

        return $sessionId ? (int) $sessionId : null;
    }
}
