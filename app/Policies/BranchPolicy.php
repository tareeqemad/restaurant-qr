<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

/**
 * Branches are a global system resource — only Super Admin manages them.
 *
 * Non-super-admin users never need to "view" the branches admin pages: they
 * see the active branch via the in-header switcher (which queries
 * $user->branches directly without going through this policy).
 *
 * BasePolicy::before() returns true for super admin, so every gate below
 * naturally returns false for everyone else.
 */
class BranchPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Branch $branch): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Branch $branch): bool
    {
        return false;
    }

    public function delete(User $user, Branch $branch): bool
    {
        return false;
    }
}
