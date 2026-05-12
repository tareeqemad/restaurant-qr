<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDiscount extends Model
{
    use BelongsToBranch, HasFactory;

    // branch_id added May 2026 (migration 2026_05_10_230000) — derive
    // from order.branch_id when creating; OrderDiscountService passes it.
    protected $fillable = ['branch_id', 'order_id', 'discount_id', 'name_snapshot', 'type', 'value', 'amount', 'category_lookup_id', 'reason', 'applied_by_user_id'];

    protected $casts = [
        'value' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'applied_by_user_id');
    }

    public function categoryLookup(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'category_lookup_id');
    }

    public function categoryLabel(): string
    {
        return $this->categoryLookup?->label ?? 'أخرى';
    }
}
