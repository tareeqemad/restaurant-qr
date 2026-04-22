<?php

namespace App\Policies;

use App\Models\User;

class MenuPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function view(User $user, $model = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function update(User $user, $model = null): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function delete(User $user, $model = null): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function toggleAvailability(User $user, $model = null): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'chef', 'bartender']);
    }
}
