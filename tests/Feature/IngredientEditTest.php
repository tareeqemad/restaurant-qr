<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
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
 * ${{{ }}} syntax bug to production (500 on every open). Also locks in #22
 * (day-zero refinement): cost_per_unit stays editable while NO receipt batch
 * exists (so a 0-cost typo can be repaired before it poisons recipe costing),
 * then becomes read-only — update() ignores any posted value from then on.
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

    /**
     * Day-zero window: while no receipt batch exists, update() must accept a
     * corrected cost — this is the only way to repair the pre-filled 0 that
     * would otherwise poison every costing report permanently.
     */
    public function test_update_allows_cost_correction_while_no_batch_exists(): void
    {
        $ing = $this->makeIngredient(['cost_per_unit' => 0]);

        $this->actingAs($this->admin)
            ->put(route('admin.ingredients.update', $ing), [
                'name' => 'سكر', 'base_unit_id' => $this->g->id,
                'reorder_threshold' => 0, 'cost_per_unit' => 7.5,
                'track_stock' => 1, 'active' => 1,
            ])
            ->assertRedirect(route('admin.ingredients.index'));

        $this->assertSame(7.5, (float) $ing->refresh()->cost_per_unit,
            'cost_per_unit must be editable while the ingredient has no batches.');
    }

    /** Update persists editable fields — and #22: post-batch, cost_per_unit is NOT changed. */
    public function test_update_persists_and_ignores_cost_per_unit_after_first_batch(): void
    {
        $ing = $this->makeIngredient(['cost_per_unit' => 5]);

        // First receipt batch — from here on the weighted average is
        // system-maintained and the manual field must lock.
        IngredientBatch::create([
            'ingredient_id' => $ing->id,
            'received_date' => now()->toDateString(),
            'initial_qty'   => 1000,
            'remaining_qty' => 1000,
            'unit_cost'     => 5,
        ]);

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
            'cost_per_unit is system-maintained once a batch exists and must ignore a posted value on edit.');
    }

    public function test_base_unit_must_match_selected_measurement_type(): void
    {
        $ing = $this->makeIngredient();

        $this->actingAs($this->admin)
            ->from(route('admin.ingredients.edit', $ing))
            ->put(route('admin.ingredients.update', $ing), [
                'name' => 'سكر',
                'measurement_type' => 'volume',
                'base_unit_id' => $this->g->id,
                'reorder_threshold' => 0,
                'cost_per_unit' => 5,
                'track_stock' => 1,
                'active' => 1,
            ])
            ->assertRedirect(route('admin.ingredients.edit', $ing))
            ->assertSessionHasErrors('base_unit_id');

        $this->assertNull($ing->refresh()->measurement_type);
    }

    public function test_base_unit_cannot_change_after_stock_history_exists(): void
    {
        $kg = Unit::create([
            'code' => 'kg', 'name' => 'kg', 'unit_type' => 'weight',
            'factor_to_base' => 1000, 'is_base' => false,
        ]);
        $ing = $this->makeIngredient();
        IngredientBatch::create([
            'ingredient_id' => $ing->id,
            'branch_id' => $this->branch->id,
            'received_date' => now()->toDateString(),
            'initial_qty' => 1000,
            'remaining_qty' => 1000,
            'unit_cost' => 1,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.ingredients.edit', $ing))
            ->put(route('admin.ingredients.update', $ing), [
                'name' => 'سكر',
                'measurement_type' => 'weight',
                'base_unit_id' => $kg->id,
                'reorder_threshold' => 0,
                'cost_per_unit' => 5,
                'track_stock' => 1,
                'active' => 1,
            ])
            ->assertSessionHasErrors('base_unit_id');

        $this->assertSame($this->g->id, $ing->refresh()->base_unit_id);
    }
}
