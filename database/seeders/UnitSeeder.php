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
            ['code' => 'g',   'name' => 'غرام',      'name_en' => 'gram',     'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true],
            ['code' => 'kg',  'name' => 'كيلوغرام', 'name_en' => 'kilogram', 'unit_type' => 'weight', 'factor_to_base' => 1000],
            ['code' => 'ton', 'name' => 'طن',         'name_en' => 'tonne',    'unit_type' => 'weight', 'factor_to_base' => 1000000],
            ['code' => 'oz',  'name' => 'أونصة',    'name_en' => 'ounce',    'unit_type' => 'weight', 'factor_to_base' => 28.3495],

            // Volume (base = milliliter)
            ['code' => 'ml', 'name' => 'مللتر', 'name_en' => 'milliliter', 'unit_type' => 'volume', 'factor_to_base' => 1, 'is_base' => true],
            ['code' => 'l', 'name' => 'لتر', 'name_en' => 'liter', 'unit_type' => 'volume', 'factor_to_base' => 1000],
            ['code' => 'tsp', 'name' => 'ملعقة صغيرة', 'name_en' => 'teaspoon', 'unit_type' => 'volume', 'factor_to_base' => 5],
            ['code' => 'tbsp', 'name' => 'ملعقة كبيرة', 'name_en' => 'tablespoon', 'unit_type' => 'volume', 'factor_to_base' => 15],
            ['code' => 'cup', 'name' => 'كوب', 'name_en' => 'cup', 'unit_type' => 'volume', 'factor_to_base' => 240],

            // Count (base = piece)
            ['code' => 'pcs', 'name' => 'قطعة', 'name_en' => 'piece', 'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true],
            ['code' => 'dozen', 'name' => 'دستة', 'name_en' => 'dozen', 'unit_type' => 'count', 'factor_to_base' => 12],
        ];

        foreach ($units as $u) {
            Unit::updateOrCreate(['code' => $u['code']], $u);
        }
    }
}
