<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\ModifierRecipeItem;
use App\Models\RecipeItem;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Unit;
use App\Support\BranchContext;
use Illuminate\Database\Seeder;

/** Explicit fictional recipes; never guesses ingredients from item names. */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::assertDemo();
        $branch = Branch::where('code', DemoDataset::BRANCH)->firstOrFail();
        BranchContext::forBranch($branch->id, fn () => $this->seedCatalogue($branch));
    }

    private function seedCatalogue(Branch $branch): void
    {
        $data = DemoDataset::data();
        $units = Unit::all()->keyBy('code');
        $stations = Station::all()->keyBy('code');
        $suppliers = [];
        foreach (['pantry' => 'تموين السرو — مورد تجريبي', 'produce' => 'سلة المزارع — مورد تجريبي'] as $key => $name) {
            $supplier = Supplier::firstOrCreate(['email' => $key.'@example.test'], [
                'name' => $name, 'phone' => null, 'active' => true,
                'notes' => 'مورد افتراضي لعرض النظام؛ لا يمثل جهة حقيقية.',
            ]);
            $supplier->branches()->syncWithoutDetaching([$branch->id]);
            $suppliers[$key] = $supplier;
        }
        $ingredients = [];
        foreach ($data['ingredients'] as $key => [$name, $unit, $stock, $threshold, $cost, $supplier]) {
            $ingredients[$key] = Ingredient::firstOrCreate(['sku' => 'DS-'.strtoupper($key)], [
                'name' => $name, 'base_unit_id' => $units[$unit]->id,
                'measurement_type' => match ($unit) {
                    'g' => 'weight', 'ml' => 'volume', default => 'count'
                },
                'supplier_id' => $suppliers[$supplier]->id, 'current_stock' => 0,
                'reorder_threshold' => $threshold, 'cost_per_unit' => $cost,
                'yield_pct' => 100, 'track_stock' => $key !== 'water', 'active' => true,
                'notes' => 'كمية وتكلفة افتراضيتان للتجربة، بوحدة الأساس الموضحة.',
            ]);
        }
        $categories = [];
        foreach ($data['categories'] as $slug => $category) {
            $categories[$slug] = Category::firstOrCreate(['slug' => $slug], [
                'name' => $category['name'], 'description' => 'من قائمة دار السرو التجريبية.',
                'default_station_id' => $stations[$category['station']]->id,
                'image' => 'demo/menu/'.$slug.'.svg', 'icon' => $category['icon'], 'color' => $category['color'],
                'display_order' => count($categories) + 1, 'active' => true,
            ]);
        }
        $groups = [];
        foreach ($data['groups'] as $slug => $group) {
            $groups[$slug] = ModifierGroup::firstOrCreate(['slug' => $slug], [
                'name' => $group['name'], 'min_select' => 0, 'max_select' => $group['max'],
                'required' => false, 'display_order' => count($groups) + 1, 'active' => true,
            ]);
            foreach ($group['modifiers'] as $index => $row) {
                $modifier = Modifier::firstOrCreate(['modifier_group_id' => $groups[$slug]->id, 'name' => $row['name']], [
                    'price_delta' => $row['price'], 'active' => true, 'display_order' => $index + 1,
                ]);
                foreach ($row['recipe'] as $ingredient => $quantity) {
                    ModifierRecipeItem::firstOrCreate(['modifier_id' => $modifier->id, 'ingredient_id' => $ingredients[$ingredient]->id], [
                        'quantity' => $quantity, 'unit_id' => $ingredients[$ingredient]->base_unit_id,
                    ]);
                }
            }
        }
        $allergens = Allergen::all()->keyBy('code');
        foreach ($data['items'] as $index => $row) {
            $category = $categories[$row['category']];
            $cost = collect($row['recipe'])->map(fn ($quantity, $key) => $quantity * (float) $ingredients[$key]->cost_per_unit)->sum();
            $item = MenuItem::firstOrCreate(['slug' => $row['slug']], [
                'sku' => 'DS-M'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'category_id' => $category->id, 'station_id' => $category->default_station_id,
                'name' => $row['name'], 'description' => $row['description'], 'price' => $row['price'],
                'cost' => round($cost, 2), 'image' => $category->image,
                'prep_time_minutes' => $row['prep'], 'display_order' => $index + 1,
                'is_available' => true, 'is_featured' => $row['featured'] ?? false,
            ]);
            foreach ($row['recipe'] as $key => $quantity) {
                RecipeItem::firstOrCreate(['menu_item_id' => $item->id, 'ingredient_id' => $ingredients[$key]->id], [
                    'quantity' => $quantity, 'unit_id' => $ingredients[$key]->base_unit_id,
                    // All listed ingredients belong to the standard serving.
                    // Optional additions have their own modifier recipes above.
                    'is_optional' => false,
                    'is_removable' => ! in_array($key, $row['non_removable'] ?? [], true),
                ]);
            }
            $item->allergens()->syncWithoutDetaching(array_map(fn ($key) => $allergens[$key]->id, $row['allergens'] ?? []));
            foreach ($row['groups'] ?? [] as $order => $key) {
                $item->modifierGroups()->syncWithoutDetaching([$groups[$key]->id => ['display_order' => $order + 1]]);
            }
        }
    }
}
