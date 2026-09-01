<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Lookup;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\SectionAssignment;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderChangeRequestedNotification;
use App\Notifications\OrderReadyNotification;
use App\Services\NotifyService;
use App\Services\OrderChangeRequestService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 3.ب (MIGRATION-PILOT.md §13): the waiter service center on
 * Inertia/Vue. Pins the merged task list's priority tiers (a cold ready
 * plate outranks a late pending order), the shared lateness definition,
 * the stock gate that blocks approval, and the serve batching contract.
 */
class ServiceBoardVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $waiter;

    protected Table $table;

    protected TableSession $session;

    protected MenuItem $burger;

    protected Station $kitchen;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->branch = Branch::create(['code' => 'sv', 'name' => 'SV', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);

        $this->waiter = User::create([
            'name' => 'Waiter', 'username' => 'sv_waiter', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $this->waiter->branches()->attach($this->branch->id);

        $gram = Unit::create(['code' => 'g', 'name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $this->kitchen = Station::create([
            'code' => 'kitchen', 'name' => 'المطبخ',
            'storage_location_id' => $storage->id, 'active' => true,
        ]);
        $category = Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $this->kitchen->id, 'active' => true,
        ]);
        $patty = Ingredient::create([
            'name' => 'Patty', 'base_unit_id' => $gram->id, 'current_stock' => 5000,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $patty->id, 'storage_location_id' => $storage->id,
            'quantity' => 5000, 'reorder_threshold' => 0,
        ]);
        $this->burger = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'برجر', 'price' => 10,
            'is_available' => true, 'display_order' => 1,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $patty->id, 'quantity' => 150, 'unit_id' => $gram->id]);

        $this->table = Table::create([
            'branch_id' => $this->branch->id, 'number' => '3',
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $this->session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'sv-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'last_activity_at' => now(), 'cover_count' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function makeOrder(string $status, string $itemStatus, array $stamps = []): Order
    {
        $order = Order::create(array_merge([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'table_session_id' => $this->session->id, 'number' => 'ORD-SV-'.uniqid(),
            'order_type' => 'dine_in', 'status' => $status, 'submitted_at' => now(),
            'subtotal' => 10, 'total' => 10,
        ], $stamps['order'] ?? []));

        OrderItem::create(array_merge([
            'order_id' => $order->id, 'menu_item_id' => $this->burger->id,
            'station_id' => $this->kitchen->id, 'name_snapshot' => 'برجر',
            'quantity' => 1, 'unit_price' => 10, 'subtotal' => 10,
            'status' => $itemStatus,
        ], $stamps['item'] ?? []));

        return $order->fresh('items');
    }

    protected function zone(string $label, int $order): Lookup
    {
        return Lookup::create([
            'branch_id' => null, 'group' => 'zones', 'code' => 'sv-zone-'.$order,
            'label' => $label, 'color' => '#166534', 'is_active' => true, 'display_order' => $order,
        ]);
    }

    protected function pendingOrderFor(Table $table, TableSession $session, string $number): Order
    {
        $order = Order::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'table_session_id' => $session->id, 'number' => $number,
            'order_type' => 'dine_in', 'status' => 'pending', 'submitted_at' => now(),
            'subtotal' => 10, 'total' => 10,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $this->burger->id,
            'station_id' => $this->kitchen->id, 'name_snapshot' => 'برجر',
            'quantity' => 1, 'unit_price' => 10, 'subtotal' => 10, 'status' => 'pending',
        ]);

        return $order->fresh(['items', 'table']);
    }

    public function test_the_board_serves_one_merged_task_list(): void
    {
        $this->makeOrder('pending', 'pending');

        $this->actingAs($this->waiter)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Service/Board')
                ->has('tasks', 1, fn (Assert $task) => $task
                    ->where('kind', 'pending')
                    ->where('title', 'طاولة 3')
                    ->where('roundNumber', 1)
                    ->where('roundLabel', 'الطلب الأول')
                    ->where('pieceCount', '1')
                    ->where('lineCount', 1)
                    ->has('stations', 1, fn (Assert $station) => $station
                        ->where('name', 'المطبخ')
                        ->where('pieces', '1'))
                    ->where('canApprove', true)
                    ->etc())
                ->where('stats.pending', 1)
                ->has('urls.action'));
    }

    public function test_waiter_actions_lock_only_the_affected_task_and_stop_cleanly_offline(): void
    {
        $board = file_get_contents(resource_path('js/Pages/Admin/Service/Board.vue'));
        $card = file_get_contents(resource_path('js/Components/Service/TaskCard.vue'));

        $this->assertStringContainsString('const busyKeys = ref(new Set())', $board);
        $this->assertStringContainsString('const taskBusy = (task)', $board);
        $this->assertStringContainsString(':busy="taskBusy(task) || offline"', $board);
        $this->assertStringContainsString('v-if="offline" class="sv-offline"', $board);
        $this->assertStringContainsString(':aria-busy="busy"', $card);
        $this->assertStringContainsString('اذهب للطاولة وراجع الجولة', $card);
        $this->assertStringContainsString(':href="task.reviewUrl"', $card);
        $this->assertStringContainsString("task.kind === 'pending' && task.canApprove", $card);
        $this->assertStringNotContainsString('const busy = ref(false)', $board);
    }

    public function test_a_waiter_assigned_to_multiple_sections_sees_all_of_them_but_not_other_sections(): void
    {
        $inside = $this->zone('داخلي', 1);
        $outside = $this->zone('خارجي', 2);
        $vip = $this->zone('عائلات', 3);
        $this->table->update(['zone_lookup_id' => $inside->id]);

        $outsideTable = Table::create([
            'branch_id' => $this->branch->id, 'number' => '4', 'capacity' => 4,
            'status' => 'occupied', 'active' => true, 'zone_lookup_id' => $outside->id,
        ]);
        $vipTable = Table::create([
            'branch_id' => $this->branch->id, 'number' => '5', 'capacity' => 4,
            'status' => 'occupied', 'active' => true, 'zone_lookup_id' => $vip->id,
        ]);
        $outsideSession = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $outsideTable->id,
            'token' => 'outside-'.uniqid(), 'status' => 'active', 'opened_at' => now(), 'last_activity_at' => now(),
        ]);
        $vipSession = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $vipTable->id,
            'token' => 'vip-'.uniqid(), 'status' => 'active', 'opened_at' => now(), 'last_activity_at' => now(),
        ]);

        SectionAssignment::insert([
            [
                'branch_id' => $this->branch->id, 'zone_lookup_id' => $inside->id,
                'user_id' => $this->waiter->id, 'service_date' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'branch_id' => $this->branch->id, 'zone_lookup_id' => $outside->id,
                'user_id' => $this->waiter->id, 'service_date' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->pendingOrderFor($this->table, $this->session, 'ORD-IN');
        $this->pendingOrderFor($outsideTable, $outsideSession, 'ORD-OUT');
        $this->pendingOrderFor($vipTable, $vipSession, 'ORD-VIP');

        $response = $this->actingAs($this->waiter)->get(route('admin.orders.index'));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 2));

        $numbers = collect(data_get($response->viewData('page'), 'props.tasks'))->pluck('number')->all();
        $this->assertEqualsCanonicalizing(['ORD-IN', 'ORD-OUT'], $numbers);
    }

    public function test_floor_notifications_go_only_to_waiters_covering_the_table_section(): void
    {
        $inside = $this->zone('داخلي', 1);
        $outside = $this->zone('خارجي', 2);
        $this->table->update(['zone_lookup_id' => $inside->id]);

        $other = User::create([
            'name' => 'Other waiter', 'username' => 'sv_other', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $other->branches()->attach($this->branch->id);

        SectionAssignment::create([
            'branch_id' => $this->branch->id, 'zone_lookup_id' => $inside->id,
            'user_id' => $this->waiter->id, 'service_date' => now()->toDateString(),
        ]);
        SectionAssignment::create([
            'branch_id' => $this->branch->id, 'zone_lookup_id' => $outside->id,
            'user_id' => $other->id, 'service_date' => now()->toDateString(),
        ]);

        $order = $this->makeOrder('pending', 'pending');
        app(NotifyService::class)->newOrder($order->load('table'));

        Notification::assertSentTo($this->waiter, NewOrderNotification::class);
        Notification::assertNotSentTo($other, NewOrderNotification::class);
    }

    public function test_customer_change_notifies_the_responsible_waiter_with_actionable_context(): void
    {
        $order = $this->makeOrder('approved', 'approved');
        $change = app(OrderChangeRequestService::class)->request($order, $this->session, [
            'type' => 'change_item',
            'order_item_id' => $order->items->first()->id,
            'requested_quantity' => 2,
            'request_note' => 'بدون بصل',
        ]);

        Notification::assertSentTo(
            $this->waiter,
            OrderChangeRequestedNotification::class,
            fn (OrderChangeRequestedNotification $notification) => $notification->changeRequest->is($change)
                && str_contains($notification->title(), 'طاولة 3')
                && str_contains($notification->body(), 'بدون بصل')
                && str_contains($notification->actionUrl(), 'table_id='.$this->table->id),
        );
    }

    public function test_tablet_alerts_poll_without_reloading_the_active_page(): void
    {
        $bell = file_get_contents(resource_path('js/Components/AdminShell/NotificationsBell.vue'));

        $this->assertStringContainsString("'order.change'", $bell);
        $this->assertStringContainsString('class="operational-float"', $bell);
        $this->assertStringContainsString('timer = setInterval(refresh, 3000)', $bell);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', refresh)", $bell);
        $this->assertStringContainsString('router.visit(notification.action_url)', $bell);
        $this->assertStringNotContainsString('window.location', $bell);
    }

    public function test_waiter_help_is_the_first_action_and_can_be_acknowledged_inline(): void
    {
        $this->session->update([
            'help_requested_at' => now(),
            'help_request_note' => 'ماء لو سمحت',
        ]);

        $this->actingAs($this->waiter)
            ->get(route('admin.orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.help', 1)
                ->where('tasks.0.kind', 'help')
                ->where('tasks.0.canAck', true)
                ->where('tasks.0.subtitle', 'ماء لو سمحت'));

        $this->actingAs($this->waiter)
            ->postJson(route('admin.orders.board-action'), [
                'verb' => 'ack-help',
                'session_id' => $this->session->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($this->session->fresh()->help_requested_at);
        $this->assertSame($this->waiter->id, $this->session->fresh()->help_ack_by_user_id);
    }

    public function test_ready_task_names_the_pickup_station_and_notifies_the_floor(): void
    {
        $order = $this->makeOrder('preparing', 'preparing', [
            'item' => ['approved_at' => now()->subMinutes(2), 'prep_started_at' => now()->subMinute()],
        ]);

        app(OrderService::class)->markItemReady($order->items->first());

        Notification::assertSentTo($this->waiter, OrderReadyNotification::class);
        $this->actingAs($this->waiter)
            ->get(route('admin.orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.kind', 'ready')
                ->where('tasks.0.items.0.stationName', 'المطبخ'));

        $source = file_get_contents(resource_path('js/Components/Service/TaskCard.vue'));
        $this->assertStringContainsString('الاستلام من {{ it.stationName }}', $source);
    }

    public function test_a_cold_ready_plate_outranks_a_late_pending_order(): void
    {
        // Late pending: 4000 + capped age. Red ready: 5200 + extras.
        $this->makeOrder('pending', 'pending', ['order' => ['created_at' => now()->subMinutes(30)]]);
        $this->makeOrder('approved', 'ready', [
            'order' => ['approved_at' => now()->subMinutes(20)],
            'item' => ['approved_at' => now()->subMinutes(20), 'ready_at' => now()->subMinutes(10)],
        ]);

        $this->actingAs($this->waiter)
            ->get(route('admin.orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 2)
                ->where('tasks.0.kind', 'ready')
                ->where('tasks.0.readyUrgency', 'red')
                ->where('tasks.1.kind', 'pending'));
    }

    public function test_the_urgent_stat_and_the_late_tab_always_agree(): void
    {
        // created_at is not fillable — age it after creation.
        $late = $this->makeOrder('pending', 'pending');
        $late->forceFill(['created_at' => now()->subMinutes(9)])->save();
        $this->makeOrder('pending', 'pending'); // fresh — not late

        $page = $this->actingAs($this->waiter)
            ->get(route('admin.orders.index', ['focus' => 'urgent']));

        $props = $page->viewData('page')['props'];
        $this->assertSame(1, $props['stats']['urgent']);
        $this->assertCount(1, $props['tasks'], 'the late tab must list exactly what the stat counts');
    }

    public function test_production_orders_are_monitoring_only(): void
    {
        $this->makeOrder('preparing', 'preparing', [
            'order' => ['approved_at' => now()->subMinutes(2)],
            'item' => ['approved_at' => now()->subMinutes(2), 'prep_started_at' => now()->subMinute()],
        ]);

        $this->actingAs($this->waiter)
            ->get(route('admin.orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.kind', 'production')
                ->where('tasks.0.canApprove', false)
                ->where('stats.production', 1));
    }

    public function test_a_bill_request_becomes_a_billing_task_until_an_invoice_exists(): void
    {
        $this->makeOrder('delivered', 'served');
        $this->session->update(['bill_requested_at' => now()->subMinutes(2), 'bill_request_note' => 'كاش']);

        $this->actingAs($this->waiter)
            ->get(route('admin.orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.billing', 1)
                ->has('tasks', fn (Assert $tasks) => $tasks->etc()));
    }

    public function test_approve_fires_the_order_to_the_kitchen(): void
    {
        $order = $this->makeOrder('pending', 'pending');

        $this->actingAs($this->waiter)
            ->postJson(route('admin.orders.board-action'), ['verb' => 'approve', 'order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_serve_ready_hands_off_every_ready_line(): void
    {
        $order = $this->makeOrder('approved', 'ready', [
            'item' => ['approved_at' => now()->subMinutes(5), 'ready_at' => now()->subMinute()],
        ]);

        $this->actingAs($this->waiter)
            ->postJson(route('admin.orders.board-action'), ['verb' => 'serve-ready', 'order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('served', $order->items()->first()->status);
    }

    public function test_serve_ready_skips_the_changed_line_and_hands_off_the_other_ready_lines(): void
    {
        $order = $this->makeOrder('approved', 'ready', [
            'item' => ['approved_at' => now()->subMinutes(5), 'ready_at' => now()->subMinute()],
        ]);
        $pausedItem = $order->items->first();
        $otherItem = OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $this->burger->id,
            'station_id' => $this->kitchen->id,
            'name_snapshot' => 'برجر ثانٍ',
            'quantity' => 1,
            'unit_price' => 10,
            'subtotal' => 10,
            'status' => 'ready',
            'approved_at' => now()->subMinutes(5),
            'ready_at' => now()->subMinute(),
        ]);
        OrderChangeRequest::create([
            'branch_id' => $this->branch->id,
            'order_id' => $order->id,
            'order_item_id' => $pausedItem->id,
            'type' => 'cancel_item',
            'status' => OrderChangeRequest::STATUS_PENDING,
        ]);

        $this->actingAs($this->waiter)
            ->postJson(route('admin.orders.board-action'), ['verb' => 'serve-ready', 'order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('ready', $pausedItem->fresh()->status);
        $this->assertSame('served', $otherItem->fresh()->status);
    }

    public function test_a_stale_action_answers_409_with_the_domain_message(): void
    {
        $order = $this->makeOrder('approved', 'approved');

        // Already approved — approving again is a stale tap.
        $this->actingAs($this->waiter)
            ->postJson(route('admin.orders.board-action'), ['verb' => 'approve', 'order_id' => $order->id])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);
    }

    public function test_the_table_filter_narrows_every_group(): void
    {
        $other = Table::create([
            'branch_id' => $this->branch->id, 'number' => '99',
            'capacity' => 2, 'status' => 'occupied', 'active' => true,
        ]);
        $this->makeOrder('pending', 'pending');
        Order::create([
            'branch_id' => $this->branch->id, 'table_id' => $other->id,
            'number' => 'ORD-SV-OTHER', 'order_type' => 'dine_in', 'status' => 'pending',
            'submitted_at' => now(), 'subtotal' => 5, 'total' => 5,
        ]);

        $this->actingAs($this->waiter)
            ->get(route('admin.orders.index', ['table_id' => $this->table->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 1)
                ->where('tasks.0.title', 'طاولة 3')
                ->where('filters.tableId', $this->table->id));
    }

    public function test_the_pulse_endpoint_reports_a_version(): void
    {
        $this->actingAs($this->waiter)
            ->getJson(route('admin.orders.board-pulse'))
            ->assertOk()
            ->assertJsonStructure(['version']);
    }

    public function test_header_notification_never_approves_an_unseen_round(): void
    {
        $order = $this->makeOrder('pending', 'pending');
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => NewOrderNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $this->waiter->id,
            'branch_id' => $this->branch->id,
            'type_key' => 'order.new',
            'severity' => 'info',
            'data' => json_encode([
                'title' => 'طلب جديد',
                'body' => 'طاولة 3',
                'extra' => ['order_id' => $order->id],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->waiter)
            ->getJson(route('admin.notifications.recent'))
            ->assertOk()
            ->assertJsonPath('items.0.type_key', 'order.new')
            ->assertJsonPath('items.0.quick_action', null);
    }

    public function test_the_orders_list_page_serves_inertia_with_stats(): void
    {
        $this->makeOrder('pending', 'pending');

        $this->actingAs($this->waiter)
            ->get(route('admin.orders.list'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders/Index')
                ->has('orders.data', 1, fn (Assert $o) => $o
                    ->where('canApprove', true)
                    ->where('statusLabel', OrderStatus::Pending->label())
                    ->etc())
                ->where('stats.pending', 1)
                ->has('orders.links'));
    }

    public function test_the_order_detail_page_ships_lines_and_permissions(): void
    {
        $target = Table::create([
            'branch_id' => $this->branch->id,
            'number' => '4',
            'capacity' => 6,
            'status' => 'available',
            'active' => true,
        ]);
        $order = $this->makeOrder('approved', 'ready', [
            'item' => ['approved_at' => now()->subMinutes(4), 'ready_at' => now()->subMinute()],
        ]);

        $this->actingAs($this->waiter)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders/Show')
                ->where('order.number', $order->number)
                ->has('order.items', 1, fn (Assert $it) => $it
                    ->where('name', 'برجر')
                    ->where('status', 'ready')
                    ->etc())
                ->where('order.can.approve', false)
                ->where('order.can.serve', true)
                ->where('order.progress.ready', 1)
                ->where('order.session.id', $this->session->id)
                ->where('order.session.tableLabel', '3')
                ->where('order.session.canTransfer', true)
                ->where('order.session.transferUrl', route('admin.tables.transfer', $this->table))
                ->has('order.session.transferTables', 1, fn (Assert $table) => $table
                    ->where('id', $target->id)
                    ->where('number', '4')
                    ->where('capacity', 6)
                    ->etc())
                ->has('order.totals.total'));
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('admin.orders.index'))->assertRedirect();
    }
}
