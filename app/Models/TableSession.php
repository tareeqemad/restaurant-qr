<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TableSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'table_id', 'token', 'cover_count', 'status',
        'customer_name', 'customer_phone', 'opened_by_user_id', 'assigned_waiter_id',
        'opened_at', 'closed_at', 'last_activity_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->token)) {
                $m->token = Str::random(40);
            }
            if (empty($m->opened_at)) {
                $m->opened_at = now();
            }
            if (empty($m->last_activity_at)) {
                $m->last_activity_at = now();
            }
        });
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function assignedWaiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_waiter_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Invoice::class)->latest();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function touch($attribute = null): bool
    {
        $this->last_activity_at = now();
        return parent::touch($attribute);
    }
}
