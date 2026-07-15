<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Refund;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Parity regressions the cashier-screen merge introduced: three capabilities of
 * the retired classic pay page that never got ported to the live Volt dashboard.
 *
 *  1. The customer link/search/create/unlink panel
 *     (<livewire:admin.cashier-customer-link>) was orphaned — without it a
 *     phone-less walk-in can never attach a customer, permanently blocking
 *     settle-on-account (which needs invoice->customer_id). The dashboard
 *     checkout must embed it again.
 *
 *  3. The refund modal dropped the reference (bank/card slip — the refunds
 *     index searches by it) and notes. submitRefund must forward both into
 *     RefundService::issue via the same opts keys RefundController::store used.
 *
 * Setup mirrors CashierSplitUnparkTest.
 */
class CashierMergeParityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $waiter;
    protected MenuItem $menuItem;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        \App\Models\Setting::put('tax_enabled', false, 'billing', 'bool');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'cmp', 'name' => 'CMP', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'waiter'], ['label' => 'Waiter', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'A', 'username' => 'cmp-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        // Waiter lacks the Refund ability — the deny side of the @can gate.
        $this->waiter = User::create([
            'name' => 'W', 'username' => 'cmp-waiter', 'role' => 'waiter',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->waiter->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $unit = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $storage = StorageLocation::create(['code'=>'k','name'=>'K','is_default'=>true,'active'=>true]);
        $station = Station::create(['code'=>'kitchen','name'=>'K','storage_location_id'=>$storage->id,'active'=>true]);
        $category = Category::create(['slug'=>'m','name'=>'M','default_station_id'=>$station->id,'active'=>true]);
        $ing = Ingredient::create(['sku'=>'I','name'=>'S','base_unit_id'=>$unit->id,'current_stock'=>500,'reorder_threshold'=>0,'cost_per_unit'=>1,'track_stock'=>true,'active'=>true]);
        IngredientStock::create(['ingredient_id'=>$ing->id,'storage_location_id'=>$storage->id,'quantity'=>500,'reorder_threshold'=>0]);
        $this->menuItem = MenuItem::create(['category_id'=>$category->id,'station_id'=>$station->id,'sku'=>'M1','slug'=>'m','name'=>'Meal','price'=>100,'cost'=>10,'prep_time_minutes'=>5,'is_available'=>true]);
        RecipeItem::create(['menu_item_id'=>$this->menuItem->id,'ingredient_id'=>$ing->id,'quantity'=>1,'unit_id'=>$unit->id]);

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599000771', defaultBranchId: $this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function openSession(bool $withCustomer = true): TableSession
    {
        $table = Table::create(['number'=>(string) random_int(1,9999),'capacity'=>4,'status'=>'occupied','active'=>true]);
        return TableSession::create([
            'table_id'    => $table->id,
            'customer_id' => $withCustomer ? $this->customer->id : null,
            'cover_count' => 1,
            'status'      => 'active',
        ]);
    }

    /** @return array{0: Invoice, 1: TableSession} A fully-PAID invoice (refundable). */
    private function paidMeal(string $method = 'card'): array
    {
        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);
        app(BillingService::class)->addPayment($invoice, (float) $invoice->total, $method, $this->admin->id);

        return [$invoice->fresh(), $session];
    }

    // ─── FIX 1: customer-link panel is embedded again ──────────────────

    /**
     * The merged dashboard checkout renders the customer-link Livewire
     * component for a selected session — both when a customer is already
     * linked (so the cashier can unlink/replace) and, critically, when none is
     * linked (the phone-less walk-in that otherwise can never reach
     * settle-on-account).
     */
    public function test_dashboard_checkout_embeds_the_customer_link_component(): void
    {
        $this->actingAs($this->admin);

        // Linked session — the component still renders (unlink/replace path).
        $linked = $this->openSession(withCustomer: true);
        $this->get(route('admin.cashier.index', ['session' => $linked->id]))
            ->assertOk()
            ->assertSeeLivewire('admin.cashier-customer-link')
            ->assertSee('cxlink-'.$linked->id);

        // Walk-in session (no customer) — the component renders its search /
        // create form ("ربط زبون"), the ONLY route to attach a customer and
        // unblock settle-on-account for a phone-less table.
        $walkIn = $this->openSession(withCustomer: false);
        $this->get(route('admin.cashier.index', ['session' => $walkIn->id]))
            ->assertOk()
            ->assertSeeLivewire('admin.cashier-customer-link')
            ->assertSee('ربط زبون');
    }

    // ─── FIX 3: refund reference + notes flow through submitRefund ──────

    /**
     * A refund issued through the dashboard's submitRefund Volt action persists
     * the reference (searchable in the refunds index) and notes onto the Refund
     * row — reproducing the classic RefundController::store opts.
     */
    public function test_submit_refund_persists_reference_and_notes(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->paidMeal(method: 'card');   // paid_total 100

        Livewire::test('cashier.dashboard')
            ->set('selectedSessionId', $session->id)
            ->set('refundAmount', '40')
            ->set('refundMethod', 'transfer')
            ->set('refundReference', 'RF-SLIP-55')
            ->set('refundNotes', 'أعيد المبلغ للزبون نقداً')
            ->set('refundReason', 'صنف تالف')
            ->call('submitRefund')
            ->assertHasNoErrors();

        $refund = Refund::where('invoice_id', $invoice->id)->latest('id')->first();

        $this->assertNotNull($refund, 'submitRefund must create a Refund row.');
        $this->assertSame('RF-SLIP-55', $refund->reference, 'The bank/card slip reference must persist.');
        $this->assertSame('أعيد المبلغ للزبون نقداً', $refund->notes, 'The free-text notes must persist.');
        $this->assertSame('transfer', $refund->method);
        $this->assertEqualsWithDelta(40.0, (float) $refund->amount, 0.001);
    }

    /**
     * A cash refund leaves reference NULL (no slip) — the property is optional
     * and trimmed-empty maps to null, matching RefundController::store.
     */
    public function test_cash_refund_stores_null_reference(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->paidMeal(method: 'cash');

        Livewire::test('cashier.dashboard')
            ->set('selectedSessionId', $session->id)
            ->set('refundAmount', '25')
            ->set('refundMethod', 'cash')
            ->set('refundReason', 'خطأ في الطلب')
            ->call('submitRefund')
            ->assertHasNoErrors();

        $refund = Refund::where('invoice_id', $invoice->id)->latest('id')->first();
        $this->assertNotNull($refund);
        $this->assertNull($refund->reference, 'A cash refund carries no slip reference.');
    }

    /**
     * The restored refund trigger is wrapped in @can('create', Refund) — for an
     * authorized user (admin) the «استرداد» button renders on a refundable
     * invoice, so the gate didn't accidentally hide the control. The ability
     * itself is the deny lever (waiter lacks it), pinned here so a policy change
     * that widened it would fail loudly.
     */
    public function test_refund_button_is_ability_gated(): void
    {
        $this->assertTrue($this->admin->can('create', Refund::class),
            'Admin must hold the refund ability the button gate checks.');
        $this->assertFalse($this->waiter->can('create', Refund::class),
            'Waiter must NOT hold the refund ability — the gate hides the button for them.');

        $this->actingAs($this->admin);
        [, $session] = $this->paidMeal(method: 'card');

        $this->get(route('admin.cashier.index', ['session' => $session->id]))
            ->assertOk()
            ->assertSee('استرداد');
    }
}
