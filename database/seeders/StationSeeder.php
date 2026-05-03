<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Station;
use App\Support\BranchContext;
use Illuminate\Database\Seeder;

/**
 * Seeds Khan Yunis's stations (kitchen, bar, dessert, coffee, grill). Gaza
 * starts with no stations — the owner adds whatever physical sections exist
 * at that branch (which can be a totally different mix: e.g. café-only with
 * just `coffee` + `dessert`, or a pure grill house).
 *
 * The whole run is wrapped in BranchContext::forBranch so the BelongsToBranch
 * trait stamps `branch_id` on each station automatically — no need to thread
 * the id through every updateOrCreate call.
 */
class StationSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('code', 'main-khan-yunis')->firstOrFail();

        BranchContext::forBranch($branch->id, fn () => $this->seedStations());
    }

    protected function seedStations(): void
    {
        $stations = [
            ['code' => 'kitchen', 'name' => 'المطبخ', 'name_en' => 'Kitchen', 'color' => '#ef4444', 'icon' => 'bi-fire', 'display_order' => 1],
            ['code' => 'bar', 'name' => 'البار', 'name_en' => 'Bar', 'color' => '#3b82f6', 'icon' => 'bi-cup-straw', 'display_order' => 2],
            ['code' => 'dessert', 'name' => 'قسم الحلويات', 'name_en' => 'Dessert', 'color' => '#ec4899', 'icon' => 'bi-cake2', 'display_order' => 3],
            ['code' => 'coffee', 'name' => 'قسم القهوة', 'name_en' => 'Coffee', 'color' => '#a16207', 'icon' => 'bi-cup-hot', 'display_order' => 4],
            ['code' => 'grill', 'name' => 'المشاوي', 'name_en' => 'Grill', 'color' => '#dc2626', 'icon' => 'bi-fire', 'display_order' => 5],
        ];

        foreach ($stations as $s) {
            Station::updateOrCreate(['code' => $s['code']], $s);
        }
    }
}
