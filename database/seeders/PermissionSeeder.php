<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            'users' => ['viewAny', 'view', 'create', 'update', 'delete'],
            'roles' => ['viewAny', 'view', 'create', 'update', 'delete'],
            'tables' => ['viewAny', 'create', 'update', 'delete', 'transfer'],
            'categories' => ['viewAny', 'create', 'update', 'delete'],
            'menu_items' => ['viewAny', 'create', 'update', 'delete', 'toggle_availability'],
            'modifiers' => ['viewAny', 'create', 'update', 'delete'],
            'ingredients' => ['viewAny', 'create', 'update', 'delete'],
            'inventory' => ['viewAny', 'manage'],
            'suppliers' => ['viewAny', 'view', 'create', 'update', 'delete'],
            'purchase_orders' => ['viewAny', 'view', 'create', 'update', 'send', 'receive', 'cancel', 'delete'],
            'supplier_invoices' => ['viewAny', 'view', 'create', 'pay', 'cancel', 'delete'],
            'stock_counts' => ['viewAny', 'view', 'create', 'update', 'finalize', 'cancel', 'delete'],
            'storage_locations' => ['viewAny', 'create', 'update', 'delete', 'transfer'],
            'waste' => ['viewAny', 'create'],
            'orders' => ['viewAny', 'view', 'create', 'approve', 'cancel', 'edit', 'delete', 'archive'],
            'payments' => ['viewAny', 'create', 'refund'],
            // Cashier-applied ad-hoc discounts on an open invoice. `apply` is
            // for the staff at the till; the per-role percent/fixed cap lives
            // in config/restaurant.php (`discounts.caps`). Owner-level is
            // uncapped via OrderDiscountService::userCap().
            'discounts' => ['apply', 'remove'],
            // Marketing announcements / promo broadcasts to portal customers.
            // `publish` is gated separately because publishing fans out one
            // notification per matched customer (potentially thousands).
            'announcements' => ['viewAny', 'view', 'create', 'update', 'publish', 'delete'],
            'shifts' => ['viewAny', 'open', 'close', 'view_all'],
            'expenses' => ['viewAny', 'view', 'create', 'update', 'approve', 'reject', 'delete'],
            'customers' => ['viewAny', 'view', 'update', 'block', 'delete'],
            'reports' => ['viewAny', 'export'],
            'settings' => ['view', 'update'],
            'lookups' => ['viewAny', 'create', 'update', 'delete'],
            'activity_logs' => ['viewAny'],
            // Staff meal program — was role-gated previously, now permissioned
            // so a single cashier can be granted the closure capability without
            // a full manager promotion. `viewAny` covers dashboard + detail
            // pages; `quick_consume` is the only one floor staff need.
            'staff_meals' => ['viewAny', 'quick_consume', 'settle', 'waive', 'close_month'],
            // Menu-item promotions (sale prices, scheduled campaigns,
            // happy-hour windows). Cashier sees the live discounts so
            // the POS lights up correctly; CRUD stays manager-only.
            'promotions' => ['viewAny', 'create', 'update', 'delete'],
            // Chart-of-accounts management — the accountant can add /
            // rename / deactivate user accounts. System accounts
            // (wired into AccountingService by code) are protected by
            // the service layer regardless of who has these perms.
            'chart_of_accounts' => ['viewAny', 'create', 'update', 'delete'],
        ];

        foreach ($groups as $group => $actions) {
            foreach ($actions as $i => $action) {
                Permission::updateOrCreate(
                    ['name' => "{$group}.{$action}"],
                    [
                        'label' => "{$group}.{$action}",
                        'group' => $group,
                        'group_label' => $group,
                        'display_order' => $i,
                    ]
                );
            }
        }

        foreach ([
            'kitchen' => 'Kitchen station screen',
            'bar' => 'Bar station screen',
            'grill' => 'Grill station screen',
            'dessert' => 'Dessert station screen',
            'cold' => 'Cold station screen',
        ] as $code => $label) {
            Permission::updateOrCreate(
                ['name' => "station.{$code}.view"],
                [
                    'label' => $label,
                    'group' => 'stations_access',
                    'group_label' => 'Station screens',
                    'display_order' => 100,
                ]
            );
        }

        // Attach permissions to roles
        $admin = Role::where('name', 'admin')->first();
        $manager = Role::where('name', 'manager')->first();
        $waiter = Role::where('name', 'waiter')->first();
        $chef = Role::where('name', 'chef')->first();
        $bartender = Role::where('name', 'bartender')->first();
        $cashier = Role::where('name', 'cashier')->first();

        if ($admin) $admin->permissions()->sync(Permission::pluck('id'));

        if ($manager) {
            // Manager doesn't manage roles or system-wide lookups (those are
            // owner-level configuration; an admin/super-admin handles them).
            $managerPerms = Permission::whereNotIn('group', ['roles', 'lookups'])->pluck('id');
            $manager->permissions()->sync($managerPerms);
        }

        if ($waiter) {
            $waiter->permissions()->sync(Permission::whereIn('name', [
                'tables.viewAny', 'tables.transfer',
                'orders.viewAny', 'orders.view', 'orders.create', 'orders.approve', 'orders.cancel', 'orders.edit',
                'menu_items.viewAny', 'menu_items.toggle_availability',
            ])->pluck('id'));
        }

        if ($chef) {
            $chef->permissions()->sync(Permission::whereIn('name', [
                'orders.viewAny', 'orders.view',
                'menu_items.viewAny', 'menu_items.toggle_availability',
                'ingredients.viewAny', 'inventory.viewAny',
                'station.kitchen.view', 'station.grill.view', 'station.dessert.view', 'station.cold.view',
            ])->pluck('id'));
        }

        if ($bartender) {
            $bartender->permissions()->sync(Permission::whereIn('name', [
                'orders.viewAny', 'orders.view',
                'menu_items.viewAny', 'menu_items.toggle_availability',
                'ingredients.viewAny', 'inventory.viewAny',
                'station.bar.view', 'station.coffee.view',
            ])->pluck('id'));
        }

        if ($cashier) {
            $cashier->permissions()->sync(Permission::whereIn('name', [
                'orders.viewAny', 'orders.view', 'orders.create',
                'payments.viewAny', 'payments.create', 'payments.refund',
                'discounts.apply', 'discounts.remove',
                'tables.viewAny',
                'shifts.open', 'shifts.close',
                'reports.viewAny',
                // Petty cash: cashier may log + view, manager approves.
                'expenses.viewAny', 'expenses.view', 'expenses.create',
                // Customer phone-lookup at checkout (read-only).
                'customers.viewAny', 'customers.view',
                // Cashier can run the staff-meal quick consume during a
                // shift (cola, water) — settling debt / closing the month
                // remains a manager action.
                'staff_meals.quick_consume',
                // Cashier sees active promotions so the POS lights up
                // the discount badge correctly; create/update stays manager.
                'promotions.viewAny',
            ])->pluck('id'));
        }
    }
}
