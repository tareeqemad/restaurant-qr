<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'user_id',
        'clock_in_at',
        'clock_out_at',
        'break_minutes',
        'worked_minutes',
        'notes',
        'source',
        'edited_by_user_id',
    ];

    protected $casts = [
        'clock_in_at'   => 'datetime',
        'clock_out_at'  => 'datetime',
        'break_minutes' => 'integer',
        'worked_minutes'=> 'integer',
    ];

    // ========== Relations ==========

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }

    // ========== Scopes ==========

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('clock_out_at');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('clock_out_at');
    }

    public function scopeForDate(Builder $query, $date): Builder
    {
        return $query->whereDate('clock_in_at', $date);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ========== State ==========

    public function isOpen(): bool
    {
        return $this->clock_out_at === null;
    }

    /**
     * Close this attendance, computing worked_minutes net of breaks.
     * Idempotent: a no-op if already closed (caller should still be
     * defensive against the race between a self-service double-click
     * and a manager forcing a close).
     */
    public function clockOut(\DateTimeInterface $at = null): self
    {
        if (! $this->isOpen()) {
            return $this;
        }

        $at = $at ?? now();
        $minutes = (int) round(
            $this->clock_in_at->diffInSeconds($at) / 60
        ) - (int) $this->break_minutes;

        $this->update([
            'clock_out_at'   => $at,
            'worked_minutes' => max(0, $minutes),
        ]);

        return $this;
    }

    /**
     * Live-computed minutes for an open shift, or stored worked_minutes
     * for a closed one — used by both the staff topbar widget and the
     * admin index ("worked: 4h 30m").
     */
    public function effectiveMinutes(): int
    {
        if (! $this->isOpen()) {
            return (int) $this->worked_minutes;
        }
        $mins = (int) round($this->clock_in_at->diffInSeconds(now()) / 60)
              - (int) $this->break_minutes;
        return max(0, $mins);
    }

    public function durationLabel(): string
    {
        $m = $this->effectiveMinutes();
        $h = intdiv($m, 60);
        $r = $m % 60;
        if ($h === 0) return "{$r} د";
        if ($r === 0) return "{$h} س";
        return "{$h} س {$r} د";
    }
}
