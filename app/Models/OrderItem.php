<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'menu_item_id', 'station_id', 'name_snapshot', 'name_en_snapshot',
        'quantity', 'unit_price', 'modifiers_total', 'subtotal', 'notes',
        'course', 'fire_order', 'status',
        'prepared_by_user_id', 'served_by_user_id', 'cancelled_by_user_id', 'cancelled_reason',
        'approved_at', 'prep_started_at', 'ready_at', 'served_at', 'cancelled_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'modifiers_total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'approved_at' => 'datetime',
        'prep_started_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    public function statusLabel(): string
    {
        return OrderItemStatus::tryFrom($this->status)?->label() ?? $this->status;
    }

    public function statusColor(): string
    {
        return OrderItemStatus::tryFrom($this->status)?->color() ?? 'secondary';
    }
}
