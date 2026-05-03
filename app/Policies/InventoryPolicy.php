<?php

namespace App\Policies;

use App\Models\User;

class InventoryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'chef', 'bartender'])
            || $user->hasPermission('inventory.viewAny')
            || $user->hasPermission('ingredients.viewAny');
    }

    public function manage(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('inventory.manage');
    }
}
