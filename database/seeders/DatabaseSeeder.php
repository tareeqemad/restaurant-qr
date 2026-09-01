<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Default development seed: system references plus demo operating data.
 *
 * Production workflow:
 *   php artisan app:install
 *
 * The installer runs the one-table migrations, restores the approved menu
 * catalogue through ProductionSeeder, and creates the first Super Admin
 * interactively. It does not create demo staff, passwords, restaurant tables,
 * orders, stock balances, supplier debts, or accounting transactions.
 *
 * Development reset:
 *   php artisan migrate:fresh --seed
 *
 * Client handover:
 *   - The demo remains fully usable until an owner deliberately opens /setup.
 *   - Setup keeps the approved catalogue and wipes trial activity in one flow.
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
