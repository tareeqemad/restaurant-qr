<?php

namespace App\Policies;

use App\Models\Lookup;
use App\Models\User;

/**
 * Lookups are configuration — only super-admin/admin should touch them.
 * (Super admin bypass is handled by BasePolicy::before.)
 *
 * `is_system` rows are protected from hard delete at the model layer
 * via the controller — the policy only gates *who* can attempt the
 * action; *what* they're allowed to do to a system row is enforced
 * separately so the same admin UI can still rename them.
 */
class LookupPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('lookups.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('lookups.create');
    }

    public function update(User $user, Lookup $lookup): bool
    {
        return $user->isAdmin() || $user->hasPermission('lookups.update');
    }

    public function delete(User $user, Lookup $lookup): bool
    {
        // Even admin can't hard-delete a system row — controller catches
        // this and returns a 422; the policy only governs *attempting*.
        return $user->isAdmin() || $user->hasPermission('lookups.delete');
    }
}
