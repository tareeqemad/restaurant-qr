<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Default seed: full install (system + demo).
 *
 * Two-tier seeding:
 *   - SystemSeeder  → roles, permissions, units, allergens. ALWAYS safe.
 *   - DemoSeeder    → branches, staff, menu, tables. DEV/STAGING ONLY.
 *
 * Production install workflow:
 *   php artisan migrate
 *   php artisan db:seed --class=SystemSeeder
 *   php artisan app:install                # creates first super admin
 *
 * Or use the all-in-one wrapper:
 *   php artisan app:install                # interactive: migrate + system seed + super admin
 *
 * Demo / dev workflow (full reset):
 *   php artisan migrate:fresh --seed       # drops + system + demo
 *
 * Wipe demo data on a live system without losing the install:
 *   - UI: Super Admin → "إدارة النظام" → "إعادة تعيين البيانات التجريبية"
 *   - Code path: App\Services\DemoResetService::reset()
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SystemSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
