<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds branch scoping to a model.
 *
 * What the trait does:
 *   1. Registers BranchScope as a global scope so reads are filtered to
 *      the active branch automatically.
 *   2. Auto-fills `branch_id` on creating a new record from the active
 *      branch context, if the caller didn't set it explicitly.
 *   3. Exposes a `branch()` BelongsTo relation.
 *
 * Apply this only to OPERATIONAL data that is per-branch (orders,
 * invoices, inventory_movements, shifts). Reference data shared across
 * branches (menu_items, suppliers, categories) must NOT use this trait.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function ($model) {
            if (empty($model->branch_id) && ($current = BranchContext::current())) {
                $model->branch_id = $current;
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
