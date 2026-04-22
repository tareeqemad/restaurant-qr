<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter', 'cashier', 'chef', 'bartender']);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter']);
    }

    public function approve(User $user, Order $order): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter']);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter']);
    }

    public function edit(User $user, Order $order): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter']) && $order->isEditable();
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->isAdmin() || $user->isManager();
    }
}
