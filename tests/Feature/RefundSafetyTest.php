<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Guards the refund-safety fixes:
 *   #2  addPayment must measure balance against NET payments (paid − refunded)
 *       so an invoice can never close "paid" while net cash collected < total.
 *   #13 A written-off / cancelled invoice can no longer be refunded (which used
 *       to resurrect it on the debt board with a balance the ledger no longer held).
 */
class RefundSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;
    protected MenuItem $menuItem;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        \App\Models\Setting::put('tax_enabled',     false, 'billing', 'bool');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'main', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'cashier', 'label' => 'Cashier', 'is_system' => true]);
        $this->cashier = $this->makeCashier();

        $unit = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $storage = StorageLocation::create(['code'=>'main-kitchen','name'=>'K','is_default'=>true,'active'=>true]);
        $station = Station::create(['code'=>'kitchen','name'=>'Kitchen','storage_location_id'=>$storage->id,'active'=>true]);
        $category = Category::create(['slug'=>'mains','name'=>'Mains','default_station_id'=>$station->id,'active'=>true]);

        $ingredient = Ingredient::create([
            'sku' => 'ING-1', 'name' => 'Stock', 'base_unit_id' => $unit->id,
            'current_stock' => 200, 'reorder_threshold' => 0, 'cost_per_unit' => 1,
            'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $ingredient->id, 'storage_location_id' => $storage->id,
            'quantity' => 200, 'reorder_threshold' => 0,
        ]);

        $this->menuItem = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $station->id,
            'sku' => 'M-1', 'slug' => 'meal', 'name' => 'Meal', 'price' => 100, 'cost' => 10,
            'prep_time_minutes' => 5, 'is_available' => true,
        ]);
        RecipeItem::create([
            'menu_item_id' => $this->menuItem->id, 'ingredient_id' => $ingredient->id,
            'quantity' => 1, 'unit_id' => $unit->id,
        ]);

        [$this->customer] = Customer::createFromCashier(
            name: 'زبون اختبار',
            phone: '0599000222',
            defaultBranchId: $this->branch->id,
        );
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** #2 — a partial refund must NOT let a later small payment close the invoice as fully paid. */
    public function test_partial_refund_prevents_underpaid_closure(): void
    {
        $this->actingAs($this->cashier);

        // Pay the 100 invoice in full → paid.
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->cashier->id);
        $this->assertSame('paid', $invoice->fresh()->status);

        // Refund 30 → net collected is now 70, balance reopens to 30.
        app(RefundService::class)->issue($invoice->fresh(), 30.0, 'cash', 'صنف معاد', $this->cashier->id);
        $invoice->refresh();
        $this->assertSame(30.0, (float) $invoice->balance);
        $this->assertSame('partially_paid', $invoice->status);

        // Pay only 10 more. BEFORE the fix this closed the invoice as "paid"
        // (balance = max(0, 100 − 110) = 0) — a silent 20 shortfall. It must
        // now stay open: net = 110 − 30 = 80, balance = 20.
        app(BillingService::class)->addPayment($invoice->fresh(), 10.0, 'cash', $this->cashier->id);
        $invoice->refresh();
        $this->assertSame(20.0, (float) $invoice->balance,
            'Invoice must still owe 20 net after paying only 10 of the reopened 30.');
        $this->assertSame('partially_paid', $invoice->status,
            'Invoice must NOT close as paid while net collected (80) is below the total (100).');

        // Paying the remaining 20 net closes it correctly.
        app(BillingService::class)->addPayment($invoice->fresh(), 20.0, 'cash', $this->cashier->id);
        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame('paid', $invoice->status);
    }

    /** #13 — a written-off invoice can no longer be refunded. */
    public function test_refund_is_rejected_on_written_off_invoice(): void
    {
        $this->actingAs($this->cashier);

        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 40.0, 'cash', $this->cashier->id);

        // Simulate the write-off outcome: residual expensed to bad debt, status closed.
        $invoice->update(['status' => 'unpaid_writeoff']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/مشطوبة|ملغاة/');
        app(RefundService::class)->issue($invoice->fresh(), 40.0, 'cash', 'محاولة استرداد', $this->cashier->id);
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function doVisit(float $total, int $quantity = 1): \App\Models\Invoice
    {
        $table = Table::create([
            'number'   => (string) random_int(1, 9999),
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $session = TableSession::create([
            'table_id' => $table->id, 'customer_id' => $this->customer->id,
            'cover_count' => 1, 'status' => 'active',
        ]);

        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->menuItem->id, 'quantity' => $quantity, 'modifier_ids' => [],
        ]]);
        app(OrderService::class)->approve($order, $this->cashier->id);

        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->cashier->id);
        $this->assertSame($total, (float) $invoice->total, 'Sanity: invoice total mismatch.');
        return $invoice;
    }

    protected function makeCashier(): User
    {
        $user = User::create([
            'name' => 'Cashier', 'username' => 'cashier_r',
            'password' => bcrypt('x'), 'status' => 'active',
            'primary_branch_id' => $this->branch->id, 'role' => 'cashier',
        ]);
        $user->branches()->attach($this->branch->id);
        return $user;
    }
}
