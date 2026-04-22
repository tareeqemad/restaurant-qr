<?php

namespace Database\Seeders;

use App\Models\Table;
use Illuminate\Database\Seeder;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            Table::updateOrCreate(
                ['number' => (string) $i],
                [
                    'name' => 'طاولة '.$i,
                    'capacity' => $i <= 4 ? 2 : ($i <= 8 ? 4 : 6),
                    'zone' => $i <= 8 ? 'indoor' : 'outdoor',
                    'status' => 'available',
                    'active' => true,
                ]
            );
        }
    }
}
