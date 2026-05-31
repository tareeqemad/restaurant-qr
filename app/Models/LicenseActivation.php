<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LicenseActivation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid',
        'license_id',
        'branch_uuid',
        'branch_id',
        'app_url',
        'status',
        'activated_at',
        'last_seen_at',
        'last_ip',
        'user_agent',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $activation): void {
            $activation->uuid ??= (string) Str::ulid();
            $activation->status ??= self::STATUS_ACTIVE;
            $activation->activated_at ??= now();
        });
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
