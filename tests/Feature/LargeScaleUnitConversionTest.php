<?php

namespace Tests\Feature;

use App\Helpers\UnitConverter;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PurchaseOrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sanity-check the unit math the operator asked about:
 *   50 tons sugar on the shelf, recipe says "100g sugar per dish" —
 *   one dish must deduct exactly 100g, leaving 49,999,900g.
 *
 * Three concerns proven here:
 *   1. Ton → kg → gram conversion is correct (factor 1,000,000).
 *   2. Decimal columns hold 50-million-gram quantities without
 *      truncation or precision loss.
 *   3. Receiving a PO line in tons lands the right base-unit number
 *      in stock, AND the cost grosses down to per-gram correctly.
 */
class LargeScaleUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $user;
    protected Unit $g;
    protected Unit $kg;
    protected Unit $ton;
    protected StorageLocation $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'big', 'name' => 'Big', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $this->user = User::create([
            'name' => 'A', 'username' => 'a_big', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->user->branches()->attach($this->branch->id);

        // The ton-backfill migration may have already seeded 'ton' in
        // the in-memory test DB. Use firstOrCreate so re-running the
        // suite stays clean either way.
        $this->g   = Unit::firstOrCreate(['code' => 'g'],   ['name' => 'g',   'unit_type' => 'weight', 'factor_to_base' => 1,         'is_base' => true]);
        $this->kg  = Unit::firstOrCreate(['code' => 'kg'],  ['name' => 'kg',  'unit_type' => 'weight', 'factor_to_base' => 1000,      'is_base' => false]);
        $this->ton = Unit::firstOrCreate(['code' => 'ton'], ['name' => 'ton', 'unit_type' => 'weight', 'factor_to_base' => 1_000_000, 'is_base' => false]);

        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'w', 'name' => 'W',
            'is_default' => true, 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** Pure math: ton ↔ kg ↔ g all line up. */
    public function test_unit_converter_handles_ton_kg_gram_chain(): void
    {
        $this->assertEqualsWithDelta(
            1_000_000.0,
            UnitConverter::convert(1, $this->ton->id, $this->g->id),
            0.0001, '1 ton = 1,000,000 g',
        );
        $this->assertEqualsWithDelta(
            1000.0,
            UnitConverter::convert(1, $this->ton->id, $this->kg->id),
            0.0001, '1 ton = 1,000 kg',
        );
        $this->assertEqualsWithDelta(
            0.5,
            UnitConverter::convert(500, $this->kg->id, $this->ton->id),
            0.0001, '500 kg = 0.5 ton',
        );
        $this->assertEqualsWithDelta(
            50.0,
            UnitConverter::convert(50_000_000, $this->g->id, $this->ton->id),
            0.0001, '50,000,000 g = 50 ton',
        );
    }

    /**
     * The operator's exact scenario: 50 tons of sugar on the shelf,
     * a recipe needs 100g per dish, one dish must deduct 100g.
     */
    public function test_recipe_deduction_at_50_tons_stock_subtracts_exact_grams(): void
    {
        $this->actingAs($this->user);

        // 50 tons = 50,000,000 g. The decimal(15,4) column holds up
        // to 99,999,999,999.9999 so this fits with five orders of
        // magnitude of headroom.
        $stockInGrams = 50 * 1_000_000;   // 50,000,000

        $sugar = Ingredient::create([
            'name' => 'سكر', 'base_unit_id' => $this->g->id,
            'current_stock' => $stockInGrams, 'reorder_threshold' => 0,
            'cost_per_unit' => 0.001,   // 1 ש"ח per kg
            'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $sugar->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => $stockInGrams, 'reorder_threshold' => 0,
        ]);

        // Place a dish using 100g sugar.
        $kitchen = Station::create([
            'code' => 'k', 'name' => 'K',
            'storage_location_id' => $this->storage->id, 'active' => true,
        ]);
        $cat = Category::create(['slug' => 'mains-big', 'name' => 'M', 'default_station_id' => $kitchen->id, 'active' => true]);
        $dish = MenuItem::create([
            'category_id' => $cat->id, 'station_id' => $kitchen->id,
            'sku' => 'BIG-1', 'slug' => 'big', 'name' => 'Sugar Dish', 'price' => 5,
            'is_available' => true,
        ]);
        RecipeItem::create([
            'menu_item_id' => $dish->id, 'ingredient_id' => $sugar->id,
            'quantity' => 100, 'unit_id' => $this->g->id,
        ]);

        $table = Table::create(['number' => 'B1', 'capacity' => 2, 'status' => 'occupied', 'active' => true]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'token' => 'big', 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $dish->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->user->id);
        app(OrderService::class)->approve($order, $this->user->id);

        $sugar->refresh();
        $this->assertSame((float) ($stockInGrams - 100), (float) $sugar->current_stock,
            'After one dish: 50,000,000 - 100 = 49,999,900 g on the shelf.');
    }

    /**
     * Receiving a PO line in TONS lands the correct gram-equivalent
     * in stock. The chef can keep writing recipes in grams; the
     * receiver enters "5 tons" and the system does the multiplication.
     */
    public function test_po_receipt_in_tons_lands_correct_gram_quantity_and_cost(): void
    {
        $this->actingAs($this->user);

        $supplier = Supplier::create(['name' => 'Wholesaler', 'active' => true]);
        $sugar = Ingredient::create([
            'name' => 'سكر بالجملة', 'base_unit_id' => $this->g->id,
            'current_stock' => 0, 'reorder_threshold' => 0,
            'cost_per_unit' => 0,
            'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $sugar->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => 0, 'reorder_threshold' => 0,
        ]);

        // PO: 5 tons @ 800 ש"ח / ton = 4000 ש"ח subtotal.
        $po = PurchaseOrder::create([
            'branch_id'   => $this->branch->id,
            'supplier_id' => $supplier->id,
            'number'      => PurchaseOrder::generateNumber(),
            'status'      => 'sent',
            'subtotal'    => 4000, 'total' => 4000,
            'created_by'  => $this->user->id,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'ingredient_id'     => $sugar->id,
            'unit_id'           => $this->ton->id,   // ordered in TONS
            'quantity_ordered'  => 5,
            'unit_price'        => 800,
            'subtotal'          => 4000,
        ]);

        app(PurchaseOrderService::class)->receive(
            po:       $po,
            receipts: [$line->id => 5],   // 5 tons received
            userId:   $this->user->id,
            meta:     [$line->id => ['storage_location_id' => $this->storage->id]],
        );

        $sugar->refresh();
        // 5 tons → 5,000,000 g on the shelf
        $this->assertSame(5_000_000.0, (float) $sugar->current_stock,
            '5 tons × 1,000,000 g/ton = 5,000,000 g in stock.');

        // Per-base-unit cost: 800 ש"ח/ton ÷ 1,000,000 g/ton = 0.0008 ש"ח per gram.
        $this->assertEqualsWithDelta(0.0008, (float) $sugar->cost_per_unit, 0.000001,
            '800/ton → 0.0008/g — the moving average normalises to base.');

        // Total stock value: 5,000,000 g × 0.0008 ש"ח/g = 4000 ש"ח — matches PO.
        $value = (float) $sugar->current_stock * (float) $sugar->cost_per_unit;
        $this->assertEqualsWithDelta(4000.0, $value, 0.01,
            'Stock VALUE must equal the PO total — no money leaks in the conversion.');
    }
}
