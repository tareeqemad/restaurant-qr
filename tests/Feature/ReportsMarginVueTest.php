<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * rep-margin — هندسة المنيو on Inertia/Vue.
 *
 * The screen's whole value is the median split, so the fixture is built to pin
 * it exactly: five items, an ODD count, so `intdiv(5, 2) = 2` picks the THIRD
 * of five ascending values on both axes — a real observed value, never an
 * interpolation. Every number below is hand-computed from that.
 *
 *   item  qty  revenue  cost/u  cogs  profit  margin%
 *   A      10      100       2     20      80     80.0
 *   B      10      100       8     80      20     20.0
 *   C       5       50       5     25      25     50.0
 *   D       2       40       1      2      38     95.0
 *   E       1       10       9      9       1     10.0
 *
 *   qty ascending    : E(1) D(2) C(5) A(10) B(10)  → index 2 → medianQty    = 5
 *   margin ascending : E(10) B(20) C(50) A(80) D(95) → index 2 → medianMargin = 50
 *
 * Both comparisons are `>=`, so the median item itself lands HIGH on that axis:
 *   A star · B plowhorse · C star (ties on BOTH axes) · D puzzle · E dog
 *
 * profit DESC → A(80) D(38) C(25) B(20) E(1) — deliberately NOT id order, which
 * is what pins the `->values()` bug the contract flagged as the highest risk:
 * without it the payload JSON-encodes as an object and JS silently re-sorts it
 * back into id order (A B C D E).
 */
class ReportsMarginVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Branch $other;

    protected User $owner;

    protected Category $category;

    /** menu item name => id, for the branch-scope assertions */
    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'me1', 'name' => 'فرع هندسة المنيو', 'is_active' => true, 'display_order' => 1]);
        $this->other = Branch::create(['code' => 'me2', 'name' => 'فرع آخر', 'is_active' => true, 'display_order' => 2]);

        BranchContext::set($this->branch->id);

        $this->seed(PermissionSeeder::class);

        $this->owner = $this->staff('super_admin', 'me_owner', $this->branch, $this->other);

        $this->category = Category::create([
            'branch_id' => $this->branch->id,
            'slug' => 'mains-me', 'name' => 'أطباق رئيسية', 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /**
     * `users` has no branch_id column — membership is primary_branch_id plus
     * the branches() pivot, and the role is a plain column backed by a Role row.
     */
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
            $user->branches()->attach($branch->id);
        }

        return $user;
    }

    protected function menuItem(string $name, float $price, float $cost, ?Branch $branch = null): MenuItem
    {
        $branch ??= $this->branch;

        $category = $branch->is($this->branch)
            ? $this->category
            : Category::withoutGlobalScopes()->firstOrCreate(
                ['branch_id' => $branch->id, 'slug' => 'mains-'.$branch->id],
                ['name' => 'أطباق', 'active' => true],
            );

        $item = MenuItem::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'category_id' => $category->id,
            'slug' => 'item-'.$branch->id.'-'.strtolower($name),
            'name' => $name,
            'price' => $price,
            'cost' => $cost,
            'is_available' => true,
        ]);

        $this->ids[$name] = $item->id;

        return $item;
    }

    /**
     * `branch_id` is NOT in Order::$fillable — mass-assigning it silently
     * drops the value and BelongsToBranch then back-fills the ACTIVE branch,
     * which would put every fixture order in the same branch and make the
     * scoping assertion below vacuously pass. Set it on the instance.
     */
    protected function order(Branch $branch, string $status = 'completed'): Order
    {
        $order = new Order([
            'number' => 'ME-'.uniqid(),
            'status' => $status,
            'order_type' => 'dine_in',
            'subtotal' => 0,
            'total' => 0,
        ]);
        $order->branch_id = $branch->id;
        $order->save();

        return $order;
    }

    protected function line(Order $order, MenuItem $item, float $qty, float $subtotal, string $status = 'served'): OrderItem
    {
        return OrderItem::withoutGlobalScopes()->create([
            'order_id' => $order->id,
            'menu_item_id' => $item->id,
            'name_snapshot' => $item->name,
            'quantity' => $qty,
            'unit_price' => $qty > 0 ? $subtotal / $qty : 0,
            'modifiers_total' => 0,
            'subtotal' => $subtotal,
            'status' => $status,
        ]);
    }

    /** The five-item fixture the class docblock tabulates. */
    protected function seedMatrix(): void
    {
        $a = $this->menuItem('A', 10, 2);
        $b = $this->menuItem('B', 10, 8);
        $c = $this->menuItem('C', 10, 5);
        $d = $this->menuItem('D', 20, 1);
        $e = $this->menuItem('E', 10, 9);

        $order = $this->order($this->branch);

        $this->line($order, $a, 10, 100);
        $this->line($order, $b, 10, 100);
        $this->line($order, $c, 5, 50);
        $this->line($order, $d, 2, 40);
        $this->line($order, $e, 1, 10);
    }

    protected function visit(array $query = [])
    {
        return $this->actingAs($this->owner)->get(route('admin.reports.menu-engineering', $query));
    }

    // ────────────────────────────────────────────────────────────────
    // The empty path — the ONLY path that ships thresholds === null
    // ────────────────────────────────────────────────────────────────

    public function test_empty_range_renders_the_same_component_with_a_degraded_payload(): void
    {
        $this->visit(['from' => '2020-01-01', 'to' => '2020-01-31'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/MenuEngineering')
                ->where('rows', [])
                ->where('thresholds', null)
                ->where('buckets', ['star' => 0, 'plowhorse' => 0, 'puzzle' => 0, 'dog' => 0])
                ->where('topByClass', ['star' => [], 'plowhorse' => [], 'puzzle' => [], 'dog' => []])
                ->where('from', '2020-01-01')
                ->where('to', '2020-01-31')
                // The degraded payload still has to carry everything the page
                // renders ABOVE the empty state, or the filter bar breaks.
                ->has('currency.symbol')
                ->has('shortcuts.d30.from')
                ->has('shortcuts.month.from')
                ->has('shortcuts.d7.from')
                ->has('urls.self')
                ->has('urls.reportsIndex')
            );
    }

    // ────────────────────────────────────────────────────────────────
    // The main path
    // ────────────────────────────────────────────────────────────────

    public function test_main_path_ships_the_five_rows_in_profit_descending_order(): void
    {
        $this->seedMatrix();

        $this->visit()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/MenuEngineering')
                ->has('rows', 5)
                // NOT id order (A B C D E) — this is the ->values() guard.
                ->where('rows.0.name', 'A')
                ->where('rows.1.name', 'D')
                ->where('rows.2.name', 'C')
                ->where('rows.3.name', 'B')
                ->where('rows.4.name', 'E')
            );
    }

    public function test_rows_are_a_json_array_not_an_object(): void
    {
        $this->seedMatrix();

        $props = $this->visit()->viewData('page')['props'];

        // A non-sequential integer-keyed PHP array encodes as a JSON OBJECT,
        // and JS then re-orders its keys numerically — silently destroying the
        // profit-DESC sort with no error anywhere.
        $this->assertSame([0, 1, 2, 3, 4], array_keys($props['rows']));
        $this->assertSame(
            '["A","C"]',
            json_encode($props['topByClass']['star'], JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_every_figure_on_the_top_row_matches_the_hand_computed_number(): void
    {
        $this->seedMatrix();

        $this->visit()->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.name', 'A')
            ->where('rows.0.qtySold', 10)
            ->where('rows.0.revenue', 100)
            ->where('rows.0.cogs', 20)
            ->where('rows.0.profit', 80)
            ->where('rows.0.marginPct', 80)
            ->where('rows.0.klass', 'star')
            ->where('rows.0.id', $this->ids['A'])
            // The lowest-profit row, which is also the only 'dog'.
            ->where('rows.4.name', 'E')
            ->where('rows.4.qtySold', 1)
            ->where('rows.4.revenue', 10)
            ->where('rows.4.cogs', 9)
            ->where('rows.4.profit', 1)
            ->where('rows.4.marginPct', 10)
            ->where('rows.4.klass', 'dog')
        );
    }

    public function test_medians_use_the_upper_middle_value_of_two_independent_sorts(): void
    {
        $this->seedMatrix();

        $this->visit()->assertInertia(fn (Assert $page) => $page
            // intdiv(5,2) = 2 → the THIRD ascending value on each axis.
            // Both happen to be item C here, but they are two separate sorts.
            ->where('thresholds.medianQty', 5)
            ->where('thresholds.medianMargin', 50)
        );
    }

    public function test_classification_is_inclusive_on_both_axes(): void
    {
        $this->seedMatrix();

        $this->visit()->assertInertia(fn (Assert $page) => $page
            // C sits EXACTLY on both medians. `>=` on both axes puts it in the
            // high/high corner — flipping either to `>` would move it to 'dog'.
            ->where('rows.2.name', 'C')
            ->where('rows.2.klass', 'star')
            ->where('buckets', ['star' => 2, 'plowhorse' => 1, 'puzzle' => 1, 'dog' => 1])
        );
    }

    public function test_top_by_class_carries_the_four_most_profitable_names_of_each_quadrant(): void
    {
        $this->seedMatrix();

        $this->visit()->assertInertia(fn (Assert $page) => $page
            ->where('topByClass', [
                'star' => ['A', 'C'],       // profit 80 then 25 — inherits the DESC sort
                'plowhorse' => ['B'],
                'puzzle' => ['D'],
                'dog' => ['E'],
            ])
        );
    }

    public function test_top_by_class_is_capped_at_four_names_while_the_bucket_counts_them_all(): void
    {
        // Six identical-margin, identical-qty items: with an even-ish spread the
        // median lands on one of them and `>=` sweeps every tie into 'star'.
        // What we care about is the cap: the chips show 4, the badge shows 6,
        // and the page derives "+ 2 أخرى" from the difference.
        $order = $this->order($this->branch);

        foreach (['S1' => 60, 'S2' => 50, 'S3' => 40, 'S4' => 30, 'S5' => 20, 'S6' => 10] as $name => $revenue) {
            $item = $this->menuItem($name, 10, 0);
            $this->line($order, $item, 10, $revenue);
        }

        $this->visit()->assertInertia(fn (Assert $page) => $page
            ->where('buckets.star', 6)
            // Profit DESC, capped at 4 — the page renders "+ 2 أخرى" from
            // buckets.star - 4, which is why no fifth name is shipped.
            ->where('topByClass.star', ['S1', 'S2', 'S3', 'S4'])
        );
    }

    public function test_a_single_item_is_always_a_star(): void
    {
        $order = $this->order($this->branch);
        $this->line($order, $this->menuItem('Solo', 10, 9), 1, 10);

        $this->visit()->assertInertia(fn (Assert $page) => $page
            // intdiv(1,2)=0 → the medians ARE its own values, and `>=` is true
            // on both. A 10% margin still reads as a star. Not a bug; a caveat.
            ->where('buckets', ['star' => 1, 'plowhorse' => 0, 'puzzle' => 0, 'dog' => 0])
            ->where('rows.0.klass', 'star')
        );
    }

    // ────────────────────────────────────────────────────────────────
    // What counts as a sale
    // ────────────────────────────────────────────────────────────────

    public function test_cancelled_lines_and_out_of_whitelist_orders_are_excluded(): void
    {
        $sold = $this->menuItem('Sold', 10, 4);
        $ghost = $this->menuItem('Ghost', 10, 4);

        $good = $this->order($this->branch, 'completed');
        $this->line($good, $sold, 3, 30);
        // Same order, cancelled line — the item must not appear at all.
        $this->line($good, $ghost, 5, 50, 'cancelled');

        // A pending order is not "a sale" — soldItemsQuery whitelists
        // approved/preparing/ready/delivered/completed only.
        $pending = $this->order($this->branch, 'pending');
        $this->line($pending, $ghost, 7, 70);

        $this->visit()->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.name', 'Sold')
            ->where('rows.0.qtySold', 3)
            ->where('rows.0.revenue', 30)
            ->where('rows.0.cogs', 12)
        );
    }

    public function test_the_date_filter_bounds_the_window_inclusively_at_both_ends(): void
    {
        $item = $this->menuItem('Dated', 10, 4);

        $inside = $this->order($this->branch);
        $inside->created_at = '2026-03-10 12:00:00';
        $inside->saveQuietly();
        $this->line($inside, $item, 2, 20);

        $outside = $this->order($this->branch);
        $outside->created_at = '2026-03-11 00:30:00';
        $outside->saveQuietly();
        $this->line($outside, $item, 99, 990);

        // dateRange() builds explicit day bounds in PHP before querying.
        $this->visit(['from' => '2026-03-10', 'to' => '2026-03-10'])
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.qtySold', 2)
                ->where('rows.0.revenue', 20)
                ->where('from', '2026-03-10')
                ->where('to', '2026-03-10')
            );
    }

    public function test_the_report_is_scoped_to_the_bound_branch(): void
    {
        $mine = $this->menuItem('Mine', 10, 4);
        $theirs = $this->menuItem('Theirs', 10, 4, $this->other);

        $here = $this->order($this->branch);
        $this->line($here, $mine, 2, 20);

        $there = $this->order($this->other);
        $this->line($there, $theirs, 9, 90);

        $this->visit()->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.name', 'Mine')
        );
    }

    // ────────────────────────────────────────────────────────────────
    // Payload shape + gate
    // ────────────────────────────────────────────────────────────────

    public function test_currency_ships_as_an_object_resolved_from_the_settings_table(): void
    {
        Setting::put('currency_symbol', 'JOD');
        $this->seedMatrix();

        $this->visit()->assertInertia(fn (Assert $page) => $page
            // An OBJECT, not a String — a page writing `${currency}` would
            // print "[object Object]" on every money cell.
            ->where('currency.symbol', 'JOD')
            ->where('currency.decimals', 2)
        );
    }

    public function test_shortcuts_ship_as_server_computed_date_pairs(): void
    {
        $this->seedMatrix();

        $this->visit()->assertInertia(fn (Assert $page) => $page
            ->where('shortcuts.d30', ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()])
            ->where('shortcuts.month', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])
            ->where('shortcuts.d7', ['from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()])
        );
    }

    public function test_no_boolean_gate_props_are_invented_for_a_screen_that_has_none(): void
    {
        $this->seedMatrix();

        $props = $this->visit()->viewData('page')['props'];

        // The old Blade had zero @can / role / permission checks in 308 lines.
        // Every user who passes the route middleware sees 100% of the page,
        // so there is no canX boolean to ship — and none was invented.
        // Assert the NEGATIVE directly instead of pinning the full prop list:
        // enumerating globally shared props here makes an unrelated change to
        // HandleInertiaRequests fail this test and point at the wrong file.
        $invented = array_filter(
            array_keys($props),
            fn ($key) => str_starts_with($key, 'can') || $key === 'permissions',
        );

        $this->assertSame([], array_values($invented));

        // The page's own payload is still pinned, so a dropped prop is caught.
        foreach (['buckets', 'currency', 'from', 'rows', 'shortcuts', 'thresholds', 'to', 'topByClass', 'urls'] as $key) {
            $this->assertArrayHasKey($key, $props);
        }
    }

    public function test_the_route_is_gated_by_reports_view_any(): void
    {
        $waiter = $this->staff('waiter', 'me_waiter', $this->branch);

        $this->actingAs($waiter)
            ->get(route('admin.reports.menu-engineering'))
            ->assertForbidden();
    }
}
