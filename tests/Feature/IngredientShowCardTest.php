<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Smoke test for the consolidated stock card. Sanity-checks that:
 *   - The show route renders 200 for a typical ingredient.
 *   - The page reports the right base-unit stock + smart-formatted
 *     value (5,000,000 g → "5 طن").
 *   - Movement aggregates (received/consumed/wasted in last 30d)
 *     pull from inventory_movements correctly.
 *
 * Not exhaustive — each tab has its own underlying queries that are
 * covered elsewhere. This test guards the controller wiring against
 * silent breakage on schema changes.
 *
 * The screen moved to Inertia/Vue, so the assertions moved with it: the
 * smart-formatted strings are now built in the controller and asserted as
 * props. Asserting on markup would only be reading Inertia's escaped JSON
 * blob, which says nothing about what the page shows.
 */
class IngredientShowCardTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'sc', 'name' => 'SC', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'a_sc', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_stock_card_renders_with_smart_quantity_formatting(): void
    {
        $g = Unit::create([
            'code' => 'g', 'name' => 'g',
            'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true,
        ]);
        $storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'Main',
            'is_default' => true, 'active' => true,
        ]);

        // 5 tons of sugar — the user's exact "big number" scenario.
        $sugar = Ingredient::create([
            'name' => 'سكر',
            'base_unit_id' => $g->id,
            'current_stock' => 5_000_000,
            'reorder_threshold' => 100_000,
            'cost_per_unit' => 0.002,
            'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $sugar->id,
            'storage_location_id' => $storage->id,
            'quantity' => 5_000_000,
            'reorder_threshold' => 100_000,
        ]);

        // Seed one movement in each direction so the overview tab has
        // numbers to display (and the chart has something to draw).
        InventoryMovement::create([
            'branch_id' => $this->branch->id, 'ingredient_id' => $sugar->id,
            'type' => 'in', 'quantity' => 1_000_000, 'quantity_in_base' => 1_000_000,
            'unit_id' => $g->id, 'unit_cost' => 0.002, 'total_cost' => 2_000,
            'stock_before' => 4_000_000, 'stock_after' => 5_000_000,
            'occurred_at' => now()->subDays(2),
        ]);
        InventoryMovement::create([
            'branch_id' => $this->branch->id, 'ingredient_id' => $sugar->id,
            'type' => 'out', 'quantity' => 50_000, 'quantity_in_base' => 50_000,
            'unit_id' => $g->id, 'unit_cost' => 0.002, 'total_cost' => 100,
            'stock_before' => 5_050_000, 'stock_after' => 5_000_000,
            'occurred_at' => now()->subDays(1),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.ingredients.show', $sugar))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ingredients/Show')
                ->where('ingredient.name', 'سكر')
                // Smart formatter must render the stock as "5 طن", NOT "5,000,000".
                ->where('hero.stockLabel', '5 طن')
                // The tooltip must still expose the raw base number for the
                // technical/accounting view — number_format(…, 4).
                ->where('hero.stockExact', '5,000,000.0000')
                // The 1M-g receive + 50K-g consume must come through.
                ->where('kpis.received30.label', '1 طن')
                ->where('kpis.consumed30.label', '50 كيلوغرام')
                ->where('kpis.wasted30.label', '0 غرام')
                // …and the chart has a full 30-day window to draw.
                ->has('dailySeries', 30));
    }
}
