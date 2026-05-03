<?php

namespace App\Policies;

use App\Models\User;

/**
 * Owner-level (SuperAdmin + Partner) users bypass via BasePolicy::before, so
 * by the time these per-method checks run, the acting `$user` is an Admin or
 * Manager (or a regular staffer who shouldn't reach here at all).
 *
 * The "target" guards on owner-level accounts stay belt-and-suspenders: even
 * if before() ever changes, a branch admin still can't edit a partner.
 */
class UserPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasAnyRole(['admin', 'manager']) || $user->id === $target->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function update(User $user, User $target): bool
    {
        // Branch admins/managers cannot touch owner-level accounts.
        if ($target->isOwnerLevel() && ! $user->isOwnerLevel()) {
            return false;
        }
        return $user->hasAnyRole(['admin', 'manager']) || $user->id === $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) return false;
        // Owner-level accounts are protected from deletion by anyone but
        // another owner-level user (which is gated by BasePolicy::before).
        if ($target->isOwnerLevel()) return false;
        return $user->isAdmin();
    }
}
