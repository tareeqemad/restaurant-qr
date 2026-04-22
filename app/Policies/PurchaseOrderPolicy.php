<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    public function view(User $user, PurchaseOrder $po): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    public function update(User $user, PurchaseOrder $po): bool
    {
        return $po->isEditable() && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    public function send(User $user, PurchaseOrder $po): bool
    {
        return $po->status === 'draft' && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    public function receive(User $user, PurchaseOrder $po): bool
    {
        return $po->isReceivable() && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    public function cancel(User $user, PurchaseOrder $po): bool
    {
        return !in_array($po->status, ['received', 'cancelled'], true)
            && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    public function delete(User $user, PurchaseOrder $po): bool
    {
        return $po->status === 'draft' && $user->hasAnyRole(['super_admin', 'admin']);
    }
}
