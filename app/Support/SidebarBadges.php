<?php

namespace App\Support;

use App\Enums\ReservationStatus;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\Order;
use App\Models\PendingTransfer;
use App\Models\Reservation;
use Illuminate\Support\Facades\Cache;

/**
 * Computes the four numeric badges shown in the admin sidebar
 * (open attendance / pending reservations / pending orders /
 * pending expenses) and caches the result per branch for 30s so
 * we don't pay 4 COUNT queries on every single page render.
 *
 * The cache TTL is short on purpose — these counts drive
 * "needs attention" badges, so even slightly stale (a few
 * seconds) is fine but minutes-stale would mislead operators.
 *
 * Each query is independently try/caught so a missing model /
 * misconfigured scope / db blip can't break the entire sidebar.
 */
class SidebarBadges
{
    /**
     * @return array{open_attendance:int, pending_reservations:int, pending_orders:int, pending_expenses:int, pending_transfers:int}
     */
    public static function counts(): array
    {
        $branchId = BranchContext::current() ?: 'all';
        $key      = "sidebar.badges.{$branchId}";

        return Cache::remember($key, 30, function () {
            return [
                'open_attendance'      => self::safeCount(fn () => Attendance::open()->count()),
                'pending_reservations' => self::safeCount(fn () => Reservation::withStatus(ReservationStatus::Pending)->count()),
                'pending_orders'       => self::safeCount(fn () => Order::where('status', 'pending')->count()),
                'pending_expenses'     => self::safeCount(fn () => Expense::pending()->count()),
                'pending_transfers'    => self::safeCount(fn () => PendingTransfer::pending()->count()),
            ];
        });
    }

    /**
     * Bust the cache for the active branch — call from observers when
     * the underlying data changes (a new pending order is placed, an
     * attendance is opened/closed, etc.) so the sidebar doesn't show
     * stale numbers for up to 30s.
     */
    public static function bust(): void
    {
        $branchId = BranchContext::current() ?: 'all';
        Cache::forget("sidebar.badges.{$branchId}");
    }

    protected static function safeCount(callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
