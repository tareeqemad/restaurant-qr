<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

/**
 * Branch-scoped attendance moderation. The self-service clock-in/out
 * endpoints are NOT gated by this policy — they're implicitly tied to
 * `auth()->user() === $attendance->user_id` and live on the staff side.
 * This policy gates the *manager's* index/edit/delete UI.
 */
class AttendancePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.viewAny');
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $attendance);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('attendance.create');
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $user->hasPermission('attendance.update')
            && $this->inUserBranch($user, $attendance);
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return $user->hasPermission('attendance.delete')
            && $this->inUserBranch($user, $attendance);
    }
}
