<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the full staff roster + assigns each non-owner user to the Khan Yunis
 * branch via the `branch_user` pivot. The assignment step used to live in
 * BranchSeeder; it moved here once stations became per-branch (BranchSeeder
 * had to run before StationSeeder, which runs before UserSeeder, so the
 * "users exist" prerequisite stopped holding from BranchSeeder's vantage).
 *
 * Owner-level users (SuperAdmin + Partner) are NOT attached to any branch —
 * their company-wide access is granted by role, not membership, so seeding
 * them into branch_user would muddle the "what does this person belong to"
 * intent of that pivot.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $kitchen = Station::where('code', 'kitchen')->first();
        $bar = Station::where('code', 'bar')->first();

        $users = [
            ['name' => 'مدير النظام', 'username' => 'admin', 'email' => 'admin@restaurant.test', 'role' => UserRole::SuperAdmin->value, 'phone' => '0790000000'],
            // The owner persona — sees every branch, no assignment to branch_user needed.
            ['name' => 'الشريك',     'username' => 'partner',  'email' => 'partner@restaurant.test', 'role' => UserRole::Partner->value, 'phone' => '0791111111'],
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

        $this->attachStaffToMainBranch();
    }

    /**
     * Attach every non-owner user to Khan Yunis with their role pivot, marking
     * it as their primary branch. Idempotent — re-running this seeder won't
     * create duplicate pivot rows.
     */
    protected function attachStaffToMainBranch(): void
    {
        $main = Branch::where('code', 'main-khan-yunis')->first();
        if (! $main) {
            return;
        }

        $rolesByName = Role::global()->pluck('id', 'name');

        User::query()->each(function (User $user) use ($main, $rolesByName) {
            if ($user->isOwnerLevel()) {
                return;
            }
            if ($main->users()->where('users.id', $user->id)->exists()) {
                return;
            }

            $main->users()->attach($user->id, [
                'role_id'    => $rolesByName[$user->role] ?? null,
                'is_primary' => true,
                'joined_at'  => $user->created_at ?? now(),
            ]);
        });
    }
}
