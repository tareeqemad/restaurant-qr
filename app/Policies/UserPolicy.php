<?php

namespace App\Policies;

use App\Models\User;

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
        if ($target->isSuperAdmin() && ! $user->isSuperAdmin()) return false;
        return $user->hasAnyRole(['admin', 'manager']) || $user->id === $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) return false;
        if ($target->isSuperAdmin()) return false;
        return $user->isAdmin();
    }
}
