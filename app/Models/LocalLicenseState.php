<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalLicenseState extends Model
{
    protected $fillable = [
        'license_key',
        'payload',
        'signature',
        'status',
        'starts_at',
        'expires_at',
        'grace_ends_at',
        'max_branches',
        'last_checked_at',
        'last_server_time_at',
        'activated_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'starts_at' => 'date',
        'expires_at' => 'date',
        'grace_ends_at' => 'date',
        'max_branches' => 'integer',
        'last_checked_at' => 'datetime',
        'last_server_time_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['status' => 'missing']);
    }

    public function storeSignedPayload(string $licenseKey, array $payload, string $signature): void
    {
        $this->forceFill([
            'license_key' => $licenseKey,
            'payload' => $payload,
            'signature' => $signature,
            'status' => $payload['status'] ?? 'unknown',
            'starts_at' => $payload['starts_at'] ?? null,
            'expires_at' => $payload['expires_at'] ?? null,
            'grace_ends_at' => $payload['grace_ends_at'] ?? null,
            'max_branches' => $payload['max_branches'] ?? null,
            'last_checked_at' => now(),
            'last_server_time_at' => $payload['server_time'] ?? null,
            'activated_at' => $this->activated_at ?? now(),
            'last_error' => null,
        ])->save();
    }

    public function markProblem(string $status, string $message): void
    {
        $this->forceFill([
            'status' => $status,
            'last_checked_at' => now(),
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();
    }
}
