<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 6 (MIGRATION-PILOT.md §13): the live operations monitor and the
 * partner overview on Inertia/Vue.
 *
 * Both screens read deliberately ACROSS branches, which makes scoping the
 * thing worth pinning: a branch manager must see their own branches and
 * nothing else, even though the global BranchScope is dropped for the
 * queries. The old components computed all of this in Blade; it now lives
 * in the controllers, so these tests assert the payload, not markup.
 */
class PartnerScreensVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $north;

    protected Branch $south;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->north = Branch::create(['code' => 'north', 'name' => 'الفرع الشمالي', 'is_active' => true, 'display_order' => 1]);
        $this->south = Branch::create(['code' => 'south', 'name' => 'الفرع الجنوبي', 'is_active' => true, 'display_order' => 2]);

        BranchContext::set($this->north->id);

        $this->seed(PermissionSeeder::class);

        $this->owner = $this->staff('super_admin', 'w6_owner', $this->north);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

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

    protected function table(Branch $branch, string $number, string $status = 'available'): Table
    {
        return Table::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'number' => $number,
            'capacity' => 4,
            'status' => $status,
            'active' => true,
        ]);
    }

    protected function order(Branch $branch, string $status, ?Table $table = null, float $total = 100): Order
    {
        return Order::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'table_id' => $table?->id,
            'number' => 'O-'.$branch->id.'-'.uniqid(),
            'status' => $status,
            'type' => 'dine_in',
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    /**
     * An invoice must belong to exactly one origin (table session XOR direct
     * order), so every fixture invoice gets a closed order behind it.
     */
    protected function paidInvoice(Branch $branch, float $amount): Invoice
    {
        $order = $this->order($branch, 'completed', null, $amount);

        return Invoice::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'number' => 'INV-'.$branch->id.'-'.uniqid(),
            'status' => 'paid',
            'issued_at' => now(),
            'subtotal' => $amount,
            'total' => $amount,
            'paid_total' => $amount,
            'balance' => 0,
        ]);
    }

    // ── Live monitor ──────────────────────────────────────────────────

    public function test_the_monitor_renders_one_column_per_accessible_branch(): void
    {
        $t1 = $this->table($this->north, 'N-1', 'occupied');
        $this->table($this->north, 'N-2');
        $this->order($this->north, 'preparing', $t1, 80);
        $this->paidInvoice($this->north, 120);

        $this->actingAs($this->owner)
            ->get(route('admin.partner.live-monitor'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Monitor/Live')
                ->has('branches', 2)
                ->where('branches.0.name', 'الفرع الشمالي')
                ->where('branches.0.sales', 120)
                ->where('branches.0.invoices', 1)
                ->where('branches.0.avgTicket', 120)
                ->where('branches.0.totalTables', 2)
                ->where('branches.0.occupied', 1)
                ->where('branches.0.activeOrders', 1)
                ->where('branches.0.todayOrders', 2)
                ->where('branches.0.statusCounts.preparing', 1)
                ->where('branches.0.statusCounts.pending', 0)
                ->where('branches.0.occupancy', 50)
                ->where('branches.0.health.tone', 'calm')
                ->has('branches.0.tables', 2)
                ->has('branches.0.recent', 2)
                ->has('generatedAt')
                ->has('live.version')
                ->has('urls.pulse')
                ->has('urls.overview'))
            ->assertInertia(function (Assert $page) {
                $recent = collect($page->toArray()['props']['branches'][0]['recent']);
                $cooking = $recent->firstWhere('status', 'preparing');

                $this->assertNotNull($cooking, 'the cooking ticket must appear in the tail');
                $this->assertSame('يحضر', $cooking['statusLabel']);
                $this->assertSame('#f59e0b', $cooking['statusColor']);
                $this->assertSame('N-1', $cooking['tableNumber']);
                $this->assertEquals(80, $cooking['total']);
            });
    }

    public function test_the_monitor_shows_a_manager_only_their_own_branches(): void
    {
        $manager = $this->staff('manager', 'w6_manager', $this->south);

        $this->actingAs($manager)
            ->get(route('admin.partner.live-monitor'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('branches', 1)
                ->where('branches.0.name', 'الفرع الجنوبي'));
    }

    public function test_the_monitor_is_management_only(): void
    {
        $waiter = $this->staff('waiter', 'w6_waiter', $this->north);

        $this->actingAs($waiter)
            ->get(route('admin.partner.live-monitor'))
            ->assertForbidden();
    }

    /**
     * The tail is capped — the owner watching a TV wants the last handful,
     * not today's whole order log rendered into the DOM.
     */
    public function test_the_recent_tail_is_capped(): void
    {
        $table = $this->table($this->north, 'N-1');

        foreach (range(1, 9) as $i) {
            $this->order($this->north, 'completed', $table, 10 * $i);
        }

        $this->actingAs($this->owner)
            ->get(route('admin.partner.live-monitor'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('branches.0.recent', 6)
                ->where('branches.0.todayOrders', 9));
    }

    /**
     * In-flight orders are live regardless of the day they opened — a ticket
     * still cooking at 00:05 belongs on the board. Only the "today" counters
     * carry a date filter.
     */
    public function test_in_flight_orders_from_yesterday_still_count_as_active(): void
    {
        $table = $this->table($this->north, 'N-1');

        $stale = $this->order($this->north, 'preparing', $table, 60);
        $stale->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $this->actingAs($this->owner)
            ->get(route('admin.partner.live-monitor'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branches.0.activeOrders', 1)
                ->where('branches.0.todayOrders', 0));
    }

    public function test_the_monitor_surfaces_branch_pressure_and_delayed_work(): void
    {
        $table = $this->table($this->north, 'N-1', 'occupied');
        $late = $this->order($this->north, 'preparing', $table, 60);
        $late->forceFill(['estimated_ready_at' => now()->subMinutes(8)])->saveQuietly();

        $this->actingAs($this->owner)
            ->get(route('admin.partner.live-monitor'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branches.0.delayedOrders', 1)
                ->where('branches.0.health.tone', 'danger')
                ->where('branches.0.health.label', 'ضغط مرتفع')
                ->where('branches.0.recent.0.delayed', true)
                ->where('branches.0.recent.0.active', true)
                ->where('branches.0.recent.0.ageMinutes', fn ($minutes) => $minutes >= 0)
                ->where('branches.0.pressure', fn ($score) => $score >= 25)
            );
    }

    // ── Partner overview ──────────────────────────────────────────────

    public function test_the_overview_aggregates_every_branch_into_totals(): void
    {
        $n1 = $this->table($this->north, 'N-1', 'occupied');
        $this->table($this->north, 'N-2');
        $this->table($this->south, 'S-1', 'occupied');

        $this->order($this->north, 'pending', $n1, 50);
        $this->paidInvoice($this->north, 200);
        $this->paidInvoice($this->south, 300);

        $this->actingAs($this->owner)
            ->get(route('admin.partner.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Partner/Overview')
                ->has('summaries', 2)
                ->where('totals.branches', 2)
                ->where('totals.sales', 500)
                ->where('totals.invoices', 2)
                ->where('totals.totalTables', 3)
                ->where('totals.occupied', 2)
                ->where('totals.occupancy', 67)
                ->has('generatedAt')
                ->has('trend')
                ->has('actions')
                ->has('live.version'));
    }

    /**
     * `attention` is the whole point of the screen: one number per branch
     * summing everything a human has to chase today. A pending order counts.
     */
    public function test_a_branch_with_work_waiting_reports_attention(): void
    {
        $table = $this->table($this->north, 'N-1');
        $this->order($this->north, 'pending', $table, 40);

        $this->actingAs($this->owner)
            ->get(route('admin.partner.overview'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $summaries = collect($page->toArray()['props']['summaries']);

                $north = $summaries->firstWhere('id', $this->north->id);
                $south = $summaries->firstWhere('id', $this->south->id);

                $this->assertSame(1, $north['pendingOrders']);
                $this->assertGreaterThanOrEqual(1, $north['attention']);
                $this->assertTrue($north['needsAttention']);
                $this->assertSame('warning', $north['health']['tone']);
                $this->assertGreaterThan(0, $north['pressure']);

                $this->assertSame(0, $south['attention']);
                $this->assertFalse($south['needsAttention']);
                $this->assertSame('calm', $south['health']['tone']);
            });
    }

    public function test_the_overview_shows_a_manager_only_their_own_branches(): void
    {
        $manager = $this->staff('manager', 'w6_ov_manager', $this->south);

        $this->paidInvoice($this->north, 999);
        $this->paidInvoice($this->south, 111);

        $this->actingAs($manager)
            ->get(route('admin.partner.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('summaries', 1)
                ->where('summaries.0.id', $this->south->id)
                ->where('totals.sales', 111));
    }

    public function test_the_overview_is_management_only(): void
    {
        $waiter = $this->staff('waiter', 'w6_ov_waiter', $this->north);

        $this->actingAs($waiter)
            ->get(route('admin.partner.overview'))
            ->assertForbidden();

        $this->actingAs($waiter)
            ->getJson(route('admin.partner.overview.pulse'))
            ->assertForbidden();
    }

    /** The query binds dates and values instead of interpolating SQL literals. */
    public function test_the_overview_aggregates_use_bound_values(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.partner.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.overdueAp', 0)
                ->where('totals.lowStock', 0)
                ->where('totals.pendingReservations', 0));
    }
}
