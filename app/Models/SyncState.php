<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bookkeeping for one sync stream — its cursor and the outcome of the last run.
 * See docs/offline-sync-architecture.md and app/Sync.
 */
class SyncState extends Model
{
    protected $fillable = [
        'stream', 'direction', 'cursor',
        'last_synced_at', 'last_status', 'last_error', 'last_count',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_count' => 'integer',
    ];

    public static function for(string $stream, string $direction): self
    {
        return static::firstOrCreate(
            ['stream' => $stream],
            ['direction' => $direction],
        );
    }

    public function markOk(?string $cursor, int $count): void
    {
        $this->forceFill([
            'cursor' => $cursor ?? $this->cursor,
            'last_synced_at' => now(),
            'last_status' => 'ok',
            'last_error' => null,
            'last_count' => $count,
        ])->save();
    }

    public function markOffline(): void
    {
        $this->forceFill(['last_status' => 'offline'])->save();
    }

    public function markError(string $message): void
    {
        $this->forceFill([
            'last_status' => 'error',
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();
    }
}
