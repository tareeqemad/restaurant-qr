<?php

namespace App\Policies;

use App\Models\SupplierInvoice;
use App\Models\User;

class SupplierInvoicePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('supplier_invoices.viewAny');
    }

    public function view(User $user, SupplierInvoice $invoice): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $invoice);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('supplier_invoices.create');
    }

    public function pay(User $user, SupplierInvoice $invoice): bool
    {
        return $invoice->status !== 'cancelled'
            && (float) $invoice->balance > 0
            && ($user->hasAnyRole(['admin', 'manager']) || $user->hasPermission('supplier_invoices.pay'))
            && $this->inUserBranch($user, $invoice);
    }

    public function cancel(User $user, SupplierInvoice $invoice): bool
    {
        return $invoice->status !== 'cancelled'
            && ! $invoice->payments()->exists()
            && ($user->hasAnyRole(['admin', 'manager']) || $user->hasPermission('supplier_invoices.cancel'))
            && $this->inUserBranch($user, $invoice);
    }

    public function delete(User $user, SupplierInvoice $invoice): bool
    {
        return $invoice->status === 'unpaid'
            && ! $invoice->payments()->exists()
            && ($user->isAdmin() || $user->hasPermission('supplier_invoices.delete'))
            && $this->inUserBranch($user, $invoice);
    }
}
