<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'loyalty_customer_id', 'invoice_id', 'order_id',
        'type', 'points', 'cash_value', 'reason', 'user_id',
    ];

    protected $casts = [
        'points'     => 'integer',
        'cash_value' => 'decimal:4',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(LoyaltyCustomer::class, 'loyalty_customer_id'); }
    public function invoice(): BelongsTo  { return $this->belongsTo(Invoice::class); }
    public function order(): BelongsTo    { return $this->belongsTo(Order::class); }
    public function user(): BelongsTo     { return $this->belongsTo(User::class); }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'earn'   => 'ربح',
            'redeem' => 'استبدال',
            'adjust' => 'تعديل',
            'expire' => 'منتهية',
            'bonus'  => 'مكافأة',
            default  => $this->type,
        };
    }

    public function typeColor(): string
    {
        return match ($this->type) {
            'earn'   => 'success',
            'redeem' => 'accent',
            'bonus'  => 'primary',
            'expire' => 'muted',
            'adjust' => 'info',
            default  => 'secondary',
        };
    }
}
