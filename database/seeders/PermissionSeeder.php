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
            'tables' => ['viewAny', 'create', 'update', 'delete'],
            'categories' => ['viewAny', 'create', 'update', 'delete'],
            'menu_items' => ['viewAny', 'create', 'update', 'delete', 'toggle_availability'],
            'modifiers' => ['viewAny', 'create', 'update', 'delete'],
            'ingredients' => ['viewAny', 'create', 'update', 'delete'],
            'inventory' => ['viewAny', 'manage'],
            'orders' => ['viewAny', 'view', 'create', 'approve', 'cancel', 'edit', 'delete'],
            'payments' => ['viewAny', 'create', 'refund'],
            'shifts' => ['viewAny', 'open', 'close', 'view_all'],
            'reports' => ['viewAny', 'export'],
            'settings' => ['view', 'update'],
            'activity_logs' => ['viewAny'],
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

        // Attach permissions to roles
        $admin = Role::where('name', 'admin')->first();
        $manager = Role::where('name', 'manager')->first();
        $waiter = Role::where('name', 'waiter')->first();
        $chef = Role::where('name', 'chef')->first();
        $bartender = Role::where('name', 'bartender')->first();
        $cashier = Role::where('name', 'cashier')->first();

        if ($admin) $admin->permissions()->sync(Permission::pluck('id'));

        if ($manager) {
            $managerPerms = Permission::whereNotIn('group', ['roles'])->pluck('id');
            $manager->permissions()->sync($managerPerms);
        }

        if ($waiter) {
            $waiter->permissions()->sync(Permission::whereIn('name', [
                'tables.viewAny',
                'orders.viewAny', 'orders.view', 'orders.create', 'orders.approve', 'orders.cancel', 'orders.edit',
                'payments.viewAny', 'payments.create',
                'menu_items.viewAny', 'menu_items.toggle_availability',
            ])->pluck('id'));
        }

        if ($chef) {
            $chef->permissions()->sync(Permission::whereIn('name', [
                'orders.viewAny', 'orders.view',
                'menu_items.viewAny', 'menu_items.toggle_availability',
                'ingredients.viewAny', 'inventory.viewAny',
            ])->pluck('id'));
        }

        if ($bartender) {
            $bartender->permissions()->sync(Permission::whereIn('name', [
                'orders.viewAny', 'orders.view',
                'menu_items.viewAny', 'menu_items.toggle_availability',
                'ingredients.viewAny', 'inventory.viewAny',
            ])->pluck('id'));
        }

        if ($cashier) {
            $cashier->permissions()->sync(Permission::whereIn('name', [
                'orders.viewAny', 'orders.view',
                'payments.viewAny', 'payments.create', 'payments.refund',
                'tables.viewAny',
                'shifts.open', 'shifts.close',
                'reports.viewAny',
            ])->pluck('id'));
        }
    }
}
