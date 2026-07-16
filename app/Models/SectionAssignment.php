<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who covers which section (zone), on a given service date.
 *
 * This is the floor plan of RESPONSIBILITY, not of furniture: it answers "whose
 * tables are these tonight". TableSession::assigned_waiter_id still records who
 * actually served a given party (it gets stamped on order approval), but that's
 * a record of what happened — this is the plan set before service starts.
 *
 * Branch-scoped like every other operational model, so a Gaza manager can never
 * assign a Khan Younis section by accident.
 */
class SectionAssignment extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id', 'zone_lookup_id', 'user_id', 'service_date', 'created_by_user_id',
    ];

    protected $casts = [
        'service_date' => 'date',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The section. Zones are a GLOBAL lookup dictionary (group='zones'). */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'zone_lookup_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeForDate(Builder $query, $date = null): Builder
    {
        return $query->whereDate('service_date', $date ?? now()->toDateString());
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Zone ids this user covers on $date in the CURRENT branch context.
     *
     * Returns an empty array when nobody has been assigned — the caller is
     * expected to fall back to the older session-claim behaviour so a
     * restaurant that never opens the assignment screen keeps working exactly
     * as before.
     *
     * @return array<int, int>
     */
    public static function zoneIdsFor(int $userId, $date = null): array
    {
        return static::query()
            ->forUser($userId)
            ->forDate($date)
            ->pluck('zone_lookup_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
