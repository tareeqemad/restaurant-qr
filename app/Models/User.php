<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'name_en',
        'username',
        'email',
        'phone',
        'avatar',
        'role',
        'station_id',
        'status',
        'suspended_reason',
        'last_login_at',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ========== Relations ==========

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission')
            ->withPivot('granted');
    }

    /**
     * All branches the user is a member of.
     *
     * Pivot used to carry an optional `role_id` for per-branch role
     * overrides; that concept was removed in May 2026 (the team always
     * relied on the global `users.role`, the override added confusion).
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')
            ->withPivot(['is_primary', 'joined_at'])
            ->withTimestamps();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function activeShift()
    {
        return $this->hasOne(Shift::class)->where('status', 'open')->latest('opened_at');
    }

    public function approvedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'approved_by_user_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * The user's currently-open attendance, if any. The lookup intentionally
     * bypasses BranchScope: a staff member must be able to clock out even
     * if they're now switched into a different branch (e.g., a regional
     * manager who started at branch A and is now in B).
     */
    public function openAttendance(): ?Attendance
    {
        return \App\Support\BranchContext::unscoped(
            fn () => $this->attendances()->open()->latest('clock_in_at')->first()
        );
    }

    // ========== Role helpers ==========

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin->value;
    }

    public function isPartner(): bool
    {
        return $this->role === UserRole::Partner->value;
    }

    /**
     * "Owner-level" = sees every branch's data without an explicit assignment.
     * Covers SuperAdmin (technical owner) AND Partner (business owner). Always
     * prefer this over `isSuperAdmin()` for branch-filter bypass logic — using
     * `isSuperAdmin()` directly silently excludes partners from their own
     * data, which is the bug class this helper exists to prevent.
     */
    public function isOwnerLevel(): bool
    {
        return in_array($this->role, UserRole::ownerRoles(), true);
    }

    /**
     * "Management-level" = roles that operate the multi-branch oversight
     * surfaces (overview, live-monitor, branch dashboard). Includes owner-
     * level (sees all branches) and the per-branch admin/manager (sees only
     * their assigned branches). Floor staff (waiter/chef/cashier) are
     * intentionally excluded — those screens aren't for them.
     */
    public function isManagementLevel(): bool
    {
        return $this->hasAnyRole([
            UserRole::SuperAdmin->value,
            UserRole::Partner->value,
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin->value;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager->value;
    }

    public function isWaiter(): bool
    {
        return $this->role === UserRole::Waiter->value;
    }

    public function isChef(): bool
    {
        return $this->role === UserRole::Chef->value;
    }

    public function isBartender(): bool
    {
        return $this->role === UserRole::Bartender->value;
    }

    public function isCashier(): bool
    {
        return $this->role === UserRole::Cashier->value;
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    // ========== Branch helpers ==========

    /**
     * The user's home branch (the one marked is_primary in the pivot).
     */
    public function primaryBranch(): ?Branch
    {
        return $this->branches()->wherePivot('is_primary', true)->first();
    }

    /**
     * The branch the user is *currently working in* — the one BranchScope is
     * filtering against. Resolves through:
     *   1. The runtime BranchContext (set by SetActiveBranch middleware from
     *      the user's session pick or, for owner-level users, the active
     *      branch they switched into).
     *   2. The user's primary branch as a fallback (e.g. early in a request
     *      before the middleware has run, or in a CLI/job context).
     *   3. null if nothing is bound and the user has no primary branch.
     *
     * Memoized on the model instance so callers can hit it freely from
     * controllers, blades and policies without N+1 queries.
     */
    public function currentBranch(): ?Branch
    {
        if (array_key_exists('currentBranch', $this->relations ?? [])) {
            return $this->relations['currentBranch'];
        }

        $branch = null;
        if ($id = \App\Support\BranchContext::current()) {
            $branch = Branch::find($id);
        }
        $branch ??= $this->primaryBranch();

        $this->setRelation('currentBranch', $branch);
        return $branch;
    }

    /**
     * Branch IDs this user is allowed to see data for.
     *
     * Owner-level users get every active branch (they own the business).
     * Everyone else gets the branches they are explicitly assigned to via
     * the branch_user pivot — this is the source of truth that drives
     * branch-scoped admin screens (partner overview, live monitor, etc.)
     * so floor managers never see another branch's numbers.
     *
     * @return array<int,int>
     */
    public function accessibleBranchIds(): array
    {
        if ($this->isOwnerLevel()) {
            return Branch::active()->orderBy('display_order')->pluck('id')->all();
        }

        return $this->branches()->pluck('branches.id')->all();
    }

    /**
     * True if the user is a member of the given branch.
     * Owner-level users (Super Admin + Partner) always pass.
     */
    public function belongsToBranch(int $branchId): bool
    {
        if ($this->isOwnerLevel()) {
            return true;
        }

        return $this->branches()->where('branches.id', $branchId)->exists();
    }

    // roleInBranch() removed in May 2026 — per-branch role overrides
    // were dropped (see migration 2026_05_10_220000). The user's role
    // is always the global `$this->role` (UserRole enum).


    public function getRoleLabelAttribute(): string
    {
        return UserRole::tryFrom($this->role)?->label() ?? $this->role;
    }

    // ========== Status ==========

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canLogin(): bool
    {
        return $this->status === 'active';
    }

    public function canAccessAdmin(): bool
    {
        return $this->isActive() && in_array($this->role, [
            UserRole::SuperAdmin->value,
            UserRole::Partner->value,
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Waiter->value,
            UserRole::Chef->value,
            UserRole::Bartender->value,
            UserRole::Cashier->value,
        ], true);
    }

    /**
     * True if the user is allowed to view/manage the given station screen.
     *
     * Authority order (each one short-circuits):
     *   1. Super admin + owner-level roles bypass.
     *   2. Explicit permission `station.{code}.view` on the user's role
     *      (auto-created for every Station by StationObserver). This is the
     *      primary gate — admins assign it per-role from the normal
     *      roles edit page.
     *   3. Legacy fallback: the user's assigned station_id matches the code.
     *      Kept so existing chef/bartender accounts keep working even if a
     *      new role is added without the explicit permission.
     */
    public function canAccessStation(string $stationCode): bool
    {
        if ($this->hasAnyRole([UserRole::SuperAdmin->value, UserRole::Partner->value, UserRole::Admin->value, UserRole::Manager->value])) {
            return true;
        }

        if ($this->hasPermission('station.'.$stationCode.'.view')) {
            return true;
        }

        if ($this->station && $this->station->code === $stationCode) {
            return true;
        }

        return false;
    }

    // ========== Permission helpers ==========

    public function hasPermission(string $name): bool
    {
        // Owner-level (SuperAdmin + Partner) bypass all permission checks —
        // they own the business and need every screen by default. Per-method
        // policies still narrow this for system-level surfaces (subscription,
        // dev tools) when those exist.
        if ($this->isOwnerLevel()) {
            return true;
        }

        $direct = $this->permissions()->where('name', $name)->first();
        if ($direct) {
            return (bool) $direct->pivot->granted;
        }

        // Role-based permissions
        $role = Role::where('name', $this->role)->with('permissions')->first();
        return $role?->permissions->contains('name', $name) ?? false;
    }

    // ========== Accessors ==========

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar && str_starts_with($this->avatar, 'http')) {
            return $this->avatar;
        }
        if ($this->avatar && file_exists(public_path('storage/'.$this->avatar))) {
            return asset('storage/'.$this->avatar);
        }
        // Neutral person silhouette — inline SVG, no broken-image flash.
        return \App\Helpers\Brand::defaultAvatarDataUri();
    }
}
