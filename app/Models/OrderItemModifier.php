<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends Model
{
    use HasFactory;

    protected $fillable = ['order_item_id', 'modifier_id', 'name_snapshot', 'price_delta', 'price_delta_original'];

    protected $casts = [
        'price_delta'          => 'decimal:2',
        'price_delta_original' => 'decimal:2',
    ];

    /**
     * "Was this modifier discounted by a promo?" — used by reporting and
     * the journal entry to recognise the savings as a real sales discount
     * rather than a silently-reduced revenue line.
     */
    public function wasDiscounted(): bool
    {
        return $this->price_delta_original !== null
            && (float) $this->price_delta_original > (float) $this->price_delta;
    }

    public function modifierSavings(): float
    {
        if (! $this->wasDiscounted()) return 0.0;
        return round((float) $this->price_delta_original - (float) $this->price_delta, 2);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
