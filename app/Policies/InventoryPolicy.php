<?php

namespace App\Policies;

use App\Models\User;

class InventoryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'chef', 'bartender']);
    }

    public function manage(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
