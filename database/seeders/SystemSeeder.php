<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Foundational reference data — roles, permissions, units, allergens.
 *
 * MUST run on every install (dev or production). Without these:
 *   - No roles exist → user creation fails.
 *   - No permissions exist → policies deny everything.
 *   - No units exist → ingredient creation fails.
 *   - No allergens exist → menu items can't be tagged.
 *
 * NOTHING here is "demo data" — every row is structural reference. Safe to
 * re-run; all child seeders use updateOrCreate so no duplicates.
 *
 * Usage:
 *   php artisan db:seed --class=SystemSeeder        # production install
 *   php artisan app:install                         # interactive wrapper
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            CurrencySeeder::class,
            UnitSeeder::class,
            AllergenSeeder::class,
        ]);
    }
}
