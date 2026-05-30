<?php

namespace App\Policies;

use App\Models\MenuPromotion;
use App\Models\User;

/**
 * Menu-item promotion permissions. Pure permission-based (no role
 * fallback) — the seeder + migration both attach the perms to
 * admin/manager/cashier appropriately, so role checks would be
 * redundant noise.
 *
 * Branch scoping: a chain-wide promo (branch_id = null) is editable
 * by anyone with `promotions.update`, while a branch-specific promo
 * additionally requires the editor be in that branch (mirrors the
 * supplier / order patterns).
 */
class MenuPromotionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('promotions.viewAny');
    }

    public function view(User $user, MenuPromotion $promotion): bool
    {
        return $this->viewAny($user) && $this->branchOK($user, $promotion);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('promotions.create');
    }

    public function update(User $user, MenuPromotion $promotion): bool
    {
        return $user->hasPermission('promotions.update') && $this->branchOK($user, $promotion);
    }

    public function delete(User $user, MenuPromotion $promotion): bool
    {
        return $user->hasPermission('promotions.delete') && $this->branchOK($user, $promotion);
    }

    /**
     * Chain-wide (null branch) is everyone's; branch-specific is gated
     * to members of that branch.
     */
    protected function branchOK(User $user, MenuPromotion $promotion): bool
    {
        if ($promotion->branch_id === null) return true;
        return $this->inUserBranch($user, $promotion);
    }
}
