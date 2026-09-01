<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Support\BranchContext;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnitSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_seed_contains_the_canonical_ton_unit(): void
    {
        $this->seed(UnitSeeder::class);

        $this->assertDatabaseHas('units', [
            'code' => 'ton',
            'unit_type' => 'weight',
            'factor_to_base' => 1000000,
        ]);
    }

    public function test_unit_seeder_is_idempotent(): void
    {
        $this->seed(UnitSeeder::class);
        $this->seed(UnitSeeder::class);

        $this->assertSame(1, DB::table('units')->where('code', 'ton')->count());
    }

    public function test_reseeding_keeps_the_in_use_ton_unit_and_its_identity(): void
    {
        $this->seed(UnitSeeder::class);

        $branch = Branch::create(['code' => 'm', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($branch->id);

        $ton = Unit::firstWhere('code', 'ton');
        $category = Category::create(['name' => 'x', 'slug' => 'x', 'active' => true]);
        $ingredient = Ingredient::create(['name' => 'Sugar', 'base_unit_id' => $ton->id, 'active' => true]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'y', 'price' => 1]);
        $recipe = RecipeItem::create([
            'menu_item_id' => $item->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 1,
            'unit_id' => $ton->id,
        ]);

        $ton->update(['name' => 'Temporary', 'factor_to_base' => 1]);
        $this->seed(UnitSeeder::class);

        $this->assertSame($ton->id, Unit::firstWhere('code', 'ton')->id);
        $this->assertSame($ton->id, $recipe->fresh()->unit_id);
        $this->assertDatabaseHas('units', [
            'id' => $ton->id,
            'factor_to_base' => 1000000,
        ]);
    }
}
