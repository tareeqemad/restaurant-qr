<?php

namespace App\Policies;

use App\Models\Refund;
use App\Models\User;

class RefundPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier']);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $refund);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier']);
    }

    public function complete(User $user, Refund $refund): bool
    {
        return $refund->status === 'pending'
            && $user->hasAnyRole(['admin', 'manager'])
            && $this->inUserBranch($user, $refund);
    }

    public function cancel(User $user, Refund $refund): bool
    {
        return $refund->status === 'pending'
            && $user->hasAnyRole(['admin', 'manager'])
            && $this->inUserBranch($user, $refund);
    }
}
