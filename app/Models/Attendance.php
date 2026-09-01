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
    use BelongsToBranch, HasFactory, SoftDeletes;

    public const SOURCE_SELF = 'self';

    public const SOURCE_MANAGER = 'manager_added';

    public const SOURCE_REVIEW = 'auto';

    public const STALE_AFTER_HOURS = 24;

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
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'break_minutes' => 'integer',
        'worked_minutes' => 'integer',
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

    public function scopeReviewRequired(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_REVIEW);
    }

    /**
     * Time ranges are company-wide for an employee. A waiter can belong to
     * multiple branches, but the same minutes cannot be credited twice.
     */
    public function scopeOverlapping(
        Builder $query,
        int $userId,
        \DateTimeInterface $startsAt,
        ?\DateTimeInterface $endsAt,
        ?int $ignoreId = null,
    ): Builder {
        $query->where('user_id', $userId)
            ->when($endsAt, fn (Builder $q) => $q->where('clock_in_at', '<', $endsAt))
            ->where(function (Builder $q) use ($startsAt) {
                $q->whereNull('clock_out_at')
                    ->orWhere('clock_out_at', '>', $startsAt);
            });

        if ($ignoreId) {
            $query->whereKeyNot($ignoreId);
        }

        return $query;
    }

    // ========== State ==========

    public function isOpen(): bool
    {
        return $this->clock_out_at === null;
    }

    public function needsReview(): bool
    {
        return $this->source === self::SOURCE_REVIEW;
    }

    /**
     * Close this attendance, computing worked_minutes net of breaks.
     * Idempotent: a no-op if already closed (caller should still be
     * defensive against the race between a self-service double-click
     * and a manager forcing a close).
     */
    public function clockOut(?\DateTimeInterface $at = null): self
    {
        if (! $this->isOpen()) {
            return $this;
        }

        $at = $at ?? now();
        $minutes = (int) round(
            $this->clock_in_at->diffInSeconds($at) / 60
        ) - (int) $this->break_minutes;

        $this->update([
            'clock_out_at' => $at,
            'worked_minutes' => max(0, $minutes),
        ]);

        return $this;
    }

    /**
     * A forgotten checkout is closed without inventing paid time. The zero
     * duration is a deliberate quarantine value: a manager must enter the
     * real checkout before the record can join payroll totals.
     */
    public function markNeedsReview(?string $note = null): self
    {
        if (! $this->isOpen()) {
            return $this;
        }

        $existing = trim((string) $this->notes);
        $message = trim((string) $note);

        $this->update([
            'clock_out_at' => $this->clock_in_at,
            'worked_minutes' => 0,
            'source' => self::SOURCE_REVIEW,
            'notes' => collect([$existing, $message])->filter()->implode("\n") ?: null,
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
        if ($this->needsReview()) {
            return 0;
        }

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
        if ($h === 0) {
            return "{$r} د";
        }
        if ($r === 0) {
            return "{$h} س";
        }

        return "{$h} س {$r} د";
    }
}
