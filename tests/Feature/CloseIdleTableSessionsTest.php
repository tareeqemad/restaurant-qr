<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-side session lifecycle: the `table-sessions:close-idle` sweep
 * and the zero-exposure guard on the manual close-session endpoint.
 *
 * Pre-fix reality this locks in place:
 *   - session_ttl_minutes was ONLY a cookie TTL — the server never
 *     expired anything, so zombie sessions lived for days and new
 *     guests scanning the table JOINED the old party's session.
 *   - The manual close button rejected sessions whose orders were ALL
 *     cancelled (guard counted orders, not exposure), so the board
 *     rendered a button that always failed.
 *
 * Contract under test (zero-exposure): auto/manual close allowed only
 * when (a) no orders, (b) nothing billable (all cancelled/zero-total),
 * or (c) invoice fully paid. Unpaid money NEVER auto-closes.
 */
class CloseIdleTableSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected Table $table;
    protected MenuItem $burger;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 't', 'name' => 'T', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);

        $this->admin = User::create([
            'name' => 'A', 'username' => 'admin_t', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true]);

        Unit::firstOrCreate(['code' => 'g'], ['name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $kitchen = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $storage->id, 'active' => true]);
        $cat = Category::create(['slug' => 'm', 'name' => 'M', 'default_station_id' => $kitchen->id, 'active' => true]);
        $this->burger = MenuItem::create([
            'category_id' => $cat->id, 'station_id' => $kitchen->id,
            'sku' => 'B', 'slug' => 'b', 'name' => 'Burger',
            'price' => 30, 'is_available' => true,
        ]);

        $this->table = Table::create(['number' => '5', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────
    // Sweep: closes zero-exposure idle sessions
    // ────────────────────────────────────────────────────────────────

    public function test_closes_empty_idle_session_and_frees_table(): void
    {
        $session = $this->openSession(idleMinutes: 300);

        $this->artisan('table-sessions:close-idle')->assertExitCode(0);

        $this->assertSame('closed', $session->fresh()->status,
            'An orderless session idle past the threshold must be auto-closed.');
        $this->assertNotNull($session->fresh()->closed_at);
        $this->assertSame('available', $this->table->fresh()->status,
            'Closing the zombie session must free the table for the next guest.');
    }

    public function test_closes_idle_session_whose_orders_are_all_cancelled(): void
    {
        $session = $this->openSession();
        $order = $this->placeOrder($session, qty: 2);
        $order->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);
        $this->makeIdle($session, 300);

        $this->artisan('table-sessions:close-idle')->assertExitCode(0);

        $this->assertSame('closed', $session->fresh()->status,
            'All-cancelled sessions carry zero exposure — the sweep must close them.');
        $this->assertSame('available', $this->table->fresh()->status);
    }

    public function test_closes_idle_session_with_fully_paid_invoice(): void
    {
        $session = $this->openSession();
        $order = $this->placeOrder($session, qty: 1);
        $this->issueInvoice($session, $order, paid: true);
        $this->makeIdle($session, 300);

        $this->artisan('table-sessions:close-idle')->assertExitCode(0);

        $session->refresh();
        $this->assertSame('closed', $session->status,
            'A paid invoice means no money is at risk — the sweep finishes the close the settle path missed.');
        $this->assertSame('available', $this->table->fresh()->status);
        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->status,
            'Stray non-cancelled orders get completed, same as the settle path.');
    }

    // ────────────────────────────────────────────────────────────────
    // Sweep: never touches money on the table
    // ────────────────────────────────────────────────────────────────

    public function test_skips_idle_session_with_unpaid_delivered_order(): void
    {
        $session = $this->openSession();
        $order = $this->placeOrder($session, qty: 2);
        $order->update(['status' => OrderStatus::Delivered->value, 'delivered_at' => now()]);
        $this->makeIdle($session, 300);

        $this->artisan('table-sessions:close-idle')->assertExitCode(0);

        $this->assertSame('active', $session->fresh()->status,
            'Unpaid delivered orders are money on the table — auto-close must NEVER touch them.');
        $this->assertSame('occupied', $this->table->fresh()->status);
    }

    public function test_skips_idle_session_with_unpaid_invoice(): void
    {
        $session = $this->openSession();
        $order = $this->placeOrder($session, qty: 1);
        $this->issueInvoice($session, $order, paid: false);
        $this->makeIdle($session, 300);

        $this->artisan('table-sessions:close-idle')->assertExitCode(0);

        $this->assertSame('active', $session->fresh()->status,
            'An issued-but-unpaid invoice still carries a balance — the sweep must skip it.');
    }

    public function test_respects_idle_threshold(): void
    {
        $session = $this->openSession(idleMinutes: 30);   // active party, below the 240-min default

        $this->artisan('table-sessions:close-idle')->assertExitCode(0);

        $this->assertSame('active', $session->fresh()->status,
            'A session inside the idle window must not be closed.');
        $this->assertSame('occupied', $this->table->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $session = $this->openSession(idleMinutes: 300);

        $this->artisan('table-sessions:close-idle', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('active', $session->fresh()->status,
            '--dry-run must only report, never write.');
    }

    // ────────────────────────────────────────────────────────────────
    // Manual close endpoint: zero-exposure guard (was: any-orders guard)
    // ────────────────────────────────────────────────────────────────

    public function test_close_session_endpoint_accepts_all_cancelled_session(): void
    {
        $session = $this->openSession();
        $order = $this->placeOrder($session, qty: 1);
        $order->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

        // Pre-fix: the guard counted orders (cancelled included) and the
        // board's button always failed for exactly this session shape.
        $this->actingAs($this->admin)
            ->post(route('admin.tables.close-session', $this->table))
            ->assertSessionHas('success');

        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('available', $this->table->fresh()->status);
    }

    public function test_close_session_endpoint_still_refuses_unpaid_orders(): void
    {
        $session = $this->openSession();
        $this->placeOrder($session, qty: 1);   // pending, unpaid — real exposure

        $this->actingAs($this->admin)
            ->post(route('admin.tables.close-session', $this->table))
            ->assertSessionHas('error');

        $this->assertSame('active', $session->fresh()->status,
            'Unpaid orders must still be routed through the cashier flow.');
    }

    public function test_abandoned_qr_draft_uses_the_short_browsing_timeout(): void
    {
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => 'QR',
            'capacity' => 2,
            'status' => 'available',
            'active' => true,
        ]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id,
            'table_id' => $table->id,
            'status' => 'active',
            'opened_at' => now()->subMinutes(21),
            'last_activity_at' => now()->subMinutes(21),
        ]);

        $this->artisan('table-sessions:close-idle')->assertExitCode(0);

        $this->assertSame('closed', $session->fresh()->status);
        $this->assertNull($session->fresh()->engaged_at);
        $this->assertSame('available', $table->fresh()->status);
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function openSession(int $idleMinutes = 0): TableSession
    {
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'tn-'.uniqid(), 'status' => 'active',
            'opened_at' => now()->subMinutes(max($idleMinutes, 1)), 'cover_count' => 2,
        ]);

        if ($idleMinutes > 0) {
            $this->makeIdle($session, $idleMinutes);
        }

        return $session;
    }

    /**
     * Backdate activity AFTER seeding orders/invoices — order creation
     * touches last_activity_at, which would silently un-idle the session.
     */
    protected function makeIdle(TableSession $session, int $minutes): void
    {
        $session->forceFill([
            'last_activity_at' => now()->subMinutes($minutes),
        ])->save();
    }

    protected function placeOrder(TableSession $session, int $qty): Order
    {
        return app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id,
            'quantity' => $qty, 'modifier_ids' => [],
        ]], createdByUserId: $this->admin->id);
    }

    protected function issueInvoice(TableSession $session, Order $order, bool $paid): Invoice
    {
        $total = (float) $order->total;

        return Invoice::create([
            'branch_id'         => $this->branch->id,
            'table_session_id'  => $session->id,
            'issued_by_user_id' => $this->admin->id,
            'subtotal'          => $total,
            'discount_total'    => 0, 'tax_total' => 0,
            'service_total'     => 0, 'delivery_fee' => 0, 'tip' => 0,
            'total'             => $total,
            'paid_total'        => $paid ? $total : 0,
            'balance'           => $paid ? 0 : $total,
            'status'            => $paid ? 'paid' : 'issued',
            'issued_at'         => now(),
            'paid_at'           => $paid ? now() : null,
        ]);
    }
}
