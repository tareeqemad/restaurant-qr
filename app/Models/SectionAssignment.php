<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToBranch;
use App\Support\BranchContext;
use Carbon\Carbon;
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
     * Ignore stale assignments whose user was disabled or no longer has the
     * waiter role. They may remain as historical data, but must never drive a
     * live floor plan, carry-forward, or a waiter's task feed.
     */
    public function scopeForRosterableWaiters(Builder $query): Builder
    {
        $branchId = BranchContext::current();

        return $query->whereHas('user', fn (Builder $users) => $users
            ->where('status', 'active')
            ->where('role', UserRole::Waiter->value)
            ->when($branchId !== null, fn (Builder $users) => $users
                ->whereHas('branches', fn (Builder $branches) => $branches
                    ->where('branches.id', $branchId))));
    }

    /**
     * How far back an old roster keeps applying when nobody set today's.
     * A week covers any normal closure; beyond it, staffing has almost
     * certainly changed and a stale plan is worse than no plan.
     */
    public const CARRY_FORWARD_DAYS = 7;

    /**
     * The service date whose roster is actually IN EFFECT on $date, in the
     * current branch context. Null = no roster applies at all.
     *
     * If $date has any assignment (for anyone), that day is authoritative — a
     * waiter missing from a day the manager DID set was left off on purpose.
     * If the day is completely empty, the manager forgot, and forgetting used
     * to silently dump every waiter onto the full floor mid-rush — so the most
     * recent roster within CARRY_FORWARD_DAYS keeps applying instead. The
     * boards and the roster screen both label a carried roster, and setting
     * (or clearing then re-setting) today always overrides it.
     */
    public static function effectiveDate($date = null): ?string
    {
        $target = $date ?? now()->toDateString();

        if (static::query()->forRosterableWaiters()->forDate($target)->exists()) {
            return $target;
        }

        $latest = static::query()
            ->forRosterableWaiters()
            ->whereDate('service_date', '<', $target)
            ->whereDate('service_date', '>=', Carbon::parse($target)->subDays(self::CARRY_FORWARD_DAYS)->toDateString())
            ->max('service_date');

        return $latest ? Carbon::parse($latest)->toDateString() : null;
    }

    /**
     * Zone ids this user covers on $date in the CURRENT branch context,
     * reading the roster effectiveDate() resolves to (today, or the most
     * recent one carried forward).
     *
     * Returns an empty array when no roster applies — the caller is expected
     * to fall back to the older session-claim behaviour so a restaurant that
     * never opens the assignment screen keeps working exactly as before.
     *
     * @return array<int, int>
     */
    public static function zoneIdsFor(int $userId, $date = null): array
    {
        $effective = static::effectiveDate($date);

        if ($effective === null) {
            return [];
        }

        return static::query()
            ->forRosterableWaiters()
            ->forUser($userId)
            ->forDate($effective)
            ->pluck('zone_lookup_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
