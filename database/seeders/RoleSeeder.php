<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => UserRole::SuperAdmin->value, 'label' => 'مدير النظام', 'is_system' => true, 'display_order' => 1],
            ['name' => UserRole::Admin->value, 'label' => 'مدير عام', 'is_system' => true, 'display_order' => 2],
            ['name' => UserRole::Manager->value, 'label' => 'مدير فرع', 'is_system' => true, 'display_order' => 3],
            ['name' => UserRole::Waiter->value, 'label' => 'جرسون', 'is_system' => true, 'display_order' => 4],
            ['name' => UserRole::Chef->value, 'label' => 'شيف', 'is_system' => true, 'display_order' => 5],
            ['name' => UserRole::Bartender->value, 'label' => 'بارمان', 'is_system' => true, 'display_order' => 6],
            ['name' => UserRole::Cashier->value, 'label' => 'كاشير', 'is_system' => true, 'display_order' => 7],
        ];

        foreach ($roles as $r) {
            Role::updateOrCreate(['name' => $r['name']], $r);
        }
    }
}
