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
 * invoices and inventory_movements). Reference data shared across
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

    /**
     * Owner-level users (Super Admin / Partner) need to be able to open
     * a specific record by URL even if their currently-active branch is
     * a different one — e.g. a PO created under "view all branches" then
     * the user switched into Gaza, the PO still belongs to Khan Yunis.
     * Without this override the route binding goes through BranchScope,
     * fails to find the row, and the user gets a confusing 404 on a
     * record they themselves just created.
     *
     * Lists/queries that don't go through route binding still scope as
     * before — this only relaxes single-record lookups by ID/key.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // Use the staff guard explicitly. Portal routes default `auth()->user()`
        // to a Customer, and Customer doesn't
        // implement isOwnerLevel — calling it blew up the order-tracking page.
        $staff = \Illuminate\Support\Facades\Auth::guard('web')->user();
        if ($staff && $staff->isOwnerLevel()) {
            return \App\Support\BranchContext::unscoped(
                fn () => parent::resolveRouteBinding($value, $field)
            );
        }
        return parent::resolveRouteBinding($value, $field);
    }
}
