<?php

namespace App\Support;

use App\Models\Order;

/**
 * Give every screen the same human meaning for an order inside a table visit.
 *
 * The database deliberately stores each submission as its own Order because
 * the kitchen needs an immutable execution ticket.  Diners and staff should
 * not have to understand that detail, so this presenter names it consistently
 * as «جولة 1 / إضافة جديدة» everywhere it is shown.
 */
final class OrderRoundContext
{
    /** @return array{number:?int,label:?string,isAddition:bool} */
    public static function for(Order $order): array
    {
        if (! $order->table_session_id) {
            return ['number' => null, 'label' => null, 'isAddition' => false];
        }

        $order->loadMissing('tableSession.orders');
        $rounds = $order->tableSession?->orders
            ?->sort(function (Order $left, Order $right) {
                $byTime = ($left->created_at?->getTimestamp() ?? 0)
                    <=> ($right->created_at?->getTimestamp() ?? 0);

                return $byTime !== 0 ? $byTime : ((int) $left->id <=> (int) $right->id);
            })
            ->values();

        $index = $rounds?->search(fn (Order $candidate) => (int) $candidate->id === (int) $order->id);
        $number = $index === false || $index === null ? null : $index + 1;

        return [
            'number' => $number,
            'label' => $number === null ? null : ($number === 1 ? 'الطلب الأول' : 'إضافة جديدة'),
            'isAddition' => ($number ?? 0) > 1,
        ];
    }
}
