<?php

namespace App\Policies;

use App\Models\OrderDiscount;
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
    public function apply(User $user): bool
    {
        return $user->hasPermission('discounts.apply');
    }

    public function remove(User $user, OrderDiscount $discount): bool
    {
        return $user->hasPermission('discounts.remove')
            && $this->inUserBranch($user, $discount->order);
    }
}
