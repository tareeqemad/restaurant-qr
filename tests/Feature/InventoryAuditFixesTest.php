<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StockCount;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\BatchInventoryService;
use App\Services\InventoryService;
use App\Services\LocationInventoryService;
use App\Services\OrderService;
use App\Services\RecipeCostService;
use App\Services\StockCountService;
use App\Services\SupplierInvoiceService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regressions for the 2026-07 inventory audit (verified HIGH/MEDIUM findings):
 *  - FIFO tie-break determinism
 *  - menu cost applies yield_pct + rolls up composites
 *  - approval is reversible before prep; restarting prep never double-deducts
 *  - stock-count finalize sets-to-counted against live stock
 *  - location transfer moves batch layers
 *  - supplier invoice blocked before receipt (GRNI integrity)
 */
class InventoryAuditFixesTest extends TestCase
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

        $this->branch = Branch::create(['code' => 'inv-fix', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin_fix', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);

        $this->gram = Unit::create(['code' => 'g',   'name' => 'g',   'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $this->ml   = Unit::create(['code' => 'ml',  'name' => 'ml',  'unit_type' => 'volume', 'factor_to_base' => 1, 'is_base' => true]);
        $this->pcs  = Unit::create(['code' => 'pcs', 'name' => 'pcs', 'unit_type' => 'count',  'factor_to_base' => 1, 'is_base' => true]);

        $this->storage  = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'Main', 'is_default' => true, 'active' => true]);
        $this->kitchen  = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $this->storage->id, 'active' => true]);
        $this->category = Category::create(['slug' => 'mains', 'name' => 'Mains', 'default_station_id' => $this->kitchen->id, 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function ing(string $name, Unit $unit, float $stock, array $extra = []): Ingredient
    {
        $ing = Ingredient::create(array_merge([
            'name' => $name, 'base_unit_id' => $unit->id, 'current_stock' => $stock,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ], $extra));
        if ($stock > 0) {
            IngredientStock::create(['ingredient_id' => $ing->id, 'storage_location_id' => $this->storage->id, 'quantity' => $stock, 'reorder_threshold' => 0]);
        }
        return $ing;
    }

    private function menuItemUsing(Ingredient $ing, float $qty): MenuItem
    {
        $item = MenuItem::create(['category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'M-'.$ing->id, 'slug' => 'm-'.$ing->id, 'name' => 'Dish '.$ing->id, 'price' => 50, 'is_available' => true]);
        RecipeItem::create(['menu_item_id' => $item->id, 'ingredient_id' => $ing->id, 'quantity' => $qty, 'unit_id' => $ing->base_unit_id]);
        return $item;
    }

    /** FIFO must be deterministic for same-day, no-expiry lots (oldest id first). */
    public function test_fifo_consumes_lower_id_first_on_same_day(): void
    {
        $ing = $this->ing('Oil', $this->ml, stock: 0);
        $batches = app(BatchInventoryService::class);
        $a = $batches->createBatchOnReceipt($ing, 10, 4.00, storageLocationId: $this->storage->id); // cheap, earlier id
        $b = $batches->createBatchOnReceipt($ing, 10, 6.00, storageLocationId: $this->storage->id); // dear, later id

        $taken = $batches->deductFifo($ing, 10, $this->storage->id);

        $this->assertCount(1, $taken);
        $this->assertSame($a->id, $taken[0]['batch']->id, 'The earlier-received (lower id) 4.00 lot must go first.');
        $this->assertEqualsWithDelta(4.00, (float) $taken[0]['batch']->unit_cost, 0.001);
    }

    /** Menu cost must gross up by yield_pct (trim loss), not use the raw paid cost. */
    public function test_menu_cost_applies_yield_pct(): void
    {
        $chicken = $this->ing('Chicken', $this->gram, stock: 1000, extra: ['cost_per_unit' => 0.01, 'yield_pct' => 70]);
        $item = $this->menuItemUsing($chicken, 200);   // 200g

        // 200 × (0.01 / 0.70) = 2.857, not the raw 200 × 0.01 = 2.00.
        $this->assertEqualsWithDelta(2.857, app(RecipeCostService::class)->costForMenuItem($item->fresh('recipeItems.ingredient')), 0.01);
    }

    /** Menu cost must roll up a composite ingredient's sub-recipe, not its stale column. */
    public function test_menu_cost_rolls_up_composite(): void
    {
        $sugar = $this->ing('Sugar', $this->gram, stock: 5000, extra: ['cost_per_unit' => 0.02]);
        $water = $this->ing('Water', $this->ml,   stock: 5000, extra: ['cost_per_unit' => 0.001]);
        // Composite sauce: 200g sugar + 100ml water → 280g. cost/g = 4.10/280 = 0.014643.
        $sauce = $this->ing('Sauce', $this->gram, stock: 0, extra: ['is_composite' => true, 'composite_yield' => 280, 'cost_per_unit' => 99]); // stale column
        RecipeItem::create(['parent_ingredient_id' => $sauce->id, 'ingredient_id' => $sugar->id, 'quantity' => 200, 'unit_id' => $this->gram->id]);
        RecipeItem::create(['parent_ingredient_id' => $sauce->id, 'ingredient_id' => $water->id, 'quantity' => 100, 'unit_id' => $this->ml->id]);

        $item = $this->menuItemUsing($sauce, 70);   // 70g sauce

        // 70 × 0.014643 = 1.025 — NOT 70 × 99 (stale column) and NOT 0.
        $this->assertEqualsWithDelta(1.025, app(RecipeCostService::class)->costForMenuItem($item->fresh('recipeItems.ingredient')), 0.01);
    }

    /** Approval itself no longer touches stock. The first kitchen start deducts;
     *  undoing and restarting that prep tap must not deduct the same line twice. */
    public function test_reapprove_then_restart_preparation_deducts_stock_only_once(): void
    {
        $patty = $this->ing('Patty', $this->gram, stock: 1000);
        $item = $this->menuItemUsing($patty, 150);

        $table = Table::create(['number' => '7', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
        $session = TableSession::create(['table_id' => $table->id, 'cover_count' => 1, 'status' => 'active']);
        $orders = app(OrderService::class);
        $order = $orders->createFromCart($session, [['menu_item_id' => $item->id, 'quantity' => 1, 'modifier_ids' => []]]);

        $orders->approve($order, $this->admin->id);
        $this->assertEqualsWithDelta(1000.0, (float) $patty->fresh()->current_stock, 0.001, 'Approval only queues the order.');

        $orders->unapprove($order->fresh(), $this->admin->id);
        $this->assertEqualsWithDelta(1000.0, (float) $patty->fresh()->current_stock, 0.001, 'Unapprove before prep has no stock effect.');

        $orders->approve($order->fresh(), $this->admin->id);
        $line = $order->items()->first();
        $orders->startPreparing($line, $this->admin->id);
        $this->assertEqualsWithDelta(850.0, (float) $patty->fresh()->current_stock, 0.001, 'Starting prep deducts 150.');

        $orders->revertItemStart($line->fresh(), $this->admin->id);
        $orders->startPreparing($line->fresh(), $this->admin->id);
        $this->assertEqualsWithDelta(850.0, (float) $patty->fresh()->current_stock, 0.001, 'Restarting prep must not double-deduct.');
    }

    /** Finalize must SET stock to the counted qty against LIVE stock, not replay
     *  the frozen open-time variance (which mid-service sales invalidate). */
    public function test_stock_count_finalize_sets_to_counted_against_live(): void
    {
        $ing = $this->ing('Rice', $this->gram, stock: 100);
        $counts = app(StockCountService::class);

        $count = $counts->create([], $this->admin->id);          // system_qty snapshot = 100
        // A sale during the count window drops live stock to 70.
        app(InventoryService::class)->recordMovement(ingredient: $ing, type: 'out', qtyBase: 30, unitCost: 1);
        $this->assertEqualsWithDelta(70.0, (float) $ing->fresh()->current_stock, 0.001);

        $itemId = $count->items()->where('ingredient_id', $ing->id)->value('id');
        $counts->saveCounts($count->fresh(), [$itemId => 95]);    // stored variance = 95−100 = −5
        $counts->finalize($count->fresh(), $this->admin->id);

        // Set-to-counted against live 70 → +25 → 95. The buggy delta-replay gave 65.
        $this->assertEqualsWithDelta(95.0, (float) $ing->fresh()->current_stock, 0.001);
    }

    /** A finalized count can't be finalized again (no double application). */
    public function test_stock_count_finalize_is_not_repeatable(): void
    {
        $ing = $this->ing('Salt', $this->gram, stock: 100);
        $counts = app(StockCountService::class);
        $count = $counts->create([], $this->admin->id);
        $itemId = $count->items()->where('ingredient_id', $ing->id)->value('id');
        $counts->saveCounts($count->fresh(), [$itemId => 90]);
        $counts->finalize($count->fresh(), $this->admin->id);
        $this->assertEqualsWithDelta(90.0, (float) $ing->fresh()->current_stock, 0.001);

        $this->expectException(ValidationException::class);
        $counts->finalize($count->fresh(), $this->admin->id);
    }

    /** A location transfer must move the batch layers so the destination can sell. */
    public function test_location_transfer_moves_batches_so_destination_can_sell(): void
    {
        $bar = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'bar', 'name' => 'Bar', 'is_default' => false, 'active' => true]);
        $ing = $this->ing('Syrup', $this->ml, stock: 100);       // IngredientStock at main = 100
        app(BatchInventoryService::class)->createBatchOnReceipt($ing, 100, 3.00, storageLocationId: $this->storage->id);

        app(LocationInventoryService::class)->transfer($ing, $this->storage, $bar, 40, 'to bar', $this->admin->id);

        // Destination now has a batch of 40 at the bar.
        $barBatch = IngredientBatch::where('ingredient_id', $ing->id)->where('storage_location_id', $bar->id)->where('remaining_qty', '>', 0)->first();
        $this->assertNotNull($barBatch, 'Transfer must create a batch layer at the destination.');
        $this->assertEqualsWithDelta(40.0, (float) $barBatch->remaining_qty, 0.001);

        // A batched sale at the bar must succeed (was hard-blocked before the fix).
        app(InventoryService::class)->deductWithBatchTrace(ingredient: $ing, qtyBase: 40, storageLocationId: $bar->id);
        $this->assertEqualsWithDelta(0.0, (float) $barBatch->fresh()->remaining_qty, 0.001);
    }

    /** A PO-linked supplier invoice cannot bill more than was received (GRNI integrity). */
    public function test_supplier_invoice_blocked_before_receipt(): void
    {
        $supplier = Supplier::create(['name' => 'Acme', 'is_active' => true]);
        $supplier->branches()->attach($this->branch->id);
        $ing = $this->ing('Flour', $this->gram, stock: 0);
        $po = PurchaseOrder::create(['number' => 'PO-1', 'supplier_id' => $supplier->id, 'branch_id' => $this->branch->id, 'status' => 'sent', 'order_date' => today()->toDateString()]);
        $poItem = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'unit_id' => $this->gram->id,
            'quantity_ordered' => 100, 'quantity_received' => 0, 'unit_price' => 10]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/قبل تسجيل استلام فعلي/u');
        app(SupplierInvoiceService::class)->create([
            'number' => 'SI-1', 'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id,
            'lines' => [[
                'description' => 'Flour', 'purchase_order_item_id' => $poItem->id,
                'quantity' => 100, 'unit_price' => 10, 'tax_total' => 0,
            ]],
        ], $this->admin->id);
    }
}
