<?php

namespace App\Models;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Generic "soft enum" row — see CreateLookupsTable migration for the
 * design rationale. Operational tables FK to `id`; this column never
 * gets renamed, so business logic that branches on a specific row
 * should match by `code` (system rows) or by `id` if it was manually
 * configured.
 *
 * Group metadata and scope live in `lookup_groups`; the operational rows
 * stay here so changing a label never changes the foreign key stored by
 * expenses, discounts, tables or inventory movements.
 *
 * `Lookup::for($group)` is the dropdown helper — cached per-request so
 * a screen with N category badges doesn't run N queries.
 */
class Lookup extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id', 'group', 'code', 'label', 'color', 'icon',
        'display_order', 'is_active', 'is_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'display_order' => 'integer',
    ];

    // ─── Cache helpers ─────────────────────────────────────────────

    /**
     * Active rows for a group, ordered for display. Cached per group + branch
     * for the current request so dropdowns + badge lookups are cheap.
     *
     * For per-branch groups, only rows belonging to the active
     * branch are returned. For global groups, only rows where `branch_id IS
     * NULL` are returned. This prevents accidental cross-branch leakage when
     * a Super-Admin views several branches in one session.
     */
    public static function for(string $group): Collection
    {
        $perBranch = LookupGroup::isPerBranch($group);
        $branchId = $perBranch
            ? BranchContext::current()
            : null;

        $cacheKey = "lookups.active.{$group}.".($branchId ?? 'global');

        // Use the default cache driver (file/redis depending on env) so the
        // 5-minute TTL actually applies across requests. The previous
        // `Cache::driver('array')` lived only for one PHP request → every
        // page rebuilt the lookup. Default driver gives real cross-request
        // hits while `forget()` (called on CRUD) keeps it fresh.
        return Cache::remember($cacheKey, now()->addMinutes(5),
            fn () => static::query()
                ->where('group', $group)
                ->when($perBranch,
                    fn ($q) => $q->where('branch_id', $branchId),
                    fn ($q) => $q->whereNull('branch_id'),
                )
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * Bust the cache for a group — call after CRUD. Clears the global
     * variant + every branch-specific entry by iterating the active branch
     * set (the array-driver-era hardcoded `range(1, 50)` would silently
     * miss branch IDs > 50).
     */
    public static function forget(string $group): void
    {
        Cache::forget("lookups.active.{$group}.global");

        // Per-branch entries — iterate the actual branch IDs in the DB so
        // this scales beyond the legacy hardcoded 50 ceiling.
        foreach (Branch::pluck('id') as $branchId) {
            Cache::forget("lookups.active.{$group}.{$branchId}");
        }
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected static function booted(): void
    {
        // Keep the array cache in sync without forcing controllers to remember.
        $invalidate = function (Lookup $lookup) {
            static::forget($lookup->group);
        };
        static::saved($invalidate);
        static::deleted($invalidate);
        static::restored($invalidate);
    }

    // ─── Query scopes ──────────────────────────────────────────────

    public function scopeGroup(Builder $q, string $group): Builder
    {
        return $q->where('group', $group);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    // ─── Display helpers ───────────────────────────────────────────

    public function badgeStyle(): string
    {
        if (! $this->color) {
            return '';
        }
        // Hex → tinted background; named color → use as-is.
        if (str_starts_with($this->color, '#')) {
            return "background:{$this->color}1a;color:{$this->color};border:1px solid {$this->color}40;";
        }

        return "background:var(--bs-{$this->color}-bg-subtle);color:var(--bs-{$this->color});";
    }

    /** Group metadata is reference data; the constants page reads the same source. */
    public static function knownGroups(): array
    {
        return LookupGroup::catalogue()
            ->mapWithKeys(fn (LookupGroup $group) => [$group->code => [
                'label' => $group->label,
                'icon' => $group->icon ?: 'bi-list-ul',
                'subtitle' => $group->subtitle ?: '',
                'scope' => $group->scope,
            ]])
            ->all();
    }
}
