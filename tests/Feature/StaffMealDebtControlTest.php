<?php

namespace Tests\Feature;

use App\Exceptions\StaffMealLimitException;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StaffMealCharge;
use App\Models\StaffMealMonthClosure;
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
 * Staff meal DEBT-CONTROL layer — the bits added on top of the basic
 * allowance flow to actually keep employee debt in check:
 *
 *   - meal_debt_ceiling: hard cap on total outstanding
 *   - over-limit policy: allow_log | warn | require_approval | block
 *   - closeMonth: month-end batch settle + payroll-deduction sheet
 *   - accounting: journal entries for charge + settlement
 *   - threshold alerts: 80% / 100% / 120% notifications via ActivityLog
 */
class StaffMealDebtControlTest extends TestCase
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

        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', false, 'billing', 'bool');
        // staff_meal_over_limit_policy default = 'warn'

        $this->branch = Branch::create(['code' => 'sd', 'name' => 'SD', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'waiter'], ['label' => 'Waiter', 'is_system' => true]);
        $this->manager = $this->staff('mgr_d', 'manager');
        $this->employee = $this->staff('emp_d', 'waiter');
        $this->employee->update([
            'monthly_meal_allowance' => 500,
            'meal_debt_ceiling' => 1000,    // hard ceiling for these tests
        ]);

        $g = Unit::firstOrCreate(['code' => 'g'], ['name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $kitchen = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $storage->id, 'active' => true]);
        $cat = Category::create(['slug' => 'm', 'name' => 'M', 'default_station_id' => $kitchen->id, 'active' => true]);

        $patty = Ingredient::create([
            'name' => 'P', 'base_unit_id' => $g->id, 'current_stock' => 100000,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create(['ingredient_id' => $patty->id, 'storage_location_id' => $storage->id, 'quantity' => 100000, 'reorder_threshold' => 0]);

        $this->burger = MenuItem::create([
            'category_id' => $cat->id, 'station_id' => $kitchen->id,
            'sku' => 'B', 'slug' => 'b', 'name' => 'Burger', 'price' => 100, 'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $patty->id, 'quantity' => 100, 'unit_id' => $g->id]);

        $this->table = Table::create(['number' => 'T', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────
    // Limit enforcement
    // ────────────────────────────────────────────────────────────────

    public function test_preview_returns_warn_when_over_monthly_allowance_but_under_ceiling(): void
    {
        $this->actingAs($this->manager);
        // Build 400 of existing tab so the 200 next charge pushes the
        // employee to 600 — over the 500 monthly cap, under the 1000 ceiling.
        $this->charge($this->employee, qty: 4);   // 400 used

        $check = app(StaffMealService::class)->previewLimitCheck($this->employee->fresh(), amount: 200);
        $this->assertSame('warn', $check['status'],
            'Default policy = warn → soft monthly overflow returns warn, not blocked.');
        $this->assertSame(100.0, $check['allowance']['over_by'],
            'Allowance overrun: 400+200=600, cap 500 → 100 over.');
        $this->assertSame(0.0, $check['ceiling']['over_by'],
            'Under the 1000 ceiling, no hard overrun.');
    }

    public function test_block_policy_throws_when_charge_would_breach_ceiling(): void
    {
        $this->actingAs($this->manager);
        Setting::put('staff_meal_over_limit_policy', 'block', 'staff_meals', 'string');

        // Push outstanding to 900 (under 1000 ceiling).
        $this->charge($this->employee, qty: 14);
        $this->assertSame(900.0, $this->employee->fresh()->staffMealOutstanding());

        // Next 200 order would land at 1100 → over the ceiling → block.
        $this->expectException(StaffMealLimitException::class);
        $this->expectExceptionMessageMatches('/سقف الدين/u');
        $this->charge($this->employee, qty: 2);
    }

    public function test_block_policy_allows_charges_inside_ceiling(): void
    {
        $this->actingAs($this->manager);
        Setting::put('staff_meal_over_limit_policy', 'block', 'staff_meals', 'string');

        // 5 burgers = 500, under both the monthly cap AND the ceiling.
        $charge = $this->charge($this->employee, qty: 5);
        $this->assertSame(0.0, (float) $charge->amount,
            'In-budget consumption is paid by the restaurant allowance.');
        $this->assertSame('allowance', $charge->settlement_method);
    }

    public function test_require_approval_policy_blocks_without_approver_and_allows_with_one(): void
    {
        $this->actingAs($this->manager);
        Setting::put('staff_meal_over_limit_policy', 'require_approval', 'staff_meals', 'string');

        // Push outstanding to 900.
        $this->charge($this->employee, qty: 14);

        // Same 200 order without approver → blocked.
        try {
            $this->charge($this->employee, qty: 2);
            $this->fail('Expected StaffMealLimitException for unapproved over-ceiling order.');
        } catch (StaffMealLimitException $e) {
            $this->assertStringContainsString('موافقة مدير', $e->getMessage());
        }

        // With manager approver → goes through.
        $order = $this->placeStaffOrder($this->employee, qty: 2);
        $charge = app(StaffMealService::class)->chargeOrder($order, approverUserId: $this->manager->id);
        $this->assertSame(200.0, (float) $charge->amount);
        $this->assertSame(1100.0, $this->employee->fresh()->staffMealOutstanding(),
            'Over-ceiling charges are still recorded — they require approval, not prevention.');
    }

    public function test_allow_log_policy_records_silently_without_warn(): void
    {
        $this->actingAs($this->manager);
        Setting::put('staff_meal_over_limit_policy', 'allow_log', 'staff_meals', 'string');
        $this->charge($this->employee, qty: 4);   // 400

        $check = app(StaffMealService::class)->previewLimitCheck($this->employee->fresh(), amount: 200);
        $this->assertSame('ok', $check['status'],
            'allow_log suppresses the warn signal even when allowance overflows.');
    }

    // ────────────────────────────────────────────────────────────────
    // Threshold notifications
    // ────────────────────────────────────────────────────────────────

    public function test_threshold_alerts_fire_at_80_100_and_120_percent(): void
    {
        $this->actingAs($this->manager);

        // 80% of 500 = 400. Use 4 burgers (=400).
        $this->charge($this->employee, qty: 4);
        $employeeRecord = Employee::where('user_id', $this->employee->id)->firstOrFail();
        $alerts = ActivityLog::where('event', 'staff_meal.threshold')
            ->where('subject_type', Employee::class)
            ->where('subject_id', $employeeRecord->id)
            ->get();
        $this->assertCount(1, $alerts, 'Crossing 80% fires exactly one alert.');
        $this->assertSame(80, (int) $alerts->first()->properties['threshold']);

        // Another burger → 500 = 100% → new alert
        $this->charge($this->employee, qty: 1);
        $this->assertSame(2, ActivityLog::where('event', 'staff_meal.threshold')->count(),
            '100% threshold fires a second alert.');

        // 2 more → 700 = 140% → 120% alert.
        $this->charge($this->employee, qty: 2);
        $this->assertSame(3, ActivityLog::where('event', 'staff_meal.threshold')->count(),
            '120% threshold fires a third alert.');
    }

    public function test_threshold_alerts_dedupe_within_same_month(): void
    {
        $this->actingAs($this->manager);
        // First charge crosses 80%.
        $this->charge($this->employee, qty: 4);   // 400 = 80%
        // Several more SMALL charges, each still over 80% — should NOT
        // fire 80% again (already alerted).
        $this->charge($this->employee, qty: 1);
        $count80 = ActivityLog::where('event', 'staff_meal.threshold')
            ->whereJsonContains('properties->threshold', 80)
            ->count();
        $this->assertSame(1, $count80,
            '80% alert is one-shot per (employee, month) — no spam.');
    }

    // ────────────────────────────────────────────────────────────────
    // Month close + payroll sheet
    // ────────────────────────────────────────────────────────────────

    public function test_close_month_batch_settles_open_charges_and_links_them_to_closure(): void
    {
        $this->actingAs($this->manager);

        $this->charge($this->employee, qty: 8);   // 800: restaurant 500, employee 300
        $other = $this->staff('other_emp', 'waiter');
        $other->update(['monthly_meal_allowance' => 400]);
        $orderOther = $this->placeStaffOrder($other, qty: 6);
        app(StaffMealService::class)->chargeOrder($orderOther);

        $this->assertSame(300.0, $this->employee->fresh()->staffMealOutstanding());
        $this->assertSame(200.0, $other->fresh()->staffMealOutstanding());

        $closure = app(StaffMealService::class)->closeMonth(
            month: now(),
            branchId: $this->branch->id,
            method: 'payroll_deduction',
            closedByUserId: $this->manager->id,
        );

        $this->assertSame(500.0, (float) $closure->total_amount);
        $this->assertSame(2, $closure->staff_count);
        $this->assertSame(2, $closure->charge_count);
        $this->assertSame(0.0, $this->employee->fresh()->staffMealOutstanding(),
            'All open charges cleared after closing the month.');
        $this->assertSame(0.0, $other->fresh()->staffMealOutstanding());

        // Every charge now links back to this closure for the sheet.
        $linked = StaffMealCharge::where('month_closure_id', $closure->id)->count();
        $this->assertSame(2, $linked);
    }

    public function test_close_month_is_idempotent_per_branch_month(): void
    {
        $this->actingAs($this->manager);
        $this->charge($this->employee, qty: 7);   // 700: employee due 200

        $first = app(StaffMealService::class)->closeMonth(now(), $this->branch->id, 'payroll_deduction', $this->manager->id);
        $second = app(StaffMealService::class)->closeMonth(now(), $this->branch->id, 'payroll_deduction', $this->manager->id);

        $this->assertSame($first->id, $second->id,
            'Re-closing the same month returns the existing closure (idempotent).');
        $this->assertSame(1, StaffMealMonthClosure::count(),
            'No duplicate closure rows.');
    }

    public function test_payroll_sheet_returns_per_employee_aggregates(): void
    {
        $this->actingAs($this->manager);
        $this->charge($this->employee, qty: 8);   // 800: employee due 300
        $closure = app(StaffMealService::class)->closeMonth(now(), $this->branch->id, 'payroll_deduction', $this->manager->id);

        $sheet = app(StaffMealService::class)->payrollSheet($closure);
        $this->assertCount(1, $sheet);
        $row = $sheet->first();
        $this->assertSame($this->employee->id, $row['user']->id);
        $this->assertSame(300.0, $row['total']);
        $this->assertSame(800.0, $row['consumption']);
        $this->assertSame(500.0, $row['covered']);
        $this->assertEqualsWithDelta(300.0, $row['overflow'], 0.001);
    }

    // ────────────────────────────────────────────────────────────────
    // Accounting integration
    // ────────────────────────────────────────────────────────────────

    public function test_charge_posts_journal_entry_debit_receivable_credit_revenue(): void
    {
        $this->actingAs($this->manager);
        $charge = $this->charge($this->employee, qty: 6);   // 600: employee due 100

        $entry = JournalEntry::where('event_type', 'staff_meal_charged')
            ->where('source_type', StaffMealCharge::class)
            ->where('source_id', $charge->id)
            ->with('lines.account')
            ->first();

        $this->assertNotNull($entry, 'Charging a staff meal MUST post a journal entry.');
        $debit = $entry->lines->firstWhere(fn ($l) => $l->debit > 0);
        $credit = $entry->lines->firstWhere(fn ($l) => $l->credit > 0);
        // Proper double-entry: receivable goes UP (DR 1110), internal
        // revenue is recognized (CR 4030). Mirrors a customer invoice
        // posting pattern (DR 1100 customer / CR 4000 sales revenue).
        $this->assertSame('1110', $debit->account->code, 'Debit hits the staff receivable account.');
        $this->assertSame('4030', $credit->account->code, 'Credit hits the staff meal revenue account.');
        $this->assertEqualsWithDelta(100.0, (float) $debit->debit, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $credit->credit, 0.001);
    }

    public function test_settlement_via_payroll_posts_2110_debit(): void
    {
        $this->actingAs($this->manager);
        $charge = $this->charge($this->employee, qty: 6);

        app(StaffMealService::class)->settle(
            staff: $this->employee,
            amount: 100,
            method: 'payroll_deduction',
            settledByUserId: $this->manager->id,
        );

        $settlement = JournalEntry::where('event_type', 'staff_meal_settled_payroll_deduction')
            ->where('source_id', $charge->id)
            ->with('lines.account')
            ->first();
        $this->assertNotNull($settlement, 'Settlement must post its own journal entry.');
        $debit = $settlement->lines->firstWhere(fn ($l) => $l->debit > 0);
        $credit = $settlement->lines->firstWhere(fn ($l) => $l->credit > 0);
        $this->assertSame('2110', $debit->account->code, 'Payroll deduction lands in the payroll liability.');
        $this->assertSame('1110', $credit->account->code, 'Receivable is cleared.');
    }

    public function test_cash_settlement_posts_1000_debit(): void
    {
        $this->actingAs($this->manager);
        $charge = $this->charge($this->employee, qty: 6);
        app(StaffMealService::class)->settle($this->employee, 100, 'cash', $this->manager->id);

        $entry = JournalEntry::where('event_type', 'staff_meal_settled_cash')
            ->where('source_id', $charge->id)
            ->with('lines.account')
            ->first();
        $this->assertNotNull($entry);
        $debit = $entry->lines->firstWhere(fn ($l) => $l->debit > 0);
        $this->assertSame('1000', $debit->account->code, 'Cash settlement debits the cash drawer.');
    }

    // ────────────────────────────────────────────────────────────────
    // Gift orders + per-charge waivers
    // ────────────────────────────────────────────────────────────────

    public function test_gift_order_records_a_charge_that_is_already_settled_and_does_not_touch_the_tab(): void
    {
        $this->actingAs($this->manager);

        $order = $this->placeStaffOrder($this->employee, qty: 2);   // 200
        $charge = app(StaffMealService::class)->chargeOrder(
            $order,
            settledByUserId: $this->manager->id,
            isGift: true,
            giftReason: 'عيد ميلاد',
        );

        $this->assertSame(0.0, (float) $charge->amount, 'A gift never creates employee debt.');
        $this->assertSame(200.0, $charge->consumptionAmount(), 'The order freezes the nominal value for reporting.');
        $this->assertNotNull($charge->settled_at, 'Gift is settled instantly — no debt accrues.');
        $this->assertSame('gift', $charge->settlement_method);
        $this->assertStringContainsString('عيد ميلاد', $charge->notes ?? '');

        $this->assertSame(0.0, $this->employee->fresh()->staffMealOutstanding(),
            'Tab outstanding stays at zero — a gift is NOT a debt.');
        $this->assertSame(0.0, $this->employee->fresh()->staffMealUsedInMonth(),
            'Used-this-month does not include gifts (only open charges count).');

        // No retail receivable/revenue is invented for a gift. Its actual
        // ingredient cost is recognized when inventory is consumed.
        $settle = JournalEntry::where('event_type', 'staff_meal_settled_gift')
            ->where('source_id', $charge->id)
            ->with('lines.account')
            ->first();
        $this->assertNull($settle);
    }

    public function test_gift_skips_limit_enforcement_even_when_over_ceiling(): void
    {
        $this->actingAs($this->manager);
        Setting::put('staff_meal_over_limit_policy', 'block', 'staff_meals', 'string');

        // Push the employee to the ceiling first (900 of 1000).
        $this->charge($this->employee, qty: 14);
        $this->assertSame(900.0, $this->employee->fresh()->staffMealOutstanding());

        // A regular 200 order would be blocked — but a GIFT 200 goes through.
        $order = $this->placeStaffOrder($this->employee, qty: 2);
        $charge = app(StaffMealService::class)->chargeOrder($order, isGift: true);

        $this->assertSame('gift', $charge->settlement_method);
        $this->assertSame(900.0, $this->employee->fresh()->staffMealOutstanding(),
            'Outstanding does NOT change — the gift bypasses the ceiling AND the tab.');
    }

    public function test_waive_charge_full_amount_settles_with_method_gift(): void
    {
        $this->actingAs($this->manager);
        $charge = $this->charge($this->employee, qty: 6);   // employee due 100
        $this->assertNull($charge->settled_at);

        app(StaffMealService::class)->waiveCharge(
            charge: $charge,
            amount: 100,
            userId: $this->manager->id,
            reason: 'تعويض عن خطأ بالطلب',
            asGift: true,
        );

        $this->assertNotNull($charge->fresh()->settled_at);
        $this->assertSame('gift', $charge->fresh()->settlement_method);
        $this->assertSame(0.0, $this->employee->fresh()->staffMealOutstanding(),
            'Full waiver clears the entire charge from the tab.');
    }

    public function test_partial_waiver_splits_the_row_in_two(): void
    {
        $this->actingAs($this->manager);
        $charge = $this->charge($this->employee, qty: 8);   // employee due 300
        $this->assertSame(300.0, $this->employee->fresh()->staffMealOutstanding());

        // Waive 100 — should split: original stays at 200 (open), new row carries 100 (settled gift).
        app(StaffMealService::class)->waiveCharge(
            charge: $charge,
            amount: 100,
            userId: $this->manager->id,
            reason: 'إعفاء جزئي',
            asGift: true,
        );

        $this->assertSame(200.0, (float) $charge->fresh()->amount,
            'Original row keeps the remaining 200 (still open).');
        $this->assertNull($charge->fresh()->settled_at, 'Original stays unsettled.');

        $this->assertSame(200.0, $this->employee->fresh()->staffMealOutstanding(),
            'Outstanding drops by exactly the waived 100.');

        // The waived 100 lives on a second row.
        $waivedRow = StaffMealCharge::where('order_id', $charge->order_id)
            ->where('id', '!=', $charge->id)
            ->first();
        $this->assertNotNull($waivedRow, 'Partial waiver must create a second row.');
        $this->assertSame(100.0, (float) $waivedRow->amount);
        $this->assertSame('gift', $waivedRow->settlement_method);
        $this->assertNotNull($waivedRow->settled_at);
    }

    public function test_waive_rejects_amount_above_charge_value(): void
    {
        $this->actingAs($this->manager);
        $charge = $this->charge($this->employee, qty: 6);   // employee due 100

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/أكبر من قيمة الحركة/u');
        app(StaffMealService::class)->waiveCharge($charge, amount: 500);
    }

    public function test_waive_rejects_already_settled_charge(): void
    {
        $this->actingAs($this->manager);
        $charge = $this->charge($this->employee, qty: 6);
        app(StaffMealService::class)->settle($this->employee, 100, 'cash');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/سُويت سابقاً/u');
        app(StaffMealService::class)->waiveCharge($charge->fresh(), amount: 50);
    }

    public function test_full_cycle_keeps_1110_receivable_balanced_to_zero(): void
    {
        $this->actingAs($this->manager);

        // Charge 100, then settle cash 100. Receivable should net to zero.
        $charge = $this->charge($this->employee, qty: 6);
        app(StaffMealService::class)->settle($this->employee, 100, 'cash');

        // Sum debits − credits on 1110 across both entries.
        $lines = JournalLine::join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', '1110')
            ->get(['journal_lines.debit', 'journal_lines.credit']);
        $balance = $lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit);

        $this->assertEqualsWithDelta(0.0, $balance, 0.001,
            'Receivable 1110 must net to zero after charge + settle — proves directionality is correct.');
    }

    public function test_monthly_summary_reports_gifted_amount(): void
    {
        $this->actingAs($this->manager);

        // 1 regular charge of 100 + 1 gift of 200.
        $this->charge($this->employee, qty: 6);
        $giftOrder = $this->placeStaffOrder($this->employee, qty: 2);
        app(StaffMealService::class)->chargeOrder($giftOrder, isGift: true);

        $summary = app(StaffMealService::class)->monthSummary($this->employee->fresh());
        $this->assertSame(600.0, (float) $summary['used']);
        $this->assertSame(500.0, (float) $summary['covered']);
        $this->assertSame(200.0, (float) $summary['gifted'], 'The 200 gift surfaces separately.');
        $this->assertSame(100.0, (float) $summary['outstanding'], 'Outstanding excludes gifts.');
    }

    // ─── helpers ──────────────────────────────────────────────────────

    public function test_month_close_does_not_settle_another_months_open_debt(): void
    {
        $this->actingAs($this->manager);
        $this->employee->update(['monthly_meal_allowance' => 0]);

        $older = $this->charge($this->employee, qty: 1);
        $older->update(['charged_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()]);
        $current = $this->charge($this->employee, qty: 2);

        $closure = app(StaffMealService::class)->closeMonth(
            now(),
            $this->branch->id,
            'payroll_deduction',
            $this->manager->id,
        );

        $this->assertNull($older->fresh()->settled_at);
        $this->assertNotNull($current->fresh()->settled_at);
        $this->assertSame($closure->id, $current->fresh()->month_closure_id);
        $this->assertSame(100.0, $this->employee->fresh()->staffMealOutstanding());
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
            'token' => 'sd-'.uniqid(),
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

    protected function charge(User $employee, int $qty): StaffMealCharge
    {
        return app(StaffMealService::class)->chargeOrder(
            $this->placeStaffOrder($employee, $qty),
            $this->manager->id,
        );
    }
}
