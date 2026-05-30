<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LicensePayment extends Model
{
    protected $fillable = [
        'uuid',
        'license_id',
        'period_months',
        'amount',
        'paid_at',
        'method',
        'reference',
        'received_by_user_id',
        'starts_at',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'period_months' => 'integer',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'starts_at' => 'date',
        'expires_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            $payment->uuid ??= (string) Str::ulid();
        });
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
