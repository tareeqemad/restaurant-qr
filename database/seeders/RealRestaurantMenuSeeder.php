<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Legacy entry point; no real-restaurant snapshot or destructive replacement. */
class RealRestaurantMenuSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::assertDemo();
        $this->call(MenuSeeder::class);
    }
}
