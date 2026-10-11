<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public const STAFF = [
        ['admin', 'رامي السرو', 'super_admin', null],
        ['partner', 'ليان السرو', 'partner', null],
        ['manager', 'سامر الزيتون', 'manager', null],
        ['waiter1', 'آدم الريحان', 'waiter', null],
        ['waiter2', 'نور الياسمين', 'waiter', null],
        ['chef1', 'مروان الزعتر', 'chef', 'kitchen'],
        ['bar1', 'هالة النعناع', 'bartender', 'bar'],
        ['cashier1', 'سلمى الورد', 'cashier', null],
        ['accountant1', 'ياسر الميزان', 'accountant', null],
    ];

    public function run(): void
    {
        DemoDataset::assertCanSeed();
        DemoDataset::assertDemo();
        $branch = Branch::where('code', DemoDataset::BRANCH)->firstOrFail();
        BranchContext::forBranch($branch->id, function () use ($branch): void {
            $stations = Station::all()->keyBy('code');
            $password = Hash::make(config('restaurant.demo_password'));
            foreach (self::STAFF as [$username, $name, $role, $station]) {
                $user = User::firstOrCreate(['username' => $username], [
                    'name' => $name, 'email' => $username.'@example.test', 'phone' => null,
                    'role' => $role, 'station_id' => $station ? $stations[$station]->id : null,
                    'password' => $password, 'status' => 'active',
                ]);
                if ($user->isOwnerLevel()) {
                    continue;
                }
                $membership = [$branch->id => ['is_primary' => true, 'joined_at' => $user->created_at]];
                $user->branches()->syncWithoutDetaching($membership);
                $employee = Employee::firstOrCreate(['user_id' => $user->id], [
                    'code' => 'DS-EMP-'.strtoupper($username), 'name' => $name, 'phone' => null,
                    'job_title' => UserRole::from($role)->label(), 'status' => 'active',
                    'monthly_meal_allowance' => 180, 'meal_debt_ceiling' => 360,
                    'notes' => 'شخصية افتراضية لعرض النظام؛ لا تمثل موظفاً حقيقياً.',
                ]);
                $employee->branches()->syncWithoutDetaching($membership);
            }
        });
    }
}
