<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order, public string $previousStatus) {}

    public function broadcastOn(): array
    {
        // Staff channels are PRIVATE (auth via routes/channels.php).
        // Customer session channel stays public (token is the capability).
        $channels = [
            new PrivateChannel('waiters'),
            new PrivateChannel('cashiers'),
        ];

        if ($this->order->tableSession) {
            $channels[] = new Channel('session.'.$this->order->tableSession->token);
        }

        foreach ($this->order->items->pluck('station.code')->filter()->unique() as $code) {
            $channels[] = new PrivateChannel('station.'.$code);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'number' => $this->order->number,
            'table_number' => $this->order->table?->number,
            'previous_status' => $this->previousStatus,
            'status' => $this->order->status,
            'status_label' => $this->order->statusLabel(),
        ];
    }
}
