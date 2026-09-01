<?php

namespace Tests;

use App\Models\Account;
use App\Models\LookupGroup;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LookupGroupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            // Historical migrations used to insert the accounting kernel.
            // The split migrations are intentionally schema-only, so tests
            // load only that kernel here. Units remain opt-in because many
            // focused fixtures create their own rows and codes.
            if (Schema::hasTable('accounts') && ! Account::query()->exists()) {
                $this->seed([
                    SystemSettingSeeder::class,
                    CurrencySeeder::class,
                    AccountingSeeder::class,
                    AccountMappingSeeder::class,
                ]);
            }

            if (Schema::hasTable('settings') && ! Setting::query()->where('key', 'setup_completed')->exists()) {
                Setting::put('setup_completed', true, 'system', 'bool');
            }

            if (Schema::hasTable('lookup_groups') && ! LookupGroup::query()->exists()) {
                $this->seed(LookupGroupSeeder::class);
            }

            $this->preparePermissionFixtures();
        } catch (\Throwable) {
            //
        }
    }

    /**
     * Policy tests must exercise the same role templates production installs.
     * Legacy fixtures often create their role after parent::setUp(), so one
     * test-only model hook hydrates that new role from the canonical seeder.
     */
    private function preparePermissionFixtures(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission')) {
            return;
        }

        $this->seed(PermissionSeeder::class);

        Role::created(function (Role $role): void {
            resolve(PermissionSeeder::class)->syncRoleDefaults($role);
        });

        User::created(function (User $user): void {
            if (! in_array($user->role, PermissionSeeder::defaultRoleNames(), true)) {
                return;
            }

            if (Role::global()->where('name', $user->role)->exists()) {
                return;
            }

            Role::create([
                'name' => $user->role,
                'label' => $user->role,
                'is_system' => true,
            ]);
        });

    }
}
