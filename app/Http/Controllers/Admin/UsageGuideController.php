<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Table;
use App\Models\User;
use App\Support\AdminShell;
use App\Support\BranchContext;

class UsageGuideController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();
        $branchId = BranchContext::current();
        $branch = $branchId ? Branch::find($branchId) : null;

        $link = static fn (bool $allowed, string $route): ?string => $allowed ? route($route) : null;

        return AdminShell::render('Admin/Guide/Index', [
            'viewer' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'roleLabel' => UserRole::tryFrom($user->role)?->label() ?? $user->role,
                'branchId' => $branchId,
                'branchName' => $branch?->localizedName(),
            ],
            'roles' => collect(UserRole::cases())->map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])->values(),
            'urls' => [
                'dashboard' => route('admin.dashboard'),
                'settings' => $link($user->hasPermission('settings.view'), 'admin.settings.index'),
                'branches' => $link($user->can('viewAny', Branch::class), 'admin.branches.index'),
                'users' => $link($user->can('viewAny', User::class), 'admin.users.index'),
                'permissions' => $link($user->hasPermission('roles.viewAny'), 'admin.permissions.index'),
                'inventory' => $link($user->hasPermission('inventory.viewAny'), 'admin.inventory.dashboard'),
                'locations' => $link($user->hasPermission('storage_locations.viewAny'), 'admin.storage-locations.index'),
                'units' => $link($user->hasPermission('units.viewAny'), 'admin.units.index'),
                'ingredients' => $link($user->hasPermission('ingredients.viewAny'), 'admin.ingredients.index'),
                'suppliers' => $link($user->hasPermission('suppliers.viewAny'), 'admin.suppliers.index'),
                'purchaseOrders' => $link($user->hasPermission('purchase_orders.viewAny'), 'admin.purchase-orders.index'),
                'supplierInvoices' => $link($user->hasPermission('supplier_invoices.viewAny'), 'admin.supplier-invoices.index'),
                'stockCounts' => $link($user->hasPermission('stock_counts.viewAny'), 'admin.stock-counts.index'),
                'menuItems' => $link($user->can('viewAny', MenuItem::class), 'admin.menu-items.index'),
                'tables' => $link($user->can('viewAny', Table::class), 'admin.tables.index'),
                'serviceBoard' => $link($user->hasPermission('orders.viewAny'), 'admin.orders.index'),
                'cashier' => $link($user->hasPermission('payments.viewAny'), 'admin.cashier.index'),
                'expenses' => $link($user->hasPermission('expenses.viewAny'), 'admin.expenses.index'),
                'debts' => $link($user->hasPermission('payments.viewAny'), 'admin.customers.debts.index'),
                'refunds' => $link($user->hasPermission('payments.refund'), 'admin.refunds.index'),
                'accounting' => $link($user->hasPermission('chart_of_accounts.viewAny'), 'admin.accounting.index'),
                'accountingGuide' => $link($user->hasPermission('chart_of_accounts.viewAny'), 'admin.accounting.guide'),
                'openingBalances' => $link($user->hasPermission('chart_of_accounts.create'), 'admin.accounting.opening-balances'),
                'journal' => $link($user->hasPermission('chart_of_accounts.viewAny'), 'admin.accounting.journal'),
                'reports' => $link($user->hasPermission('reports.viewAny'), 'admin.reports.index'),
                'endOfDay' => $link($user->hasPermission('reports.viewAny'), 'admin.reports.end-of-day'),
            ],
        ]);
    }
}
