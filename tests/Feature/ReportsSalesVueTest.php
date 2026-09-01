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
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The rep-sales group on Inertia/Vue: the reports hub, the daily sales
 * report, and the top-selling-items report.
 *
 * Everything these three Blades used to compute in-template — bar heights,
 * the 9-tile report map, per-row collection rates and their badge tones,
 * and the absolute row rank — is now a prop.
 * That is exactly what is pinned here: not markup, but the payload, so a
 * refactor that quietly changes a reported number fails loudly.
 *
 * Several assertions deliberately pin behaviour the audit flagged as
 * surprising-but-shipped (unclamped channel share, written-off invoices
 * appearing in sales but not in the hub, cancelled orders counting in the
 * items report). They are regression guards, not endorsements.
 */
class ReportsSalesVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected MenuItem $meal;

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'rep-main', 'name' => 'الفرع الرئيسي', 'is_active' => true, 'display_order' => 1]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(
            ['name' => 'accountant'],
            ['label' => 'محاسب', 'is_system' => true, 'display_order' => 5],
        );
        $this->seed(PermissionSeeder::class);

        $this->admin = $this->staff('super_admin', 'rep_admin');

        $storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'Main',
            'is_default' => true, 'active' => true,
        ]);
        $station = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $storage->id, 'active' => true,
        ]);
        $category = Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $station->id, 'active' => true,
        ]);

        // cost = 4 → a 2-unit line costs 8 of COGS.
        $this->meal = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $station->id,
            'sku' => 'M-1', 'slug' => 'meal', 'name' => 'وجبة', 'price' => 10, 'cost' => 4,
            'is_available' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ── fixtures ──────────────────────────────────────────────────────

    /** `users` has no branch_id column — membership is primary + pivot. */
    protected function staff(string $role, string $username): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'primary_branch_id' => $this->branch->id,
            'role' => $role,
        ]);
        $user->branches()->attach($this->branch->id);

        return $user;
    }

    protected function order(string $status, float $total): Order
    {
        return Order::create([
            'number' => 'O-'.(++$this->seq),
            'status' => $status,
            'order_type' => 'dine_in',
            'order_source' => 'dine_in',
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    /** An invoice needs exactly one origin, so every one gets its own order. */
    protected function invoice(string $status, Carbon $when, array $money): Invoice
    {
        $order = $this->order('completed', $money['total'] ?? 0);

        return Invoice::create([
            'order_id' => $order->id,
            'number' => 'INV-'.(++$this->seq),
            'status' => $status,
            'issued_at' => $when,
            'subtotal' => $money['subtotal'] ?? 0,
            'tax_total' => $money['tax'] ?? 0,
            'service_total' => $money['service'] ?? 0,
            'total' => $money['total'] ?? 0,
            'paid_total' => $money['paid'] ?? 0,
            'balance' => $money['balance'] ?? 0,
        ]);
    }

    protected function line(Order $order, string $name, float $qty, float $subtotal, string $status = 'approved'): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $this->meal->id,
            'name_snapshot' => $name,
            'quantity' => $qty,
            'unit_price' => $qty > 0 ? $subtotal / $qty : 0,
            'subtotal' => $subtotal,
            'status' => $status,
        ]);
    }

    protected function waste(float $cost): InventoryMovement
    {
        $unit = Unit::firstOrCreate(['code' => 'g'], [
            'name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true,
        ]);
        $ing = Ingredient::create([
            'name' => 'دقيق', 'sku' => 'ING-1', 'base_unit_id' => $unit->id,
            'current_stock' => 0, 'reorder_threshold' => 0, 'cost_per_unit' => 1,
            'track_stock' => true, 'active' => true,
        ]);

        return InventoryMovement::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ing->id,
            'type' => 'waste',
            'quantity' => 10,
            'quantity_in_base' => 10,
            'unit_id' => $unit->id,
            'total_cost' => $cost,
            'occurred_at' => now(),
        ]);
    }

    /**
     * The hub fixture: one paid invoice of 100 backed by a 2×(cost 4) line,
     * one unpaid invoice carrying a 50 balance, a portal order, and 12 of
     * waste. Numbers are chosen so every derived figure lands whole.
     */
    protected function seedHub(): array
    {
        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();

        $paid = $this->invoice('paid', now(), [
            'subtotal' => 92, 'tax' => 5, 'service' => 3, 'total' => 100, 'paid' => 100,
        ]);
        $this->line($paid->order, 'وجبة', 2, 20);

        // status `issued` → counted in unpaidBalance, never in revenue.
        $this->invoice('issued', now(), [
            'subtotal' => 50, 'total' => 50, 'paid' => 0, 'balance' => 50,
        ]);

        $this->order('completed', 40);
        $this->waste(12);

        return [$from, $to];
    }

    // ── 1. the hub ────────────────────────────────────────────────────

    public function test_the_hub_ships_every_kpi_alert_tile_and_channel(): void
    {
        [$from, $to] = $this->seedHub();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('Admin/Reports/Index');
                $p = $page->toArray()['props'];

                // KPIs — revenue is GROSS paid over paid/partially_paid only.
                $this->assertEqualsWithDelta(100, $p['stats']['revenue'], 0.001);
                $this->assertSame(1, $p['stats']['invoiceCount']);
                $this->assertEqualsWithDelta(8, $p['stats']['cogs'], 0.001);
                $this->assertEqualsWithDelta(92, $p['stats']['grossProfit'], 0.001);
                $this->assertEqualsWithDelta(92, $p['stats']['marginPct'], 0.001);
                $this->assertEqualsWithDelta(100, $p['stats']['averageTicket'], 0.001);
                $this->assertEqualsWithDelta(12, $p['stats']['wasteCost'], 0.001);
                $this->assertEqualsWithDelta(50, $p['stats']['unpaidBalance'], 0.001);
                // 4-day window → 100 / 4.
                $this->assertEqualsWithDelta(25, $p['stats']['dailyAverage'], 0.001);
                // 3 orders: the two invoice origins + the portal order.
                $this->assertSame(3, $p['stats']['ordersCount']);

                // Alerts, in build order: waste then unpaid.
                // Margin is 92%, so the low-margin alert never fires.
                $this->assertCount(2, $p['alerts']);
                $this->assertSame('warning', $p['alerts'][0]['tone']);
                $this->assertSame('الهدر أعلى من الطبيعي', $p['alerts'][0]['title']);
                $this->assertStringContainsString('150.0%', $p['alerts'][0]['body']);
                $this->assertNotNull($p['alerts'][0]['link']);
                $this->assertSame('info', $p['alerts'][1]['tone']);
                $this->assertSame('رصيد غير محصل', $p['alerts'][1]['title']);
                $this->assertNull($p['alerts'][1]['link'], 'alert #4 has no link — Vue must render a non-focusable div');

                // Top items: profit = subtotal − qty × cost = 20 − 8.
                $this->assertCount(1, $p['topItems']);
                $this->assertSame('وجبة', $p['topItems'][0]['name']);
                $this->assertEqualsWithDelta(2, $p['topItems'][0]['qty'], 0.001);
                $this->assertEqualsWithDelta(20, $p['topItems'][0]['revenue'], 0.001);
                $this->assertEqualsWithDelta(12, $p['topItems'][0]['profit'], 0.001);

                $this->assertSame(['symbol' => '₪', 'decimals' => 2], $p['currency']);
                $this->assertArrayHasKey('today', $p['shortcuts']);
                $this->assertArrayHasKey('week', $p['shortcuts']);
                $this->assertArrayHasKey('month', $p['shortcuts']);
                $this->assertSame(route('admin.reports.index'), $p['urls']['self']);
            });
    }

    public function test_the_trend_is_zero_filled_ascending_with_precomputed_bar_heights(): void
    {
        [$from, $to] = $this->seedHub();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $p = $page->toArray()['props'];

                // One entry per calendar day in a 4-day window, ascending.
                $this->assertCount(4, $p['trend']);
                $days = array_column($p['trend'], 'day');
                $sorted = $days;
                sort($sorted);
                $this->assertSame($sorted, $days, 'trend must be ascending');

                $this->assertEqualsWithDelta(100, $p['maxTrend'], 0.001);

                // Empty days are zero-filled and still get the 6% floor bar.
                $this->assertEqualsWithDelta(0, $p['trend'][0]['revenue'], 0.001);
                $this->assertSame(0, $p['trend'][0]['invoicesCount']);
                $this->assertEqualsWithDelta(6, $p['trend'][0]['heightPct'], 0.001);

                $last = $p['trend'][3];
                $this->assertSame(now()->toDateString(), $last['day']);
                $this->assertSame(now()->format('d/m'), $last['label']);
                $this->assertEqualsWithDelta(100, $last['revenue'], 0.001);
                $this->assertSame(1, $last['invoicesCount']);
                $this->assertEqualsWithDelta(100, $last['heightPct'], 0.001);
            });
    }

    public function test_the_hub_falls_back_to_the_all_clear_alert(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $p = $page->toArray()['props'];

                $this->assertCount(1, $p['alerts']);
                $this->assertSame('success', $p['alerts'][0]['tone']);
                $this->assertSame('المؤشرات مستقرة', $p['alerts'][0]['title']);
                $this->assertNull($p['alerts'][0]['link']);

                $this->assertSame([], $p['topItems']);
            });
    }

    /** The hub defaults to month-to-date; sales and items default to 30 days. */
    public function test_the_hub_defaults_to_month_to_date(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('from', now()->startOfMonth()->toDateString())
                ->where('to', now()->toDateString()));
    }

    /**
     * The 9-tile report map moved out of the Blade @php block. Every tile
     * must still point at a route that exists — including the ones other
     * agents migrated (profit-loss, trial-balance, reorder-suggestions).
     */
    public function test_every_hub_tile_points_at_a_route_that_resolves(): void
    {
        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($from, $to) {
                $tiles = $page->toArray()['props']['tiles'];

                $this->assertCount(9, $tiles);
                $this->assertSame([
                    'نهاية اليوم', 'قائمة الدخل', 'ميزان المراجعة', 'هندسة المنيو',
                    'اقتراحات الشراء', 'مخزون ومشتريات', 'تقييم المخزون',
                    'المبيعات اليومية', 'الأصناف والمخزون',
                ], array_column($tiles, 'title'));

                $range = compact('from', 'to');
                $this->assertSame([
                    route('admin.reports.end-of-day'),
                    route('admin.reports.profit-loss', $range),
                    route('admin.accounting.trial-balance'),
                    route('admin.reports.menu-engineering', $range),
                    route('admin.reports.reorder-suggestions'),
                    route('admin.inventory.dashboard'),
                    route('admin.reports.stock-valuation'),
                    route('admin.reports.sales', $range),
                    route('admin.reports.items', $range),
                ], array_column($tiles, 'href'));

                foreach ($tiles as $tile) {
                    $this->assertStringStartsWith('bi-', $tile['icon']);
                    $this->assertNotEmpty($tile['desc']);
                    $this->assertNotEmpty($tile['tone']);
                }
            });
    }

    // ── 2. daily sales ────────────────────────────────────────────────

    /**
     * Four days of trading, one of them written off and one of them
     * collecting nothing — enough to exercise the whole screen.
     */
    protected function seedSales(): array
    {
        $this->invoice('paid', now(), [
            'subtotal' => 90, 'tax' => 6, 'service' => 4, 'total' => 100, 'paid' => 100,
        ]);
        $this->invoice('partially_paid', now()->subDays(1), [
            'subtotal' => 180, 'tax' => 12, 'service' => 8, 'total' => 200, 'paid' => 170, 'balance' => 30,
        ]);
        $this->invoice('partially_paid', now()->subDays(2), [
            'subtotal' => 90, 'tax' => 6, 'service' => 4, 'total' => 100, 'paid' => 30, 'balance' => 70,
        ]);
        // Written off: the hub excludes this status, the sales report does not.
        $this->invoice('unpaid_writeoff', now()->subDays(3), [
            'subtotal' => 60, 'total' => 60, 'paid' => 0, 'balance' => 60,
        ]);

        return [now()->subDays(3)->toDateString(), now()->toDateString()];
    }

    public function test_the_sales_table_is_descending_and_the_chart_is_ascending(): void
    {
        [$from, $to] = $this->seedSales();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('Admin/Reports/Sales');
                $p = $page->toArray()['props'];

                $this->assertSame([
                    now()->toDateString(),
                    now()->subDays(1)->toDateString(),
                    now()->subDays(2)->toDateString(),
                    now()->subDays(3)->toDateString(),
                ], array_column($p['rows'], 'day'), 'the table order is DESC');

                $this->assertSame([
                    now()->subDays(3)->toDateString(),
                    now()->subDays(2)->toDateString(),
                    now()->subDays(1)->toDateString(),
                    now()->toDateString(),
                ], array_column($p['chart'], 'day'), 'the chart order is ASC');

                $this->assertSame(now()->format('d/m'), $p['rows'][0]['dayLabel']);
                $this->assertEqualsWithDelta(170, $p['maxPaid'], 0.001);

                // heightPct floors at 8 for a day that collected nothing.
                $this->assertEqualsWithDelta(8, $p['chart'][0]['heightPct'], 0.001);
                $this->assertEqualsWithDelta(100, $p['chart'][2]['heightPct'], 0.001);
                $this->assertEqualsWithDelta(100 / 170 * 100, $p['chart'][3]['heightPct'], 0.001);
            });
    }

    public function test_the_sales_rows_carry_their_rate_and_badge_tone(): void
    {
        [$from, $to] = $this->seedSales();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $rows = $page->toArray()['props']['rows'];

                // 100% → success (>= 95)
                $this->assertEqualsWithDelta(100, $rows[0]['rate'], 0.001);
                $this->assertSame('success', $rows[0]['rateTone']);
                // 85% → warning (>= 80). NOTE the StatRail uses `accent` here.
                $this->assertEqualsWithDelta(85, $rows[1]['rate'], 0.001);
                $this->assertSame('warning', $rows[1]['rateTone']);
                // 30% → danger
                $this->assertEqualsWithDelta(30, $rows[2]['rate'], 0.001);
                $this->assertSame('danger', $rows[2]['rateTone']);
                // 0% → danger
                $this->assertEqualsWithDelta(0, $rows[3]['rate'], 0.001);
                $this->assertSame('danger', $rows[3]['rateTone']);

                // The decimal:2 cast trap: subtotal/total came back as STRINGS
                // from Eloquent. Every figure must arrive as a JSON number.
                foreach (['subtotal', 'tax', 'service', 'total', 'paid', 'rate'] as $field) {
                    $this->assertIsNumeric($rows[1][$field]);
                    $this->assertFalse(is_string($rows[1][$field]), "rows[].$field must not be a string");
                }
                $this->assertSame(1, $rows[0]['invoicesCount']);
            });
    }

    public function test_the_sales_totals_and_collection_figures(): void
    {
        [$from, $to] = $this->seedSales();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $p = $page->toArray()['props'];

                $this->assertSame(4, $p['totals']['invoicesCount']);
                $this->assertEqualsWithDelta(420, $p['totals']['subtotal'], 0.001);
                $this->assertEqualsWithDelta(24, $p['totals']['tax'], 0.001);
                $this->assertEqualsWithDelta(16, $p['totals']['service'], 0.001);
                $this->assertEqualsWithDelta(460, $p['totals']['total'], 0.001);
                $this->assertEqualsWithDelta(300, $p['totals']['paid'], 0.001);
                // Was arithmetic in the template.
                $this->assertEqualsWithDelta(40, $p['totals']['taxPlusService'], 0.001);

                $this->assertEqualsWithDelta(300 / 460 * 100, $p['collectionRate'], 0.001);
                $this->assertEqualsWithDelta(160, $p['collectionGap'], 0.001);
                $this->assertEqualsWithDelta(75, $p['averageDaily'], 0.001);   // 300 / 4 days
                $this->assertEqualsWithDelta(75, $p['averageInvoice'], 0.001); // 300 / 4 invoices
            });
    }

    /**
     * `quietDay` filters `paid > 0`, so the written-off day that collected
     * nothing is never reported as the quietest — the day that collected
     * 30 is. `bestDay` has no such filter.
     */
    public function test_the_quiet_day_ignores_days_that_collected_nothing(): void
    {
        [$from, $to] = $this->seedSales();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $p = $page->toArray()['props'];

                $this->assertSame(now()->subDays(1)->toDateString(), $p['bestDay']['day']);
                $this->assertEqualsWithDelta(170, $p['bestDay']['paid'], 0.001);

                $this->assertSame(now()->subDays(2)->toDateString(), $p['quietDay']['day']);
                $this->assertEqualsWithDelta(30, $p['quietDay']['paid'], 0.001);
            });
    }

    public function test_an_empty_sales_period_ships_null_insights_and_no_rows(): void
    {
        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $p = $page->toArray()['props'];

                $this->assertSame([], $p['rows']);
                $this->assertSame([], $p['chart']);
                $this->assertNull($p['bestDay']);
                $this->assertNull($p['quietDay']);
                $this->assertEqualsWithDelta(0, $p['collectionRate'], 0.001);
                $this->assertEqualsWithDelta(0, $p['collectionGap'], 0.001);
                // maxPaid is floored at 1 so the chart never divides by zero.
                $this->assertEqualsWithDelta(1, $p['maxPaid'], 0.001);
            });
    }

    /**
     * By design the two screens do not tie out: the hub counts only
     * paid/partially_paid, the sales report also counts unpaid_writeoff.
     * Documented divergence — pinned so nobody "harmonises" it by accident.
     */
    public function test_a_written_off_invoice_shows_in_sales_but_not_in_the_hub(): void
    {
        $day = now()->subDays(1);
        $this->invoice('unpaid_writeoff', $day, ['subtotal' => 60, 'total' => 60, 'paid' => 0, 'balance' => 60]);

        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('totals.total', 60));

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', compact('from', 'to')))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.revenue', 0));
    }

    public function test_the_sales_report_defaults_to_thirty_days(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('from', now()->subDays(30)->toDateString())
                ->where('to', now()->toDateString())
                ->has('urls.reportsIndex'));
    }

    // ── 3. top-selling items ──────────────────────────────────────────

    /**
     * The items report filters ONLY the line's own status — a cancelled or
     * still-pending ORDER still contributes. That is why it disagrees with
     * the hub's profit panel about the same period.
     */
    public function test_the_items_report_counts_cancelled_and_pending_orders(): void
    {
        $completed = $this->order('completed', 20);
        $this->line($completed, 'وجبة', 2, 20);

        $pending = $this->order('pending', 30);
        $this->line($pending, 'وجبة', 3, 30);

        $cancelledOrder = $this->order('cancelled', 7);
        $this->line($cancelledOrder, 'عصير', 1, 7);

        // A cancelled LINE is the only thing actually excluded.
        $this->line($completed, 'كيك', 5, 50, 'cancelled');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.items'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('Admin/Reports/Items');
                $p = $page->toArray()['props'];

                $this->assertSame(2, $p['rows']['total']);
                $rows = $p['rows']['data'];

                // Ordered by quantity DESC.
                $this->assertSame(1, $rows[0]['rank']);
                $this->assertSame('وجبة', $rows[0]['name']);
                $this->assertEqualsWithDelta(5, $rows[0]['qty'], 0.001);
                $this->assertEqualsWithDelta(50, $rows[0]['total'], 0.001);
                $this->assertEqualsWithDelta(10, $rows[0]['avgUnit'], 0.001);

                $this->assertSame(2, $rows[1]['rank']);
                $this->assertSame('عصير', $rows[1]['name']);
                $this->assertEqualsWithDelta(7, $rows[1]['avgUnit'], 0.001);

                $this->assertArrayNotHasKey(2, $rows, 'the cancelled LINE must be excluded');
                $this->assertIsArray($p['rows']['links']);
            });
    }

    /**
     * `rank` used to be `$i + 1 + (currentPage - 1) * 50` in the template,
     * with the page size hardcoded. It is now absolute and server-computed.
     */
    public function test_the_items_rank_stays_absolute_across_pages(): void
    {
        $order = $this->order('completed', 0);
        // 51 distinct snapshot names with strictly descending quantities so
        // the qty DESC ordering (which has no tie-breaker) is deterministic.
        for ($i = 51; $i >= 1; $i--) {
            $this->line($order, 'صنف-'.$i, $i, $i * 2);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.reports.items'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $p = $page->toArray()['props'];

                $this->assertSame(51, $p['rows']['total']);
                $this->assertCount(50, $p['rows']['data']);
                $this->assertSame(1, $p['rows']['data'][0]['rank']);
                $this->assertSame('صنف-51', $p['rows']['data'][0]['name']);
                $this->assertSame(50, $p['rows']['data'][49]['rank']);
                $this->assertGreaterThan(3, count($p['rows']['links']));
            });

        $this->actingAs($this->admin)
            ->get(route('admin.reports.items', ['page' => 2]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $data = $page->toArray()['props']['rows']['data'];

                $this->assertCount(1, $data);
                $this->assertSame(51, $data[0]['rank'], 'rank must not restart on page 2');
                $this->assertSame('صنف-1', $data[0]['name']);
            });
    }

    public function test_the_items_report_defaults_to_thirty_days(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.items'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('from', now()->subDays(30)->toDateString())
                ->where('to', now()->toDateString())
                ->has('rows.links')
                ->has('urls.reportsIndex'));
    }

    // ── 4. gates, filters, currency ───────────────────────────────────

    public function test_all_three_screens_are_gated_by_the_reports_permission(): void
    {
        // A waiter passes the `admin` middleware but holds no reports.viewAny.
        $waiter = $this->staff('waiter', 'rep_waiter');

        foreach (['index', 'sales', 'items'] as $action) {
            $this->actingAs($waiter)
                ->get(route('admin.reports.'.$action))
                ->assertForbidden();
        }
    }

    /** The gate is the permission, not the role — an accountant holds it. */
    public function test_an_accountant_holds_the_reports_permission(): void
    {
        $accountant = $this->staff('accountant', 'rep_accountant');

        $this->actingAs($accountant)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Index'));
    }

    public function test_the_date_filter_narrows_every_screen(): void
    {
        $this->invoice('paid', now(), ['subtotal' => 90, 'tax' => 6, 'service' => 4, 'total' => 100, 'paid' => 100]);
        $this->invoice('paid', now()->subDays(20), ['subtotal' => 500, 'total' => 500, 'paid' => 500]);

        $today = now()->toDateString();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', ['from' => $today, 'to' => $today]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('from', $today)
                ->where('to', $today)
                ->has('rows', 1)
                ->where('totals.paid', 100));

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['from' => $today, 'to' => $today]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.revenue', 100)
                ->has('trend', 1));
    }

    /**
     * `currency` must follow the SETTINGS symbol the way Money::format()
     * does, not the config default — otherwise a restaurant that changed
     * its symbol sees two different symbols across the report suite.
     */
    public function test_the_currency_prop_follows_the_settings_symbol(): void
    {
        Setting::put('currency_symbol', 'JD');

        foreach (['index', 'sales', 'items'] as $action) {
            $this->actingAs($this->admin)
                ->get(route('admin.reports.'.$action))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('currency.symbol', 'JD')
                    ->where('currency.decimals', 2));
        }
    }

    /**
     * Date shortcuts are computed server-side (app timezone), not in the
     * browser. `7 أيام` is six days back — seven inclusive days.
     */
    public function test_the_date_shortcuts_are_server_computed(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shortcuts.today.from', now()->toDateString())
                ->where('shortcuts.today.to', now()->toDateString())
                ->where('shortcuts.week.from', now()->subDays(6)->toDateString())
                ->where('shortcuts.month.from', now()->startOfMonth()->toDateString())
                ->where('shortcuts.month.to', now()->toDateString()));
    }
}
