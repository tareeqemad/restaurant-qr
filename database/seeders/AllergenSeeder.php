<?php

namespace Database\Seeders;

use App\Models\Allergen;
use Illuminate\Database\Seeder;

class AllergenSeeder extends Seeder
{
    public function run(): void
    {
        $allergens = [
            ['code' => 'gluten', 'name' => 'غلوتين', 'name_en' => 'Gluten', 'icon' => '🌾'],
            ['code' => 'dairy', 'name' => 'ألبان', 'name_en' => 'Dairy', 'icon' => '🥛'],
            ['code' => 'eggs', 'name' => 'بيض', 'name_en' => 'Eggs', 'icon' => '🥚'],
            ['code' => 'nuts', 'name' => 'مكسرات', 'name_en' => 'Nuts', 'icon' => '🥜'],
            ['code' => 'peanuts', 'name' => 'فول سوداني', 'name_en' => 'Peanuts', 'icon' => '🥜'],
            ['code' => 'soy', 'name' => 'صويا', 'name_en' => 'Soy', 'icon' => '🫘'],
            ['code' => 'fish', 'name' => 'سمك', 'name_en' => 'Fish', 'icon' => '🐟'],
            ['code' => 'shellfish', 'name' => 'محار', 'name_en' => 'Shellfish', 'icon' => '🦐'],
            ['code' => 'sesame', 'name' => 'سمسم', 'name_en' => 'Sesame', 'icon' => '🌰'],
            ['code' => 'spicy', 'name' => 'حار', 'name_en' => 'Spicy', 'icon' => '🌶️'],
        ];

        foreach ($allergens as $i => $a) {
            $a['display_order'] = $i;
            Allergen::updateOrCreate(['code' => $a['code']], $a);
        }
    }
}
