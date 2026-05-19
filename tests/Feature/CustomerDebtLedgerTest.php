<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Permission;
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
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * End-to-end coverage of the customer-debt ledger:
 *   - Cashier collects partial cash, parks remainder as customer debt
 *   - Customer returns, pays toward both their old debt + a fresh visit
 *   - Credit-limit ceiling blocks settle-on-account when it would exceed
 *
 * Why these three: every other code path (refunds, write-off, normal full
 * payment) is already covered by RestaurantCriticalWorkflowTest. This file
 * focuses on what's NEW.
 */
class CustomerDebtLedgerTest extends TestCase
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

        // Disable tax/service so the test math is straightforward and the
        // arithmetic mirrors what the cashier sees on a tax-exempt sale.
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
            name: 'محمد الدائم',
            phone: '0599000111',
            defaultBranchId: $this->branch->id,
        );
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_cashier_collects_partial_cash_and_parks_remainder_as_customer_debt(): void
    {
        $this->actingAs($this->cashier);

        // Visit 1: customer orders 100 ש"ח (1 × Meal), pays 60 cash, owes 40.
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->cashier->id);

        $invoice->refresh();
        $this->assertSame(40.0, (float) $invoice->balance);
        $this->assertSame('partially_paid', $invoice->status);

        // Settle the remaining 40 on the customer's account.
        app(BillingService::class)->settleOnAccount($invoice, $this->cashier->id);

        $invoice->refresh();
        $this->assertNotNull($invoice->settled_on_account_at,
            'Invoice should now be flagged as settled-on-account so the debt ledger picks it up.');
        $this->assertSame(40.0, (float) $invoice->balance,
            'Balance MUST stay positive — the invoice IS the debt record.');

        // Session should be closed and the table freed.
        $session = $invoice->tableSession;
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('available', $session->table->fresh()->status);

        // Customer ledger surfaces the debt.
        $this->customer->refresh();
        $this->assertSame(40.0, $this->customer->outstandingDebt());
        $this->assertCount(1, $this->customer->openDebtInvoices);
    }

    public function test_returning_customer_pays_both_old_debt_and_current_visit_via_fifo_allocation(): void
    {
        $this->actingAs($this->cashier);

        // Visit 1 — 100 ש"ח, paid 60, parked 40 as debt.
        $invoice1 = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice1, 60.0, 'cash', $this->cashier->id);
        app(BillingService::class)->settleOnAccount($invoice1, $this->cashier->id);

        // Visit 2 — fresh 200 ש"ח invoice issued.
        $invoice2 = $this->doVisit(total: 200, quantity: 2);
        $this->assertSame(200.0, (float) $invoice2->balance);

        // Customer hands over 180 — system should clear the older 40 debt
        // first (FIFO) and then chip 140 off the current visit.
        $allocations = app(BillingService::class)->payCustomerDebt(
            customer:       $this->customer->refresh(),
            amount:         180.0,
            method:         'cash',
            userId:         $this->cashier->id,
            primaryInvoice: $invoice2,
        );

        $this->assertCount(2, $allocations, 'Two invoices should have been touched.');
        $this->assertSame($invoice1->id, $allocations[0]['invoice_id'], 'Oldest invoice MUST be paid first.');
        $this->assertSame(40.0, (float) $allocations[0]['amount']);
        $this->assertSame($invoice2->id, $allocations[1]['invoice_id']);
        $this->assertSame(140.0, (float) $allocations[1]['amount']);

        $this->assertSame('paid', $invoice1->fresh()->status);
        $this->assertSame(60.0, (float) $invoice2->fresh()->balance,
            'Current invoice still owes the unpaid 60 after 180 spread across.');

        // The old debt is now cleared. Invoice2 still has a 60 balance
        // but it's an ACTIVE checkout, not a debt — only `settleOnAccount`
        // promotes a balance into the ledger. The cashier still has the
        // option to collect that 60 now or park it as new debt.
        $this->customer->refresh();
        $this->assertSame(0.0, $this->customer->outstandingDebt(),
            'No settled-on-account invoices remain — the carryover from visit #1 was fully cleared.');

        // Now demonstrate the "park the remainder" path: settle invoice2
        // on account. The customer's ledger should now reflect the new
        // 60 ש"ח debt and nothing else.
        app(BillingService::class)->settleOnAccount($invoice2->fresh(), $this->cashier->id);
        $this->customer->refresh();
        $this->assertSame(60.0, $this->customer->outstandingDebt(),
            'After settling invoice2 on account, the 60 carryover becomes the only open debt.');
    }

    public function test_settle_on_account_refuses_when_credit_limit_would_be_breached(): void
    {
        $this->actingAs($this->cashier);

        // Set a 50 ש"ח ceiling. The first visit's 40 carryover stays inside.
        $this->customer->update(['credit_limit' => 50]);

        $invoice1 = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice1, 60.0, 'cash', $this->cashier->id);
        app(BillingService::class)->settleOnAccount($invoice1, $this->cashier->id);
        $this->assertSame(40.0, $this->customer->refresh()->outstandingDebt());

        // Second visit: 100 total, only 30 paid → would push debt to 110
        // → 60 over the 50 cap. Service must refuse.
        $invoice2 = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice2, 30.0, 'cash', $this->cashier->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/الحد الائتماني/');
        app(BillingService::class)->settleOnAccount($invoice2, $this->cashier->id);
    }

    // ─── helpers ──────────────────────────────────────────────────────

    /**
     * Open a fresh dine-in visit for the linked customer, drop one or
     * more of the test meal in, approve, and issue the invoice. Returns
     * the issued invoice ready for payment.
     */
    protected function doVisit(float $total, int $quantity = 1): \App\Models\Invoice
    {
        $table = Table::create([
            'number'   => (string) random_int(1, 9999),
            'capacity' => 4,
            'status'   => 'occupied',
            'active'   => true,
        ]);
        $session = TableSession::create([
            'table_id'    => $table->id,
            'customer_id' => $this->customer->id,
            'cover_count' => 1,
            'status'      => 'active',
        ]);

        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->menuItem->id,
            'quantity'     => $quantity,
            'modifier_ids' => [],
        ]]);
        app(OrderService::class)->approve($order, $this->cashier->id);

        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->cashier->id);
        $this->assertSame($total, (float) $invoice->total, 'Sanity: invoice total mismatched test expectation.');
        return $invoice;
    }

    protected function makeCashier(): User
    {
        $user = User::create([
            'name' => 'Cashier', 'username' => 'cashier_t',
            'password' => bcrypt('x'), 'status' => 'active',
            'primary_branch_id' => $this->branch->id, 'role' => 'cashier',
        ]);
        $user->branches()->attach($this->branch->id);
        return $user;
    }
}
