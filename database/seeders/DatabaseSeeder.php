<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // 1. Auth / RBAC
            RoleSeeder::class,
            PermissionSeeder::class,

            // 2. Setup reference data
            StationSeeder::class,          // kitchen, bar, dessert…
            UnitSeeder::class,             // g, ml, pcs, kg…
            AllergenSeeder::class,         // gluten, dairy, nuts…

            // 3. People + Tables
            UserSeeder::class,             // admin + waiters/cashiers/chefs
            TableSeeder::class,            // 12 tables (indoor + outdoor)

            // 4. Menu structure (categories + modifier groups + sample items)
            MenuSeeder::class,

            // 5. Real restaurant menu — replaces sample items with 7 categories
            //    × 10-12 real dishes + localized Unsplash imagery.
            //    Must run last (it truncates menu_items then inserts).
            RealRestaurantMenuSeeder::class,
        ]);
    }
}
