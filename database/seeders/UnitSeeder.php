<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            // Weight (base = gram). Range covers a single pinch (g)
            // up to a bulk pallet (ton) so receipts from any kind of
            // supplier — corner spice shop OR wholesale distributor —
            // all land on the same base unit without manual math.
            ['code' => 'g',   'name' => 'غرام',     'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true],
            ['code' => 'kg',  'name' => 'كيلوغرام', 'unit_type' => 'weight', 'factor_to_base' => 1000],
            ['code' => 'ton', 'name' => 'طن',    'unit_type' => 'weight', 'factor_to_base' => 1000000],
            ['code' => 'oz',  'name' => 'أونصة',    'unit_type' => 'weight', 'factor_to_base' => 28.3495],

            // Volume (base = milliliter)
            ['code' => 'ml', 'name' => 'مللتر', 'unit_type' => 'volume', 'factor_to_base' => 1, 'is_base' => true],
            ['code' => 'l', 'name' => 'لتر', 'unit_type' => 'volume', 'factor_to_base' => 1000],
            ['code' => 'tsp', 'name' => 'ملعقة صغيرة', 'unit_type' => 'volume', 'factor_to_base' => 5],
            ['code' => 'tbsp', 'name' => 'ملعقة كبيرة', 'unit_type' => 'volume', 'factor_to_base' => 15],
            ['code' => 'cup', 'name' => 'كوب', 'unit_type' => 'volume', 'factor_to_base' => 240],

            // Count (base = piece)
            ['code' => 'pcs', 'name' => 'قطعة', 'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true],
            ['code' => 'dozen', 'name' => 'دستة', 'unit_type' => 'count', 'factor_to_base' => 12],
        ];

        foreach ($units as $u) {
            Unit::updateOrCreate(['code' => $u['code']], $u);
        }
    }
}
