<?php

namespace App\Policies;

use App\Models\User;

/**
 * Activity logs are global system-wide audit data — they reference
 * subjects from many domains (users, orders, branches, …) and don't
 * carry their own branch_id. Until we either backfill branch_id onto
 * the table or filter by polymorphic subject branch, treat them as a
 * Super-Admin-only resource so a branch manager doesn't see audit
 * entries from sibling branches.
 */
class ActivityLogPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        // Super admin is granted via BasePolicy::before(); branch admins/
        // managers used to see every log. That's a cross-branch leak.
        return false;
    }
}
