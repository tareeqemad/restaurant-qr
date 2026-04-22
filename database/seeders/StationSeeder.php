<?php

namespace Database\Seeders;

use App\Models\Station;
use Illuminate\Database\Seeder;

class StationSeeder extends Seeder
{
    public function run(): void
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
