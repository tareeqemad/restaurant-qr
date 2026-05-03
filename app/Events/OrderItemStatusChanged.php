<?php

namespace App\Events;

use App\Models\OrderItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderItemStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public OrderItem $item, public string $previousStatus) {}

    public function broadcastOn(): array
    {
        // Staff channels are PRIVATE (auth via routes/channels.php).
        $channels = [new PrivateChannel('waiters')];

        if ($this->item->station?->code) {
            $channels[] = new PrivateChannel('station.'.$this->item->station->code);
        }

        // Customer session channel stays public — token is the capability.
        if ($this->item->order->tableSession) {
            $channels[] = new Channel('session.'.$this->item->order->tableSession->token);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'item.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'item_id' => $this->item->id,
            'order_id' => $this->item->order_id,
            'order_number' => $this->item->order->number,
            'name' => $this->item->name_snapshot,
            'quantity' => (float) $this->item->quantity,
            'table_number' => $this->item->order->table?->number,
            'station' => $this->item->station?->code,
            'previous_status' => $this->previousStatus,
            'status' => $this->item->status,
            'status_label' => $this->item->statusLabel(),
        ];
    }
}
