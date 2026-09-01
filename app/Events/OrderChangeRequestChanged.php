<?php

namespace App\Events;

use App\Models\OrderChangeRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderChangeRequestChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public OrderChangeRequest $changeRequest) {}

    public function broadcastOn(): array
    {
        $branchId = $this->changeRequest->branch_id;
        $channels = [
            new PrivateChannel('branch.'.$branchId.'.waiters'),
            new PrivateChannel('branch.'.$branchId.'.cashiers'),
            new PrivateChannel('owners'),
        ];

        if ($token = $this->changeRequest->order?->tableSession?->token) {
            $channels[] = new Channel('session.'.$token);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.change_request';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->changeRequest->id,
            'order_id' => $this->changeRequest->order_id,
            'order_number' => $this->changeRequest->order?->number,
            'type' => $this->changeRequest->type,
            'status' => $this->changeRequest->status,
        ];
    }
}
