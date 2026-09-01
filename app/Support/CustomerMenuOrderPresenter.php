<?php

namespace App\Support;

use App\Helpers\Money;
use App\Models\Order;

/**
 * Compact, session-scoped order rounds shown inside the customer menu.
 *
 * The menu deliberately receives snapshots instead of live menu-item names:
 * an already-sent round must keep exactly what the diner ordered, even when
 * the catalogue is edited later. The full tracker owns change/cancel actions;
 * this payload only keeps the dining session visible while a new round is
 * being assembled.
 */
class CustomerMenuOrderPresenter
{
    public static function forSession(int $sessionId): array
    {
        $orders = Order::with('items.modifiers', 'items.exclusions')
            ->where('table_session_id', $sessionId)
            ->latest()
            ->get();
        $roundCount = $orders->count();

        return $orders->values()->map(function (Order $order, int $index) use ($roundCount) {
            return [
                'id' => $order->id,
                'number' => $order->number,
                'roundNumber' => $roundCount - $index,
                'status' => $order->status,
                'statusLabel' => $order->statusLabel(),
                'createdAgo' => $order->created_at?->diffForHumans(),
                'total' => Money::format($order->total),
                'totalRaw' => (float) $order->total,
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => self::localizedSnapshot($item),
                    'qty' => (int) $item->quantity,
                    'status' => $item->status,
                    'modifiers' => $item->modifiers
                        ->map(fn ($modifier) => self::localizedSnapshot($modifier))
                        ->filter()
                        ->values()
                        ->all(),
                    'exclusions' => $item->exclusions->pluck('name_snapshot')->filter()->values()->all(),
                ])->values()->all(),
            ];
        })->all();
    }

    protected static function localizedSnapshot(object $row): ?string
    {
        return $row->name_snapshot ?? null;
    }
}
