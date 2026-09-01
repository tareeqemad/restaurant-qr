<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * rep-ops — the operational reports on Inertia/Vue: end-of-day and
 * branch-comparison.
 *
 * These screens had ZERO test coverage as Blade, and the migration moved a
 * lot of arithmetic out of templates into the controller. What is pinned
 * here is exactly what would have shipped broken and silent:
 *
 *   • end-of-day's two inventory cost accumulators — a financial total the
 *     old template built while iterating rendered rows;
 *   • the method→Arabic label map and the tfoot totals over the ALREADY
 *     grouped payments collection;
 *   • the deliberate quirks: invoice aggregates include draft/cancelled so
 *     the three status counters do NOT sum to invoices_count, and inventory
 *     costs print with no currency symbol;
 *   • branch-comparison's owner-level gate, its cross-branch read (it must
 *     see a branch that is NOT the active one), and the pre-rendered
 *     best/worst matrix — whose highlights ride on integer position alone.
 */
class ReportsOpsVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $north;

    protected Branch $south;

    protected User $owner;

    protected Unit $gram;

    protected Unit $ml;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->north = Branch::create(['code' => 'ops-north', 'name' => 'الفرع الشمالي', 'is_active' => true, 'display_order' => 1]);
        $this->south = Branch::create(['code' => 'ops-south', 'name' => 'الفرع الجنوبي', 'is_active' => true, 'display_order' => 2]);

        BranchContext::set($this->north->id);

        // PermissionSeeder syncs onto roles that ALREADY exist — a role
        // created afterwards silently ends up with zero permissions.
        foreach (['super_admin', 'admin', 'manager', 'cashier', 'waiter'] as $role) {
            Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);
        }

        $this->seed(PermissionSeeder::class);

        $this->owner = $this->staff('super_admin', 'ops_owner', $this->north);

        $this->gram = Unit::create(['code' => 'g',  'name' => 'g',  'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $this->ml = Unit::create(['code' => 'ml', 'name' => 'ml', 'unit_type' => 'volume', 'factor_to_base' => 1, 'is_base' => true]);

        $this->category = Category::create(['slug' => 'mains', 'name' => 'أطباق', 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** users has no branch_id column — membership is primary_branch_id + the pivot. */
    protected function staff(string $role, string $username, Branch ...$branches): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'primary_branch_id' => $branches[0]->id,
            'role' => $role,
        ]);

        foreach ($branches as $branch) {
            $user->branches()->attach($branch->id, ['is_primary' => true]);
        }

        return $user;
    }

    /** Run a closure with a different branch bound, then restore. */
    protected function inBranch(Branch $branch, callable $fn)
    {
        $previous = BranchContext::current();
        BranchContext::set($branch->id);
        try {
            return $fn();
        } finally {
            $previous ? BranchContext::set($previous) : BranchContext::clear();
        }
    }

    protected function order(Branch $branch, array $attrs = []): Order
    {
        return $this->inBranch($branch, fn () => Order::create(array_merge([
            'status' => 'completed',
            'order_type' => 'dine_in',
            'order_source' => 'dine_in',
            'subtotal' => 0,
            'total' => 0,
        ], $attrs)));
    }

    /** An Invoice needs exactly ONE origin — order_id XOR table_session_id. */
    protected function invoice(Branch $branch, Order $order, array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'status' => 'paid',
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'service_total' => 0,
            'total' => 0,
            'paid_total' => 0,
            'balance' => 0,
            'issued_at' => now(),
        ], $attrs));
    }

    protected function ingredient(string $name, Unit $base): Ingredient
    {
        return Ingredient::create([
            'sku' => 'ING-'.substr(md5($name), 0, 8),
            'name' => $name,
            'base_unit_id' => $base->id,
            'cost_per_unit' => 1,
            // Left OFF so branch-comparison's low_count N+1 does not fire and
            // the KPI stays a deterministic 0 on both branches.
            'track_stock' => false,
            'active' => true,
        ]);
    }

    protected function movement(Branch $branch, Ingredient $ing, string $type, float $qtyBase, float $cost, ?string $when = null): InventoryMovement
    {
        return InventoryMovement::create([
            'branch_id' => $branch->id,
            'ingredient_id' => $ing->id,
            'type' => $type,
            'quantity' => $qtyBase,
            'quantity_in_base' => $qtyBase,
            'unit_id' => $ing->base_unit_id,
            'unit_cost' => $qtyBase > 0 ? $cost / $qtyBase : 0,
            'total_cost' => $cost,
            'occurred_at' => $when ?? now(),
        ]);
    }

    protected function menuItem(string $name): MenuItem
    {
        return MenuItem::create([
            'category_id' => $this->category->id,
            'slug' => Str::slug($name).'-'.uniqid(),
            'name' => $name,
            'price' => 10,
            'is_available' => true,
        ]);
    }

    /** Props as the browser would see them — through a JSON round-trip. */
    protected function props($response): array
    {
        return json_decode(json_encode($response->viewData('page')), true)['props'];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  END OF DAY
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The whole closing sheet in one shot: the summary (including the
     * draft-invoice quirk), the payment method rows + their tfoot, the
     * collector split, the top items, and the inventory usage table with
     * the two cost accumulators the Blade used to build while rendering.
     */
    public function test_end_of_day_ships_every_figure_the_closing_sheet_needs(): void
    {
        $today = now()->toDateString();
        $cashier = $this->staff('cashier', 'ops_cashier', $this->north);

        $burger = $this->menuItem('برجر');
        $juice = $this->menuItem('عصير');

        $orderA = $this->order($this->north, ['status' => 'completed', 'subtotal' => 70, 'total' => 75.5]);
        OrderItem::create(['order_id' => $orderA->id, 'menu_item_id' => $burger->id,
            'name_snapshot' => 'برجر', 'quantity' => 3, 'unit_price' => 15, 'subtotal' => 45, 'status' => 'served']);
        OrderItem::create(['order_id' => $orderA->id, 'menu_item_id' => $juice->id,
            'name_snapshot' => 'عصير', 'quantity' => 5, 'unit_price' => 5, 'subtotal' => 25, 'status' => 'served']);
        // Cancelled ITEM — excluded by the only filter this query applies.
        OrderItem::create(['order_id' => $orderA->id, 'menu_item_id' => $burger->id,
            'name_snapshot' => 'ملغي', 'quantity' => 99, 'unit_price' => 1, 'subtotal' => 99, 'status' => 'cancelled']);

        $orderB = $this->order($this->north, ['status' => 'cancelled', 'subtotal' => 30, 'total' => 34.5]);
        $orderC = $this->order($this->north, ['status' => 'completed', 'subtotal' => 100, 'total' => 100]);

        $paid = $this->invoice($this->north, $orderA, ['status' => 'paid', 'subtotal' => 70,
            'tax_total' => 7, 'service_total' => 3.5, 'discount_total' => 5, 'total' => 75.5, 'paid_total' => 75.5]);
        $this->invoice($this->north, $orderB, ['status' => 'issued', 'subtotal' => 30,
            'tax_total' => 3, 'service_total' => 1.5, 'total' => 34.5, 'paid_total' => 10, 'balance' => 24.5]);
        // DRAFT — counted in invoices_count and in every money total, but in
        // none of the three status counters. Documented, deliberate, pinned.
        $this->invoice($this->north, $orderC, ['status' => 'draft', 'subtotal' => 100, 'total' => 100]);

        Payment::create(['branch_id' => $this->north->id, 'invoice_id' => $paid->id, 'method' => 'cash',
            'amount' => 40, 'received_by_user_id' => $cashier->id, 'paid_at' => now()]);
        Payment::create(['branch_id' => $this->north->id, 'invoice_id' => $paid->id, 'method' => 'transfer',
            'amount' => 25.5, 'received_by_user_id' => $cashier->id, 'paid_at' => now()]);
        // payments.received_by_user_id is NOT NULL, so the "unknown collector"
        // bucket is reached the only way it can be in production: the receiver
        // is gone (soft-deleted), the relation resolves to null, and the row
        // falls back to "تحصيل ذاتي / غير محدد".
        $ghost = $this->staff('cashier', 'ops_ghost', $this->north);
        Payment::create(['branch_id' => $this->north->id, 'invoice_id' => $paid->id, 'method' => 'card',
            'amount' => 10, 'received_by_user_id' => $ghost->id, 'paid_at' => now()]);
        $ghost->delete();

        $flour = $this->ingredient('طحين', $this->gram);
        $oil = $this->ingredient('زيت', $this->ml);
        $this->movement($this->north, $flour, 'out', 2500, 12.5);
        $this->movement($this->north, $flour, 'waste', 500, 2.25);
        $this->movement($this->north, $oil, 'out', 1500, 8.0);
        // Another branch's movement must NOT leak into a branch-scoped report.
        $this->movement($this->south, $flour, 'out', 9999, 999.99);

        $response = $this->actingAs($this->owner)->get('/admin/reports/end-of-day?date='.$today);
        $response->assertOk();
        $props = $this->props($response);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/EndOfDay')
            ->where('date', $today)
            ->where('invoicesPaidLabel', '1 / 3')
            ->where('statTones.orders', 'warning')     // one cancelled order today
            ->where('statTones.discount', 'warning')   // discount_total > 0
            ->where('inventoryTotals.showFooter', true)
            ->where('inventoryTotals.showWasteRow', true)
            ->has('currency.symbol')
            ->has('urls.self')
            ->has('urls.reportsIndex')
            ->has('brandName')
        );

        // ── summary ── the three status counters do NOT sum to invoices_count.
        $this->assertSame(3, $props['summary']['invoices_count']);
        $this->assertSame(1, $props['summary']['invoices_paid']);
        $this->assertSame(1, $props['summary']['invoices_unpaid']);
        $this->assertSame(0, $props['summary']['invoices_writeoff']);
        $this->assertSame(3, $props['summary']['orders_count']);
        $this->assertSame(1, $props['summary']['orders_cancelled']);
        $this->assertEquals(200, $props['summary']['gross_sales']);   // 70 + 30 + 100 (draft included)
        $this->assertEquals(10, $props['summary']['tax_total']);
        $this->assertEquals(5, $props['summary']['service_total']);
        $this->assertEquals(5, $props['summary']['discount_total']);
        $this->assertEquals(210, $props['summary']['total_billed']);
        $this->assertEquals(75.5, $props['summary']['total_collected']);

        // ── payments by method ── labels came out of a Blade @switch.
        $byMethod = collect($props['byMethod'])->keyBy('method');
        $this->assertSame('كاش', $byMethod['cash']['label']);
        $this->assertSame('حوالة', $byMethod['transfer']['label']);
        $this->assertSame('كارد', $byMethod['card']['label']);
        $this->assertSame(1, $byMethod['cash']['count']);
        $this->assertEquals(40, $byMethod['cash']['total']);
        $this->assertEquals(25.5, $byMethod['transfer']['total']);
        // tfoot: summed over the ALREADY-grouped collection, not re-queried.
        $this->assertSame(3, $props['byMethodTotals']['count']);
        $this->assertEquals(75.5, $props['byMethodTotals']['total']);

        // ── collection by user ── sorted total DESC; cash+transfer < total is
        // normal because card/app/credit money has no column of its own.
        $collectors = $props['byCollector'];
        $this->assertCount(2, $collectors);
        $this->assertSame('ops_cashier', $collectors[0]['name']);
        $this->assertEquals(40, $collectors[0]['cash']);
        $this->assertEquals(25.5, $collectors[0]['transfer']);
        $this->assertEquals(65.5, $collectors[0]['total']);
        $this->assertSame('تحصيل ذاتي / غير محدد', $collectors[1]['name']);
        $this->assertEquals(0, $collectors[1]['cash']);
        $this->assertEquals(10, $collectors[1]['total']);

        // ── top items ── qty DESC, rank server-side, qtyText at ONE decimal.
        $this->assertSame(2, count($props['topItems']));
        $this->assertSame(1, $props['topItems'][0]['rank']);
        $this->assertSame('عصير', $props['topItems'][0]['name']);
        $this->assertSame('5.0', $props['topItems'][0]['qtyText']);
        $this->assertEquals(25, $props['topItems'][0]['total']);
        $this->assertSame('برجر', $props['topItems'][1]['name']);
        $this->assertSame('3.0', $props['topItems'][1]['qtyText']);
        // The cancelled line never appears.
        $this->assertNotContains('ملغي', array_column($props['topItems'], 'name'));

        // ── inventory usage ── row order is NOT deterministic (no ORDER BY),
        // so look rows up by (name, type) rather than by index.
        $usage = collect($props['inventoryUsage']);
        $this->assertCount(3, $usage, 'south branch movement must not leak in');

        $flourOut = $usage->firstWhere(fn ($r) => $r['name'] === 'طحين' && $r['type'] === 'out');
        $this->assertFalse($flourOut['isWaste']);
        $this->assertSame('2.5 كيلوغرام', $flourOut['qtyText']);   // PHP-only unit ladder
        $this->assertSame('2,500.0000 g', $flourOut['qtyExact']);
        $this->assertSame('12.50', $flourOut['costText']);
        // Deliberately unlike every other money cell on the page: no symbol.
        $this->assertStringNotContainsString('₪', $flourOut['costText']);

        $flourWaste = $usage->firstWhere(fn ($r) => $r['name'] === 'طحين' && $r['type'] === 'waste');
        $this->assertTrue($flourWaste['isWaste']);
        $this->assertSame('500 غرام', $flourWaste['qtyText']);

        $oilOut = $usage->firstWhere(fn ($r) => $r['name'] === 'زيت');
        $this->assertSame('1.5 لتر', $oilOut['qtyText']);

        // ── the accumulators the template used to build while rendering ──
        $this->assertEquals(20.5, $props['inventoryTotals']['usageCost']);   // 12.50 + 8.00
        $this->assertEquals(2.25, $props['inventoryTotals']['wasteCost']);
        $this->assertEquals(22.75, $props['inventoryTotals']['grandTotal']);
        $this->assertSame('20.50', $props['inventoryTotals']['usageText']);
        $this->assertSame('2.25', $props['inventoryTotals']['wasteText']);
        $this->assertSame('22.75', $props['inventoryTotals']['grandText']);
    }

    /** A day with nothing on it must still render, with the footer gated off. */
    public function test_end_of_day_on_a_quiet_day_renders_with_the_inventory_footer_hidden(): void
    {
        $response = $this->actingAs($this->owner)
            ->get('/admin/reports/end-of-day?date='.now()->subYear()->toDateString());

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/EndOfDay')
            ->where('byMethod', [])
            ->where('byCollector', [])
            ->where('topItems', [])
            ->where('inventoryUsage', [])
            ->where('inventoryTotals.showFooter', false)
            ->where('inventoryTotals.showWasteRow', false)
            ->where('invoicesPaidLabel', '0 / 0')
            ->where('statTones.orders', 'info')
            ->where('statTones.discount', 'muted')
        );
    }

    /** No ?date → today; the filter round-trips through the query string. */
    public function test_end_of_day_defaults_to_today_and_honours_the_date_filter(): void
    {
        $this->actingAs($this->owner)->get('/admin/reports/end-of-day')
            ->assertInertia(fn (Assert $page) => $page->where('date', now()->toDateString()));

        $this->actingAs($this->owner)->get('/admin/reports/end-of-day?date=2026-01-15')
            ->assertInertia(fn (Assert $page) => $page->where('date', '2026-01-15'));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  BRANCH COMPARISON
    // ─────────────────────────────────────────────────────────────────────

    protected function seedTwoBranchUniverse(): void
    {
        $flour = $this->ingredient('طحين', $this->gram);

        $northOrder = $this->order($this->north, ['total' => 1000]);
        $southOrder = $this->order($this->south, ['total' => 500]);

        $this->invoice($this->north, $northOrder, ['status' => 'paid', 'total' => 1000, 'paid_total' => 1000]);
        $this->invoice($this->south, $southOrder, ['status' => 'paid', 'total' => 500, 'paid_total' => 500]);

        $this->movement($this->north, $flour, 'out', 100, 400);
        $this->movement($this->north, $flour, 'waste', 10, 50);
        $this->movement($this->south, $flour, 'out', 50, 100);
    }

    /**
     * The matrix reads ACROSS branches while the active branch is north —
     * that is BranchContext::unscoped() doing its job. If this ever regresses
     * to the scoped read, south's column silently goes to zero.
     */
    public function test_branch_comparison_reads_across_branches_and_pre_renders_the_best_worst_matrix(): void
    {
        $this->seedTwoBranchUniverse();

        $from = now()->subDays(1)->toDateString();
        $to = now()->toDateString();

        $response = $this->actingAs($this->owner)
            ->get("/admin/reports/branch-comparison?from={$from}&to={$to}");
        $response->assertOk();
        $props = $this->props($response);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/BranchComparison')
            ->where('rowCount', 2)
            ->has('branches', 2)
            ->has('kpiDefs', 12)
            ->has('cells', 12)
            ->has('notesText', 3)
            ->where('canExport', true)
        );

        // branches[] is display_order then name; rows[] is the SAME index order.
        $this->assertSame('الفرع الشمالي', $props['branches'][0]['name']);
        $this->assertSame('ops-north', $props['branches'][0]['code']);
        $this->assertSame('الفرع الجنوبي', $props['branches'][1]['name']);

        $rows = $props['rows'];
        $this->assertSame($props['branches'][0]['id'], $rows[0]['branchId']);
        $this->assertEquals(1000, $rows[0]['revenue']);
        $this->assertEquals(500, $rows[1]['revenue'], 'the non-active branch must still be read');
        $this->assertEquals(400, $rows[0]['cogs']);
        $this->assertEquals(600, $rows[0]['gross_profit']);
        $this->assertEquals(60, $rows[0]['profit_margin']);
        $this->assertEquals(80, $rows[1]['profit_margin']);
        $this->assertEquals(50, $rows[0]['waste_cost']);
        $this->assertEquals(12.5, $rows[0]['waste_pct_of_cogs']);

        $kpiKeys = array_column($props['kpiDefs'], 'key');
        $this->assertSame([
            'revenue', 'cogs', 'gross_profit', 'profit_margin', 'waste_cost',
            'waste_pct_of_cogs', 'purchases', 'stock_value', 'low_count',
            'invoice_count', 'open_pos', 'ap_balance',
        ], $kpiKeys);

        $idx = array_flip($kpiKeys);

        // money → hardcoded ₪ (this screen does NOT use Money::format)
        $this->assertSame('1,000.00 ₪', $props['cells'][$idx['revenue']][0]['text']);
        $this->assertTrue($props['cells'][$idx['revenue']][0]['isBest']);
        $this->assertSame('500.00 ₪', $props['cells'][$idx['revenue']][1]['text']);
        $this->assertTrue($props['cells'][$idx['revenue']][1]['isWorst']);
        $this->assertSame('↑ الأعلى أفضل', $props['kpiDefs'][$idx['revenue']]['directionHint']);

        // Percentage values use two decimals in this report.
        $this->assertSame('80.00%', $props['cells'][$idx['profit_margin']][1]['text']);
        $this->assertTrue($props['cells'][$idx['profit_margin']][1]['isBest']);

        // lower-is-better flips which column gets the trophy
        $this->assertSame('↓ الأقل أفضل', $props['kpiDefs'][$idx['waste_cost']]['directionHint']);
        $this->assertTrue($props['cells'][$idx['waste_cost']][1]['isBest']);
        $this->assertTrue($props['cells'][$idx['waste_cost']][0]['isWorst']);

        // higher_is_better === null → no direction hint, no highlight anywhere
        $this->assertNull($props['kpiDefs'][$idx['cogs']]['directionHint']);
        $this->assertNull($props['kpiDefs'][$idx['cogs']]['best_idx']);
        $this->assertFalse($props['cells'][$idx['cogs']][0]['isBest']);
        $this->assertFalse($props['cells'][$idx['cogs']][0]['isWorst']);

        // A KPI where every branch ties shows no green/red at all.
        $this->assertNull($props['kpiDefs'][$idx['invoice_count']]['best_idx']);
        $this->assertFalse($props['cells'][$idx['invoice_count']][0]['isBest']);

        // int → bare, no thousands separator
        $this->assertSame('0', $props['cells'][$idx['low_count']][0]['text']);
        $this->assertSame('1', $props['cells'][$idx['invoice_count']][0]['text']);

        $this->assertStringContainsString('export=csv', $props['exportUrl']);
    }

    /** No active branches → the empty state, and the CSV button is gated off. */
    public function test_branch_comparison_with_no_active_branches_ships_an_empty_matrix(): void
    {
        Branch::query()->update(['is_active' => false]);

        $this->actingAs($this->owner)->get('/admin/reports/branch-comparison')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/BranchComparison')
                ->where('rowCount', 0)
                ->where('branches', [])
                ->where('rows', [])
            );
    }

    /**
     * Owner-level only. A manager holds reports.viewAny — it gets them into
     * end-of-day but NOT into this screen, and the difference is the
     * controller's own abort, not the permission middleware.
     */
    public function test_branch_comparison_is_owner_level_only(): void
    {
        $manager = $this->staff('manager', 'ops_manager', $this->north);

        $this->actingAs($manager)->get('/admin/reports/end-of-day')->assertOk();
        $this->actingAs($manager)->get('/admin/reports/branch-comparison')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/reports/branch-comparison')->assertOk();
    }

    /** Staff without reports.viewAny never reach either operational report. */
    public function test_the_operational_reports_require_the_reports_permission(): void
    {
        $waiter = $this->staff('waiter', 'ops_waiter', $this->north);

        $this->actingAs($waiter)->get('/admin/reports/end-of-day')->assertForbidden();
        $this->actingAs($waiter)->get('/admin/reports/branch-comparison')->assertForbidden();
    }

    /**
     * The CSV branch must keep returning a streamed file, NOT an Inertia
     * page — that is why the button stays a native <a href> in the Vue page.
     */
    public function test_branch_comparison_csv_export_still_streams_a_file(): void
    {
        $this->seedTwoBranchUniverse();

        $response = $this->actingAs($this->owner)
            ->get('/admin/reports/branch-comparison?from='.now()->subDays(1)->toDateString()
                .'&to='.now()->toDateString().'&export=csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('branch-comparison-', $response->headers->get('content-disposition'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 BOM so Excel reads Arabic');
        $this->assertStringContainsString('الفرع الشمالي (ops-north)', $body);
        // Money in the CSV has NO thousands separator so Excel parses it.
        $this->assertStringContainsString('1000.00', $body);
        $this->assertStringContainsString('higher = better', $body);
    }
}
