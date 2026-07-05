<?php

namespace App\Events;

use App\Models\PendingTransfer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A bank transfer was just claimed (by the diner, waiter, or cashier) and is
 * now waiting for the cashier to confirm it against the bank. Broadcast so the
 * cashier dashboard lights the amber strip AND chimes the moment it lands,
 * instead of waiting for the next 10s poll.
 *
 * Always dispatched via App\Helpers\SafeBroadcast so a downed Reverb can't
 * break the customer/waiter/cashier write path — the strip still appears on
 * the next poll either way.
 */
class PendingTransferDeclared implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PendingTransfer $transfer) {}

    public function broadcastOn(): array
    {
        // Cashiers act on it; waiters like to know their table's diner paid.
        // Both channels are already authorized in routes/channels.php.
        return [new PrivateChannel('cashiers'), new PrivateChannel('waiters')];
    }

    public function broadcastAs(): string
    {
        return 'pending_transfer.declared';
    }

    public function broadcastWith(): array
    {
        return [
            'id'            => $this->transfer->id,
            'amount'        => (float) $this->transfer->amount,
            'sender_name'   => $this->transfer->sender_name,
            'table_number'  => $this->transfer->tableSession?->table?->number,
            'customer_name' => $this->transfer->customer_name_snapshot,
        ];
    }
}
