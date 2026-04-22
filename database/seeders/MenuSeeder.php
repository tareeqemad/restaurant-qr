<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\RecipeItem;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $kitchen = Station::where('code', 'kitchen')->first();
        $bar = Station::where('code', 'bar')->first();
        $dessert = Station::where('code', 'dessert')->first();

        $g = Unit::where('code', 'g')->first();
        $ml = Unit::where('code', 'ml')->first();
        $pcs = Unit::where('code', 'pcs')->first();
        $tbsp = Unit::where('code', 'tbsp')->first();

        // Categories
        $cats = [
            ['slug' => 'appetizers', 'name' => 'المقبلات', 'name_en' => 'Appetizers', 'icon' => 'bi-egg-fried', 'color' => '#f59e0b', 'default_station_id' => $kitchen?->id, 'display_order' => 1],
            ['slug' => 'main-courses', 'name' => 'الأطباق الرئيسية', 'name_en' => 'Main Courses', 'icon' => 'bi-fire', 'color' => '#ef4444', 'default_station_id' => $kitchen?->id, 'display_order' => 2],
            ['slug' => 'grill', 'name' => 'المشاوي', 'name_en' => 'Grill', 'icon' => 'bi-fire', 'color' => '#dc2626', 'default_station_id' => $kitchen?->id, 'display_order' => 3],
            ['slug' => 'sandwiches', 'name' => 'السندويشات', 'name_en' => 'Sandwiches', 'icon' => 'bi-basket', 'color' => '#a855f7', 'default_station_id' => $kitchen?->id, 'display_order' => 4],
            ['slug' => 'salads', 'name' => 'السلطات', 'name_en' => 'Salads', 'icon' => 'bi-flower2', 'color' => '#22c55e', 'default_station_id' => $kitchen?->id, 'display_order' => 5],
            ['slug' => 'drinks', 'name' => 'المشروبات', 'name_en' => 'Drinks', 'icon' => 'bi-cup-straw', 'color' => '#3b82f6', 'default_station_id' => $bar?->id, 'display_order' => 6],
            ['slug' => 'desserts', 'name' => 'الحلويات', 'name_en' => 'Desserts', 'icon' => 'bi-cake2', 'color' => '#ec4899', 'default_station_id' => $dessert?->id, 'display_order' => 7],
        ];

        foreach ($cats as $c) {
            Category::updateOrCreate(['slug' => $c['slug']], array_merge($c, ['active' => true]));
        }

        // Supplier
        $supplier = Supplier::firstOrCreate(['name' => 'المورد الرئيسي'], ['active' => true]);

        // Ingredients
        $ings = [
            ['sku' => 'ING-001', 'name' => 'صدر دجاج', 'name_en' => 'Chicken Breast', 'base_unit_id' => $g->id, 'current_stock' => 50000, 'reorder_threshold' => 5000, 'cost_per_unit' => 0.008],
            ['sku' => 'ING-002', 'name' => 'لحم بقري', 'name_en' => 'Beef', 'base_unit_id' => $g->id, 'current_stock' => 30000, 'reorder_threshold' => 3000, 'cost_per_unit' => 0.015],
            ['sku' => 'ING-003', 'name' => 'طماطم', 'name_en' => 'Tomato', 'base_unit_id' => $g->id, 'current_stock' => 20000, 'reorder_threshold' => 2000, 'cost_per_unit' => 0.002],
            ['sku' => 'ING-004', 'name' => 'بصل', 'name_en' => 'Onion', 'base_unit_id' => $g->id, 'current_stock' => 15000, 'reorder_threshold' => 1500, 'cost_per_unit' => 0.001],
            ['sku' => 'ING-005', 'name' => 'خبز', 'name_en' => 'Bread', 'base_unit_id' => $pcs->id, 'current_stock' => 200, 'reorder_threshold' => 30, 'cost_per_unit' => 0.2],
            ['sku' => 'ING-006', 'name' => 'زيت زيتون', 'name_en' => 'Olive Oil', 'base_unit_id' => $ml->id, 'current_stock' => 10000, 'reorder_threshold' => 1000, 'cost_per_unit' => 0.01],
            ['sku' => 'ING-007', 'name' => 'ملح', 'name_en' => 'Salt', 'base_unit_id' => $g->id, 'current_stock' => 5000, 'reorder_threshold' => 500, 'cost_per_unit' => 0.0005],
            ['sku' => 'ING-008', 'name' => 'جبنة موزاريلا', 'name_en' => 'Mozzarella', 'base_unit_id' => $g->id, 'current_stock' => 10000, 'reorder_threshold' => 1000, 'cost_per_unit' => 0.012],
            ['sku' => 'ING-009', 'name' => 'خيار', 'name_en' => 'Cucumber', 'base_unit_id' => $g->id, 'current_stock' => 8000, 'reorder_threshold' => 800, 'cost_per_unit' => 0.002],
            ['sku' => 'ING-010', 'name' => 'نوتيلا', 'name_en' => 'Nutella', 'base_unit_id' => $g->id, 'current_stock' => 3000, 'reorder_threshold' => 500, 'cost_per_unit' => 0.025],
            ['sku' => 'ING-011', 'name' => 'سكر', 'name_en' => 'Sugar', 'base_unit_id' => $g->id, 'current_stock' => 10000, 'reorder_threshold' => 1000, 'cost_per_unit' => 0.001],
            ['sku' => 'ING-012', 'name' => 'قهوة حب', 'name_en' => 'Coffee Beans', 'base_unit_id' => $g->id, 'current_stock' => 5000, 'reorder_threshold' => 500, 'cost_per_unit' => 0.02],
            ['sku' => 'ING-013', 'name' => 'حليب', 'name_en' => 'Milk', 'base_unit_id' => $ml->id, 'current_stock' => 20000, 'reorder_threshold' => 2000, 'cost_per_unit' => 0.001],
            ['sku' => 'ING-014', 'name' => 'ماء', 'name_en' => 'Water', 'base_unit_id' => $ml->id, 'current_stock' => 50000, 'reorder_threshold' => 5000, 'cost_per_unit' => 0.0001],
            ['sku' => 'ING-015', 'name' => 'ليمون', 'name_en' => 'Lemon', 'base_unit_id' => $pcs->id, 'current_stock' => 100, 'reorder_threshold' => 20, 'cost_per_unit' => 0.15],
        ];
        foreach ($ings as $i) {
            $i['supplier_id'] = $supplier->id;
            $i['track_stock'] = true;
            $i['active'] = true;
            Ingredient::updateOrCreate(['sku' => $i['sku']], $i);
        }

        // Allergens
        $gluten = Allergen::where('code', 'gluten')->first();
        $dairy = Allergen::where('code', 'dairy')->first();
        $nuts = Allergen::where('code', 'nuts')->first();

        // Modifier groups
        $sizeGroup = ModifierGroup::updateOrCreate(['slug' => 'size'], [
            'name' => 'الحجم', 'name_en' => 'Size', 'min_select' => 1, 'max_select' => 1, 'required' => true, 'display_order' => 1, 'active' => true,
        ]);
        Modifier::updateOrCreate(['modifier_group_id' => $sizeGroup->id, 'name' => 'صغير'], ['name_en' => 'Small', 'price_delta' => 0, 'display_order' => 1, 'active' => true]);
        Modifier::updateOrCreate(['modifier_group_id' => $sizeGroup->id, 'name' => 'متوسط'], ['name_en' => 'Medium', 'price_delta' => 2, 'display_order' => 2, 'active' => true]);
        Modifier::updateOrCreate(['modifier_group_id' => $sizeGroup->id, 'name' => 'كبير'], ['name_en' => 'Large', 'price_delta' => 4, 'display_order' => 3, 'active' => true]);

        $addonsGroup = ModifierGroup::updateOrCreate(['slug' => 'addons'], [
            'name' => 'إضافات', 'name_en' => 'Add-ons', 'min_select' => 0, 'max_select' => 10, 'required' => false, 'display_order' => 2, 'active' => true,
        ]);
        Modifier::updateOrCreate(['modifier_group_id' => $addonsGroup->id, 'name' => 'جبنة إضافية'], ['name_en' => 'Extra Cheese', 'price_delta' => 2, 'display_order' => 1, 'active' => true]);
        Modifier::updateOrCreate(['modifier_group_id' => $addonsGroup->id, 'name' => 'بدون بصل'], ['name_en' => 'No Onion', 'price_delta' => 0, 'display_order' => 2, 'active' => true]);
        Modifier::updateOrCreate(['modifier_group_id' => $addonsGroup->id, 'name' => 'حار'], ['name_en' => 'Spicy', 'price_delta' => 0, 'display_order' => 3, 'active' => true]);

        // Menu items
        $appetizers = Category::where('slug', 'appetizers')->first();
        $mains = Category::where('slug', 'main-courses')->first();
        $sandwiches = Category::where('slug', 'sandwiches')->first();
        $salads = Category::where('slug', 'salads')->first();
        $drinks = Category::where('slug', 'drinks')->first();
        $desserts = Category::where('slug', 'desserts')->first();

        $chicken = Ingredient::where('sku', 'ING-001')->first();
        $beef = Ingredient::where('sku', 'ING-002')->first();
        $tomato = Ingredient::where('sku', 'ING-003')->first();
        $onion = Ingredient::where('sku', 'ING-004')->first();
        $bread = Ingredient::where('sku', 'ING-005')->first();
        $oil = Ingredient::where('sku', 'ING-006')->first();
        $salt = Ingredient::where('sku', 'ING-007')->first();
        $mozz = Ingredient::where('sku', 'ING-008')->first();
        $cucumber = Ingredient::where('sku', 'ING-009')->first();
        $nutella = Ingredient::where('sku', 'ING-010')->first();
        $sugar = Ingredient::where('sku', 'ING-011')->first();
        $coffee = Ingredient::where('sku', 'ING-012')->first();
        $milk = Ingredient::where('sku', 'ING-013')->first();
        $water = Ingredient::where('sku', 'ING-014')->first();
        $lemon = Ingredient::where('sku', 'ING-015')->first();

        // Chicken Shawarma Sandwich
        $m1 = MenuItem::updateOrCreate(
            ['slug' => 'chicken-shawarma'],
            [
                'category_id' => $sandwiches->id,
                'station_id' => $kitchen->id,
                'name' => 'شاورما دجاج',
                'name_en' => 'Chicken Shawarma',
                'description' => 'شاورما دجاج بتتبيلة خاصة مع الخضروات والصوص',
                'description_en' => 'Chicken shawarma with special sauce and veggies',
                'price' => 4.50,
                'prep_time_minutes' => 8,
                'is_available' => true,
                'display_order' => 1,
            ]
        );
        if ($chicken && $bread && $tomato && $onion) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m1->id, 'ingredient_id' => $chicken->id], ['quantity' => 200, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m1->id, 'ingredient_id' => $bread->id], ['quantity' => 1, 'unit_id' => $pcs->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m1->id, 'ingredient_id' => $tomato->id], ['quantity' => 50, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m1->id, 'ingredient_id' => $onion->id], ['quantity' => 30, 'unit_id' => $g->id]);
        }
        $m1->allergens()->syncWithoutDetaching([$gluten?->id]);
        $m1->modifierGroups()->syncWithoutDetaching([$sizeGroup->id, $addonsGroup->id]);

        // Beef Burger
        $m2 = MenuItem::updateOrCreate(
            ['slug' => 'beef-burger'],
            [
                'category_id' => $sandwiches->id,
                'station_id' => $kitchen->id,
                'name' => 'برجر لحم',
                'name_en' => 'Beef Burger',
                'description' => 'برجر لحم طازج مع الجبنة والخضار',
                'price' => 5.00,
                'prep_time_minutes' => 12,
                'is_available' => true,
                'display_order' => 2,
            ]
        );
        if ($beef && $bread && $mozz) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m2->id, 'ingredient_id' => $beef->id], ['quantity' => 180, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m2->id, 'ingredient_id' => $bread->id], ['quantity' => 1, 'unit_id' => $pcs->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m2->id, 'ingredient_id' => $mozz->id], ['quantity' => 30, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m2->id, 'ingredient_id' => $tomato->id], ['quantity' => 30, 'unit_id' => $g->id]);
        }
        $m2->allergens()->syncWithoutDetaching([$gluten?->id, $dairy?->id]);
        $m2->modifierGroups()->syncWithoutDetaching([$sizeGroup->id, $addonsGroup->id]);

        // Fattoush Salad
        $m3 = MenuItem::updateOrCreate(
            ['slug' => 'fattoush'],
            [
                'category_id' => $salads->id,
                'station_id' => $kitchen->id,
                'name' => 'فتوش',
                'name_en' => 'Fattoush',
                'description' => 'سلطة فتوش باللبنانية مع الخبز المحمص',
                'price' => 3.00,
                'prep_time_minutes' => 5,
                'is_available' => true,
            ]
        );
        if ($tomato && $cucumber && $oil) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m3->id, 'ingredient_id' => $tomato->id], ['quantity' => 80, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m3->id, 'ingredient_id' => $cucumber->id], ['quantity' => 60, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m3->id, 'ingredient_id' => $oil->id], ['quantity' => 15, 'unit_id' => $ml->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m3->id, 'ingredient_id' => $bread->id], ['quantity' => 1, 'unit_id' => $pcs->id]);
        }
        $m3->allergens()->syncWithoutDetaching([$gluten?->id]);

        // Pepsi
        $m4 = MenuItem::updateOrCreate(
            ['slug' => 'pepsi'],
            [
                'category_id' => $drinks->id,
                'station_id' => $bar->id,
                'name' => 'بيبسي',
                'name_en' => 'Pepsi',
                'description' => 'بيبسي بارد 330 مل',
                'price' => 1.00,
                'prep_time_minutes' => 1,
                'is_available' => true,
            ]
        );
        if ($water) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m4->id, 'ingredient_id' => $water->id], ['quantity' => 330, 'unit_id' => $ml->id]);
        }

        // Espresso
        $m5 = MenuItem::updateOrCreate(
            ['slug' => 'espresso'],
            [
                'category_id' => $drinks->id,
                'station_id' => $bar->id,
                'name' => 'إسبريسو',
                'name_en' => 'Espresso',
                'price' => 1.50,
                'prep_time_minutes' => 3,
                'is_available' => true,
            ]
        );
        if ($coffee) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m5->id, 'ingredient_id' => $coffee->id], ['quantity' => 9, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m5->id, 'ingredient_id' => $water->id], ['quantity' => 30, 'unit_id' => $ml->id]);
        }

        // Cappuccino
        $m6 = MenuItem::updateOrCreate(
            ['slug' => 'cappuccino'],
            [
                'category_id' => $drinks->id,
                'station_id' => $bar->id,
                'name' => 'كابتشينو',
                'name_en' => 'Cappuccino',
                'price' => 2.50,
                'prep_time_minutes' => 4,
                'is_available' => true,
            ]
        );
        if ($coffee && $milk) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m6->id, 'ingredient_id' => $coffee->id], ['quantity' => 9, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m6->id, 'ingredient_id' => $milk->id], ['quantity' => 150, 'unit_id' => $ml->id]);
        }
        $m6->allergens()->syncWithoutDetaching([$dairy?->id]);

        // Fresh Lemon
        $m7 = MenuItem::updateOrCreate(
            ['slug' => 'fresh-lemon'],
            [
                'category_id' => $drinks->id,
                'station_id' => $bar->id,
                'name' => 'ليمون بالنعنع',
                'name_en' => 'Fresh Lemon Mint',
                'price' => 2.00,
                'prep_time_minutes' => 4,
                'is_available' => true,
            ]
        );
        if ($lemon && $sugar && $water) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m7->id, 'ingredient_id' => $lemon->id], ['quantity' => 2, 'unit_id' => $pcs->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m7->id, 'ingredient_id' => $sugar->id], ['quantity' => 15, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m7->id, 'ingredient_id' => $water->id], ['quantity' => 300, 'unit_id' => $ml->id]);
        }

        // Nutella Crepe
        $m8 = MenuItem::updateOrCreate(
            ['slug' => 'nutella-crepe'],
            [
                'category_id' => $desserts->id,
                'station_id' => $dessert->id,
                'name' => 'كريب نوتيلا',
                'name_en' => 'Nutella Crepe',
                'price' => 4.50,
                'prep_time_minutes' => 10,
                'is_available' => true,
            ]
        );
        if ($nutella && $milk) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m8->id, 'ingredient_id' => $nutella->id], ['quantity' => 50, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m8->id, 'ingredient_id' => $milk->id], ['quantity' => 100, 'unit_id' => $ml->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m8->id, 'ingredient_id' => $sugar->id], ['quantity' => 20, 'unit_id' => $g->id]);
        }
        $m8->allergens()->syncWithoutDetaching([$nuts?->id, $dairy?->id, $gluten?->id]);

        // Grilled Chicken main
        $m9 = MenuItem::updateOrCreate(
            ['slug' => 'grilled-chicken'],
            [
                'category_id' => $mains->id,
                'station_id' => $kitchen->id,
                'name' => 'دجاج مشوي',
                'name_en' => 'Grilled Chicken',
                'price' => 7.50,
                'prep_time_minutes' => 20,
                'is_available' => true,
            ]
        );
        if ($chicken) {
            RecipeItem::updateOrCreate(['menu_item_id' => $m9->id, 'ingredient_id' => $chicken->id], ['quantity' => 350, 'unit_id' => $g->id]);
            RecipeItem::updateOrCreate(['menu_item_id' => $m9->id, 'ingredient_id' => $oil->id], ['quantity' => 10, 'unit_id' => $ml->id]);
        }
        $m9->modifierGroups()->syncWithoutDetaching([$addonsGroup->id]);
    }
}
