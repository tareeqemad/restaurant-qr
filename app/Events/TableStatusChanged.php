<?php

namespace App\Events;

use App\Models\Table;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a table transitions between states (available, occupied,
 * reserved, out_of_service). The admin tables board listens to this instead
 * of polling — so the UI only updates when something real happens.
 */
class TableStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Table $table,
        public string $previousStatus,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('waiters'),
            new PrivateChannel('cashiers'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'table.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->table->id,
            'number'          => $this->table->number,
            'previous_status' => $this->previousStatus,
            'status'          => $this->table->status,
        ];
    }
}
