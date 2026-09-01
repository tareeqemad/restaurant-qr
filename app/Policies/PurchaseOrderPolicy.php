<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchase_orders.viewAny');
    }

    public function view(User $user, PurchaseOrder $po): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $po);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('purchase_orders.create');
    }

    public function update(User $user, PurchaseOrder $po): bool
    {
        return $po->isEditable()
            && $user->hasPermission('purchase_orders.update')
            && $this->inUserBranch($user, $po);
    }

    public function send(User $user, PurchaseOrder $po): bool
    {
        return $po->isSendable()
            && $user->hasPermission('purchase_orders.send')
            && $this->inUserBranch($user, $po);
    }

    public function approve(User $user, PurchaseOrder $po): bool
    {
        return $po->isApprovable()
            && $user->hasPermission('purchase_orders.approve')
            && $this->inUserBranch($user, $po);
    }

    public function receive(User $user, PurchaseOrder $po): bool
    {
        return $po->isReceivable()
            && $user->hasPermission('purchase_orders.receive')
            && $this->inUserBranch($user, $po);
    }

    public function cancel(User $user, PurchaseOrder $po): bool
    {
        return ! in_array($po->status, ['received', 'cancelled'], true)
            && $user->hasPermission('purchase_orders.cancel')
            && $this->inUserBranch($user, $po);
    }

    public function delete(User $user, PurchaseOrder $po): bool
    {
        return $po->isEditable()
            && $user->hasPermission('purchase_orders.delete')
            && $this->inUserBranch($user, $po);
    }
}
