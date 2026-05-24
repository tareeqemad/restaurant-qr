<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\IngredientUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alternate-unit (pack-size) coverage:
 *   1. PO receipt math uses the ingredient_unit factor when set
 *      (5 cartons × 24 cans = 120 cans land in stock).
 *   2. Effective cost is grossed down accordingly — paid 30 ש"ח for
 *      24 cans means 1.25 ש"ח per can on the moving average.
 *   3. Barcode lookup endpoint resolves to the right (ingredient, unit).
 *   4. Barcode 404 has a useful message.
 */
class IngredientUnitsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected Unit $pcs;
    protected StorageLocation $storage;
    protected Supplier $supplier;
    protected Ingredient $cola;
    protected IngredientUnit $carton24;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'iu', 'name' => 'IU', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'a_t', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);

        $this->pcs = Unit::create([
            'code' => 'pcs', 'name' => 'pcs',
            'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true,
        ]);

        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'Main',
            'is_default' => true, 'active' => true,
        ]);

        $this->supplier = Supplier::create([
            'name' => 'مورد كولا', 'active' => true,
        ]);

        $this->cola = Ingredient::create([
            'name' => 'علبة كولا',
            'base_unit_id' => $this->pcs->id,
            'current_stock' => 0,
            'reorder_threshold' => 0,
            'cost_per_unit' => 0,
            'track_stock' => true,
            'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->cola->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => 0, 'reorder_threshold' => 0,
        ]);

        // Pack: 1 carton = 24 cans, supplier sells the carton at 30 ש"ח
        $this->carton24 = IngredientUnit::create([
            'ingredient_id'       => $this->cola->id,
            'name'                => 'كرتون 24 علبة',
            'factor_to_base'      => 24,
            'barcode'             => '6285000123456',
            'purchase_price'      => 30,
            'sale_price'          => null,
            'is_default_purchase' => true,
            'active'              => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /**
     * Receiving 5 cartons (line in carton-unit) MUST land 120 cans
     * (5 × 24) in stock, and the weighted-average cost MUST be the
     * per-can derivative (30/24 ≈ 1.25 ש"ח per can).
     */
    public function test_po_receipt_multiplies_by_alternate_unit_factor(): void
    {
        $this->actingAs($this->admin);

        $po = PurchaseOrder::create([
            'branch_id'   => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'number'      => PurchaseOrder::generateNumber(),
            'status'      => 'sent',     // receive() requires sent / partially_received
            'subtotal'    => 150, 'total' => 150,
            'created_by'  => $this->admin->id,
        ]);

        // The line is in the pack unit — quantity_ordered = 5 cartons,
        // unit_price = 30 (price per carton), ingredient_unit_id = carton.
        $line = PurchaseOrderItem::create([
            'purchase_order_id'  => $po->id,
            'ingredient_id'      => $this->cola->id,
            'unit_id'            => $this->pcs->id,
            'ingredient_unit_id' => $this->carton24->id,
            'quantity_ordered'   => 5,
            'unit_price'         => 30,
            'subtotal'           => 150,
        ]);

        app(PurchaseOrderService::class)->receive(
            po:       $po,
            receipts: [$line->id => 5],   // received 5 cartons
            userId:   $this->admin->id,
            meta:     [$line->id => ['storage_location_id' => $this->storage->id]],
        );

        $this->cola->refresh();
        $this->assertSame(120.0, (float) $this->cola->current_stock,
            '5 cartons × 24 cans/carton = 120 cans on the shelf.');

        // Weighted-average cost: paid 30 per carton, 24 cans each →
        // 1.25 per can.
        $this->assertEqualsWithDelta(1.25, (float) $this->cola->cost_per_unit, 0.0001,
            'Per-base-unit cost MUST be the pack price divided by the factor.');
    }

    /**
     * Barcode lookup → returns the ingredient + unit + factor so the
     * scanner-driven UI can fill the row in one fetch.
     */
    public function test_barcode_lookup_resolves_to_ingredient_unit_pair(): void
    {
        $this->actingAs($this->admin);

        $res = $this->getJson(route('admin.inventory.by_barcode', ['barcode' => '6285000123456']));
        $res->assertOk();
        $res->assertJson([
            'ok'   => true,
            'kind' => 'ingredient_unit',
            'unit' => [
                'id'             => $this->carton24->id,
                'name'           => 'كرتون 24 علبة',
                'factor_to_base' => 24.0,
                'purchase_price' => 30.0,
            ],
            'ingredient' => [
                'id'   => $this->cola->id,
                'name' => 'علبة كولا',
            ],
        ]);
    }

    /** Unknown barcode → 404 with a clear message naming the code. */
    public function test_barcode_lookup_returns_404_for_unknown_code(): void
    {
        $this->actingAs($this->admin);

        $res = $this->getJson(route('admin.inventory.by_barcode', ['barcode' => '9999999999999']));
        $res->assertStatus(404);
        $this->assertSame('not_found', $res->json('error'));
        $this->assertStringContainsString('9999999999999', $res->json('message') ?? '');
    }

    /**
     * Mixed-units variance: PO line ordered in 24-can cartons, but
     * the supplier invoice itemises the same delivery as 120 single
     * cans. Naive subtraction (120 - 5) would print nonsense; the
     * normalised math must spot they're actually equal (variance 0).
     */
    public function test_supplier_invoice_variance_normalises_mixed_units(): void
    {
        $this->actingAs($this->admin);

        $po = PurchaseOrder::create([
            'branch_id'   => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'number'      => PurchaseOrder::generateNumber(),
            'status'      => 'sent',
            'subtotal'    => 150, 'total' => 150,
            'created_by'  => $this->admin->id,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id'  => $po->id,
            'ingredient_id'      => $this->cola->id,
            'unit_id'            => $this->pcs->id,
            'ingredient_unit_id' => $this->carton24->id,
            'quantity_ordered'   => 5,
            'unit_price'         => 30,
            'subtotal'           => 150,
        ]);

        app(PurchaseOrderService::class)->receive(
            po:       $po,
            receipts: [$line->id => 5],
            userId:   $this->admin->id,
            meta:     [$line->id => ['storage_location_id' => $this->storage->id]],
        );

        // Now create a supplier invoice — itemised in single cans (no
        // ingredient_unit_id passed, so SI line is in the base unit).
        $si = app(\App\Services\SupplierInvoiceService::class)->create(
            data: [
                'supplier_id'  => $this->supplier->id,
                'number'       => 'INV-001',
                'invoice_date' => now()->toDateString(),
                'due_date'     => now()->addDays(30)->toDateString(),
                'subtotal'     => 150, 'tax_total' => 0, 'total' => 150,
                'status'       => 'received',
                'lines' => [[
                    'purchase_order_item_id' => $line->id,
                    'ingredient_id'          => $this->cola->id,
                    'unit_id'                => $this->pcs->id,
                    'description'            => 'علبة كولا',
                    'quantity'               => 120,    // 120 cans — single unit
                    'unit_price'             => 1.25,
                    'tax_total'              => 0,
                ]],
            ],
            userId: $this->admin->id,
        );

        $siLine = $si->items()->first();
        $this->assertEqualsWithDelta(0.0, (float) $siLine->variance_qty, 0.0001,
            'Mixed units must normalise to base before subtracting; 120 cans = 5 cartons → variance 0.');
        $this->assertEqualsWithDelta(0.0, (float) $siLine->variance_total, 0.0001,
            'Money variance was always right (both sides totalled 150).');
    }

    /**
     * The chef-friendly case the operator asked for: recipe says
     * "1 tablespoon sugar", where the tablespoon is defined on the
     * sugar ingredient as 100g. Selling 1 dish must deduct 100g.
     */
    public function test_recipe_using_ingredient_specific_unit_deducts_correct_base_quantity(): void
    {
        $this->actingAs($this->admin);

        // Sugar at 500g on the shelf, with a "ملعقة كبيرة" = 100g unit.
        $g = \App\Models\Unit::create([
            'code' => 'g', 'name' => 'g', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $sugar = \App\Models\Ingredient::create([
            'name' => 'سكر', 'base_unit_id' => $g->id,
            'current_stock' => 500, 'reorder_threshold' => 0,
            'cost_per_unit' => 0.01,
            'track_stock' => true, 'active' => true,
        ]);
        \App\Models\IngredientStock::create([
            'ingredient_id' => $sugar->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => 500, 'reorder_threshold' => 0,
        ]);
        $tbsp = \App\Models\IngredientUnit::create([
            'ingredient_id'  => $sugar->id,
            'name'           => 'ملعقة كبيرة',
            'factor_to_base' => 100,    // The operator's example: 100g per tbsp
            'active'         => true,
        ]);

        // Dessert that uses "1 tbsp sugar" — qty=1, ingredient_unit=tbsp.
        $cat = \App\Models\Category::create([
            'name' => 'حلويات', 'slug' => 'desserts-iu',
            'default_station_id' => null, 'active' => true,
        ]);
        $kunafa = \App\Models\MenuItem::create([
            'category_id' => $cat->id, 'sku' => 'D-IU', 'slug' => 'kunafa-iu',
            'name' => 'كنافة', 'price' => 10, 'is_available' => true,
        ]);
        \App\Models\RecipeItem::create([
            'menu_item_id'       => $kunafa->id,
            'ingredient_id'      => $sugar->id,
            'quantity'           => 1,
            'unit_id'            => $g->id,         // legacy fallback
            'ingredient_unit_id' => $tbsp->id,
        ]);

        // Preview math: 1 tbsp × 100g factor = 100g deducted.
        $lines = app(\App\Services\InventoryService::class)
            ->previewDeductionForItem($kunafa->fresh(), 1.0);
        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(100.0, (float) $lines[0]['quantity_in_base'], 0.0001);
        $this->assertSame($sugar->id, $lines[0]['ingredient_id']);

        // Cost: 100g × 0.01/g = 1.00 — RecipeCostService must use
        // the same factor as the deduction math. recomputeMenuItem
        // returns the new total cost AND persists it on menu_items.cost.
        $cost = app(\App\Services\RecipeCostService::class)->recomputeMenuItem($kunafa->fresh());
        $this->assertEqualsWithDelta(1.0, $cost, 0.0001);
    }

    /** Barcode is globally unique — assigning the same code to a
     *  different ingredient must be rejected at DB level. */
    public function test_barcode_must_be_globally_unique(): void
    {
        $water = Ingredient::create([
            'name' => 'مياه', 'base_unit_id' => $this->pcs->id,
            'current_stock' => 0, 'reorder_threshold' => 0, 'cost_per_unit' => 0,
            'track_stock' => true, 'active' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        IngredientUnit::create([
            'ingredient_id'  => $water->id,
            'name'           => 'كرتون',
            'factor_to_base' => 12,
            'barcode'        => '6285000123456',   // same as cola's carton
            'active'         => true,
        ]);
    }
}
