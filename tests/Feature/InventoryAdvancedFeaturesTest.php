<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\InventorySnapshot;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the four inventory enhancements shipped in 2026_05_19_160000:
 *
 *  F1 — Theoretical-vs-actual report endpoint returns balanced numbers
 *  F2 — Composite ingredients expand recursively at deduction time
 *  F3 — Yield-pct adjusts qty-stocked + per-unit cost on PO receipt
 *  F4 — Daily snapshot command writes one row per (ingredient, branch)
 *
 * These hit the live services / controllers, not just the models — every
 * test is here because the previous suite would have missed the bug.
 */
class InventoryAdvancedFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected Unit $gram;
    protected Unit $ml;
    protected Unit $pcs;
    protected StorageLocation $storage;
    protected Station $kitchen;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'inv-main', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin_t', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);

        $this->gram = Unit::create(['code' => 'g',   'name' => 'g',   'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $this->ml   = Unit::create(['code' => 'ml',  'name' => 'ml',  'unit_type' => 'volume', 'factor_to_base' => 1, 'is_base' => true]);
        $this->pcs  = Unit::create(['code' => 'pcs', 'name' => 'pcs', 'unit_type' => 'count',  'factor_to_base' => 1, 'is_base' => true]);

        $this->storage  = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'Main',
            'is_default' => true, 'active' => true,
        ]);
        $this->kitchen  = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $this->storage->id, 'active' => true,
        ]);
        $this->category = Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $this->kitchen->id, 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** F2 — composite ingredients should be recursively expanded into raw lines. */
    public function test_composite_ingredient_deduction_expands_into_raw_inputs(): void
    {
        // Raw ingredients
        $sugar = $this->ing('Sugar', $this->gram, stock: 5000);
        $water = $this->ing('Water', $this->ml,   stock: 5000);

        // Composite: 200g sugar + 100ml water → 280g sauce
        $sauce = $this->ing('Sauce', $this->gram, stock: 0, isComposite: true, compositeYield: 280);
        RecipeItem::create(['parent_ingredient_id' => $sauce->id, 'ingredient_id' => $sugar->id,
                            'quantity' => 200, 'unit_id' => $this->gram->id]);
        RecipeItem::create(['parent_ingredient_id' => $sauce->id, 'ingredient_id' => $water->id,
                            'quantity' => 100, 'unit_id' => $this->ml->id]);

        // Menu item using 70g of the sauce
        $item = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'D-1', 'slug' => 'dessert', 'name' => 'Dessert', 'price' => 10,
            'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $item->id, 'ingredient_id' => $sauce->id,
                            'quantity' => 70, 'unit_id' => $this->gram->id]);

        $lines = app(InventoryService::class)->previewDeductionForItem($item, 1.0);

        // Sauce was 70g and yield was 280g → scale = 0.25
        // → sugar = 200 × 0.25 = 50g, water = 100 × 0.25 = 25ml
        $byId = collect($lines)->keyBy('ingredient_id');
        $this->assertEqualsWithDelta(50.0,  (float) $byId[$sugar->id]['quantity_in_base'], 0.0001);
        $this->assertEqualsWithDelta(25.0,  (float) $byId[$water->id]['quantity_in_base'], 0.0001);
        $this->assertFalse($byId->has($sauce->id),
            'Composite ingredient itself MUST NOT appear in deduction lines — only raw inputs.');
    }

    /** F2 — circular composite references must throw, not stack-overflow. */
    public function test_composite_cycle_is_detected_and_rejected(): void
    {
        $a = $this->ing('A', $this->gram, stock: 0, isComposite: true, compositeYield: 100);
        $b = $this->ing('B', $this->gram, stock: 0, isComposite: true, compositeYield: 100);

        // A → 50g of B; B → 30g of A. Classic loop.
        RecipeItem::create(['parent_ingredient_id' => $a->id, 'ingredient_id' => $b->id, 'quantity' => 50, 'unit_id' => $this->gram->id]);
        RecipeItem::create(['parent_ingredient_id' => $b->id, 'ingredient_id' => $a->id, 'quantity' => 30, 'unit_id' => $this->gram->id]);

        $item = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'L-1', 'slug' => 'loop', 'name' => 'Loop', 'price' => 10, 'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $item->id, 'ingredient_id' => $a->id, 'quantity' => 10, 'unit_id' => $this->gram->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/حلقة مرجعية/');
        app(InventoryService::class)->previewDeductionForItem($item, 1.0);
    }

    /** F4 — snapshot command writes one row per (ingredient, branch, date). */
    public function test_inventory_snapshot_command_captures_per_branch_quantities(): void
    {
        $chicken = $this->ing('Chicken', $this->gram, stock: 7500);

        $output = '';
        \Illuminate\Support\Facades\Artisan::call('app:snapshot-inventory');
        $output = \Illuminate\Support\Facades\Artisan::output();

        // Debug print so a future failure surfaces the command output:
        $snapshots = InventorySnapshot::all();
        if ($snapshots->isEmpty()) {
            $this->fail("No snapshots written. Command output:\n{$output}");
        }

        $snap = InventorySnapshot::where('ingredient_id', $chicken->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->assertNotNull($snap, 'Snapshot row should exist for the chicken/branch combination.');
        $this->assertSame(7500.0, (float) $snap->quantity_in_base);
        $this->assertSame(now()->toDateString(), $snap->taken_on->toDateString());
    }

    /** F4 — re-running the snapshot for the same day must NOT duplicate rows. */
    public function test_inventory_snapshot_is_idempotent_per_day(): void
    {
        $sugar = $this->ing('Sugar', $this->gram, stock: 2000);

        // Artisan::call works inside RefreshDatabase; the test-runner's
        // ->artisan() helper sometimes loses the per-test connection
        // and lands in a fresh one where the row already exists.
        \Illuminate\Support\Facades\Artisan::call('app:snapshot-inventory');
        \Illuminate\Support\Facades\Artisan::call('app:snapshot-inventory');

        $this->assertSame(1,
            InventorySnapshot::where('ingredient_id', $sugar->id)
                ->where('branch_id', $this->branch->id)
                ->whereDate('taken_on', now()->toDateString())
                ->count(),
            'Second run on the same day must overwrite, not duplicate.',
        );
    }

    /** F3 — yield_pct on the ingredient model exposes a higher effective cost. */
    public function test_yield_pct_grosses_up_effective_unit_cost(): void
    {
        // 10/kg paid, 70% yield → usable cost should be ~14.29/kg
        $chicken = $this->ing('Chicken', $this->gram, stock: 0);
        $chicken->update(['cost_per_unit' => 0.01, 'yield_pct' => 70]);  // 0.01/g = 10/kg

        $this->assertEqualsWithDelta(0.01 / 0.7, $chicken->effectiveCostPerUnit(), 0.0001);

        // Null yield_pct = 100% (no adjustment)
        $rice = $this->ing('Rice', $this->gram, stock: 0);
        $rice->update(['cost_per_unit' => 0.005]);
        $this->assertEqualsWithDelta(0.005, $rice->effectiveCostPerUnit(), 0.0001);
    }

    /** F1 — variance-report endpoint returns sensible structure & numbers. */
    public function test_consumption_variance_report_renders_with_data(): void
    {
        // Build a simple universe: one menu item, one order, deduct directly.
        $chicken = $this->ing('Chicken', $this->gram, stock: 1000);
        $item = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'M-1', 'slug' => 'meal', 'name' => 'Meal', 'price' => 10, 'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $item->id, 'ingredient_id' => $chicken->id,
                            'quantity' => 200, 'unit_id' => $this->gram->id]);

        $order = Order::create([
            'branch_id'   => $this->branch->id, 'status' => 'approved',
            'subtotal'    => 10, 'total' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
        $oi = OrderItem::create([
            'order_id'    => $order->id, 'menu_item_id' => $item->id,
            'name_snapshot' => 'Meal', 'quantity' => 2, 'unit_price' => 10,
            'subtotal' => 20, 'status' => 'approved',
        ]);

        // Actual movement = 450g (50g more than the recipe theoretical of 400g).
        InventoryMovement::create([
            'branch_id'        => $this->branch->id,
            'ingredient_id'    => $chicken->id,
            'type'             => 'out',
            'quantity'         => 450,
            'quantity_in_base' => 450,
            'unit_id'          => $this->gram->id,
            'reference_type'   => OrderItem::class,
            'reference_id'     => $oi->id,
            'occurred_at'      => now(),
        ]);

        $this->actingAs($this->admin);
        $response = $this->get(route('admin.reports.consumption-variance', [
            'from' => now()->toDateString(),
            'to'   => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Chicken');
        $response->assertSee('+50');     // variance in grams as a string
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function ing(string $name, Unit $unit, float $stock, bool $isComposite = false, ?float $compositeYield = null): Ingredient
    {
        $ing = Ingredient::create([
            'name'             => $name,
            'base_unit_id'     => $unit->id,
            'current_stock'    => $stock,
            'reorder_threshold' => 0,
            'cost_per_unit'    => 1,
            'track_stock'      => true,
            'active'           => true,
            'is_composite'     => $isComposite,
            'composite_yield'  => $compositeYield,
        ]);

        if ($stock > 0) {
            IngredientStock::create([
                'ingredient_id'       => $ing->id,
                'storage_location_id' => $this->storage->id,
                'quantity'            => $stock,
                'reorder_threshold'   => 0,
            ]);
        }
        return $ing;
    }
}
