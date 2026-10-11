<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StorageLocation;
use App\Support\BranchContext;
use Illuminate\Database\Seeder;

/** Opening demo stock only: a rerun never refills sold or depleted quantities. */
class RecipesSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::assertDemo();
        $branch = Branch::where('code', DemoDataset::BRANCH)->firstOrFail();
        BranchContext::forBranch($branch->id, function (): void {
            $location = StorageLocation::firstOrCreate(['code' => 'dar-pantry'], [
                'name' => 'مخزن دار السرو', 'is_default' => true, 'active' => true,
                'description' => 'رصيد افتتاحي افتراضي للعرض، يخدم المطبخ وبار المشروبات.',
            ]);
            foreach (DemoDataset::data()['ingredients'] as $key => $row) {
                $ingredient = Ingredient::where('sku', 'DS-'.strtoupper($key))->firstOrFail();
                if (! $ingredient->track_stock) {
                    continue;
                }
                $stock = IngredientStock::firstOrCreate([
                    'ingredient_id' => $ingredient->id, 'storage_location_id' => $location->id,
                ], ['quantity' => $row[2], 'reorder_threshold' => $row[3]]);
                if ($stock->wasRecentlyCreated) {
                    $ingredient->update(['current_stock' => IngredientStock::where('ingredient_id', $ingredient->id)->sum('quantity')]);
                }
            }
        });
    }
}
