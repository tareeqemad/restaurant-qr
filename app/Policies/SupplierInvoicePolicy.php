<?php

namespace App\Policies;

use App\Models\SupplierInvoice;
use App\Models\User;

class SupplierInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
    public function view(User $user, SupplierInvoice $inv): bool
    {
        return $this->viewAny($user);
    }
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
    public function pay(User $user, SupplierInvoice $inv): bool
    {
        return $inv->status !== 'cancelled'
            && (float) $inv->balance > 0
            && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
    public function cancel(User $user, SupplierInvoice $inv): bool
    {
        return $inv->status !== 'cancelled'
            && !$inv->payments()->exists()
            && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
    public function delete(User $user, SupplierInvoice $inv): bool
    {
        return $inv->status === 'unpaid'
            && !$inv->payments()->exists()
            && $user->hasAnyRole(['super_admin', 'admin']);
    }
}
