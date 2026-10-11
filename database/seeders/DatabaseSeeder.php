<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Default development seed: the guarded, fictional Dar Al Sarw demonstration. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // DemoSeeder checks the target before even reference rows are written.
        $this->call(DemoSeeder::class);
    }
}
