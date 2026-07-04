<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BatchInventoryService;
use App\Services\InventoryService;
use App\Services\PurchaseOrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #18 — unmanaged stock changes (count / manual adjustment / location waste)
 *       must keep FIFO batches in sync, or batch totals drift from stock and a
 *       later sale either throws "batches < needed" or re-costs ghost batches.
 * #24 — receiving into NEGATIVE stock must not skew the weighted-average cost
 *       above any real purchase price.
 */
class InventoryBatchSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected Unit $pcs;
    protected StorageLocation $storage;
    protected Ingredient $ing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'bs', 'name' => 'BS', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'a_bs', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);

        $this->pcs = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'Main',
            'is_default' => true, 'active' => true,
        ]);

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

    /** Seed a batch-tracked ingredient with `$qty` in stock AND a matching FIFO batch. */
    private function seedBatch(float $qty, float $cost): void
    {
        IngredientStock::where('ingredient_id', $this->ing->id)
            ->where('storage_location_id', $this->storage->id)
            ->update(['quantity' => $qty]);
        $this->ing->update(['current_stock' => $qty, 'cost_per_unit' => $cost]);
        app(BatchInventoryService::class)->createBatchOnReceipt(
            ingredient: $this->ing->fresh(), qtyBase: $qty, unitCost: $cost,
            storageLocationId: $this->storage->id,
        );
    }

    private function batchTotal(): float
    {
        return (float) IngredientBatch::where('ingredient_id', $this->ing->id)->sum('remaining_qty');
    }

    /** #18 — a surplus adjustment materialises a new FIFO layer so batches track stock. */
    public function test_surplus_adjustment_keeps_batches_in_sync(): void
    {
        $this->seedBatch(10, 4);

        app(InventoryService::class)->recordMovement(
            ingredient: $this->ing->fresh(), type: 'adjustment', qtyBase: 5, unitCost: 4,
            reason: 'جرد فائض', storageLocationId: $this->storage->id, syncBatches: true,
        );

        $this->assertSame(15.0, (float) $this->ing->fresh()->current_stock);
        $this->assertSame(15.0, $this->batchTotal(), 'Batch total must track the surplus (10 + 5).');
    }

    /** #18 — a shortage adjustment consumes FIFO batches so no ghost layers linger. */
    public function test_shortage_adjustment_consumes_ghost_batch_qty(): void
    {
        $this->seedBatch(10, 4);

        app(InventoryService::class)->recordMovement(
            ingredient: $this->ing->fresh(), type: 'adjustment', qtyBase: -4, unitCost: 4,
            reason: 'جرد عجز', storageLocationId: $this->storage->id, syncBatches: true,
        );

        $this->assertSame(6.0, (float) $this->ing->fresh()->current_stock);
        $this->assertSame(6.0, $this->batchTotal(), 'Ghost batch qty must be consumed by the shortage.');
    }

    /** #18 — the real symptom: after a surplus, a sale bigger than the original batch must NOT throw. */
    public function test_sale_after_surplus_does_not_block_on_missing_batches(): void
    {
        $this->seedBatch(10, 4);

        app(InventoryService::class)->recordMovement(
            ingredient: $this->ing->fresh(), type: 'adjustment', qtyBase: 5, unitCost: 4,
            reason: 'جرد فائض', storageLocationId: $this->storage->id, syncBatches: true,
        );

        // Deduct 15 (> the original 10-unit batch). BEFORE the fix the surplus
        // created no batch, so deductFifo threw "batches < needed" and blocked
        // the sale/checkout. Now the batch total is 15, so it succeeds.
        $movements = app(InventoryService::class)->deductWithBatchTrace(
            ingredient: $this->ing->fresh(), qtyBase: 15, fallbackUnitCost: 4,
            reason: 'بيع', storageLocationId: $this->storage->id,
        );

        $this->assertNotEmpty($movements);
        $this->assertSame(0.0, $this->batchTotal(), 'All 15 units should have been consumed FIFO.');
    }

    /** #24 — receiving into negative stock sets cost to the lot price, not an inflated extrapolation. */
    public function test_receipt_into_negative_stock_does_not_skew_average_cost(): void
    {
        // Negative stock only arises when strict_stock is off (otherwise the
        // observer blocks it) — that's exactly the condition this bug needs.
        \App\Models\Setting::put('strict_stock', false, 'inventory', 'bool');

        // Oversold: stock is −10 at an old cost of 4.
        IngredientStock::where('ingredient_id', $this->ing->id)
            ->where('storage_location_id', $this->storage->id)
            ->update(['quantity' => -10]);
        $this->ing->update(['current_stock' => -10, 'cost_per_unit' => 4]);

        $supplier = Supplier::create(['name' => 'مورد', 'active' => true]);
        $po = PurchaseOrder::create([
            'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id,
            'number' => PurchaseOrder::generateNumber(), 'status' => 'sent',
            'subtotal' => 100, 'total' => 100, 'created_by' => $this->admin->id,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'ingredient_id' => $this->ing->id,
            'unit_id' => $this->pcs->id, 'quantity_ordered' => 20,
            'unit_price' => 5, 'subtotal' => 100,
        ]);

        app(PurchaseOrderService::class)->receive(
            po: $po, receipts: [$line->id => 20], userId: $this->admin->id,
            meta: [$line->id => ['storage_location_id' => $this->storage->id]],
        );

        $this->ing->refresh();
        $this->assertSame(10.0, (float) $this->ing->current_stock, '−10 + 20 = 10 on the shelf.');
        // BEFORE the fix: (−10×4 + 20×5) / 10 = 6.00 (dearer than any lot bought).
        // AFTER: basis clamped to 0 → 100 / 20 = 5.00.
        $this->assertEqualsWithDelta(5.0, (float) $this->ing->cost_per_unit, 0.0001,
            'Cost must equal the received lot price, not an extrapolation above it.');
    }
}
