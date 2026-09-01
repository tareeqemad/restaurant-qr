<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RecipeItem;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Services\InventoryService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Customer-side stock gating — the user shouldn't be able to add an
 * item to the cart when the kitchen is already out of an ingredient.
 *
 * Three layers of defence covered:
 *   1. MenuItem::availableToOrder()  — flags items as unorderable
 *   2. CartController::add()         — rejects with stock-specific error
 *   3. Cumulative cart check         — second add that pushes past stock
 */
class CustomerStockGatingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Unit $gram;
    protected Unit $pcs;
    protected StorageLocation $storage;
    protected Station $kitchen;
    protected Category $category;
    protected MenuItem $burger;
    protected Ingredient $patty;
    protected Ingredient $bun;
    protected Table $table;
    protected TableSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'gate', 'name' => 'Gate', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        $this->gram = Unit::create(['code' => 'g',   'name' => 'g',   'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $this->pcs  = Unit::create(['code' => 'pcs', 'name' => 'pcs', 'unit_type' => 'count',  'factor_to_base' => 1, 'is_base' => true]);

        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $this->kitchen = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $this->storage->id, 'active' => true,
        ]);
        $this->category = Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $this->kitchen->id, 'active' => true,
        ]);

        // Burger needs 150g patty + 1 bun
        $this->patty = $this->ing('Patty', $this->gram, stock: 100);   // only 100g
        $this->bun   = $this->ing('Bun',   $this->pcs,  stock: 10);

        $this->burger = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'Burger', 'price' => 10,
            'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->patty->id,
                            'quantity' => 150, 'unit_id' => $this->gram->id]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->bun->id,
                            'quantity' => 1,   'unit_id' => $this->pcs->id]);

        // Active table session so the cart middleware lets us in.
        $this->table = Table::create([
            'number' => '1', 'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $this->session = TableSession::create([
            'table_id' => $this->table->id, 'cover_count' => 1, 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** Stock is insufficient (need 150g, have 100g) → availableToOrder=false. */
    public function test_menu_item_reports_out_of_stock_when_an_ingredient_is_short(): void
    {
        $this->burger->load('recipeItems.ingredient');
        $this->assertFalse($this->burger->availableToOrder(),
            'Burger needs 150g patty but only 100g is on hand → must not be orderable.');

        $shortages = $this->burger->stockShortages();
        $this->assertCount(1, $shortages);
        $this->assertSame('Patty', $shortages[0]['ingredient']);
    }

    /** Topping up stock flips availability back on. */
    public function test_menu_item_becomes_orderable_again_after_stock_top_up(): void
    {
        IngredientStock::where('ingredient_id', $this->patty->id)
            ->update(['quantity' => 5000]);
        $this->patty->update(['current_stock' => 5000]);

        $this->burger->load('recipeItems.ingredient');
        $this->assertTrue($this->burger->availableToOrder());
    }

    /**
     * The cart-add controller's stock check is just a thin wrapper
     * around `InventoryService::checkStockForOrderPreview`. Testing the
     * service directly avoids the customer-session middleware ceremony
     * while still proving the gate fires for the same shortage condition.
     */
    public function test_inventory_service_flags_shortage_for_out_of_stock_item(): void
    {
        $issues = app(InventoryService::class)->checkStockForOrderPreview([[
            'menu_item_id' => $this->burger->id,
            'quantity'     => 1,
            'modifier_ids' => [],
        ]]);

        $this->assertCount(1, $issues);
        $this->assertSame('Patty', $issues[0]['ingredient']);
        $this->assertSame(150.0, (float) $issues[0]['required']);
        $this->assertSame(100.0, (float) $issues[0]['available']);
    }

    /** Cumulative gate: 1 burger fits 200g stock; 2 burgers (300g) don't. */
    public function test_cumulative_cart_demand_correctly_aggregates_per_ingredient(): void
    {
        IngredientStock::where('ingredient_id', $this->patty->id)
            ->update(['quantity' => 200]);
        $this->patty->update(['current_stock' => 200]);

        // One burger — passes.
        $one = app(InventoryService::class)->checkStockForOrderPreview([[
            'menu_item_id' => $this->burger->id,
            'quantity'     => 1,
            'modifier_ids' => [],
        ]]);
        $this->assertEmpty($one);

        // Two burgers — 300g needed, only 200g available.
        $two = app(InventoryService::class)->checkStockForOrderPreview([[
            'menu_item_id' => $this->burger->id,
            'quantity'     => 2,
            'modifier_ids' => [],
        ]]);
        $this->assertCount(1, $two);
        $this->assertSame(300.0, (float) $two[0]['required']);
    }

    public function test_stock_in_another_branch_cannot_cover_this_kitchens_shortage(): void
    {
        $otherBranch = Branch::create(['code' => 'other', 'name' => 'Other', 'is_active' => true]);
        $otherStorage = BranchContext::forBranch($otherBranch->id, fn () => StorageLocation::create([
            'branch_id' => $otherBranch->id,
            'code' => 'other-k',
            'name' => 'Other kitchen',
            'is_default' => true,
            'active' => true,
        ]));
        IngredientStock::create([
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $otherStorage->id,
            'quantity' => 10000,
            'reorder_threshold' => 0,
        ]);
        $this->patty->update(['current_stock' => 10100]);

        $issues = app(InventoryService::class)->checkStockForOrderPreview([[
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
            'modifier_ids' => [],
        ]], $this->branch->id);

        $this->assertCount(1, $issues);
        $this->assertSame(100.0, (float) $issues[0]['available']);
    }

    public function test_strict_stock_blocks_a_negative_location_balance(): void
    {
        try {
            app(InventoryService::class)->recordMovement(
                ingredient: $this->patty,
                type: 'out',
                qtyBase: 150,
                storageLocationId: $this->storage->id,
            );
            $this->fail('Expected the local stock guard to reject a negative balance.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(100.0, (float) IngredientStock::where([
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $this->storage->id,
        ])->value('quantity'));
    }

    public function test_qr_submit_token_prevents_a_duplicate_order(): void
    {
        IngredientStock::where('ingredient_id', $this->patty->id)->update(['quantity' => 1000]);
        $this->patty->update(['current_stock' => 1000]);
        $cart = [[
            'id' => 'line-1',
            'menu_item_id' => $this->burger->id,
            'name' => 'Burger',
            'image' => '',
            'quantity' => 1,
            'unit_price' => 10,
            'modifier_ids' => [],
            'modifiers' => [],
            'modifiers_total' => 0,
            'notes' => null,
            'subtotal' => 10,
        ]];

        $payload = [
            'session' => $this->session->token,
            '_idem' => 'same-mobile-tap',
            'customer_name' => 'زبون اختبار',
            'customer_phone' => '0599000444',
        ];
        $cartKey = 'cart.'.$this->session->token;

        $this->withSession([$cartKey => $cart])->post(route('customer.cart.submit'), $payload);
        // Re-introduce the stale cart snapshot a concurrent second request
        // would have read before the first request cleared the session.
        $this->withSession([$cartKey => $cart])->post(route('customer.cart.submit'), $payload);

        $this->assertSame(1, Order::where('table_session_id', $this->session->id)->count());
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function ing(string $name, Unit $unit, float $stock): Ingredient
    {
        $ing = Ingredient::create([
            'name'              => $name,
            'base_unit_id'      => $unit->id,
            'current_stock'     => $stock,
            'reorder_threshold' => 0,
            'cost_per_unit'     => 1,
            'track_stock'       => true,
            'active'            => true,
        ]);
        IngredientStock::create([
            'ingredient_id'       => $ing->id,
            'storage_location_id' => $this->storage->id,
            'quantity'            => $stock,
            'reorder_threshold'   => 0,
        ]);
        return $ing;
    }
}
