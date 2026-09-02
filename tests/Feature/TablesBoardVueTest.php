<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\OrderItemStatusChanged;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Lookup;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Role;
use App\Models\SectionAssignment;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Support\BranchContext;
use App\Support\LiveRefreshPulse;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 1 (MIGRATION-PILOT.md §13): the tables board on Inertia/Vue.
 *
 * Pins the load-bearing behavior at HTTP level: triage precedence,
 * mine/roster scoping
 * with carry-forward, per-role actions, the serve polling-pulse contract,
 * bulk stale close, and the quick-edit pipeline. Fixture style is copied
 * from that suite so behavior stays comparable board-to-board.
 */
class TablesBoardVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $waiter;

    protected User $otherWaiter;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'tv', 'name' => 'TV', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        Role::create(['name' => 'cashier', 'label' => 'Cashier', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);

        $this->waiter = $this->staff('w1', 'waiter');
        $this->otherWaiter = $this->staff('w2', 'waiter');
        $this->admin = $this->staff('a1', 'admin');
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ── Fixtures (TablesBoardTest style) ─────────────────────────────────

    protected function staff(string $username, string $role): User
    {
        $u = User::create([
            'name' => $username, 'username' => $username, 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => $role,
        ]);
        $u->branches()->attach($this->branch->id);

        return $u;
    }

    protected function table(string $number, ?int $zoneId = null): Table
    {
        return Table::create([
            'branch_id' => $this->branch->id, 'number' => $number,
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
            'zone_lookup_id' => $zoneId,
        ]);
    }

    protected function openSession(Table $t, ?int $waiterId, ?Carbon $openedAt = null): TableSession
    {
        return TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $t->id,
            'token' => 'tv-'.uniqid(), 'status' => 'active',
            'opened_at' => $openedAt ?? now(),
            'last_activity_at' => $openedAt ?? now(),
            'assigned_waiter_id' => $waiterId,
            'cover_count' => 1,
        ]);
    }

    protected function zone(string $label): Lookup
    {
        return Lookup::create([
            'branch_id' => null,   // zones are a GLOBAL dictionary
            'group' => 'zones', 'code' => Str::slug($label),
            'label' => $label, 'color' => '#166534', 'is_active' => true, 'display_order' => 1,
        ]);
    }

    protected ?MenuItem $menu = null;

    protected function menuItem(): MenuItem
    {
        if ($this->menu) {
            return $this->menu;
        }

        $storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
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

        return $this->menu = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $station->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'برجر', 'price' => 10,
            'is_available' => true, 'display_order' => 1,
        ]);
    }

    /** @return array{0: Table, 1: TableSession, 2: Order} */
    protected function readyOrder(): array
    {
        $table = $this->table('40');
        $session = $this->openSession($table, $this->waiter->id);

        $order = app(OrderService::class)->createFromCart($session, [
            ['menu_item_id' => $this->menuItem()->id, 'quantity' => 1, 'modifier_ids' => []],
        ], $this->waiter->id);

        $order->items()->update([
            'status' => OrderItemStatus::Ready->value,
            'ready_at' => now()->subMinutes(6),
        ]);
        $order->update([
            'status' => OrderStatus::Ready->value,
            'ready_at' => now()->subMinutes(6),
        ]);

        return [$table, $session, $order->fresh()];
    }

    protected function roster(Lookup $zone, User $waiter, $date = null): SectionAssignment
    {
        return SectionAssignment::create([
            'branch_id' => $this->branch->id,
            'zone_lookup_id' => $zone->id,
            'user_id' => $waiter->id,
            'service_date' => $date ?? now()->toDateString(),
        ]);
    }

    /** The board payload rows for a user/view, keyed by table number. */
    protected function rowsFor(User $as, array $query = []): array
    {
        $response = $this->actingAs($as)->get(route('admin.tables.index', $query));
        $response->assertOk();

        $page = $response->viewData('page');
        $rows = data_get($page, 'props.board.rows', []);

        return collect($rows)->keyBy('number')->all();
    }

    // ── The page ─────────────────────────────────────────────────────────

    public function test_the_board_serves_the_inertia_page(): void
    {
        $this->table('1');

        $this->actingAs($this->waiter)
            ->get(route('admin.tables.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tables/Board')
                ->has('board.rows', 1)
                ->has('tabs')
                ->has('live.version')
                ->has('shell.nav'));
    }

    public function test_table_numbers_use_natural_floor_order(): void
    {
        $this->table('10');
        $this->table('2');
        $this->table('1');

        $this->actingAs($this->admin)
            ->get(route('admin.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('board.rows.0.number', '1')
                ->where('board.rows.1.number', '2')
                ->where('board.rows.2.number', '10'));
    }

    public function test_an_unrostered_waiter_opens_on_the_whole_floor_and_is_told_why(): void
    {
        $this->table('1');

        $this->actingAs($this->waiter)
            ->get(route('admin.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.view', 'all')
                ->where('tabs.needsRosterNudge', true)
                ->where('tabs.showsMineTab', false));
    }

    public function test_each_table_reports_current_session_and_non_cancelled_orders_received_today(): void
    {
        $table = $this->table('7');
        $previous = $this->openSession($table, $this->waiter->id, now()->subHours(5));

        $previousOrder = app(OrderService::class)->createFromCart($previous, [
            ['menu_item_id' => $this->menuItem()->id, 'quantity' => 1, 'modifier_ids' => []],
        ], $this->waiter->id);
        $cancelledOrder = app(OrderService::class)->createFromCart($previous, [
            ['menu_item_id' => $this->menuItem()->id, 'quantity' => 1, 'modifier_ids' => []],
        ], $this->waiter->id);
        $cancelledOrder->update([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
        $oldOrder = app(OrderService::class)->createFromCart($previous, [
            ['menu_item_id' => $this->menuItem()->id, 'quantity' => 1, 'modifier_ids' => []],
        ], $this->waiter->id);
        $oldOrder->update(['submitted_at' => now()->subDay()]);
        $previous->update(['status' => 'closed', 'closed_at' => now()->subHours(2)]);

        $current = $this->openSession($table, $this->waiter->id, now()->subHour());
        foreach (range(1, 2) as $_) {
            app(OrderService::class)->createFromCart($current, [
                ['menu_item_id' => $this->menuItem()->id, 'quantity' => 1, 'modifier_ids' => []],
            ], $this->waiter->id);
        }

        $row = $this->rowsFor($this->waiter)['7'];

        $this->assertSame(2, $row['counts']['session']);
        $this->assertSame(3, $row['counts']['today']);
        $this->assertSame(2, $row['counts']['active']);
        $this->assertNotNull($previousOrder->submitted_at);
    }

    public function test_a_rostered_waiter_opens_on_their_section(): void
    {
        $inside = $this->zone('داخلي');
        $terrace = $this->zone('تراس');
        $this->table('1', $inside->id);
        $this->table('2', $terrace->id);
        $this->roster($inside, $this->waiter);

        $response = $this->actingAs($this->waiter)->get(route('admin.tables.index'));
        $response->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'mine')
            ->where('tabs.showsMineTab', true)
            ->where('tabs.rosterCarried', false)
            ->has('board.rows', 1));

        $rows = $this->rowsFor($this->waiter);
        $this->assertArrayHasKey('1', $rows);
        $this->assertArrayNotHasKey('2', $rows);
    }

    public function test_claim_fallback_keeps_unclaimed_sessions_and_drops_other_waiters_tables(): void
    {
        $mine = $this->table('10');
        $unclaimed = $this->table('11');
        $others = $this->table('12');
        $free = $this->table('13');
        $free->update(['status' => 'available']);

        $this->openSession($mine, $this->waiter->id);
        $this->openSession($unclaimed, null);          // QR session, nobody approved yet
        $this->openSession($others, $this->otherWaiter->id);

        $rows = $this->rowsFor($this->waiter, ['view' => 'mine']);

        $this->assertArrayHasKey('10', $rows);
        $this->assertArrayHasKey('11', $rows, 'hiding unclaimed sessions deadlocks approval');
        $this->assertArrayHasKey('13', $rows, 'free tables stay seatable');
        $this->assertArrayNotHasKey('12', $rows);
    }

    public function test_an_empty_today_carries_yesterdays_roster_forward(): void
    {
        $inside = $this->zone('داخلي');
        $this->table('1', $inside->id);
        $this->table('2');
        $this->roster($inside, $this->waiter, now()->subDay()->toDateString());

        $this->actingAs($this->waiter)
            ->get(route('admin.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.view', 'mine')
                ->where('tabs.rosterCarried', true)
                ->has('board.rows', 1));
    }

    // ── Triage ───────────────────────────────────────────────────────────

    public function test_ready_food_lands_in_the_red_strip_with_a_serve_action(): void
    {
        [$table] = $this->readyOrder();

        $response = $this->actingAs($this->waiter)->get(route('admin.tables.index'));
        $response->assertInertia(fn (Assert $page) => $page
            ->where('board.priorityIds.0', $table->id)
            ->where('board.hotIds.0', $table->id)
            ->where('board.redIds.0', $table->id));

        $row = $this->rowsFor($this->waiter)['40'];
        $this->assertSame('food_ready', $row['triage']['type']);
        $this->assertSame('red', $row['triage']['urgency']);
        $this->assertSame('serve', $row['triage']['action']['kind']);
        $this->assertSame('urgent', $row['tileState']);
    }

    public function test_a_help_request_outranks_even_cold_food(): void
    {
        [, $session] = $this->readyOrder();
        $session->update(['help_requested_at' => now(), 'help_request_note' => 'ماء']);

        $row = $this->rowsFor($this->waiter)['40'];
        $this->assertSame('help', $row['triage']['type']);
        $this->assertStringContainsString('ماء', $row['triage']['label']);
        $this->assertSame('ack', $row['triage']['action']['kind']);
    }

    public function test_a_cashier_gets_a_list_link_instead_of_a_serve_button(): void
    {
        $cashier = $this->staff('c1', 'cashier');
        $this->readyOrder();

        $row = $this->rowsFor($cashier)['40'];
        $this->assertSame('link', $row['triage']['action']['kind'],
            'a cashier must never be handed a button that 403s mid-rush');
    }

    public function test_a_pending_round_has_an_audible_direct_review_action_with_item_preview(): void
    {
        $table = $this->table('20');
        $session = $this->openSession($table, $this->waiter->id);
        $order = app(OrderService::class)->createFromCart($session, [
            ['menu_item_id' => $this->menuItem()->id, 'quantity' => 1, 'modifier_ids' => []],
        ], $this->waiter->id);

        $row = $this->rowsFor($this->waiter)['20'];
        $this->assertSame('approval', $row['triage']['type']);
        $this->assertSame('amber', $row['triage']['urgency']);
        $this->assertSame('راجع الجولة', $row['triage']['action']['label']);
        $this->assertStringContainsString('/admin/waiter-orders/table/'.$table->id, $row['triage']['action']['url']);
        $this->assertStringContainsString('review_order='.$order->id, $row['triage']['action']['url']);
        $this->assertSame($order->id, $row['pendingReview']['orderId']);
        $this->assertSame(1, $row['pendingReview']['roundNumber']);
        $this->assertSame(1, $row['pendingReview']['lineCount']);
        $this->assertSame('1', $row['pendingReview']['pieceCount']);
        $this->assertSame('برجر', $row['pendingReview']['items'][0]['name']);
        $this->assertSame($row['triage']['action']['url'], $row['urls']['review']);

        $this->actingAs($this->waiter)
            ->get(route('admin.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('board.soundIds.0', $table->id));
    }

    public function test_an_idle_session_offers_close_to_management_and_inspect_to_waiters(): void
    {
        $table = $this->table('30');
        $this->openSession($table, $this->waiter->id, now()->subMinutes(200));

        $adminRow = $this->rowsFor($this->admin)['30'];
        $this->assertSame('idle', $adminRow['triage']['type']);
        $this->assertSame('close', $adminRow['triage']['action']['kind']);

        $waiterRow = $this->rowsFor($this->waiter)['30'];
        $this->assertSame('idle', $waiterRow['triage']['type']);
        $this->assertSame('link', $waiterRow['triage']['action']['kind'],
            "close needs TablePolicy::update — a waiter's only card action must not 403");
    }

    // ── Actions ──────────────────────────────────────────────────────────

    public function test_serving_marks_ready_food_served_and_touches_the_polling_pulse(): void
    {
        $table = $this->table('40');
        $session = $this->openSession($table, $this->waiter->id);

        $m1 = $this->menuItem();
        $m2 = MenuItem::create([
            'category_id' => $m1->category_id, 'station_id' => $m1->station_id,
            'sku' => 'B-2', 'slug' => 'shawarma', 'name' => 'شاورما', 'price' => 8,
            'is_available' => true, 'display_order' => 2,
        ]);

        $order = app(OrderService::class)->createFromCart($session, [
            ['menu_item_id' => $m1->id, 'quantity' => 1, 'modifier_ids' => []],
            ['menu_item_id' => $m2->id, 'quantity' => 1, 'modifier_ids' => []],
        ], $this->waiter->id);

        $order->items()->update(['status' => OrderItemStatus::Ready->value, 'ready_at' => now()]);
        $order->update(['status' => OrderStatus::Ready->value, 'ready_at' => now()]);

        $beforePulse = LiveRefreshPulse::version($this->branch->id);
        Event::fake([OrderItemStatusChanged::class, OrderStatusChanged::class]);

        $this->actingAs($this->waiter)
            ->postJson(route('admin.tables.serve', $session))
            ->assertOk()
            ->assertJson(['ok' => true]);

        Event::assertNotDispatched(OrderItemStatusChanged::class);
        Event::assertNotDispatched(OrderStatusChanged::class);
        $this->assertNotSame($beforePulse, LiveRefreshPulse::version($this->branch->id));

        $this->assertSame(
            OrderItemStatus::Served->value,
            $order->items()->first()->fresh()->status,
            'touching the polling pulse must not mute the state change',
        );
    }

    public function test_serving_leaves_items_that_are_still_cooking_alone(): void
    {
        [, $session, $order] = $this->readyOrder();
        $cooking = $order->items()->first()->replicate();
        $cooking->uuid = (string) Str::ulid();
        $cooking->status = OrderItemStatus::Preparing->value;
        $cooking->ready_at = null;
        $cooking->save();

        $this->actingAs($this->waiter)
            ->postJson(route('admin.tables.serve', $session))
            ->assertOk();

        $this->assertSame(OrderItemStatus::Preparing->value, $cooking->fresh()->status,
            'a half-ready ticket must not be signed off as delivered');
    }

    public function test_bar_handoff_surfaces_and_serves_while_the_same_order_is_still_cooking_in_kitchen(): void
    {
        $table = $this->table('41');
        $session = $this->openSession($table, $this->waiter->id);
        $meal = $this->menuItem();

        $barStorage = StorageLocation::create([
            'branch_id' => $this->branch->id,
            'code' => 'bar-41',
            'name' => 'مخزن البار',
            'active' => true,
        ]);
        $bar = Station::create([
            'code' => 'bar-41',
            'name' => 'البار',
            'storage_location_id' => $barStorage->id,
            'active' => true,
        ]);
        $drinks = Category::create([
            'slug' => 'drinks-41',
            'name' => 'مشروبات',
            'default_station_id' => $bar->id,
            'active' => true,
        ]);
        $drink = MenuItem::create([
            'category_id' => $drinks->id,
            'station_id' => $bar->id,
            'sku' => 'DRINK-41',
            'slug' => 'drink-41',
            'name' => 'ليمون ونعنع',
            'price' => 6,
            'is_available' => true,
        ]);

        $service = app(OrderService::class);
        $order = $service->createFromCart($session, [
            ['menu_item_id' => $meal->id, 'quantity' => 1, 'modifier_ids' => []],
            ['menu_item_id' => $drink->id, 'quantity' => 2, 'modifier_ids' => []],
        ], $this->waiter->id);
        $service->approve($order, $this->waiter->id);

        $mealLine = $order->items()->where('menu_item_id', $meal->id)->firstOrFail();
        $drinkLine = $order->items()->where('menu_item_id', $drink->id)->firstOrFail();
        $service->startPreparing($mealLine, $this->waiter->id, broadcast: false);
        $service->startPreparing($drinkLine, $this->waiter->id, broadcast: false);
        $service->markItemReady($drinkLine, broadcast: false);

        $billing = app(BillingService::class);
        $invoice = $billing->issueInvoice($session->fresh(), $this->admin->id);
        $billing->addPayment($invoice, (float) $invoice->balance, 'cash', $this->admin->id);

        $this->assertSame(OrderStatus::Preparing->value, $order->fresh()->status,
            'the mixed ticket correctly remains preparing while kitchen is working');
        $this->assertSame('active', $session->fresh()->status,
            'paying early must not release a table whose food is still in production');

        $row = $this->rowsFor($this->waiter)['41'];
        $this->assertSame('food_ready', $row['triage']['type']);
        $this->assertSame(1, $row['counts']['ready']);
        $this->assertSame('2', $row['readyHandoff']['pieceCount']);
        $this->assertSame(['البار'], $row['readyHandoff']['stationNames']);
        $this->assertSame('ليمون ونعنع', $row['readyHandoff']['items'][0]['name']);

        $this->actingAs($this->waiter)
            ->postJson(route('admin.tables.serve', $session))
            ->assertOk()
            ->assertJsonPath('served_count', '2');

        $this->assertSame(OrderItemStatus::Served->value, $drinkLine->fresh()->status);
        $this->assertSame(OrderItemStatus::Preparing->value, $mealLine->fresh()->status);
        $this->assertSame(OrderStatus::Preparing->value, $order->fresh()->status);
        $this->assertSame('active', $session->fresh()->status);
        $this->assertSame('occupied', $table->fresh()->status);

        $service->markItemReady($mealLine, broadcast: false);
        $this->actingAs($this->waiter)
            ->postJson(route('admin.tables.serve', $session))
            ->assertOk()
            ->assertJsonPath('served_count', '1');

        $this->assertSame(OrderItemStatus::Served->value, $mealLine->fresh()->status);
        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->status);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('available', $table->fresh()->status);
    }

    public function test_ack_help_clears_the_hand_and_records_who_went(): void
    {
        $table = $this->table('50');
        $session = $this->openSession($table, null);
        $session->update(['help_requested_at' => now(), 'help_request_note' => 'ملح']);

        $this->actingAs($this->waiter)
            ->postJson(route('admin.tables.ack-help', $session))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $fresh = $session->fresh();
        $this->assertNull($fresh->help_requested_at);
        $this->assertNull($fresh->help_request_note);
        $this->assertSame($this->waiter->id, $fresh->help_ack_by_user_id);
    }

    public function test_bulk_stale_close_sweeps_zero_exposure_sessions_only(): void
    {
        $clean1 = $this->openSession($this->table('60'), $this->admin->id, now()->subDay());
        $clean2 = $this->openSession($this->table('61'), $this->admin->id, now()->subDay());

        // Financial exposure: an unpaid order — the broom must skip it.
        $exposed = $this->openSession($this->table('62'), $this->admin->id, now()->subDay());
        app(OrderService::class)->createFromCart($exposed, [
            ['menu_item_id' => $this->menuItem()->id, 'quantity' => 1, 'modifier_ids' => []],
        ], $this->admin->id);

        // Waiters can't sweep at all.
        $this->actingAs($this->waiter)
            ->postJson(route('admin.tables.close-stale'), ['view' => 'all'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson(route('admin.tables.close-stale'), ['view' => 'all'])
            ->assertOk()
            ->assertJson(['ok' => true, 'closed' => 2]);

        $this->assertSame('closed', $clean1->fresh()->status);
        $this->assertSame('closed', $clean2->fresh()->status);
        $this->assertSame('active', $exposed->fresh()->status,
            'money on the table — the bulk close must never touch it');
    }

    public function test_quick_update_edits_through_the_full_update_pipeline(): void
    {
        $zone = $this->zone('تراس');
        $table = $this->table('70');
        // Ghost session: orderless, must be closed when status flips to available.
        $ghost = $this->openSession($table, null, now()->subDay());

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tables.quick-update', $table), [
                'number' => '70',
                'name' => 'طاولة الزاوية',
                'capacity' => 6,
                'zone_lookup_id' => $zone->id,
                'status' => 'available',
                'active' => true,
                'release_active_session' => true,
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'released' => true]);

        $fresh = $table->fresh();
        $this->assertSame('طاولة الزاوية', $fresh->name);
        $this->assertSame(6, (int) $fresh->capacity);
        $this->assertSame('available', $fresh->status);
        $this->assertSame('closed', $ghost->fresh()->status,
            'the ghost-session sweep must survive the JSON path');
        $this->assertNull($fresh->needs_cleaning_since,
            'explicitly making the table ready also acknowledges cleaning');
    }

    public function test_available_table_with_a_stale_session_can_be_released_from_quick_edit(): void
    {
        $table = $this->table('71');
        $table->update(['status' => 'available']);
        $ghost = $this->openSession($table, null, now()->subDay());

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tables.quick-update', $table), [
                'number' => '71',
                'capacity' => 4,
                'status' => 'available',
                'active' => true,
                'release_active_session' => true,
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'released' => true,
            ]);

        $this->assertSame('closed', $ghost->fresh()->status);
        $this->assertSame('available', $table->fresh()->status);
        $this->assertNull($table->fresh()->needs_cleaning_since);
    }

    public function test_direct_stale_release_closes_the_session_and_marks_the_table_ready(): void
    {
        $table = $this->table('73');
        $session = $this->openSession($table, null, now()->subDay());

        $this->actingAs($this->admin)
            ->post(route('admin.tables.close-session', $table), ['mark_ready' => true])
            ->assertSessionHas('success');

        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('available', $table->fresh()->status);
        $this->assertNull($table->fresh()->needs_cleaning_since);
    }

    public function test_quick_edit_cannot_release_a_recent_live_session(): void
    {
        $table = $this->table('72');
        $session = $this->openSession($table, null, now()->subMinutes(5));

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tables.quick-update', $table), [
                'number' => '72',
                'name' => 'لا يجب حفظه',
                'capacity' => 4,
                'status' => 'available',
                'active' => true,
                'release_active_session' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['release_active_session']);

        $this->assertSame('active', $session->fresh()->status);
        $this->assertNull($table->fresh()->name,
            'the metadata edit and release intent must roll back together');
    }

    public function test_quick_update_rejects_a_duplicate_number(): void
    {
        $this->table('80');
        $table = $this->table('81');

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tables.quick-update', $table), [
                'number' => '80', 'capacity' => 4, 'status' => 'occupied', 'active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number']);
    }

    public function test_quick_update_is_management_only(): void
    {
        $table = $this->table('90');

        $this->actingAs($this->waiter)
            ->patchJson(route('admin.tables.quick-update', $table), [
                'number' => '90', 'capacity' => 4, 'status' => 'occupied', 'active' => true,
            ])
            ->assertForbidden();
    }

    public function test_the_pulse_endpoint_reports_a_version(): void
    {
        $this->actingAs($this->waiter)
            ->getJson(route('admin.tables.board-pulse'))
            ->assertOk()
            ->assertJsonStructure(['version']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('admin.tables.index'))->assertRedirect();
    }
}
