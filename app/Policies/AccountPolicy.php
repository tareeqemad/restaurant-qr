<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Chart-of-accounts permissions. Pure permission-based — no role
 * fallback. The seeder + the 2026_05_31_120000 migration both grant
 * the chart_of_accounts.* set to admin + manager. Cashier is left
 * out by design (they have no business browsing the chart from the
 * till).
 *
 * System accounts get extra protection at the SERVICE layer, not
 * here — the AccountService refuses to deactivate or change the
 * code on a row with `is_system=true` even when this policy says yes.
 */
class AccountPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('chart_of_accounts.viewAny');
    }

    public function view(User $user, Account $account): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('chart_of_accounts.create');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasPermission('chart_of_accounts.update');
    }

    public function delete(User $user, Account $account): bool
    {
        // Even WITH the permission, system accounts and accounts with
        // history can't be deleted. Surfacing this at the policy layer
        // keeps the UI cleanly hiding the delete button when irrelevant.
        return $user->hasPermission('chart_of_accounts.delete')
            && $account->isDeletable();
    }
}
