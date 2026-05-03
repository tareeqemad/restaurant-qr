<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'phone', 'email', 'contact_person', 'address', 'notes', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    /**
     * Branches this supplier serves. Many-to-many via `branch_supplier`.
     * If empty, the supplier is considered "global" (legacy/no restriction).
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_supplier')
            ->withTimestamps();
    }

    /**
     * Scope: only suppliers that serve the given branch (or have no branch
     * restriction at all — backwards-compat with installs predating the
     * branch_supplier pivot).
     *
     *   Supplier::servingBranch(1)->get();
     *
     * Owner-level views can skip this scope to see all suppliers.
     */
    public function scopeServingBranch(Builder $query, int $branchId): Builder
    {
        return $query->where(function ($q) use ($branchId) {
            $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId))
              // Defensive: also include suppliers with NO branch links so
              // a fresh-from-the-DB supplier doesn't disappear from any
              // branch's dropdown until an admin assigns it.
              ->orWhereDoesntHave('branches');
        });
    }
}
