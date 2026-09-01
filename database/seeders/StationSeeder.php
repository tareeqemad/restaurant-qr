<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Station;
use App\Support\BranchContext;
use Illuminate\Database\Seeder;

/**
 * Seeds Khan Yunis's stations (kitchen, bar, dessert, grill). Gaza starts
 * with no stations — the owner adds whatever physical sections exist at that
 * branch. Coffee is intentionally NOT a station: coffee drinks are made at
 * the bar in this restaurant, so a separate "coffee" screen would just be a
 * duplicate display the bartender has to keep in sync.
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
            // Icons use RemixIcons (ri-*) for glyphs missing from the installed Bootstrap Icons font.
            ['code' => 'kitchen', 'name' => 'المطبخ', 'color' => '#ef4444', 'icon' => 'ri-fire-fill', 'display_order' => 1],
            ['code' => 'bar', 'name' => 'البار', 'color' => '#3b82f6', 'icon' => 'bi-cup-straw', 'display_order' => 2],
            ['code' => 'dessert', 'name' => 'قسم الحلويات', 'color' => '#ec4899', 'icon' => 'ri-cake-2-fill', 'display_order' => 3],
            ['code' => 'grill', 'name' => 'المشاوي', 'color' => '#dc2626', 'icon' => 'ri-fire-fill', 'display_order' => 4],
        ];

        foreach ($stations as $s) {
            Station::updateOrCreate(['code' => $s['code']], $s);
        }
    }
}
