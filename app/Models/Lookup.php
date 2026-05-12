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
 * Branch scoping is HYBRID, not all-or-nothing:
 *   - Some groups are GLOBAL (`branch_id IS NULL`) — e.g.
 *     `expense_categories` shared across the company.
 *   - Other groups are PER-BRANCH (`branch_id IS NOT NULL`) — e.g.
 *     `zones` where each branch has its own indoor/outdoor/balcony list.
 * The trait isn't applied here because that would force every row to
 * carry a branch; instead `Lookup::for($group)` decides the right scope
 * by group.
 *
 * `Lookup::for($group)` is the dropdown helper — cached per-request so
 * a screen with N category badges doesn't run N queries.
 */
class Lookup extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Groups whose rows are scoped to a single branch. Anything not in this
     * list is treated as global (branch_id IS NULL).
     *
     * `zones` used to live here, but operations want them shared across
     * branches — "indoor / outdoor / VIP" mean the same thing everywhere
     * and re-creating them per-branch was just busywork. Tables still
     * carry their own zone_lookup_id so seating layouts stay independent
     * per branch; only the dictionary of zone names is global now.
     */
    public const PER_BRANCH_GROUPS = [];

    protected $fillable = [
        'branch_id', 'group', 'code', 'label', 'color', 'icon',
        'display_order', 'is_active', 'is_system',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'is_system'     => 'boolean',
        'display_order' => 'integer',
    ];

    // ─── Cache helpers ─────────────────────────────────────────────

    /**
     * Active rows for a group, ordered for display. Cached per group + branch
     * for the current request so dropdowns + badge lookups are cheap.
     *
     * For per-branch groups (e.g. `zones`), only rows belonging to the active
     * branch are returned. For global groups, only rows where `branch_id IS
     * NULL` are returned. This prevents accidental cross-branch leakage when
     * a Super-Admin views several branches in one session.
     */
    public static function for(string $group): Collection
    {
        $branchId = in_array($group, static::PER_BRANCH_GROUPS, true)
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
                ->when(in_array($group, static::PER_BRANCH_GROUPS, true),
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
        foreach (\App\Models\Branch::pluck('id') as $branchId) {
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

    /** All groups currently in use — drives the tab bar in the admin UI. */
    public static function knownGroups(): array
    {
        return [
            'expense_categories' => [
                'label'    => 'تصنيفات المصروفات',
                'icon'     => 'bi-cash-coin',
                'subtitle' => 'تظهر في قائمة "التصنيف" عند إضافة مصروف جديد.',
            ],
            'zones' => [
                'label'    => 'مناطق الطاولات',
                'icon'     => 'bi-geo-alt-fill',
                'subtitle' => 'مناطق المطعم (داخلي / VIP / تراس…) — تظهر عند إنشاء طاولة وفي شاشة الطاولات.',
            ],
            'discount_categories' => [
                'label'    => 'تصنيفات الخصومات',
                'icon'     => 'bi-percent',
                'subtitle' => 'تظهر في قائمة "السبب" عند إضافة خصم على فاتورة من شاشة الكاشير.',
            ],
            'waste_reasons' => [
                'label'    => 'أسباب الهدر',
                'icon'     => 'bi-trash3-fill',
                'subtitle' => 'تظهر في قائمة "السبب" عند تسجيل هدر مخزون. تساعدك في تحليل أسباب الهدر لاحقاً وفي تقارير المخزون.',
            ],
        ];
    }
}
