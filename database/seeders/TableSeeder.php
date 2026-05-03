<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Lookup;
use App\Models\Table;
use Illuminate\Database\Seeder;

/**
 * Seeds the 12 tables of the Khan Yunis main branch + its indoor/outdoor
 * zones. Gaza branch starts EMPTY by design — the owner builds it from
 * scratch (or via the menu/tables copy tools) so the seed reflects how a
 * real second-branch onboarding would look.
 */
class TableSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('code', 'main-khan-yunis')->firstOrFail();

        // Per-branch zone lookups — Khan Yunis gets the standard
        // indoor/outdoor pair. Gaza will define its own ("طاولات تحت" /
        // "طاولات فوق") through the admin UI later.
        $indoor = Lookup::updateOrCreate(
            ['group' => 'zones', 'branch_id' => $branch->id, 'code' => 'indoor'],
            ['label' => 'داخلي', 'color' => '#1f4733', 'display_order' => 1, 'is_active' => true, 'is_system' => true]
        );

        $outdoor = Lookup::updateOrCreate(
            ['group' => 'zones', 'branch_id' => $branch->id, 'code' => 'outdoor'],
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
