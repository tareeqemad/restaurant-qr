<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier']);
    }

    /**
     * Per-payment view — branch comes from the payment's parent invoice
     * via BasePolicy::resolveBranchId().
     */
    public function view(User $user, Payment $payment): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $payment);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier']);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            && $this->inUserBranch($user, $payment);
    }
}
