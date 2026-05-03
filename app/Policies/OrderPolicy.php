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

    /**
     * Permission-driven archive view. Anyone holding `orders.archive`
     * (granted via the role editor) gets in. Roles with broad authority
     * — admin / manager / cashier — get it implicitly.
     */
    public function archive(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier'])
            || $user->hasPermission('orders.archive');
    }

    public function view(User $user, Order $order): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $order);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter', 'cashier'])
            || $user->hasPermission('orders.create');
    }

    public function approve(User $user, Order $order): bool
    {
        return ($this->canManageFloorOrders($user) || $this->canManageCashierOrder($user, $order))
            && $this->inUserBranch($user, $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        return ($this->canManageFloorOrders($user) || $this->canManageCashierOrder($user, $order))
            && $this->inUserBranch($user, $order);
    }

    public function edit(User $user, Order $order): bool
    {
        return ($this->canManageFloorOrders($user) || $this->canManageCashierOrder($user, $order))
            && $order->isEditable()
            && $this->inUserBranch($user, $order);
    }

    public function serve(User $user, Order $order): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter'])
            && ! in_array($order->status, ['cancelled', 'completed'], true)
            && $this->inUserBranch($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return ($user->isAdmin() || $user->isManager())
            && $this->inUserBranch($user, $order);
    }

    private function canManageFloorOrders(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'waiter']);
    }

    private function canManageCashierOrder(User $user, Order $order): bool
    {
        return $user->hasAnyRole(['cashier'])
            && $order->table_session_id === null
            && in_array($order->order_type, ['takeaway', 'delivery'], true);
    }
}
