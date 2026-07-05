<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientUnit;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the ingredient create/edit screens — the pages that shipped a Blade
 * ${{{ }}} syntax bug to production (500 on every open). Also locks in #22:
 * cost_per_unit is read-only on edit and update() ignores any posted value.
 */
class IngredientEditTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected Unit $g;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'ie', 'name' => 'IE', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'a_ie', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);

        $this->g = Unit::create([
            'code' => 'g', 'name' => 'g', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function makeIngredient(array $overrides = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'name' => 'سكر', 'base_unit_id' => $this->g->id,
            'reorder_threshold' => 0, 'cost_per_unit' => 5,
            'track_stock' => true, 'active' => true,
        ], $overrides));
    }

    public function test_create_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.ingredients.create'))
            ->assertOk();
    }

    public function test_edit_page_renders_for_a_plain_ingredient(): void
    {
        $ing = $this->makeIngredient();
        IngredientUnit::create([
            'ingredient_id' => $ing->id, 'name' => 'كيس 1كغ',
            'factor_to_base' => 1000, 'is_default_purchase' => true, 'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.ingredients.edit', $ing))
            ->assertOk();
    }

    public function test_edit_page_renders_for_a_composite_ingredient(): void
    {
        $raw = $this->makeIngredient(['name' => 'طحينة خام']);
        $composite = $this->makeIngredient([
            'name' => 'صلصة طحينة', 'is_composite' => true, 'composite_yield' => 280,
        ]);
        RecipeItem::create([
            'parent_ingredient_id' => $composite->id, 'ingredient_id' => $raw->id,
            'quantity' => 200, 'unit_id' => $this->g->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.ingredients.edit', $composite))
            ->assertOk();
    }

    /** Update persists editable fields — and #22: cost_per_unit is NOT changed. */
    public function test_update_persists_and_ignores_cost_per_unit(): void
    {
        $ing = $this->makeIngredient(['cost_per_unit' => 5]);

        $this->actingAs($this->admin)
            ->put(route('admin.ingredients.update', $ing), [
                'name' => 'سكر بني', 'base_unit_id' => $this->g->id,
                'reorder_threshold' => 100, 'cost_per_unit' => 999,  // tampered — must be ignored
                'track_stock' => 1, 'active' => 1,
            ])
            ->assertRedirect(route('admin.ingredients.index'));

        $ing->refresh();
        $this->assertSame('سكر بني', $ing->name);
        $this->assertSame(100.0, (float) $ing->reorder_threshold);
        $this->assertSame(5.0, (float) $ing->cost_per_unit,
            'cost_per_unit is system-maintained and must ignore a posted value on edit.');
    }
}
