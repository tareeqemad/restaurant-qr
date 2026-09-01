<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderChangeRequest extends Model
{
    use BelongsToBranch, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'branch_id', 'order_id', 'order_item_id', 'replacement_order_item_id',
        'requested_by_customer_id', 'handled_by_user_id', 'type',
        'requested_quantity', 'request_note', 'status', 'disposition',
        'resolution_note', 'handled_at',
    ];

    protected $casts = [
        'requested_quantity' => 'decimal:2',
        'handled_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function replacementOrderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'replacement_order_item_id');
    }

    public function requestedByCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'requested_by_customer_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'change_item' => 'تعديل صنف',
            'cancel_item' => 'إلغاء صنف',
            'cancel_order' => 'إلغاء الطلب بالكامل',
            default => $this->type,
        };
    }
}
