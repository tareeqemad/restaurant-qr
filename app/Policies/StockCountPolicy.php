<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;

class StockCountPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('stock_counts.viewAny');
    }

    public function view(User $user, StockCount $count): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $count);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('stock_counts.create');
    }

    public function update(User $user, StockCount $count): bool
    {
        return $count->isEditable()
            && ($user->hasAnyRole(['admin', 'manager']) || $user->hasPermission('stock_counts.update'))
            && $this->inUserBranch($user, $count);
    }

    public function finalize(User $user, StockCount $count): bool
    {
        return $count->isEditable()
            && ($user->hasAnyRole(['admin', 'manager']) || $user->hasPermission('stock_counts.finalize'))
            && $this->inUserBranch($user, $count);
    }

    public function cancel(User $user, StockCount $count): bool
    {
        return $count->isEditable()
            && ($user->hasAnyRole(['admin', 'manager']) || $user->hasPermission('stock_counts.cancel'))
            && $this->inUserBranch($user, $count);
    }

    public function delete(User $user, StockCount $count): bool
    {
        return $count->status === 'draft'
            && ($user->isAdmin() || $user->hasPermission('stock_counts.delete'))
            && $this->inUserBranch($user, $count);
    }
}
