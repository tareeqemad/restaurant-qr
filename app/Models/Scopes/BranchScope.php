<?php

namespace App\Models\Scopes;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters operational queries to the active branch.
 *
 * Applied via the BelongsToBranch trait to models that own per-branch data
 * (orders, invoices, reservations, etc.). Reference data that is shared
 * across branches (menu_items, suppliers, categories) does NOT use this
 * trait.
 *
 * Filtering rules — applied in this order:
 *
 *   1. BranchContext::unscoped() flag set
 *      → no filter. Used by Super-Admin cross-branch reports and CLI
 *        maintenance tasks that explicitly opt out via the helper.
 *
 *   2. No active branch bound (e.g. CLI, queue worker without explicit
 *      context, or a Super Admin viewing a global "all branches" dashboard)
 *      → no filter. Application code that needs to scope MUST set the
 *        context first via BranchContext::set() or BranchContext::forBranch().
 *
 *   3. Active branch bound → filter to that branch — INCLUDING Super Admin.
 *
 * The Super Admin used to be unconditionally bypassed here. That was wrong:
 * once a super admin "switches into" a branch via the in-header switcher,
 * the rest of the UI must behave as if they were a regular branch user
 * (otherwise they'd see another branch's data leak into the screen they
 * thought they were filtering). Cross-branch views are now an explicit
 * opt-in via `BranchContext::unscoped()` (the global dashboard layer)
 * rather than an accidental side-effect of the auth role.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (BranchContext::isUnscoped()) {
            return;
        }

        $branchId = BranchContext::current();
        if ($branchId === null) {
            return;
        }

        $builder->where($model->getTable() . '.branch_id', $branchId);
    }
}
