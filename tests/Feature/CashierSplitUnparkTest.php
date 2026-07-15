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
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Controller-level regressions for the C4 cashier-page package:
 *  - un-park (reverse settle-on-account) route: happy path + ability guard
 *  - split edit («تعديل التقسيم» re-POST) replaces rows while nothing is
 *    paid, and 422s once any split is paid
 *  - per-split payment reference flows into payments.reference
 *  - print/pdf receipt endpoints require the cashier ability
 */
class CashierSplitUnparkTest extends TestCase
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

        $this->branch = Branch::create(['code' => 'c4t', 'name' => 'C4T', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'waiter'], ['label' => 'Waiter', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'A', 'username' => 'c4t-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        // Waiter has no Payment ability — the deny side of every guard test.
        $this->waiter = User::create([
            'name' => 'W', 'username' => 'c4t-waiter', 'role' => 'waiter',
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

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599000778', defaultBranchId: $this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function openSession(): TableSession
    {
        $table = Table::create(['number'=>(string) random_int(1,9999),'capacity'=>4,'status'=>'occupied','active'=>true]);
        return TableSession::create(['table_id'=>$table->id,'customer_id'=>$this->customer->id,'cover_count'=>1,'status'=>'active']);
    }

    /** @return array{0: \App\Models\Invoice, 1: TableSession} */
    private function issueMeal(): array
    {
        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);
        return [$invoice, $session];
    }

    /**
     * Merge backbone (Phase 0): the cashier dashboard is session-addressable
     * via ?session=ID, so every classic money action can redirect back into
     * the one live screen with the checkout pre-selected — including a CLOSED
     * session's invoice (the un-park collection target).
     */
    public function test_dashboard_deep_links_to_a_session_checkout(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();

        // Active session: the checkout renders in the initial Livewire paint.
        $this->get(route('admin.cashier.index', ['session' => $session->id]))
            ->assertOk()
            ->assertSee($invoice->number);

        // Close the session (as settle/pay would) — the checkout must still
        // render so an un-parked balance stays collectable from the merged screen.
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        $this->get(route('admin.cashier.index', ['session' => $session->id]))
            ->assertOk()
            ->assertSee($invoice->number);
    }

    /** Un-park via the route lifts the flag and pulls the balance off the debt ledger. */
    public function test_unpark_route_restores_invoice_to_normal_collection(): void
    {
        // The service method ships in a sibling package (C2) — skip cleanly
        // during the parallel-work window instead of failing on a missing method.
        if (! method_exists(BillingService::class, 'unparkSettleOnAccount')) {
            $this->markTestSkipped('BillingService::unparkSettleOnAccount not merged yet (C2 package).');
        }

        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();                                                  // total 100
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id); // balance 40
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);
        $this->assertEqualsWithDelta(40.0, $this->customer->outstandingDebt(), 0.001);

        $this->post(route('admin.cashier.unpark', $invoice))->assertSessionHas('success');

        $invoice->refresh();
        $this->assertNull($invoice->settled_on_account_at, 'Un-park must lift the parked-debt flag.');
        $this->assertEqualsWithDelta(0.0, $this->customer->outstandingDebt(), 0.001,
            'The balance must leave the customer debt ledger once un-parked.');
    }

    /** Un-park is gated by the same ability as settle-on-account. */
    public function test_unpark_requires_payment_ability(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);

        $this->actingAs($this->waiter)
            ->post(route('admin.cashier.unpark', $invoice))
            ->assertForbidden();
        $this->assertNotNull($invoice->fresh()->settled_on_account_at, 'A denied un-park must change nothing.');
    }

    /** «تعديل التقسيم» re-POSTs the split route — rows are replaced while nothing is paid. */
    public function test_split_edit_replaces_rows_while_nothing_paid(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();   // total 100

        $this->post(route('admin.cashier.split', $invoice), ['splits' => [
            ['label' => 'الشخص 1', 'amount' => 50, 'method' => 'cash'],
            ['label' => 'الشخص 2', 'amount' => 50, 'method' => 'cash'],
        ]])->assertSessionHas('success');
        $this->assertSame(2, $invoice->splits()->count());

        // Regroup: 2 equal → 3 uneven. The service clears-and-recreates.
        $this->post(route('admin.cashier.split', $invoice), ['splits' => [
            ['label' => 'أحمد', 'amount' => 40, 'method' => 'card'],
            ['label' => 'سمير', 'amount' => 30, 'method' => 'cash'],
            ['label' => 'ليلى', 'amount' => 30, 'method' => 'cash'],
        ]])->assertSessionHas('success');

        $splits = $invoice->splits()->orderBy('id')->get();
        $this->assertSame(3, $splits->count(), 'Old rows must be gone, replaced by the new grouping.');
        $this->assertSame(['أحمد', 'سمير', 'ليلى'], $splits->pluck('label')->all());
        $this->assertEqualsWithDelta(40.0, (float) $splits[0]->amount, 0.001);
        $this->assertSame('card', $splits[0]->method);
    }

    /** Once ANY split is paid, the regroup POST is refused with a 422. */
    public function test_split_edit_blocked_with_422_after_a_split_paid(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();

        $this->post(route('admin.cashier.split', $invoice), ['splits' => [
            ['label' => 'الشخص 1', 'amount' => 50, 'method' => 'cash'],
            ['label' => 'الشخص 2', 'amount' => 50, 'method' => 'cash'],
        ]])->assertSessionHas('success');
        $first = $invoice->splits()->orderBy('id')->first();

        $this->post(route('admin.cashier.split.pay', ['invoice' => $invoice, 'split' => $first]))
            ->assertSessionHas('success');

        $this->postJson(route('admin.cashier.split', $invoice), ['splits' => [
            ['label' => 'أ', 'amount' => 60, 'method' => 'cash'],
            ['label' => 'ب', 'amount' => 40, 'method' => 'cash'],
        ]])->assertStatus(422);

        $this->assertSame(2, $invoice->splits()->count(), 'Paid grouping must survive the refused edit.');
        $this->assertTrue((bool) $first->fresh()->paid);
    }

    /** The optional reference on a split payment lands in payments.reference. */
    public function test_pay_split_persists_reference(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();

        $this->post(route('admin.cashier.split', $invoice), ['splits' => [
            ['label' => 'الشخص 1', 'amount' => 60, 'method' => 'transfer'],
            ['label' => 'الشخص 2', 'amount' => 40, 'method' => 'cash'],
        ]])->assertSessionHas('success');
        $transferSplit = $invoice->splits()->where('method', 'transfer')->first();

        $this->post(
            route('admin.cashier.split.pay', ['invoice' => $invoice, 'split' => $transferSplit]),
            ['reference' => 'TRX-98765']
        )->assertSessionHas('success');

        $payment = $invoice->payments()->latest('id')->first();
        $this->assertSame('TRX-98765', $payment->reference);
        $this->assertSame('transfer', $payment->method);
        $this->assertSame('دفعة جزء: الشخص 1', $payment->notes,
            'The split label in the notes is what the receipt uses to tag the payment line.');
    }

    /** Receipt endpoints carry customer + money data — same ability as the cashier page. */
    public function test_receipt_endpoints_require_payment_view_ability(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();

        $this->actingAs($this->waiter)->get(route('admin.cashier.print', $invoice))->assertForbidden();
        $this->actingAs($this->waiter)->get(route('admin.cashier.pdf', $invoice))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.cashier.print', $invoice))->assertOk();
    }
}
