<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $kitchen = Station::where('code', 'kitchen')->first();
        $bar = Station::where('code', 'bar')->first();

        $users = [
            ['name' => 'مدير النظام', 'username' => 'admin', 'email' => 'admin@restaurant.test', 'role' => UserRole::SuperAdmin->value, 'phone' => '0790000000'],
            ['name' => 'المدير العام', 'username' => 'manager', 'email' => 'manager@restaurant.test', 'role' => UserRole::Manager->value, 'phone' => '0790000001'],
            ['name' => 'أحمد الجرسون', 'username' => 'waiter1', 'role' => UserRole::Waiter->value, 'phone' => '0790000002'],
            ['name' => 'محمد الجرسون', 'username' => 'waiter2', 'role' => UserRole::Waiter->value, 'phone' => '0790000003'],
            ['name' => 'يوسف الشيف', 'username' => 'chef1', 'role' => UserRole::Chef->value, 'station_id' => $kitchen?->id, 'phone' => '0790000004'],
            ['name' => 'خالد البارمان', 'username' => 'bar1', 'role' => UserRole::Bartender->value, 'station_id' => $bar?->id, 'phone' => '0790000005'],
            ['name' => 'سارة الكاشير', 'username' => 'cashier1', 'role' => UserRole::Cashier->value, 'phone' => '0790000006'],
        ];

        foreach ($users as $u) {
            $u['password'] = Hash::make('password');
            $u['status'] = 'active';
            User::updateOrCreate(['username' => $u['username']], $u);
        }
    }
}
