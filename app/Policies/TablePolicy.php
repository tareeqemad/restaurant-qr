<?php

namespace App\Policies;

use App\Models\Table;
use App\Models\User;

class TablePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tables.viewAny');
    }

    public function view(User $user, Table $table): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $table);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('tables.create');
    }

    public function update(User $user, Table $table): bool
    {
        return $user->hasPermission('tables.update')
            && $this->inUserBranch($user, $table);
    }

    public function transfer(User $user, Table $table): bool
    {
        return $user->hasPermission('tables.transfer')
            && $this->inUserBranch($user, $table);
    }

    public function delete(User $user, Table $table): bool
    {
        return $user->hasPermission('tables.delete')
            && $this->inUserBranch($user, $table);
    }
}
