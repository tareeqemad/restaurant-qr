<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;

class StockCountPolicy
{
    public function viewAny(User $user): bool { return $user->hasAnyRole(['super_admin', 'admin', 'manager']); }
    public function view(User $user, StockCount $c): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return $user->hasAnyRole(['super_admin', 'admin', 'manager']); }
    public function update(User $user, StockCount $c): bool
    {
        return $c->isEditable() && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
    public function finalize(User $user, StockCount $c): bool
    {
        return $c->isEditable() && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
    public function cancel(User $user, StockCount $c): bool
    {
        return $c->isEditable() && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
    public function delete(User $user, StockCount $c): bool
    {
        return $c->status === 'draft' && $user->hasAnyRole(['super_admin', 'admin']);
    }
}
