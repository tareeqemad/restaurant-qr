<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StaffMealCharge;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StaffMealService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff meal allowance — covers the day-to-day lifecycle:
 *  - chargeOrder freezes the order total on the tab
 *  - over-limit allowed (overflow becomes part of the outstanding tab)
 *  - settle() walks open charges FIFO
 *  - re-charging the same order is a no-op (idempotency)
 */
class StaffMealAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $manager;

    protected User $employee;

    protected MenuItem $burger;

    protected Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        // Tax off — most tests assert exact money math.
        // Service ON so we can prove the staff-meal flow strips it
        // back out (see `service_is_excluded_from_staff_charge`).
        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', true, 'billing', 'bool');
        Setting::put('service_rate', 10, 'billing', 'float');

        $this->branch = Branch::create(['code' => 'sm', 'name' => 'SM', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'manager', 'label' => 'Manager', 'is_system' => true]);
        Role::create(['name' => 'waiter',  'label' => 'Waiter',  'is_system' => true]);
        $this->manager = $this->staff('manager_t', 'manager');
        $this->employee = $this->staff('emp_t', 'waiter');
        $this->employee->update(['monthly_meal_allowance' => 500]);

        $gram = Unit::create(['code' => 'g', 'name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $pcs = Unit::create(['code' => 'pcs', 'name' => 'pcs', 'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $kitchen = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $storage->id, 'active' => true]);
        $category = Category::create(['slug' => 'mains', 'name' => 'Mains', 'default_station_id' => $kitchen->id, 'active' => true]);

        $patty = Ingredient::create([
            'name' => 'Patty', 'base_unit_id' => $gram->id, 'current_stock' => 10000,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create(['ingredient_id' => $patty->id, 'storage_location_id' => $storage->id, 'quantity' => 10000, 'reorder_threshold' => 0]);

        $this->burger = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'Burger', 'price' => 100, 'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $patty->id, 'quantity' => 100, 'unit_id' => $gram->id]);

        $this->table = Table::create(['number' => '1', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_charge_order_records_a_tab_entry_for_the_consumer(): void
    {
        $this->actingAs($this->manager);

        $order = $this->placeStaffOrder($this->employee, qty: 2);   // 2 × 100 = 200

        $charge = app(StaffMealService::class)->chargeOrder($order, $this->manager->id);

        $this->assertSame(0.0, (float) $charge->amount,
            'The monthly allowance covers the meal; no employee receivable is created.');
        $this->assertSame($this->employee->id, $charge->user_id);
        $this->assertSame($order->id, $charge->order_id);
        $this->assertNotNull($charge->settled_at);
        $this->assertSame('allowance', $charge->settlement_method);
        $this->assertSame(200.0, $charge->consumptionAmount());
        $this->assertSame(200.0, $charge->coveredAmount());

        $this->assertSame(200.0, $this->employee->fresh()->staffMealUsedInMonth());
        $this->assertSame(300.0, $this->employee->fresh()->staffMealRemainingThisMonth());
    }

    public function test_charging_the_same_order_twice_is_idempotent(): void
    {
        $this->actingAs($this->manager);

        $order = $this->placeStaffOrder($this->employee, qty: 1);
        $first = app(StaffMealService::class)->chargeOrder($order);
        $second = app(StaffMealService::class)->chargeOrder($order->fresh());

        $this->assertSame($first->id, $second->id,
            'Re-running chargeOrder MUST return the same row — every staff order yields exactly one charge.');
        $this->assertSame(1, StaffMealCharge::where('order_id', $order->id)->count());
    }

    public function test_over_limit_charge_is_allowed_and_appears_as_overflow(): void
    {
        $this->actingAs($this->manager);

        // 6 × 100 = 600 — overshoots the 500 limit by 100.
        $order = $this->placeStaffOrder($this->employee, qty: 6);
        app(StaffMealService::class)->chargeOrder($order);

        $summary = app(StaffMealService::class)->monthSummary($this->employee->fresh());
        $this->assertSame(600.0, (float) $summary['used']);
        $this->assertSame(-100.0, (float) $summary['remaining'],
            'Remaining goes negative when the cap is exceeded.');
        $this->assertSame(100.0, (float) $summary['overflow'],
            'Overflow = used − allowance, clamped at zero.');
        $this->assertSame(100.0, (float) $summary['outstanding'],
            'Only the portion above the allowance becomes employee debt.');
        $this->assertSame(500.0, (float) $summary['covered']);
    }

    public function test_settle_walks_open_charges_fifo(): void
    {
        $this->actingAs($this->manager);
        $this->employee->update(['monthly_meal_allowance' => 0]);

        // Three charges over time: 100, 200, 150 — total open 450.
        foreach ([1, 2, 2] as $i => $qty) {
            $order = $this->placeStaffOrder($this->employee, qty: $qty);
            app(StaffMealService::class)->chargeOrder($order);
            // Backdate the charge so FIFO ordering is deterministic
            // (else they'd all share the same now() timestamp).
            StaffMealCharge::where('order_id', $order->id)
                ->update(['charged_at' => now()->subDays(3 - $i)]);
        }
        $this->assertSame(500.0, $this->employee->fresh()->staffMealOutstanding());

        // Pay 250 cash → fully settles charge #1 (100), fully settles
        // charge #2 (200) → leftover -50, so it should split charge #2
        // partially. Actually 100 + 200 = 300 > 250, so #1 settles fully
        // and #2 partially (150 leftover). Let's verify.
        app(StaffMealService::class)->settle(
            staff: $this->employee,
            amount: 250,
            method: 'cash',
            settledByUserId: $this->manager->id,
        );

        $this->assertSame(250.0, $this->employee->fresh()->staffMealOutstanding(),
            '450 − 250 = 200 should remain open.');

        // The oldest charge (100) is fully settled.
        $oldest = StaffMealCharge::where('user_id', $this->employee->id)
            ->orderBy('charged_at')
            ->first();
        $this->assertNotNull($oldest->settled_at,
            'Oldest charge MUST be settled first (FIFO).');
    }

    /**
     * Default behavior (setting OFF / unset): service is stripped from
     * the tab. With service_rate set to 10% in setUp, the order is
     * created at 110 (100 subtotal + 10 service), but the tab is
     * charged 100 (subtotal only).
     */
    public function test_service_charge_is_excluded_from_staff_meal_amount_by_default(): void
    {
        $this->actingAs($this->manager);
        Setting::put('staff_meal_include_service', false, 'staff_meals', 'bool');

        $order = $this->placeStaffOrder($this->employee, qty: 1);
        $this->assertGreaterThan(0, (float) $order->fresh()->service_total,
            'Sanity: setUp must seed service_rate=10 so the strip-out is meaningful.');
        $this->assertSame(110.0, (float) $order->fresh()->total,
            '100 subtotal × 10% service = 110.');

        $charge = app(StaffMealService::class)->chargeOrder($order);

        $this->assertSame(0.0, (float) $charge->amount);
        $this->assertSame(100.0, $charge->consumptionAmount(),
            'The allowance uses the service-free nominal value.');
        $this->assertSame(0.0, (float) $order->fresh()->service_total,
            'Order itself ends up service-free so reports / payroll match the tab.');
    }

    /**
     * Setting ON: service is left on the staff order — the chain pools
     * service across all sales and pays it out separately.
     */
    public function test_service_charge_is_kept_when_setting_is_enabled(): void
    {
        $this->actingAs($this->manager);
        Setting::put('staff_meal_include_service', true, 'staff_meals', 'bool');

        $order = $this->placeStaffOrder($this->employee, qty: 1);
        $charge = app(StaffMealService::class)->chargeOrder($order);

        $this->assertSame(0.0, (float) $charge->amount);
        $this->assertSame(110.0, $charge->consumptionAmount(),
            'With include_service=true, the allowance uses the full total.');
        $this->assertGreaterThan(0, (float) $order->fresh()->service_total,
            'Order keeps the original service_total — operator chose to pool it.');
    }

    public function test_settle_refuses_overpayment(): void
    {
        $this->actingAs($this->manager);
        $this->employee->update(['monthly_meal_allowance' => 0]);
        $order = $this->placeStaffOrder($this->employee, qty: 1);   // 100
        app(StaffMealService::class)->chargeOrder($order);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/أكبر من إجمالي المستحق/');
        app(StaffMealService::class)->settle($this->employee, 500, 'cash');
    }

    /**
     * Quick consume: cashier records "employee took a cola during shift"
     * in one shot. The order is created Completed (no kitchen workflow),
     * stock is deducted immediately, and a tab charge is opened.
     */
    public function test_quick_consume_creates_completed_order_and_charges_tab(): void
    {
        $this->actingAs($this->manager);

        $before = $this->employee->fresh()->staffMealOutstanding();

        $charge = app(StaffMealService::class)->quickConsume(
            staff: $this->employee,
            lines: [
                ['menu_item_id' => $this->burger->id, 'quantity' => 2],
            ],
            recordedByUserId: $this->manager->id,
        );

        $this->assertSame(0.0, (float) $charge->amount,
            'Service stripped by default, so 2 × 100 subtotal = 200 lands on the tab.');
        $this->assertSame(200.0, $charge->consumptionAmount());
        $this->assertSame($before, $this->employee->fresh()->staffMealOutstanding());

        $order = $charge->order;
        $this->assertSame(OrderStatus::Completed->value, $order->status,
            'Quick-consume orders skip the kitchen flow — they\'re Completed on creation.');
        $this->assertNull($order->table_id, 'No table for a quick consume.');
        $this->assertNull($order->customer_id, 'No paying customer either.');

        // Stock movement was created (1 'out' per OrderItem).
        $this->assertSame(1,
            InventoryMovement::where('reference_type', OrderItem::class)
                ->whereIn('reference_id', $order->items->pluck('id'))
                ->where('type', 'out')
                ->count());
    }

    /**
     * Price snapshot integrity: a menu price change AFTER a staff
     * order doesn't drift the historical tab. The user explicitly
     * called this out — if cola was 2 ש"ח in May and 3 in June, the
     * May tab still says 2 forever.
     */
    public function test_menu_price_change_does_not_drift_historical_staff_charges(): void
    {
        $this->actingAs($this->manager);

        $charge1 = app(StaffMealService::class)->quickConsume(
            staff: $this->employee,
            lines: [['menu_item_id' => $this->burger->id, 'quantity' => 1]],
        );
        $this->assertSame(100.0, $charge1->consumptionAmount());

        // Menu price doubles (supplier raised cost / restaurant raised
        // sell price); a NEW consumption hits the new price, the old
        // one does not.
        $this->burger->update(['price' => 200]);

        $charge2 = app(StaffMealService::class)->quickConsume(
            staff: $this->employee,
            lines: [['menu_item_id' => $this->burger->id, 'quantity' => 1]],
        );
        $this->assertSame(200.0, $charge2->consumptionAmount(),
            'New consumption gets the new price.');

        $this->assertSame(100.0, $charge1->fresh()->consumptionAmount(),
            'Old charge MUST stay at the historical price — snapshot is a snapshot.');
    }

    // ─── helpers ──────────────────────────────────────────────────────

    public function test_staff_meal_inventory_cost_posts_to_employee_benefit_not_cogs(): void
    {
        $this->actingAs($this->manager);

        $charge = app(StaffMealService::class)->quickConsume(
            staff: $this->employee,
            lines: [['menu_item_id' => $this->burger->id, 'quantity' => 1]],
            recordedByUserId: $this->manager->id,
        );

        $movement = InventoryMovement::where('reference_type', OrderItem::class)
            ->whereIn('reference_id', $charge->order->items->pluck('id'))
            ->where('type', 'out')
            ->firstOrFail();
        $entry = JournalEntry::where('event_type', 'inventory_staff_meal_consumed')
            ->where('source_type', $movement::class)
            ->where('source_id', $movement->id)
            ->with('lines.account')
            ->first();

        $this->assertNotNull($entry);
        $debit = $entry->lines->firstWhere(fn ($line) => (float) $line->debit > 0);
        $credit = $entry->lines->firstWhere(fn ($line) => (float) $line->credit > 0);
        $this->assertSame('5060', $debit->account->code);
        $this->assertSame('1200', $credit->account->code);
        $this->assertEqualsWithDelta(100.0, (float) $debit->debit, 0.001);
        $this->assertFalse(JournalEntry::where('event_type', 'inventory_cogs_recognized')
            ->where('source_id', $movement->id)
            ->exists());
    }

    public function test_served_staff_meal_cannot_be_cancelled_after_accounting_is_frozen(): void
    {
        $this->actingAs($this->manager);
        $charge = app(StaffMealService::class)->quickConsume(
            staff: $this->employee,
            lines: [['menu_item_id' => $this->burger->id, 'quantity' => 1]],
            recordedByUserId: $this->manager->id,
        );
        $movementCount = InventoryMovement::where('reference_type', OrderItem::class)
            ->whereIn('reference_id', $charge->order->items->pluck('id'))
            ->count();

        try {
            app(OrderService::class)->cancel($charge->order->fresh(), $this->manager->id, 'تصحيح متأخر');
            $this->fail('A served staff meal must not reopen its stock/accounting footprint.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('التسوية أو الإعفاء', $exception->getMessage());
        }

        $this->assertSame(OrderStatus::Completed->value, $charge->order->fresh()->status);
        $this->assertSame($movementCount, InventoryMovement::where('reference_type', OrderItem::class)
            ->whereIn('reference_id', $charge->order->items->pluck('id'))
            ->count());
        $this->assertSame(1, StaffMealCharge::where('order_id', $charge->order_id)->count());
    }

    protected function staff(string $username, string $role): User
    {
        $u = User::create([
            'name' => ucfirst($username),
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'role' => $role,
            'primary_branch_id' => $this->branch->id,
        ]);
        $u->branches()->attach($this->branch->id);

        return $u;
    }

    protected function placeStaffOrder(User $employee, int $qty): Order
    {
        $session = TableSession::create([
            'branch_id' => $this->branch->id,
            'table_id' => $this->table->id,
            'token' => 'staff-'.uniqid(),
            'status' => 'active',
            'opened_at' => now(),
            'cover_count' => 1,
        ]);

        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id,
            'quantity' => $qty,
            'modifier_ids' => [],
        ]], createdByUserId: $this->manager->id);

        $order->update(['staff_consumer_user_id' => $employee->id]);

        return $order->fresh();
    }
}
