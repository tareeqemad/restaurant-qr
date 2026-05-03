<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryAssignment extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'order_id',
        'driver_user_id',
        'provider',
        'status',
        'recipient_name',
        'recipient_phone',
        'address_snapshot',
        'delivery_fee',
        'distance_km',
        'delivery_notes',
        'failure_reason',
        'assigned_at',
        'picked_up_at',
        'en_route_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected $casts = [
        'delivery_fee' => 'decimal:2',
        'distance_km' => 'decimal:2',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'en_route_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }
}
