<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        // Staff channels are PRIVATE — the auth closures in routes/channels.php
        // gate them by role. The customer session channel stays public because
        // the session token itself is the capability.
        $channels = [
            new PrivateChannel('branch.'.$this->order->branch_id.'.waiters'),
            new PrivateChannel('owners'),
        ];
        if ($this->order->tableSession) {
            $channels[] = new Channel('session.'.$this->order->tableSession->token);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'number' => $this->order->number,
            'table_number' => $this->order->table?->number,
            'status' => $this->order->status,
            'total' => (float) $this->order->total,
            'items_count' => $this->order->items()->count(),
        ];
    }
}
