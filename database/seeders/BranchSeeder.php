<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Provisions the two starter branches.
 *
 * Runs early — BEFORE StationSeeder/UserSeeder/etc — because every
 * per-branch table needs at least one branch to attach to. User-to-branch
 * assignments live in UserSeeder, which runs once both users and branches
 * exist.
 *
 * Branch #1 (Khan Yunis) carries all the historical seed data: 75 menu
 * items, indoor/outdoor zones, 12 tables, the kitchen+bar+grill stations.
 * Branch #2 (Gaza) starts empty by design — the owner builds it from
 * scratch (or via the future "نسخ القائمة" tool), so the seed mirrors
 * how a real second-branch onboarding looks day one.
 */
class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::firstOrCreate(
            ['code' => 'main-khan-yunis'],
            [
                'name'          => 'الفرع الرئيسي - خانيونس',
                'name_en'       => 'Main Branch — Khan Yunis',
                'city'          => 'خانيونس',
                'is_active'     => true,
                'display_order' => 1,
            ]
        );

        Branch::firstOrCreate(
            ['code' => 'gaza'],
            [
                'name'          => 'فرع غزة',
                'name_en'       => 'Gaza Branch',
                'city'          => 'غزة',
                'is_active'     => true,
                'display_order' => 2,
            ]
        );
    }
}
