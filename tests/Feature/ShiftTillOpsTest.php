<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Shift;
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
use Tests\TestCase;

/**
 * Till operational features: mid-shift cash pay-in/pay-out feeding the expected
 * drawer, the read-only X-report, and the net-of-refunds cash-today figure.
 */
class ShiftTillOpsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected MenuItem $menuItem;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        \App\Models\Setting::put('tax_enabled', false, 'billing', 'bool');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'till', 'name' => 'TILL', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'till-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $unit = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $storage = StorageLocation::create(['code'=>'k','name'=>'K','is_default'=>true,'active'=>true]);
        $station = Station::create(['code'=>'kitchen','name'=>'K','storage_location_id'=>$storage->id,'active'=>true]);
        $category = Category::create(['slug'=>'m','name'=>'M','default_station_id'=>$station->id,'active'=>true]);
        $ing = Ingredient::create(['sku'=>'I','name'=>'S','base_unit_id'=>$unit->id,'current_stock'=>500,'reorder_threshold'=>0,'cost_per_unit'=>1,'track_stock'=>true,'active'=>true]);
        IngredientStock::create(['ingredient_id'=>$ing->id,'storage_location_id'=>$storage->id,'quantity'=>500,'reorder_threshold'=>0]);
        $this->menuItem = MenuItem::create(['category_id'=>$category->id,'station_id'=>$station->id,'sku'=>'M1','slug'=>'m','name'=>'Meal','price'=>100,'cost'=>10,'prep_time_minutes'=>5,'is_available'=>true]);
        RecipeItem::create(['menu_item_id'=>$this->menuItem->id,'ingredient_id'=>$ing->id,'quantity'=>1,'unit_id'=>$unit->id]);

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599000888', defaultBranchId: $this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function openShift(float $opening): Shift
    {
        return Shift::create([
            'user_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'cash_opening' => $opening, 'status' => 'open', 'opened_at' => now(),
        ]);
    }

    private function issueMeal(): \App\Models\Invoice
    {
        $table = Table::create(['number'=>(string) random_int(1,9999),'capacity'=>4,'status'=>'occupied','active'=>true]);
        $session = TableSession::create(['table_id'=>$table->id,'customer_id'=>$this->customer->id,'cover_count'=>1,'status'=>'active']);
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        return app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);
    }

    /** A cash pay-in/pay-out on the open shift flows into the expected-drawer math. */
    public function test_cash_movements_feed_expected_cash(): void
    {
        $shift = $this->openShift(100.0);

        $this->actingAs($this->admin)
            ->post(route('admin.shifts.cash-movement', $shift), ['type' => 'pay_in', 'amount' => 30, 'reason' => 'فكة من الخزنة'])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)
            ->post(route('admin.shifts.cash-movement', $shift), ['type' => 'pay_out', 'amount' => 10, 'reason' => 'شراء أكياس'])
            ->assertSessionHas('success');

        $this->assertSame(2, CashMovement::where('shift_id', $shift->id)->count());

        $resp = $this->actingAs($this->admin)->get(route('admin.shifts.x-report', $shift));
        $resp->assertOk();
        $b = $resp->viewData('breakdown');
        $this->assertEqualsWithDelta(30.0, $b['cash_pay_ins'], 0.001);
        $this->assertEqualsWithDelta(10.0, $b['cash_pay_outs'], 0.001);
        $this->assertEqualsWithDelta(120.0, $b['expected_cash'], 0.001, 'opening 100 + 30 − 10');
    }

    /** A cash movement is refused once the shift is closed. */
    public function test_cash_movement_blocked_on_closed_shift(): void
    {
        $shift = $this->openShift(100.0);
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        $this->actingAs($this->admin)
            ->post(route('admin.shifts.cash-movement', $shift), ['type' => 'pay_in', 'amount' => 10, 'reason' => 'x'])
            ->assertSessionHas('error');

        $this->assertSame(0, CashMovement::where('shift_id', $shift->id)->count());
    }

    /** The X-report is read-only — viewing it must NOT close or mutate the shift. */
    public function test_x_report_is_read_only(): void
    {
        $shift = $this->openShift(100.0);

        $this->actingAs($this->admin)->get(route('admin.shifts.x-report', $shift))->assertOk();

        $shift->refresh();
        $this->assertSame('open', $shift->status, 'X-report must not close the shift.');
        $this->assertNull($shift->closed_at);
    }

    /** Opening a shift whose count mismatches the previous close warns + audits. */
    public function test_shift_open_warns_on_drawer_handoff_variance(): void
    {
        // A previous shift closed with 300 in the drawer.
        $prev = $this->openShift(200.0);
        $prev->update(['status' => 'closed', 'cash_closing' => 300.0, 'closed_at' => now()->subMinutes(5)]);

        // New cashier opens with 250 — a 50 hand-off discrepancy.
        $this->actingAs($this->admin)
            ->post(route('admin.shifts.store'), ['cash_opening' => 250])
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('activity_logs', ['event' => 'shift.handoff_variance']);
    }

    /** A matching opening count opens cleanly, no hand-off warning. */
    public function test_shift_open_matching_previous_close_has_no_warning(): void
    {
        $prev = $this->openShift(200.0);
        $prev->update(['status' => 'closed', 'cash_closing' => 300.0, 'closed_at' => now()->subMinutes(5)]);

        $this->actingAs($this->admin)
            ->post(route('admin.shifts.store'), ['cash_opening' => 300])
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');
    }

    /** The cashier "cash today" stat nets same-day completed cash refunds. */
    public function test_cash_today_is_net_of_cash_refunds(): void
    {
        $invoice = $this->issueMeal();                                   // 100
        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);
        app(RefundService::class)->issue($invoice->fresh(), 30.0, 'cash', 'صنف معاد', $this->admin->id);

        $resp = $this->actingAs($this->admin)->get(route('admin.cashier.index'));
        $resp->assertOk();

        // 100 cash in − 30 cash refunded today = 70 actually in the drawer.
        $this->assertEqualsWithDelta(70.0, (float) $resp->viewData('stats')['cash_today'], 0.001);
    }
}
