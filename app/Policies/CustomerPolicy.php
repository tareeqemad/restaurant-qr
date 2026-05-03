<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Customers are GLOBAL (no branch_id) — see PROJECT_CONTEXT. So unlike
 * branch-scoped policies, there's no inUserBranch check here. The screen
 * surfaces every customer in the system; the per-branch context shows up
 * only on the show page (their reservations / reviews per branch).
 *
 * Roles: admin/manager have full access by default. Cashier can view to
 * help with phone-lookup at checkout, but can't edit/delete. Owners (the
 * super admin) bypass everything via BasePolicy::before().
 */
class CustomerPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier'])
            || $user->hasPermission('customers.viewAny');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('customers.update');
    }

    public function block(User $user, Customer $customer): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('customers.block');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->isAdmin() || $user->hasPermission('customers.delete');
    }
}
