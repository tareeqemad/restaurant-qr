<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'name', 'label', 'description', 'is_system', 'display_order'];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    // ========== Relations ==========

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /**
     * Owning branch — null for global role templates managed by Super Admin.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ========== Scopes ==========

    /**
     * Global role templates (no owning branch) — managed by Super Admin and
     * available for any branch to use as-is.
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('branch_id');
    }

    /**
     * Roles owned by a specific branch (its customized instances).
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Roles visible to a branch: its own customizations + the global templates.
     */
    public function scopeAvailableTo(Builder $query, int $branchId): Builder
    {
        return $query->where(function (Builder $q) use ($branchId) {
            $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
        });
    }

    // ========== Helpers ==========

    public function isGlobal(): bool
    {
        return $this->branch_id === null;
    }
}
