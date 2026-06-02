<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: `php artisan migrate:refresh` aborted half-way because the
 * add_ton_unit migration's down() deleted the `ton` unit unconditionally —
 * which fails with a FK constraint error (1451) the moment a recipe line uses
 * it, leaving the schema in a broken half-rolled-back state.
 *
 * The down() must now no-op when the unit is still referenced.
 */
class TonUnitMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private function tonMigration(): object
    {
        return require database_path('migrations/2026_05_24_100000_add_ton_unit.php');
    }

    public function test_down_keeps_the_ton_unit_when_a_recipe_still_uses_it(): void
    {
        $branch = Branch::create(['code' => 'm', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($branch->id);

        // The migration already ran (RefreshDatabase), so the row exists.
        $ton = Unit::firstWhere('code', 'ton');
        $this->assertNotNull($ton, 'ton unit should be seeded by its migration');

        $category = Category::create(['name' => 'x', 'slug' => 'x', 'active' => true]);
        $ingredient = Ingredient::create(['name' => 'سكر', 'base_unit_id' => $ton->id, 'active' => true]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'y', 'price' => 1]);
        RecipeItem::create([
            'menu_item_id' => $item->id, 'ingredient_id' => $ingredient->id,
            'quantity' => 1, 'unit_id' => $ton->id,
        ]);

        // Rolling back must NOT throw and must leave the in-use unit intact.
        $this->tonMigration()->down();

        $this->assertDatabaseHas('units', ['code' => 'ton']);
    }

    public function test_down_removes_the_ton_unit_when_nothing_references_it(): void
    {
        // Seeded by the migration; nothing references it in a clean DB.
        $this->assertDatabaseHas('units', ['code' => 'ton']);

        $this->tonMigration()->down();

        $this->assertDatabaseMissing('units', ['code' => 'ton']);
    }

    public function test_up_is_idempotent(): void
    {
        $migration = $this->tonMigration();
        // Already ran once via RefreshDatabase; running again must not duplicate.
        $migration->up();

        $this->assertSame(1, DB::table('units')->where('code', 'ton')->count());
    }
}
