<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoicePaid implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function broadcastOn(): array
    {
        $channels = [new Channel('waiters'), new Channel('cashiers')];
        if ($this->invoice->tableSession) {
            $channels[] = new Channel('session.'.$this->invoice->tableSession->token);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'invoice.paid';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->invoice->id,
            'number' => $this->invoice->number,
            'total' => (float) $this->invoice->total,
            'paid_total' => (float) $this->invoice->paid_total,
            'balance' => (float) $this->invoice->balance,
            'status' => $this->invoice->status,
            'table_number' => $this->invoice->tableSession?->table?->number,
        ];
    }
}
