<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\TableSession;
use App\Models\User;

/**
 * Authorisation for cashier-applied order discounts. The per-amount cap
 * (how big a discount a given role may hand out) is enforced inside
 * OrderDiscountService against config/restaurant.php — keep it there.
 * This policy answers only "is this user allowed to touch the discount
 * surface at all", and "is the target row in their branch".
 */
class OrderDiscountPolicy extends BasePolicy
{
    /**
     * `$target` is the Order or TableSession being discounted. Class-level
     * checks (`authorize('apply', OrderDiscount::class)` from controllers /
     * Volt gates) pass no target and stay permission-only — they gate the UI
     * affordance before a target exists. OrderDiscountService passes the
     * resolved target, adding the branch wall (mirrors OrderPolicy's
     * inUserBranch): a cashier must belong to the ORDER's branch, otherwise
     * a multi-branch viewer could comp tickets on tills they don't work.
     */
    public function apply(User $user, Order|TableSession|null $target = null): bool
    {
        if (! $user->hasPermission('discounts.apply')) {
            return false;
        }

        return $target === null || $this->inUserBranch($user, $target);
    }

    public function remove(User $user, OrderDiscount $discount): bool
    {
        return $user->hasPermission('discounts.remove')
            && $this->inUserBranch($user, $discount->order);
    }
}
