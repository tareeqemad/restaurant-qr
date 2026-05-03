<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Demo / sample data for development + sales demos.
 *
 * Creates two branches (Khan Yunis as the populated main + Gaza as an empty
 * second branch to exercise the multi-branch flow), a starter staff roster,
 * a 75-item Arabic menu, indoor/outdoor zones, kitchen+bar+grill stations,
 * and 12 tables.
 *
 * NEVER run this on a production database. The wipe-and-reseed flow is
 * destructive and ships demo logins (admin/password, partner/password, …)
 * that would be a security hole in production.
 *
 * Usage:
 *   php artisan db:seed                      # full install (system + demo)
 *   php artisan db:seed --class=DemoSeeder   # demo only (system already there)
 *
 * To wipe demo data while keeping the production install intact, the admin
 * UI exposes "إعادة تعيين البيانات التجريبية" (Super Admin only) — see
 * App\Services\DemoResetService for the destructive-action contract.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Branches FIRST — every per-branch seeder below needs at least
            // one branch to attach to.
            BranchSeeder::class,           // Khan Yunis (main) + Gaza (empty)

            // Per-branch reference data (stations belong to branches now).
            StationSeeder::class,          // kitchen, bar, dessert… (Khan Yunis)

            // People — UserSeeder also assigns each non-owner user to the
            // Khan Yunis branch via branch_user.
            UserSeeder::class,

            // Tables (Khan Yunis only — Gaza starts empty by design).
            TableSeeder::class,

            // Menu structure (categories + modifier groups + sample items).
            MenuSeeder::class,

            // Real restaurant menu — replaces sample items with 7 categories
            // × 10-12 real dishes + localized Unsplash imagery.
            // Must run BEFORE InternetCardsSeeder because RealRestaurantMenuSeeder
            // truncates menu_items + categories — it would wipe the cards.
            RealRestaurantMenuSeeder::class,

            // Internet (Wi-Fi) cards — 1h / 2h / 3h. Seeded for EVERY branch
            // (Khan Yunis + Gaza) so the second branch has something on its
            // menu out of the box, even before the owner copies / builds a
            // full food menu for it.
            InternetCardsSeeder::class,
        ]);
    }
}
