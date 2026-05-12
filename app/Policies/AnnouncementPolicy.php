<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

/**
 * Branch-scoped marketing announcement permissions.
 *
 *   - viewAny / create / update     → admin, manager, or `announcements.*` permission
 *   - publish / unpublish           → same set; publishing fans out one
 *                                     notification per matched customer, so
 *                                     it's gated to the same level as create.
 *   - delete                        → admin only (hard delete archives history).
 *
 * `BasePolicy::before()` still grants owner-level a global pass, but in the
 * VIEW we additionally gate destructive buttons on the announcement state
 * (e.g., can't republish a published row) — same pattern documented in
 * restaurant-qr.md النمط ٤.
 */
class AnnouncementPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('announcements.viewAny');
    }

    public function view(User $user, Announcement $announcement): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $announcement);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('announcements.create');
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return ($user->hasAnyRole(['admin', 'manager'])
                || $user->hasPermission('announcements.update'))
            && $this->inUserBranch($user, $announcement);
    }

    public function publish(User $user, Announcement $announcement): bool
    {
        return ($user->hasAnyRole(['admin', 'manager'])
                || $user->hasPermission('announcements.publish'))
            && $this->inUserBranch($user, $announcement);
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return ($user->isAdmin() || $user->hasPermission('announcements.delete'))
            && $this->inUserBranch($user, $announcement);
    }
}
