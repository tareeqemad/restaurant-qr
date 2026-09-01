<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payments.viewAny');
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
        return $user->hasPermission('payments.create');
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->hasPermission('payments.void')
            && $this->inUserBranch($user, $payment);
    }

    public function void(User $user, Payment $payment): bool
    {
        if (! $this->inUserBranch($user, $payment)) {
            return false;
        }

        if ($user->hasPermission('payments.void')) {
            return true;
        }

        return $user->hasPermission('payments.void_own')
            && (int) $payment->received_by_user_id === (int) $user->id
            && $payment->paid_at?->isToday();
    }
}
