<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class BasePolicy
{
    /**
     * Global owner-level bypass + suspended-user kill switch.
     *
     *   - Owner-level (SuperAdmin + Partner) → true (skips every per-method
     *     check). The two roles are treated identically for business-level
     *     authorization; system-level UIs gate them separately when needed.
     *   - Suspended  → false (denies everything outright).
     *   - Otherwise  → null (let the specific method decide).
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isOwnerLevel()) {
            return true;
        }
        if ($user->isSuspended()) {
            return false;
        }
        return null;
    }

    /**
     * True if the user is a member of the model's owning branch.
     *
     * Resolution order:
     *   1. The model carries a `branch_id` column directly (most aggregate
     *      roots). Use it.
     *   2. The model is a child that exposes `invoice` or `order` — inherit
     *      the branch from that parent. This covers Payment, Refund,
     *      InvoiceSplit, and similar children that intentionally don't
     *      duplicate branch_id.
     *   3. Anything else → deny. We refuse to authorize against an
     *      untraceable branch context: better a false negative than a
     *      cross-branch leak.
     *
     * Super-admin bypass is handled by `before()` — by the time this method
     * runs, the user is a regular branch staffer.
     */
    protected function inUserBranch(User $user, ?Model $model): bool
    {
        if (! $model) {
            return false;
        }

        $branchId = $this->resolveBranchId($model);
        if ($branchId === null) {
            return false;
        }

        return $user->belongsToBranch((int) $branchId);
    }

    /**
     * Walk known parent relationships to find a branch_id. Returns null if
     * no branch context can be established.
     */
    protected function resolveBranchId(Model $model): ?int
    {
        if (isset($model->branch_id)) {
            return (int) $model->branch_id;
        }

        foreach (['invoice', 'order', 'shift', 'purchaseOrder', 'stockCount'] as $relation) {
            if (method_exists($model, $relation) && ($parent = $model->{$relation})) {
                if (isset($parent->branch_id)) {
                    return (int) $parent->branch_id;
                }
            }
        }

        return null;
    }
}
