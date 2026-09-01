<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryPurchasingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $manager;

    protected Supplier $supplier;

    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'inv-work', 'name' => 'فرع المخزون', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        $this->manager = User::create([
            'name' => 'مدير المخزون', 'username' => 'inventory-workspace-manager',
            'password' => bcrypt('password'), 'status' => 'active', 'role' => 'manager',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->manager->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);
        $this->supplier = Supplier::create(['name' => 'مورد الاختبار', 'active' => true, 'payment_terms_days' => 14]);
        $this->supplier->branches()->attach($this->branch->id);
        $this->unit = Unit::create([
            'code' => 'g-work', 'name' => 'غرام اختبار', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_supplier_pages_are_vue_and_carry_the_inventory_workspace(): void
    {
        $this->actingAs($this->manager)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.suppliers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Suppliers/Index')
                ->where('shell.workspace.type', 'inventory')
                ->where('shell.workspace.active', 'suppliers')
                ->has('shell.workspace.groups', 5)
                ->has('suppliers.data', 1)
                ->where('suppliers.data.0.name', 'مورد الاختبار'));

        $this->actingAs($this->manager)
            ->get(route('admin.suppliers.edit', $this->supplier))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Suppliers/Form')
                ->where('supplier.paymentTermsDays', 14)
                ->where('branches.0.editable', true));

        $this->actingAs($this->manager)
            ->get(route('admin.suppliers.show', $this->supplier))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Suppliers/Show')
                ->where('supplier.name', 'مورد الاختبار')
                ->where('totals.ingredientCount', 0));
    }

    public function test_stock_count_entry_pages_are_vue_and_do_not_apply_stock_on_creation(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.stock-counts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StockCounts/Index')
                ->where('shell.workspace.active', 'counts')
                ->where('stats.draftCount', 0));

        $this->actingAs($this->manager)
            ->get(route('admin.stock-counts.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StockCounts/Create')
                ->where('ingredientCount', 0)
                ->has('urls.store'));
    }

    public function test_unit_pages_are_vue_and_an_in_use_unit_cannot_be_deleted(): void
    {
        Ingredient::create([
            'name' => 'طحين الاختبار', 'base_unit_id' => $this->unit->id,
            'current_stock' => 0, 'reorder_threshold' => 0, 'cost_per_unit' => 0,
            'track_stock' => true, 'active' => true,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.units.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Units/Index')
                ->where('units.data.0.used', true)
                ->where('shell.workspace.active', 'units'));

        $this->actingAs($this->manager)
            ->delete(route('admin.units.destroy', $this->unit))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('units', ['id' => $this->unit->id]);
    }

    public function test_supplier_invoice_create_and_show_are_vue(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.supplier-invoices.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SupplierInvoices/Create')
                ->where('shell.workspace.active', 'supplierInvoices')
                ->where('suppliers.0.name', 'مورد الاختبار'));

        $invoice = SupplierInvoice::create([
            'branch_id' => $this->branch->id,
            'number' => 'SUP-WORK-1', 'supplier_id' => $this->supplier->id,
            'subtotal' => 100, 'tax_total' => 0, 'total' => 100,
            'paid_total' => 0, 'balance' => 100, 'status' => 'unpaid',
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(14)->toDateString(),
            'created_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.supplier-invoices.show', $invoice))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SupplierInvoices/Show')
                ->where('invoice.number', 'SUP-WORK-1')
                ->where('invoice.amounts.balanceRaw', 100)
                ->where('can.pay', true));
    }

    public function test_partially_invoiced_receipt_prefills_only_the_remaining_quantity(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'أرز للاختبار',
            'base_unit_id' => $this->unit->id,
            'current_stock' => 10,
            'cost_per_unit' => 10,
            'track_stock' => true,
            'active' => true,
        ]);
        $po = PurchaseOrder::create([
            'branch_id' => $this->branch->id,
            'number' => 'PO-PARTIAL-INVOICE',
            'supplier_id' => $this->supplier->id,
            'status' => 'received',
            'currency_code' => 'ILS',
            'exchange_rate' => 1,
            'subtotal' => 100,
            'total' => 100,
            'received_at' => now(),
            'approved_at' => now(),
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'ingredient_id' => $ingredient->id,
            'unit_id' => $this->unit->id,
            'quantity_ordered' => 10,
            'quantity_received' => 10,
            'unit_price' => 10,
            'subtotal' => 100,
        ]);
        $firstInvoice = SupplierInvoice::create([
            'branch_id' => $this->branch->id,
            'number' => 'SUP-PARTIAL-1',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'subtotal' => 40,
            'total' => 40,
            'balance' => 40,
            'status' => 'unpaid',
            'invoice_date' => today(),
        ]);
        SupplierInvoiceItem::create([
            'supplier_invoice_id' => $firstInvoice->id,
            'purchase_order_item_id' => $poItem->id,
            'ingredient_id' => $ingredient->id,
            'unit_id' => $this->unit->id,
            'description' => $ingredient->name,
            'quantity' => 4,
            'received_qty' => 4,
            'unit_price' => 10,
            'subtotal' => 40,
            'total' => 40,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.supplier-invoices.create', ['po' => $po->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedPo.id', $po->id)
                ->where('selectedPo.subtotal', 60)
                ->where('selectedPo.total', 60)
                ->has('lines', 1)
                ->where('lines.0.receivedQty', 6)
                ->where('lines.0.quantity', 6));

        $firstInvoice->update(['status' => 'cancelled']);
        $this->assertFalse($po->fresh([
            'items.supplierInvoiceItems.supplierInvoice',
        ])->isFullyInvoiced());
    }

    public function test_price_compare_is_vue_even_before_the_first_receipt(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.vendor-prices.compare'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/VendorPrices/Compare')
                ->where('shell.workspace.active', 'prices')
                ->has('rows', 0));
    }
}
