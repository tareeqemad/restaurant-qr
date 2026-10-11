<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::assertDemo();
        Branch::firstOrCreate(['code' => DemoDataset::BRANCH], [
            'name' => 'صالة دار السرو', 'city' => 'غزة — موقع افتراضي للعرض',
            'is_active' => true, 'display_order' => 1,
        ]);
    }
}
