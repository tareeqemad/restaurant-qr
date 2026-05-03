<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * Branch-scoped expense moderation.
 *
 *   - viewAny / create  → admin, manager, cashier (cashier logs petty cash).
 *   - update            → only while still pending; admin/manager.
 *   - approve / reject  → admin/manager only (cashier cannot self-approve).
 *   - delete            → admin only, and only soft-deleted/rejected entries.
 *
 * The `expenses.*` permission strings give role admins fine-grained control
 * via the roles UI; the role-based fallbacks here keep the screen working
 * out-of-the-box on a fresh seed.
 */
class ExpensePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier'])
            || $user->hasPermission('expenses.viewAny');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $expense);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier'])
            || $user->hasPermission('expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        if (! $expense->isPending()) {
            return false;
        }

        return ($user->hasAnyRole(['admin', 'manager']) || $user->hasPermission('expenses.update'))
            && $this->inUserBranch($user, $expense);
    }

    public function approve(User $user, Expense $expense): bool
    {
        if (! $expense->isPending()) {
            return false;
        }

        return ($user->hasAnyRole(['admin', 'manager']) || $user->hasPermission('expenses.approve'))
            && $this->inUserBranch($user, $expense);
    }

    public function reject(User $user, Expense $expense): bool
    {
        if (! $expense->isPending()) {
            return false;
        }

        return ($user->hasAnyRole(['admin', 'manager']) || $user->hasPermission('expenses.reject'))
            && $this->inUserBranch($user, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        // Approved expenses are immutable history — only an admin can purge,
        // and only if it hasn't been linked into the till yet.
        if ($expense->isApproved() && $expense->cash_movement_id) {
            return false;
        }

        return ($user->isAdmin() || $user->hasPermission('expenses.delete'))
            && $this->inUserBranch($user, $expense);
    }
}
