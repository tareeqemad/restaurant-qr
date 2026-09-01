<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\OrderReadyNotification;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 3 (MIGRATION-PILOT.md §13): the KDS on Inertia/Vue. Service-level
 * transition physics stay pinned by KdsFlowTest; this file covers the new
 * HTTP surface: the decorated board payload (columns, urgency, hand-off,
 * orphan safety net), the station permission gate, the action verbs, and
 * the stale-tap contract.
 */
class KitchenBoardVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Station $kitchen;

    protected User $manager;

    protected User $chef;

    protected MenuItem $burger;

    protected Table $table;

    protected TableSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->branch = Branch::create(['code' => 'kb', 'name' => 'KB', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'manager', 'label' => 'Manager', 'is_system' => true]);
        Role::create(['name' => 'chef', 'label' => 'Chef', 'is_system' => true]);
        Role::create(['name' => 'bartender', 'label' => 'Bartender', 'is_system' => true]);
        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);

        $gram = Unit::create(['code' => 'g', 'name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $this->kitchen = Station::create([
            'code' => 'kitchen', 'name' => 'المطبخ',
            'storage_location_id' => $storage->id, 'active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager', 'username' => 'kb_manager', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'manager',
        ]);
        $this->manager->branches()->attach($this->branch->id);

        $this->chef = User::create([
            'name' => 'Chef', 'username' => 'kb_chef', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'chef',
            'station_id' => $this->kitchen->id,
        ]);
        $this->chef->branches()->attach($this->branch->id);

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
            'is_available' => true, 'display_order' => 1, 'prep_time_minutes' => 10,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $patty->id, 'quantity' => 150, 'unit_id' => $gram->id]);

        $this->table = Table::create([
            'branch_id' => $this->branch->id, 'number' => '4',
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $this->session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'kb-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'last_activity_at' => now(), 'cover_count' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** One approved burger line on the kitchen board. */
    protected function approvedItem(): OrderItem
    {
        $order = Order::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'table_session_id' => $this->session->id, 'number' => 'ORD-KB-'.uniqid(),
            'order_type' => 'dine_in', 'status' => 'approved', 'submitted_at' => now(),
            'approved_at' => now(), 'subtotal' => 10, 'total' => 10,
        ]);

        return OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $this->burger->id,
            'station_id' => $this->kitchen->id, 'name_snapshot' => 'برجر',
            'quantity' => 1, 'unit_price' => 10, 'subtotal' => 10,
            'status' => 'approved', 'approved_at' => now(),
        ]);
    }

    public function test_the_board_serves_the_inertia_page_with_columns(): void
    {
        $this->approvedItem();

        $this->actingAs($this->chef)
            ->get(route('admin.station.show', 'kitchen'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Kitchen/Board')
                ->where('station.code', 'kitchen')
                ->has('board.waiting', 1, fn (Assert $card) => $card
                    ->where('tableNum', 4)
                    ->where('roundNumber', 1)
                    ->where('roundLabel', 'الطلب الأول')
                    ->where('pieceCount', '1')
                    ->has('items', 1, fn (Assert $it) => $it
                        ->where('name', 'برجر')
                        ->where('status', 'approved')
                        ->where('orphan', false)
                        ->etc())
                    ->etc())
                ->has('board.cooking', 0)
                ->has('board.ready', 0)
                ->where('board.load.activeItems', 1)
                ->has('urls.action'));
    }

    public function test_the_kitchen_sees_removed_ingredients_as_a_structured_warning(): void
    {
        $item = $this->approvedItem();
        $ingredient = $this->burger->recipeItems()->firstOrFail()->ingredient;
        $stock = IngredientStock::query()->where('ingredient_id', $ingredient->id)->sole();
        $quantityBeforePreparation = (float) $stock->quantity;
        $item->exclusions()->create([
            'ingredient_id' => $ingredient->id,
            'name_snapshot' => 'Patty',
        ]);

        $this->actingAs($this->chef)
            ->get(route('admin.station.show', 'kitchen'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('board.waiting.0.items.0.exclusions.0', 'Patty'));

        $card = file_get_contents(resource_path('js/Components/Kds/KdsCard.vue'));
        $this->assertStringContainsString('بدون {{ ingredient }}', $card);
        $this->assertStringContainsString('kb-item-exclusions', $card);

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'start', 'item_id' => $item->id])
            ->assertOk();

        $this->assertSame(
            $quantityBeforePreparation,
            (float) $stock->fresh()->quantity,
            'starting preparation must not deduct an ingredient the diner removed',
        );
    }

    public function test_station_actions_lock_one_ticket_and_are_disabled_while_offline(): void
    {
        $board = file_get_contents(resource_path('js/Pages/Admin/Kitchen/Board.vue'));
        $card = file_get_contents(resource_path('js/Components/Kds/KdsCard.vue'));

        $this->assertStringContainsString('const busyKeys = ref(new Set())', $board);
        $this->assertStringContainsString('const cardBusy = (card)', $board);
        $this->assertStringContainsString(':busy="cardBusy(card) || offline"', $board);
        $this->assertStringContainsString('if (offline.value)', $board);
        $this->assertStringContainsString(':aria-busy="busy"', $card);
        $this->assertStringNotContainsString('const busy = ref(false)', $board);
        $this->assertStringContainsString('linear-gradient(135deg, #176b4a, #123323)', $board);
        $this->assertStringNotContainsString('linear-gradient(135deg, var(--station-color)', $board);
    }

    public function test_the_board_keeps_twenty_tickets_visible_and_reports_pressure_counts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $item = $this->approvedItem();
            $item->update(['approved_at' => now()->subMinutes($i + 1)]);

            if ($i >= 10) {
                $item->update([
                    'status' => 'preparing',
                    'prep_started_at' => now()->subMinutes($i - 8),
                ]);
            }
        }

        $this->actingAs($this->chef)
            ->get(route('admin.station.show', 'kitchen'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('board.waiting', 10)
                ->has('board.cooking', 10)
                ->has('board.ready', 0)
                ->where('board.load.activeTickets', 20)
                ->where('board.load.totalTickets', 20)
                ->where('board.load.waitingTickets', 10)
                ->where('board.load.cookingTickets', 10)
                ->where('board.load.level', 'red'));
    }

    public function test_station_access_is_gated(): void
    {
        $waiter = User::create([
            'name' => 'W', 'username' => 'kb_waiter', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $waiter->branches()->attach($this->branch->id);

        $this->actingAs($waiter)
            ->get(route('admin.station.show', 'kitchen'))
            ->assertForbidden();
    }

    public function test_start_then_ready_walk_the_line_through_the_kitchen(): void
    {
        $item = $this->approvedItem();

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'start', 'item_id' => $item->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $item->refresh();
        $this->assertSame('preparing', $item->status);
        $this->assertNotNull($item->prep_started_at);

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'ready', 'item_id' => $item->id])
            ->assertOk();

        $this->assertSame('ready', $item->fresh()->status);
    }

    public function test_ready_ticket_names_and_notifies_the_waiter_responsible_for_the_session(): void
    {
        $waiter = User::create([
            'name' => 'أحمد الجرسون', 'username' => 'kb_waiter_assigned', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $waiter->branches()->attach($this->branch->id);

        $otherWaiter = User::create([
            'name' => 'جرسون آخر', 'username' => 'kb_waiter_other', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $otherWaiter->branches()->attach($this->branch->id);

        $this->session->update(['assigned_waiter_id' => $waiter->id]);
        $item = $this->approvedItem();
        $item->update(['status' => 'preparing', 'prep_started_at' => now()->subMinutes(4)]);

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'ready', 'item_id' => $item->id])
            ->assertOk();

        Notification::assertSentTo($waiter, OrderReadyNotification::class);
        Notification::assertNotSentTo($otherWaiter, OrderReadyNotification::class);

        $this->actingAs($this->chef)
            ->get(route('admin.station.show', 'kitchen'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('board.ready', 1)
                ->where('board.ready.0.handoff.waiterId', $waiter->id)
                ->where('board.ready.0.handoff.waiterName', 'أحمد الجرسون')
                ->where('board.ready.0.handoff.recipientLabel', 'الجرسون أحمد الجرسون'));
    }

    public function test_bar_uses_the_same_three_stage_board_and_hands_off_to_the_assigned_waiter(): void
    {
        $bar = Station::create([
            'code' => 'bar', 'name' => 'البار',
            'storage_location_id' => $this->kitchen->storage_location_id,
            'active' => true,
        ]);
        $bartender = User::create([
            'name' => 'موظف البار', 'username' => 'kb_bartender', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'bartender',
            'station_id' => $bar->id,
        ]);
        $bartender->branches()->attach($this->branch->id);

        $waiter = User::create([
            'name' => 'محمد الجرسون', 'username' => 'kb_bar_waiter', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $waiter->branches()->attach($this->branch->id);
        $this->session->update(['assigned_waiter_id' => $waiter->id]);

        $drink = MenuItem::create([
            'category_id' => $this->burger->category_id, 'station_id' => $bar->id,
            'sku' => 'DRINK-1', 'slug' => 'cola', 'name' => 'كولا', 'price' => 3,
            'is_available' => true, 'display_order' => 2, 'prep_time_minutes' => 1,
        ]);
        $barItem = $this->approvedItem();
        $barItem->update([
            'menu_item_id' => $drink->id,
            'station_id' => $bar->id,
            'name_snapshot' => 'كولا',
        ]);
        $this->approvedItem(); // Kitchen line must never leak onto the bar board.

        $this->actingAs($bartender)
            ->get(route('admin.station.show', 'bar'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Kitchen/Board')
                ->where('station.code', 'bar')
                ->where('station.name', 'البار')
                ->where('station.emoji', '🍹')
                ->has('board.waiting', 1)
                ->where('board.waiting.0.items.0.name', 'كولا')
                ->has('board.cooking', 0)
                ->has('board.ready', 0));

        $this->actingAs($bartender)
            ->postJson(route('admin.station.action', 'bar'), ['verb' => 'start', 'item_id' => $barItem->id])
            ->assertOk();
        $this->actingAs($bartender)
            ->postJson(route('admin.station.action', 'bar'), ['verb' => 'ready', 'item_id' => $barItem->id])
            ->assertOk();

        Notification::assertSentTo($waiter, OrderReadyNotification::class);
        $this->actingAs($bartender)
            ->get(route('admin.station.show', 'bar'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('board.waiting', 0)
                ->has('board.cooking', 0)
                ->has('board.ready', 1)
                ->where('board.ready.0.handoff.waiterId', $waiter->id)
                ->where('board.ready.0.handoff.recipientLabel', 'الجرسون محمد الجرسون'));

        $this->actingAs($bartender)
            ->get(route('admin.station.show', 'kitchen'))
            ->assertForbidden();
    }

    public function test_ready_all_walks_approved_lines_through_preparing_first(): void
    {
        $item = $this->approvedItem();

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'ready-all', 'order_id' => $item->order_id])
            ->assertOk();

        $item->refresh();
        $this->assertSame('ready', $item->status);
        $this->assertNotNull($item->prep_started_at, 'approved lines must walk through preparing for consistent stamps');
    }

    public function test_customer_change_is_visible_on_the_station_and_pauses_individual_and_bulk_actions(): void
    {
        $item = $this->approvedItem();
        $change = OrderChangeRequest::create([
            'branch_id' => $this->branch->id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'type' => 'cancel_item',
            'request_note' => 'بدون هذا الصنف',
            'status' => OrderChangeRequest::STATUS_PENDING,
        ]);

        $this->actingAs($this->chef)
            ->get(route('admin.station.show', 'kitchen'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('board.waiting.0.changeRequest.id', $change->id)
                ->where('board.waiting.0.changeRequest.type', 'cancel_item')
                ->where('board.waiting.0.items.0.changePending', true)
                ->where('board.changeRequestIds.0', $change->id));

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'start', 'item_id' => $item->id])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), [
                'verb' => 'cancel-item', 'item_id' => $item->id, 'disposition' => 'return',
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'ready-all', 'order_id' => $item->order_id])
            ->assertOk();

        $this->assertSame('approved', $item->fresh()->status);
    }

    public function test_the_kitchen_cannot_complete_the_floor_delivery(): void
    {
        $item = $this->approvedItem();
        $item->update(['status' => 'ready', 'ready_at' => now(), 'prep_started_at' => now()->subMinutes(5)]);

        // Kitchen declares readiness; only the service board can complete
        // the floor delivery, even when a manager is looking at the KDS.
        $this->actingAs($this->manager)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'serve-order', 'order_id' => $item->order_id])
            ->assertUnprocessable();

        $board = file_get_contents(resource_path('js/Pages/Admin/Kitchen/Board.vue'));
        $card = file_get_contents(resource_path('js/Components/Kds/KdsCard.vue'));

        $this->assertSame('ready', $item->fresh()->status);
        $this->assertStringNotContainsString('serve-order', $board);
        $this->assertStringContainsString('card.handoff.recipientLabel', $card);
        $this->assertStringContainsString('وهو يؤكد التقديم من شاشة الخدمة', $card);
    }

    public function test_a_stale_tap_answers_409_with_the_domain_message(): void
    {
        $item = $this->approvedItem();
        $item->update(['status' => 'served', 'served_at' => now()]);

        // The line moved on another screen — marking it ready now is stale.
        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), ['verb' => 'ready', 'item_id' => $item->id])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);
    }

    public function test_cancel_item_with_return_disposition_cancels_the_line(): void
    {
        $item = $this->approvedItem();

        $this->actingAs($this->chef)
            ->postJson(route('admin.station.action', 'kitchen'), [
                'verb' => 'cancel-item', 'item_id' => $item->id, 'disposition' => 'return',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('cancelled', $item->fresh()->status);
    }

    public function test_orphan_items_land_on_the_primary_board(): void
    {
        $item = $this->approvedItem();
        $item->update(['station_id' => null]);

        $this->actingAs($this->chef)
            ->get(route('admin.station.show', 'kitchen'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('board.waiting', 1)
                ->where('board.waiting.0.items.0.orphan', true));
    }

    public function test_the_pulse_endpoint_is_station_gated(): void
    {
        $this->actingAs($this->chef)
            ->getJson(route('admin.station.pulse', 'kitchen'))
            ->assertOk()
            ->assertJsonStructure(['version']);

        $waiter = User::create([
            'name' => 'W2', 'username' => 'kb_waiter2', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $waiter->branches()->attach($this->branch->id);

        $this->actingAs($waiter)
            ->getJson(route('admin.station.pulse', 'kitchen'))
            ->assertForbidden();
    }
}
