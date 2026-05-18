<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Lookup;
use App\Models\Table;
use Illuminate\Database\Seeder;

/**
 * Seeds the 12 tables of the Khan Yunis main branch. The indoor/outdoor
 * zones are GLOBAL (shared across branches) — see App\Models\Lookup
 * PER_BRANCH_GROUPS, which deliberately excludes "zones".
 */
class TableSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('code', 'main-khan-yunis')->firstOrFail();

        // Zone lookups are shared across branches.
        $indoor = Lookup::updateOrCreate(
            ['group' => 'zones', 'branch_id' => null, 'code' => 'indoor'],
            ['label' => 'داخلي', 'color' => '#1f4733', 'display_order' => 1, 'is_active' => true, 'is_system' => true]
        );

        $outdoor = Lookup::updateOrCreate(
            ['group' => 'zones', 'branch_id' => null, 'code' => 'outdoor'],
            ['label' => 'خارجي', 'color' => '#b8872a', 'display_order' => 2, 'is_active' => true, 'is_system' => true]
        );

        for ($i = 1; $i <= 12; $i++) {
            Table::updateOrCreate(
                ['branch_id' => $branch->id, 'number' => (string) $i],
                [
                    'name' => 'طاولة '.$i,
                    'capacity' => $i <= 4 ? 2 : ($i <= 8 ? 4 : 6),
                    'zone_lookup_id' => $i <= 8 ? $indoor->id : $outdoor->id,
                    'status' => 'available',
                    'active' => true,
                ]
            );
        }
    }
}
