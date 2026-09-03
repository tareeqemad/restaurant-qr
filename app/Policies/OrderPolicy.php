<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('orders.viewAny');
    }

    /**
     * Permission-driven archive view. Anyone holding `orders.archive`
     * (granted via the role editor) gets in. Roles with broad authority
     * — admin / manager / cashier — get it implicitly.
     */
    public function archive(User $user): bool
    {
        return $user->hasPermission('orders.archive');
    }

    public function view(User $user, Order $order): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $order);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('orders.create');
    }

    public function approve(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.approve')
            && $this->withinCashierScope($user, $order)
            && $this->inUserBranch($user, $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.cancel')
            && $this->withinCashierScope($user, $order)
            && $this->inUserBranch($user, $order);
    }

    /**
     * Allow an authorised cashier to correct one dine-in line without also
     * granting the broader ability to cancel the whole table order.
     */
    public function cancelItem(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.cancel')
            && $this->inUserBranch($user, $order);
    }

    public function edit(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.edit')
            && $this->withinCashierScope($user, $order)
            && $order->isEditable()
            && $this->inUserBranch($user, $order);
    }

    public function serve(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.serve')
            && ! in_array($order->status, ['cancelled', 'completed'], true)
            && $this->inUserBranch($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.delete')
            && $this->inUserBranch($user, $order);
    }

    private function withinCashierScope(User $user, Order $order): bool
    {
        if (! $user->isCashier()) {
            return true;
        }

        return $order->table_session_id === null
            && in_array($order->order_type, ['takeaway', 'delivery'], true);
    }
}
