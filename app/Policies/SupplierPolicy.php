<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy extends BasePolicy
{
    /**
     * Inventory/purchasing management is admin/manager-only territory.
     * Cashiers/waiters/chefs don't need supplier access.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('suppliers.viewAny');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('suppliers.create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission('suppliers.update');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission('suppliers.delete');
    }
}
