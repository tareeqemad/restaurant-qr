<?php

namespace App\Policies;

use App\Models\Table;
use App\Models\User;

class TablePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter', 'cashier']);
    }

    public function view(User $user, Table $table): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function update(User $user, Table $table): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function delete(User $user, Table $table): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
