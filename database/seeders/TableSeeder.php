<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Lookup;
use App\Models\Table;
use Illuminate\Database\Seeder;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::assertDemo();
        $branch = Branch::where('code', DemoDataset::BRANCH)->firstOrFail();
        $hall = Lookup::firstOrCreate(['group' => 'zones', 'branch_id' => null, 'code' => 'dar-hall'], [
            'label' => 'الصالة الرئيسية', 'color' => '#315F4B', 'display_order' => 1, 'is_active' => true, 'is_system' => false,
        ]);
        $family = Lookup::firstOrCreate(['group' => 'zones', 'branch_id' => null, 'code' => 'dar-family'], [
            'label' => 'ركن العائلات', 'color' => '#B47845', 'display_order' => 2, 'is_active' => true, 'is_system' => false,
        ]);
        for ($number = 1; $number <= 12; $number++) {
            Table::firstOrCreate(['branch_id' => $branch->id, 'number' => (string) $number], [
                'name' => 'طاولة '.$number, 'capacity' => $number <= 2 ? 2 : ($number >= 11 ? 6 : 4),
                'zone_lookup_id' => $number <= 8 ? $hall->id : $family->id,
                'status' => 'available', 'active' => true,
            ]);
        }
    }
}
