<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientUnit;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 4 (MIGRATION-PILOT.md §13): the purchasing chain on Inertia/Vue —
 * the PO line builder (one page serving create AND edit) and the goods
 * receipt form.
 *
 * The receipt's actual-price feature is the one that matters beyond the
 * screen: whatever price is entered at receiving becomes the weighted
 * average cost and the supplier's price history. The controller owns this
 * behavior so the screen cannot drift from the write path.
 */
class PurchasingVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $manager;
    protected Supplier $supplier;
    protected Ingredient $flour;
    protected Unit $gram;
    protected StorageLocation $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'pu', 'name' => 'PU', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'is_system' => true]);
        // PO creation is permission-gated, so the role needs its grants.
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->manager = User::create([
            'name' => 'M', 'username' => 'pu_manager', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'manager',
        ]);
        $this->manager->branches()->attach($this->branch->id);

        $this->gram = Unit::create([
            'code' => 'g', 'name' => 'جرام', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $this->store = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 's', 'name' => 'المخزن',
            'is_default' => true, 'active' => true,
        ]);
        $this->supplier = Supplier::create(['name' => 'مورّد الطحين', 'active' => true]);
        $this->supplier->branches()->attach($this->branch->id);

        $this->flour = Ingredient::create([
            'name' => 'طحين', 'base_unit_id' => $this->gram->id, 'current_stock' => 0,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
            'supplier_id' => $this->supplier->id,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function makePo(): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create(
            data: ['supplier_id' => $this->supplier->id, 'expected_at' => null, 'notes' => null],
            lines: [[
                'ingredient_id' => $this->flour->id,
                'unit_id' => $this->gram->id,
                'quantity_ordered' => 1000,
                'unit_price' => 2,
            ]],
            userId: $this->manager->id,
        );
    }

    public function test_create_serves_the_builder_with_an_empty_draft(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PurchaseOrders/Form')
                ->where('po.id', null)
                ->has('po.submitUrl')
                ->has('suppliers', 1)
                ->has('ingredients', 1, fn (Assert $i) => $i
                    ->where('name', 'طحين')
                    ->where('baseUnitCode', 'g')
                    ->has('packs')
                    ->etc()));
    }

    public function test_edit_serves_the_same_builder_seeded_with_the_po(): void
    {
        $po = $this->makePo();

        $this->actingAs($this->manager)
            ->get(route('admin.purchase-orders.edit', $po))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // ONE component for both create and edit.
                ->component('Admin/PurchaseOrders/Form')
                ->where('po.id', $po->id)
                ->where('po.number', $po->number)
                ->has('po.lines', 1, fn (Assert $l) => $l
                    ->where('ingredient_id', $this->flour->id)
                    ->where('quantity_ordered', 1000)
                    ->where('unit_price', 2)
                    ->etc()));
    }

    public function test_purchase_packs_reach_the_builder(): void
    {
        IngredientUnit::create([
            'ingredient_id' => $this->flour->id,
            'name' => 'كرتونة 12 كغم',
            'factor_to_base' => 12000,
            'purchase_price' => 90,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.purchase-orders.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('ingredients.0.packs', 1, fn (Assert $p) => $p
                    ->where('name', 'كرتونة 12 كغم')
                    ->where('factorToBase', 12000)
                    ->where('purchasePrice', 90)
                    ->where('isDefaultPurchase', true)
                    ->etc()));
    }

    public function test_store_still_validates_every_line(): void
    {
        // A line with no ingredient must be refused, not silently dropped.
        $this->actingAs($this->manager)
            ->post(route('admin.purchase-orders.store'), [
                'supplier_id' => $this->supplier->id,
                'lines' => [[
                    'ingredient_id' => '',
                    'unit_id' => $this->gram->id,
                    'quantity_ordered' => 5,
                    'unit_price' => 1,
                ]],
            ])
            ->assertSessionHasErrors('lines.0.ingredient_id');
    }

    public function test_the_receive_form_ships_lines_and_branch_own_locations(): void
    {
        $po = $this->makePo();
        $po->update(['status' => 'sent']);

        // A location in ANOTHER branch must never be offered as a destination.
        $otherBranch = Branch::create(['code' => 'zz', 'name' => 'ZZ', 'is_active' => true]);
        StorageLocation::create([
            'branch_id' => $otherBranch->id, 'code' => 'x', 'name' => 'مخزن غريب',
            'is_default' => true, 'active' => true,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.purchase-orders.receive-form', $po))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PurchaseOrders/Receive')
                ->has('lines', 1, fn (Assert $l) => $l
                    ->where('ingredientName', 'طحين')
                    ->where('ordered', 1000)
                    ->where('outstanding', 1000)
                    ->where('unitPrice', 2)
                    ->etc())
                ->has('locations', 1, fn (Assert $loc) => $loc
                    ->where('label', 'المخزن (s)')
                    ->etc()));
    }

    public function test_a_partial_receipt_leaves_the_rest_outstanding(): void
    {
        $po = $this->makePo();
        $po->update(['status' => 'sent']);
        $line = $po->items->first();

        $this->actingAs($this->manager)
            ->post(route('admin.purchase-orders.receive', $po), [
                'receipts' => [$line->id => 400],
                'storage_location_id' => $this->store->id,
            ])
            ->assertRedirect(route('admin.purchase-orders.show', $po));

        $this->assertSame(400.0, (float) $line->fresh()->quantity_received);
        $this->assertSame(400.0, (float) $this->flour->fresh()->current_stock);
    }

    /**
     * The actual invoiced price entered at receiving must drive the
     * ingredient's cost — the controller had no such field before Wave 4,
     * so a migration that merely "ported the form" would have dropped it.
     */
    public function test_the_actual_price_at_receiving_moves_the_ingredient_cost(): void
    {
        $po = $this->makePo();
        $po->update(['status' => 'sent']);
        $line = $po->items->first();

        $this->actingAs($this->manager)
            ->post(route('admin.purchase-orders.receive', $po), [
                'receipts' => [$line->id => 1000],
                'unit_prices' => [$line->id => 5],   // invoice says 5, PO said 2
                'storage_location_id' => $this->store->id,
            ])
            ->assertRedirect();

        // Stock was empty, so the weighted average becomes the paid price.
        $this->assertSame(5.0, round((float) $this->flour->fresh()->cost_per_unit, 4));
    }

    public function test_a_negative_receipt_price_is_refused(): void
    {
        $po = $this->makePo();
        $po->update(['status' => 'sent']);
        $line = $po->items->first();

        $this->actingAs($this->manager)
            ->post(route('admin.purchase-orders.receive', $po), [
                'receipts' => [$line->id => 100],
                'unit_prices' => [$line->id => -3],
                'storage_location_id' => $this->store->id,
            ])
            ->assertSessionHasErrors('unit_prices.'.$line->id);

        $this->assertSame(0.0, (float) $line->fresh()->quantity_received);
    }

    public function test_reorder_bulk_create_routes_a_line_to_the_chosen_supplier(): void
    {
        $cheaper = Supplier::create(['name' => 'مورّد أرخص', 'active' => true]);
        $cheaper->branches()->attach($this->branch->id);

        $this->actingAs($this->manager)
            ->post(route('admin.reports.reorder-suggestions.bulk-create'), [
                // "id:qty:supplierId" — route it away from the default supplier.
                'selections' => ["{$this->flour->id}:500:{$cheaper->id}"],
            ])
            ->assertRedirect();

        $po = PurchaseOrder::latest('id')->first();
        $this->assertNotNull($po);
        $this->assertSame($cheaper->id, $po->supplier_id,
            'the chosen supplier must win over the ingredient default');
    }

    public function test_a_forged_supplier_id_falls_back_to_the_default(): void
    {
        // Inactive supplier — must not be usable even if the client posts it.
        $dodgy = Supplier::create(['name' => 'مورّد موقوف', 'active' => false]);

        $this->actingAs($this->manager)
            ->post(route('admin.reports.reorder-suggestions.bulk-create'), [
                'selections' => ["{$this->flour->id}:500:{$dodgy->id}"],
            ])
            ->assertRedirect();

        $po = PurchaseOrder::latest('id')->first();
        $this->assertNotNull($po);
        $this->assertSame($this->supplier->id, $po->supplier_id);
    }
}
