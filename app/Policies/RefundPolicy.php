<?php

namespace App\Policies;

use App\Models\Refund;
use App\Models\User;

class RefundPolicy
{
    /** Viewing refunds — needs cashier+ access */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->viewAny($user);
    }

    /** Creating refunds — typically cashier or above */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']);
    }

    /** Cancelling/completing pending refunds — manager+ only */
    public function complete(User $user, Refund $refund): bool
    {
        return $refund->status === 'pending'
            && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    public function cancel(User $user, Refund $refund): bool
    {
        return $refund->status === 'pending'
            && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
}
