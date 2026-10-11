<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Reference rows only; owner catalogue and operating data are never replaced. */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SystemSeeder::class);
    }
}
