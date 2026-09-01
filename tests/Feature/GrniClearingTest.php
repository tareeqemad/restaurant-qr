<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\JournalLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\SupplierInvoiceService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #8 — When the receiver overrides the delivered price, the supplier invoice
 *      must clear GRNI (2300) at the ACTUAL received value, not the notional PO
 *      price. Otherwise 2300 keeps a permanent residue and 5420 shows a purchase
 *      price variance that never happened.
 */
class GrniClearingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected Unit $pcs;
    protected StorageLocation $storage;
    protected Supplier $supplier;
    protected Ingredient $ing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'gr', 'name' => 'GR', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'a_gr', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);

        $this->pcs = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'Main',
            'is_default' => true, 'active' => true,
        ]);
        $this->supplier = Supplier::create(['name' => 'مورد', 'active' => true]);
        $this->supplier->branches()->attach($this->branch->id);

        $this->ing = Ingredient::create([
            'name' => 'لحم', 'base_unit_id' => $this->pcs->id,
            'current_stock' => 0, 'reorder_threshold' => 0, 'cost_per_unit' => 0,
            'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->ing->id, 'storage_location_id' => $this->storage->id,
            'quantity' => 0, 'reorder_threshold' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function acctNet(string $code): float
    {
        $lines = JournalLine::whereHas('account', fn ($q) => $q->where('code', $code))->get();
        return round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2);
    }

    public function test_price_override_at_receipt_still_zeros_grni_and_creates_no_phantom_variance(): void
    {
        $this->actingAs($this->admin);

        // PO priced at 10/unit for 100 units.
        $po = PurchaseOrder::create([
            'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id,
            'number' => PurchaseOrder::generateNumber(), 'status' => 'sent',
            'subtotal' => 1000, 'total' => 1000, 'created_by' => $this->admin->id,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'ingredient_id' => $this->ing->id,
            'unit_id' => $this->pcs->id, 'quantity_ordered' => 100,
            'unit_price' => 10, 'subtotal' => 1000,
        ]);

        // Supplier actually delivered at 12/unit — receiver overrides the price.
        // Receipt: DR 1200 = 1200 / CR 2300 = 1200.
        app(PurchaseOrderService::class)->receive(
            po: $po, receipts: [$line->id => 100], userId: $this->admin->id,
            meta: [$line->id => ['storage_location_id' => $this->storage->id, 'actual_unit_price' => 12]],
        );
        $this->assertSame(-1200.0, $this->acctNet('2300'), 'Receipt should credit GRNI by the actual 1,200.');

        // Supplier invoice matches the delivery: 100 × 12 = 1,200.
        app(SupplierInvoiceService::class)->create([
            'number' => 'SI-1', 'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'description' => 'لحم', 'purchase_order_item_id' => $line->id,
                'ingredient_id' => $this->ing->id, 'unit_id' => $this->pcs->id,
                'quantity' => 100, 'unit_price' => 12, 'tax_total' => 0,
            ]],
        ], $this->admin->id);

        // GRNI must now be fully cleared (debited 1,200 against the 1,200 credit),
        // and NO phantom price variance in 5420. Before the fix, 2300 kept a 200
        // residue and 5420 showed a 200 variance that never existed.
        $this->assertSame(0.0, $this->acctNet('2300'), 'GRNI (2300) must net to zero.');
        $this->assertSame(0.0, $this->acctNet('5420'), 'No phantom purchase-price variance in 5420.');
    }
}
