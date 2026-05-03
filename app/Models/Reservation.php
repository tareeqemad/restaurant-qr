<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A diner's booking for a specific branch + time slot.
 *
 * The model intentionally does NOT enforce capacity at write time — that's
 * the controller's job (it knows about the branch's open hours, table mix,
 * and overlap rules). This model only enforces lifecycle integrity:
 *
 *   - reference is auto-generated on creating
 *   - branch_id is auto-filled by BelongsToBranch from BranchContext
 *   - transitionTo() refuses any move that ReservationStatus::nextStates()
 *     does not allow, and stamps the relevant audit columns
 */
class Reservation extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'reference',
        'customer_id',
        'table_id',
        'party_size',
        'reserved_for',
        'duration_minutes',
        'status',
        'customer_notes',
        'internal_notes',
        'confirmed_by_user_id',
        'confirmed_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'cancelled_reason',
        'seated_at',
        'completed_at',
    ];

    protected $casts = [
        'reserved_for' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'seated_at'    => 'datetime',
        'completed_at' => 'datetime',
        'status'       => ReservationStatus::class,
    ];

    // ========== Lifecycle ==========

    protected static function booted(): void
    {
        static::creating(function (self $reservation) {
            if (empty($reservation->reference)) {
                $reservation->reference = static::generateReference();
            }
        });
    }

    /**
     * RV-XXXXXX where X is uppercase alphanumeric. Loops until unique to
     * avoid the (extremely unlikely) collision with an existing code.
     */
    public static function generateReference(): string
    {
        do {
            $code = 'RV-' . strtoupper(Str::random(6));
        } while (static::withoutGlobalScopes()->where('reference', $code)->exists());

        return $code;
    }

    // ========== Relations ==========

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * The (at-most-one) Review tied to this reservation. Named
     * `reviewRelation` to avoid colliding with the noun "review" in
     * controller-side eloquent expressions.
     */
    public function reviewRelation(): HasOne
    {
        return $this->hasOne(Review::class, 'reservation_id');
    }

    // ========== Scopes ==========

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('reserved_for', '>=', now())
            ->whereNotIn('status', [
                ReservationStatus::Cancelled->value,
                ReservationStatus::NoShow->value,
                ReservationStatus::Completed->value,
            ]);
    }

    public function scopeForDate(Builder $query, $date): Builder
    {
        return $query->whereDate('reserved_for', $date);
    }

    public function scopeWithStatus(Builder $query, ReservationStatus|string $status): Builder
    {
        return $query->where('status',
            $status instanceof ReservationStatus ? $status->value : $status);
    }

    // ========== State machine ==========

    /**
     * Move to a new status, validating against the allowed transitions
     * defined in ReservationStatus::nextStates(). Optional metadata is
     * applied as part of the transition (table_id, cancelled_reason, …).
     *
     * Returns the saved model so callers can chain: `$res->transitionTo(…)`.
     *
     * @param  array<string,mixed>  $meta
     */
    public function transitionTo(ReservationStatus $next, array $meta = [], ?User $actor = null): self
    {
        $current = $this->status;
        if (! in_array($next, $current->nextStates(), true)) {
            throw new \DomainException(
                "Reservation cannot move from {$current->value} to {$next->value}."
            );
        }

        $stamps = match ($next) {
            ReservationStatus::Confirmed => [
                'confirmed_at'         => now(),
                'confirmed_by_user_id' => $actor?->id,
            ],
            ReservationStatus::Cancelled => [
                'cancelled_at'         => now(),
                'cancelled_by_user_id' => $actor?->id,
            ],
            ReservationStatus::Seated    => ['seated_at' => now()],
            ReservationStatus::Completed => ['completed_at' => now()],
            default                      => [],
        };

        $this->fill($meta + $stamps);
        $this->status = $next;
        $this->save();

        return $this;
    }
}
