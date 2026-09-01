<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy extends BasePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuspended()) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isOwnerLevel();
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Role $role): bool
    {
        return $user->isSuperAdmin()
            && $role->isGlobal()
            && ! in_array($role->name, ['super_admin', 'partner'], true);
    }

    public function delete(User $user, Role $role): bool
    {
        return false;
    }
}
