<?php

namespace Database\Seeders;

use App\Models\Allergen;
use Illuminate\Database\Seeder;

class AllergenSeeder extends Seeder
{
    public function run(): void
    {
        $allergens = [
            ['code' => 'gluten', 'name' => 'غلوتين', 'icon' => '🌾'],
            ['code' => 'dairy', 'name' => 'ألبان', 'icon' => '🥛'],
            ['code' => 'eggs', 'name' => 'بيض', 'icon' => '🥚'],
            ['code' => 'nuts', 'name' => 'مكسرات', 'icon' => '🥜'],
            ['code' => 'peanuts', 'name' => 'فول سوداني', 'icon' => '🥜'],
            ['code' => 'soy', 'name' => 'صويا', 'icon' => '🫘'],
            ['code' => 'fish', 'name' => 'سمك', 'icon' => '🐟'],
            ['code' => 'shellfish', 'name' => 'محار', 'icon' => '🦐'],
            ['code' => 'sesame', 'name' => 'سمسم', 'icon' => '🌰'],
            ['code' => 'spicy', 'name' => 'حار', 'icon' => '🌶️'],
        ];

        foreach ($allergens as $i => $a) {
            $a['display_order'] = $i;
            Allergen::updateOrCreate(['code' => $a['code']], $a);
        }
    }
}
