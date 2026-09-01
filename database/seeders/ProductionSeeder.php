<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Safe first-install seed for this restaurant.
 *
 * It contains structural reference rows plus the approved development menu
 * snapshot (the complete catalogue that must be restored in production).
 * It deliberately creates no demo users, passwords, restaurant tables,
 * customers, orders, stock balances, supplier debts, or accounting entries.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SystemSeeder::class,
            BranchSeeder::class,
            StationSeeder::class,
            RealRestaurantMenuSeeder::class,
        ]);
    }
}
