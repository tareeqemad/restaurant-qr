<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_note_id', 'order_item_id', 'description', 'quantity',
        'revenue_amount', 'tax_amount', 'service_amount', 'delivery_amount', 'tip_amount',
        'total', 'disposition',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'revenue_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'service_amount' => 'decimal:4',
        'delivery_amount' => 'decimal:4',
        'tip_amount' => 'decimal:4',
        'total' => 'decimal:4',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
