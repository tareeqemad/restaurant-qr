<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Computes the expected "ready time" for an order based on the prep
 * times of its items.
 *
 * Formula:
 *   eta_minutes = max(item.prep_time_minutes for items where prep_time > 0)
 *               × (1 + buffer_pct / 100)
 *
 * Why MAX, not SUM:
 *   A real kitchen cooks items in parallel — the customer waits for the
 *   slowest one. Burger (12 min) + fries (5 min) + soda (1 min) is ready
 *   when the burger lands, not 18 minutes later. The buffer accounts for
 *   plating + final assembly + the slim chance of a slow station.
 *
 * Why exclude items with prep_time = 0:
 *   Drinks / pre-made items are instant. Including them just clutters
 *   the max() with zeros that don't matter. If they're the ONLY items
 *   in the order, eta_minutes falls back to a sane default below.
 *
 * Buffer comes from the admin Settings screen (key: prep_time_buffer_pct,
 * default 20). Admin can dial it up if the kitchen is overrun or down
 * for a fast operation.
 */
class OrderTimingService
{
    /**
     * Default buffer percent when the setting hasn't been configured.
     * 20% on top of max prep time is the industry rule of thumb.
     */
    public const DEFAULT_BUFFER_PCT = 20;

    /**
     * Default ETA when the order has zero items with prep_time > 0
     * (e.g., drinks-only order). Five minutes covers pour + serve.
     */
    public const FALLBACK_MINUTES = 5;

    /**
     * Compute how many minutes the order is expected to take from
     * "preparing" to "ready". Returns an integer minute count.
     *
     * Pass the order with `items.menuItem` eager-loaded for free perf.
     */
    public function estimateMinutes(Order $order): int
    {
        // Look at the prep_time of every item × its quantity? No — quantity
        // doesn't change time (parallel cooking again). Just the max base
        // prep time across the line items.
        $maxPrep = (int) $order->items
            ->map(fn ($i) => (int) ($i->menuItem?->prep_time_minutes ?? 0))
            ->filter(fn ($t) => $t > 0)          // skip instant items
            ->max();

        if ($maxPrep <= 0) {
            return self::FALLBACK_MINUTES;
        }

        $bufferPct = (int) Setting::get('prep_time_buffer_pct', self::DEFAULT_BUFFER_PCT);
        // Cap at sane bounds so a bad setting can't make the kitchen
        // show "ready in 4 hours" or "ready immediately".
        $bufferPct = max(0, min(200, $bufferPct));

        return (int) ceil($maxPrep * (1 + $bufferPct / 100));
    }

    /**
     * Compute the absolute timestamp the order should be ready by, anchored
     * to `prep_started_at` (the moment status flipped to "preparing").
     *
     * Returns null when prep hasn't started — the caller should not show
     * a countdown to the customer yet.
     */
    public function estimateReadyAt(Order $order): ?Carbon
    {
        if (! $order->prep_started_at) {
            return null;
        }
        return $order->prep_started_at->copy()->addMinutes($this->estimateMinutes($order));
    }

    /**
     * Stamp `prep_started_at` and `estimated_ready_at` on the order. Idempotent
     * — calling twice doesn't move the start time. Used by OrderService when
     * the order transitions into "preparing" status.
     *
     * Eager-loads items + menuItem if missing so the estimate is correct
     * even if the caller didn't pre-load.
     */
    public function stampPrepStart(Order $order): void
    {
        if ($order->prep_started_at) {
            return;   // already stamped, leave the baseline alone
        }

        // Guard: never stamp on orders that are already past the preparing
        // phase. Otherwise a stray item-touch in the kitchen (someone
        // re-cooking part of a delivered order) would set prep_started_at
        // hours after ready_at — producing nonsense timing data like a
        // -149 minute "actual" prep time on the archive.
        if (in_array($order->status, [
            \App\Enums\OrderStatus::Ready->value,
            \App\Enums\OrderStatus::Delivered->value,
            \App\Enums\OrderStatus::Completed->value,
            \App\Enums\OrderStatus::Cancelled->value,
        ], true)) {
            return;
        }

        // Make sure we have the items + menu items for the estimate.
        $order->loadMissing('items.menuItem');

        $now      = now();
        $minutes  = $this->estimateMinutes($order);
        $readyAt  = $now->copy()->addMinutes($minutes);

        $order->update([
            'prep_started_at'        => $now,
            'estimated_ready_at'     => $readyAt,
            // Mirror into the legacy `estimated_prep_minutes` integer so
            // anything reading the old field stays accurate.
            'estimated_prep_minutes' => $minutes,
        ]);
    }

    /**
     * Customer-facing remaining seconds for the countdown. Returns:
     *   - null  when prep hasn't started (no timer yet)
     *   - 0     when the ETA has elapsed but the order isn't ready
     *           (front-end shows "جاهز قريباً" instead of negative time)
     *   - >0    seconds remaining
     */
    public function remainingSeconds(Order $order): ?int
    {
        $eta = $this->estimateReadyAt($order);
        if ($eta === null) return null;
        return max(0, (int) round(now()->diffInSeconds($eta, false)));
    }

    /**
     * How long has prep been running, in minutes (integer). Used by the
     * kitchen board to compare against per-item prep_time for the
     * threshold coloring (green / amber / red).
     */
    public function elapsedMinutes(Order $order): ?int
    {
        if (! $order->prep_started_at) return null;
        return (int) max(0, round($order->prep_started_at->diffInMinutes(now())));
    }
}
