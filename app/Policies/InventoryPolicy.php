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

    /*
     * The inventory controllers (IngredientController, StorageLocationController,
     * StockCountController, etc.) all authorize against Ingredient::class with
     * a small set of abilities — viewAny, create, update, delete, manage.
     * Without explicit methods here Laravel returns false by default, which
     * would 403 a manager on /admin/storage-locations/create even though they
     * have inventory.manage and storage_locations.create checked in their role.
     * Map create / update / delete back to the same "manage" gate so the role
     * permissions UI lines up with what the controllers actually check.
     */
    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }
}
