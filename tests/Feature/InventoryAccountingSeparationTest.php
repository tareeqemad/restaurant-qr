<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BatchInventoryService;
use App\Services\BranchTransferService;
use App\Services\LocationInventoryService;
use App\Services\PurchaseOrderService;
use App\Services\StockCountService;
use App\Services\SupplierInvoiceService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryAccountingSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;
    private Branch $b;
    private StorageLocation $storeA;
    private StorageLocation $storeB;
    private Unit $piece;
    private User $owner;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Branch::create(['code' => 'sep-a', 'name' => 'فرع أ', 'is_active' => true]);
        $this->b = Branch::create(['code' => 'sep-b', 'name' => 'فرع ب', 'is_active' => true]);
        BranchContext::set($this->a->id);

        Role::create(['name' => 'super_admin', 'label' => 'Owner', 'is_system' => true]);
        $this->owner = User::create([
            'name' => 'Owner', 'username' => 'separation_owner', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->a->id, 'role' => 'super_admin',
        ]);

        $this->piece = Unit::create([
            'code' => 'pcs', 'name' => 'قطعة', 'unit_type' => 'count',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $this->storeA = StorageLocation::create([
            'branch_id' => $this->a->id, 'code' => 'a-main', 'name' => 'مخزن أ',
            'is_default' => true, 'active' => true,
        ]);
        BranchContext::set($this->b->id);
        $this->storeB = StorageLocation::create([
            'branch_id' => $this->b->id, 'code' => 'b-main', 'name' => 'مخزن ب',
            'is_default' => true, 'active' => true,
        ]);
        BranchContext::set($this->a->id);

        $this->supplier = Supplier::create(['name' => 'مورد مشترك', 'active' => true]);
        $this->supplier->branches()->attach([$this->a->id, $this->b->id]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function ingredient(string $name, float $cost = 2, array $extra = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'name' => $name,
            'base_unit_id' => $this->piece->id,
            'current_stock' => 0,
            'reorder_threshold' => 0,
            'cost_per_unit' => $cost,
            'track_stock' => true,
            'active' => true,
        ], $extra));
    }

    private function purchaseOrder(Ingredient $ingredient, Branch $branch, float $ordered = 10, float $received = 0, float $price = 2): array
    {
        $po = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'number' => 'PO-'.$branch->code.'-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
            'currency_code' => 'ILS',
            'exchange_rate' => 1,
            'order_date' => today(),
            'approved_at' => now(),
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'ingredient_id' => $ingredient->id,
            'unit_id' => $this->piece->id,
            'quantity_ordered' => $ordered,
            'quantity_received' => $received,
            'unit_price' => $price,
        ]);

        return [$po, $item];
    }

    public function test_receipt_cannot_land_in_another_branch_warehouse(): void
    {
        $ingredient = $this->ingredient('أرز');
        [$po, $item] = $this->purchaseOrder($ingredient, $this->a);

        try {
            app(PurchaseOrderService::class)->receive(
                $po,
                [$item->id => 4],
                $this->owner->id,
                [$item->id => ['storage_location_id' => $this->storeB->id]],
            );
            $this->fail('Cross-branch receipt should have been rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, IngredientStock::where('ingredient_id', $ingredient->id)->count());
            $this->assertSame(0, InventoryMovement::withoutGlobalScopes()->where('ingredient_id', $ingredient->id)->count());
        }
    }

    public function test_one_supplier_can_supply_both_branches_without_mixing_stock_or_cost(): void
    {
        $ingredient = $this->ingredient('أرز بسمتي');
        [$poA, $itemA] = $this->purchaseOrder($ingredient, $this->a, ordered: 10, price: 4);
        app(PurchaseOrderService::class)->receive(
            $poA,
            [$itemA->id => 10],
            $this->owner->id,
            [$itemA->id => ['storage_location_id' => $this->storeA->id]],
        );

        BranchContext::set($this->b->id);
        [$poB, $itemB] = $this->purchaseOrder($ingredient, $this->b, ordered: 5, price: 7);
        app(PurchaseOrderService::class)->receive(
            $poB,
            [$itemB->id => 5],
            $this->owner->id,
            [$itemB->id => ['storage_location_id' => $this->storeB->id]],
        );

        $this->assertSame($this->supplier->id, (int) $poA->supplier_id);
        $this->assertSame($this->supplier->id, (int) $poB->supplier_id);
        $this->assertEqualsWithDelta(10, $ingredient->stockAtBranch($this->a->id), 0.0001);
        $this->assertEqualsWithDelta(5, $ingredient->stockAtBranch($this->b->id), 0.0001);
        $this->assertEqualsWithDelta(4, $ingredient->costAtBranch($this->a->id), 0.0001);
        $this->assertEqualsWithDelta(7, $ingredient->costAtBranch($this->b->id), 0.0001);
    }

    public function test_branch_wide_count_uses_only_that_branch_and_adjusts_its_default_location(): void
    {
        $ingredient = $this->ingredient('سكر', 3);
        IngredientStock::create(['ingredient_id' => $ingredient->id, 'storage_location_id' => $this->storeA->id, 'quantity' => 10]);
        IngredientStock::create(['ingredient_id' => $ingredient->id, 'storage_location_id' => $this->storeB->id, 'quantity' => 50]);
        $ingredient->update(['current_stock' => 60]);

        $service = app(StockCountService::class);
        $count = $service->create([], $this->owner->id);
        $line = $count->items()->where('ingredient_id', $ingredient->id)->firstOrFail();
        $this->assertEqualsWithDelta(10, (float) $line->system_qty, 0.0001);

        $service->saveCounts($count, [$line->id => 8]);
        $service->finalize($count->fresh(), $this->owner->id);

        $this->assertEqualsWithDelta(8, (float) IngredientStock::where('ingredient_id', $ingredient->id)->where('storage_location_id', $this->storeA->id)->value('quantity'), 0.0001);
        $this->assertEqualsWithDelta(50, (float) IngredientStock::where('ingredient_id', $ingredient->id)->where('storage_location_id', $this->storeB->id)->value('quantity'), 0.0001);
        $this->assertEqualsWithDelta(58, (float) $ingredient->fresh()->current_stock, 0.0001);
    }

    public function test_supplier_invoice_enforces_branch_supplier_po_and_server_total(): void
    {
        $ingredient = $this->ingredient('طحين');
        [$po, $poItem] = $this->purchaseOrder($ingredient, $this->a, ordered: 5, received: 5, price: 10);
        $po->update(['status' => 'received']);

        $other = Supplier::create(['name' => 'مورد آخر', 'active' => true]);
        $other->branches()->attach($this->a->id);

        $base = [
            'number' => 'SUP-SEP-1',
            'branch_id' => $this->a->id,
            'purchase_order_id' => $po->id,
            'invoice_date' => today()->toDateString(),
            'subtotal' => 50,
            'tax_total' => 0,
            'total' => 999,
            'lines' => [[
                'purchase_order_item_id' => $poItem->id,
                'description' => 'طحين',
                'quantity' => 5,
                'unit_price' => 10,
                'tax_total' => 0,
            ]],
        ];

        try {
            app(SupplierInvoiceService::class)->create([...$base, 'supplier_id' => $other->id], $this->owner->id);
            $this->fail('Mismatched PO supplier should have been rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('supplier_invoices', ['number' => 'SUP-SEP-1']);
        }

        $invoice = app(SupplierInvoiceService::class)->create([
            ...$base,
            'number' => 'SUP-SEP-2',
            'supplier_id' => $this->supplier->id,
        ], $this->owner->id);

        $this->assertSame($this->a->id, (int) $invoice->branch_id);
        $this->assertEqualsWithDelta(50, (float) $invoice->total, 0.0001, 'Client-forged total must be ignored.');
    }

    public function test_supplier_payment_rechecks_a_stale_invoice_balance_inside_the_transaction(): void
    {
        $invoice = app(SupplierInvoiceService::class)->create([
            'number' => 'SUP-PAY-LOCK',
            'branch_id' => $this->a->id,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => today()->toDateString(),
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
        ], $this->owner->id);
        $stale = $invoice->fresh();

        app(SupplierInvoiceService::class)->recordPayment($invoice->fresh(), [
            'amount' => 80, 'method' => 'cash', 'paid_on' => today()->toDateString(),
        ], $this->owner->id);

        $this->expectException(ValidationException::class);
        app(SupplierInvoiceService::class)->recordPayment($stale, [
            'amount' => 30, 'method' => 'cash', 'paid_on' => today()->toDateString(),
        ], $this->owner->id);
    }

    public function test_receipt_yield_cost_is_not_grossed_up_twice_in_recipe_cost(): void
    {
        $ingredient = $this->ingredient('دجاج كامل', 0, ['yield_pct' => 50]);
        [$po, $item] = $this->purchaseOrder($ingredient, $this->a, ordered: 10, price: 10);

        app(PurchaseOrderService::class)->receive(
            $po,
            [$item->id => 10],
            $this->owner->id,
            [$item->id => ['storage_location_id' => $this->storeA->id]],
        );

        $ingredient->refresh();
        $this->assertEqualsWithDelta(5, (float) $ingredient->current_stock, 0.0001);
        $this->assertEqualsWithDelta(20, (float) $ingredient->cost_per_unit, 0.0001);
        $this->assertEqualsWithDelta(20, $ingredient->effectiveCostPerUnit(), 0.0001);
    }

    public function test_branch_transfer_preserves_fifo_expiry_cost_and_balances_interbranch_account(): void
    {
        $ingredient = $this->ingredient('لبن', 3, ['tracks_expiry' => true]);
        IngredientStock::create(['ingredient_id' => $ingredient->id, 'storage_location_id' => $this->storeA->id, 'quantity' => 10]);
        $ingredient->update(['current_stock' => 10]);
        $sourceBatch = app(BatchInventoryService::class)->createBatchOnReceipt(
            $ingredient, 10, 3, now()->addDays(5)->toDateString(), 'LOT-A', storageLocationId: $this->storeA->id,
        );

        $service = app(BranchTransferService::class);
        $transfer = $service->create([
            'from_branch_id' => $this->a->id,
            'to_branch_id' => $this->b->id,
        ], [[
            'ingredient_id' => $ingredient->id,
            'quantity_base' => 4,
            'from_location_id' => $this->storeA->id,
            'to_location_id' => $this->storeB->id,
        ]], $this->owner->id);

        $service->send($transfer, $this->owner->id);
        $service->receive($transfer->fresh(), $this->owner->id);

        $destinationBatch = IngredientBatch::withoutGlobalScopes()
            ->where('branch_id', $this->b->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('storage_location_id', $this->storeB->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(6, (float) $sourceBatch->fresh()->remaining_qty, 0.0001);
        $this->assertEqualsWithDelta(4, (float) $destinationBatch->remaining_qty, 0.0001);
        $this->assertEqualsWithDelta(3, (float) $destinationBatch->unit_cost, 0.0001);
        $this->assertSame($sourceBatch->expiry_date->toDateString(), $destinationBatch->expiry_date->toDateString());

        $currentAccountId = Account::where('code', '1160')->value('id');
        $debit = (float) DB::table('journal_lines')->where('account_id', $currentAccountId)->sum('debit');
        $credit = (float) DB::table('journal_lines')->where('account_id', $currentAccountId)->sum('credit');
        $this->assertEqualsWithDelta(12, $debit, 0.0001);
        $this->assertEqualsWithDelta(12, $credit, 0.0001);
        $this->assertTrue(JournalEntry::where('event_type', 'inventory_transfer_source_closed')->where('branch_id', $this->a->id)->exists());
        $this->assertTrue(JournalEntry::withoutGlobalScopes()->where('event_type', 'inventory_transfer_received')->where('branch_id', $this->b->id)->exists());
    }

    public function test_internal_location_transfer_cannot_cross_branches(): void
    {
        $ingredient = $this->ingredient('زيت');

        $this->expectException(ValidationException::class);
        app(LocationInventoryService::class)->transfer(
            $ingredient,
            $this->storeA,
            $this->storeB,
            1,
            userId: $this->owner->id,
        );
    }

    public function test_manual_adjustment_changes_only_the_explicit_branch_location(): void
    {
        $ingredient = $this->ingredient('قهوة', 4);
        IngredientStock::create([
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storeA->id,
            'quantity' => 10,
        ]);
        IngredientStock::create([
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storeB->id,
            'quantity' => 20,
        ]);
        $ingredient->update(['current_stock' => 30]);

        $this->actingAs($this->owner)
            ->post(route('admin.ingredients.adjust', $ingredient), [
                'type' => 'adjustment_out',
                'quantity' => 3,
                'unit_id' => $this->piece->id,
                'storage_location_id' => $this->storeA->id,
                'reason' => 'فرق جرد فرع أ',
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(7, (float) IngredientStock::where([
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storeA->id,
        ])->value('quantity'), 0.0001);
        $this->assertEqualsWithDelta(20, (float) IngredientStock::where([
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storeB->id,
        ])->value('quantity'), 0.0001);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $this->a->id,
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storeA->id,
            'type' => 'adjustment',
            'quantity_in_base' => -3,
        ]);
    }

    public function test_batch_cannot_use_a_location_from_another_supply_branch(): void
    {
        $ingredient = $this->ingredient('سمك');
        [$po, $item] = $this->purchaseOrder($ingredient, $this->a);

        $this->expectException(ValidationException::class);
        app(BatchInventoryService::class)->createBatchOnReceipt(
            ingredient: $ingredient,
            qtyBase: 2,
            unitCost: 5,
            source: $item,
            storageLocationId: $this->storeB->id,
        );
    }
}
