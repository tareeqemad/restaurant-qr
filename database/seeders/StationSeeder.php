<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Station;
use App\Support\BranchContext;
use Illuminate\Database\Seeder;

class StationSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::assertDemo();
        $branch = Branch::where('code', DemoDataset::BRANCH)->firstOrFail();
        BranchContext::forBranch($branch->id, function (): void {
            foreach ([
                ['code' => 'kitchen', 'name' => 'مطبخ الدار', 'color' => '#A64F35', 'icon' => 'ri-fire-fill', 'display_order' => 1],
                ['code' => 'bar', 'name' => 'بار المشروبات', 'color' => '#487566', 'icon' => 'bi-cup-straw', 'display_order' => 2],
            ] as $station) {
                Station::firstOrCreate(['code' => $station['code']], $station);
            }
        });
    }
}
