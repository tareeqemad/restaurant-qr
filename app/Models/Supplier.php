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

    protected $fillable = [
        'name', 'phone', 'email', 'contact_person', 'address', 'notes', 'active',
        'lead_time_days', 'payment_terms_days', 'minimum_order_amount', 'delivery_days',
    ];

    protected $casts = [
        'active' => 'boolean',
        'minimum_order_amount' => 'decimal:4',
        'delivery_days' => 'array',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    /**
     * Branches this supplier serves. Many-to-many via `branch_supplier`.
     * Every supplier must be linked to at least one branch — the create/
     * edit flow enforces this, so there are no "global" suppliers.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_supplier')
            ->withTimestamps();
    }

    /**
     * Scope: only suppliers explicitly linked to the given branch.
     *
     *   Supplier::servingBranch(1)->get();
     *
     * Owner-level views can skip this scope to see all suppliers.
     */
    public function scopeServingBranch(Builder $query, int $branchId): Builder
    {
        return $query->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
    }
}
