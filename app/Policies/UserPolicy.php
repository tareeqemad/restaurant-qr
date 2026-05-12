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
        // Self-edit always allowed (subject to ProfileController gates).
        if ($user->id === $target->id) {
            return $user->hasAnyRole(['admin', 'manager']);
        }

        // Rank guard — never let a lower-rank actor edit a peer-or-higher
        // target. Without this an Admin could edit another Admin (or, with
        // the bypass at line 9, edit a SuperAdmin) and rotate their password
        // / change their role / suspend them.
        //
        // Hierarchy = the inverse of UserRole::grantableBy: an actor can
        // only EDIT users whose role they could ALSO have GRANTED.
        $grantable = \App\Enums\UserRole::grantableBy($user);
        if (! in_array($target->role, $grantable, true)) {
            return false;
        }

        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) return false;

        // Same rank-based guard as update — you can only delete users at or
        // below your own grantable level.
        $grantable = \App\Enums\UserRole::grantableBy($user);
        if (! in_array($target->role, $grantable, true)) return false;

        // Owner-level accounts are protected from deletion by anyone but
        // another owner-level user (which is gated by BasePolicy::before).
        if ($target->isOwnerLevel()) return false;

        return $user->isAdmin();
    }
}
