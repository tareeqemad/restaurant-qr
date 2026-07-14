<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecipeItem;
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
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Kitchen Display System flows (stage 2):
 *  1. Mis-tap undo — revertItemReady / revertItemStart roll a line back
 *     one step without touching inventory.
 *  2. Auto-ready — zero-prep stationless lines (internet cards) jump to
 *     Ready on approval so they never clog a board nor block the order.
 *  3. Zombie guard — chef-cancelling the last live line kills the order.
 *  4. Broadcast batching — $broadcast=false still transitions + recomputes.
 */
class KdsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $chef;

    protected Unit $gram;

    protected StorageLocation $storage;

    protected Station $kitchen;

    protected Category $mains;

    protected MenuItem $burger;

    protected MenuItem $netCard;

    protected Ingredient $patty;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->branch = Branch::create(['code' => 'k', 'name' => 'K', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'chef', 'label' => 'Chef', 'is_system' => true]);
        $this->chef = User::create([
            'name' => 'Chef', 'username' => 'chef_t', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'chef',
        ]);
        $this->chef->branches()->attach($this->branch->id);

        $this->gram = Unit::create(['code' => 'g', 'name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);

        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $this->kitchen = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $this->storage->id, 'active' => true,
        ]);
        $this->mains = Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $this->kitchen->id, 'active' => true,
        ]);

        $this->patty = Ingredient::create([
            'name' => 'Patty', 'base_unit_id' => $this->gram->id, 'current_stock' => 5000,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $this->storage->id,
            'quantity' => 5000, 'reorder_threshold' => 0,
        ]);

        $this->burger = MenuItem::create([
            'category_id' => $this->mains->id, 'station_id' => $this->kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'Burger', 'price' => 10,
            'prep_time_minutes' => 10, 'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->patty->id,
            'quantity' => 150, 'unit_id' => $this->gram->id]);

        // The InternetCardsSeeder shape: no station (category default is
        // null too), zero prep, zero recipe — sold at the till.
        $cards = Category::create(['slug' => 'internet-cards', 'name' => 'بطاقات نت', 'active' => true]);
        $this->netCard = MenuItem::create([
            'category_id' => $cards->id, 'station_id' => null,
            'sku' => 'NET-1H', 'slug' => 'net-card-1h', 'name' => 'بطاقة نت — ساعة', 'price' => 5,
            'prep_time_minutes' => 0, 'is_available' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ─── helpers ──────────────────────────────────────────────────────

    /** @param array<int,MenuItem> $menuItems */
    protected function placeOrder(array $menuItems): Order
    {
        $table = Table::create([
            'number' => (string) random_int(100, 999),
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'token' => 'kds-'.uniqid(), 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 1,
        ]);

        return app(OrderService::class)->createFromCart(
            $session,
            array_map(fn (MenuItem $m) => ['menu_item_id' => $m->id, 'quantity' => 1, 'modifier_ids' => []], $menuItems),
            createdByUserId: $this->chef->id,
        );
    }

    protected function approvedBurgerItem(): OrderItem
    {
        $order = $this->placeOrder([$this->burger]);
        app(OrderService::class)->approve($order, $this->chef->id);

        return $order->items->first()->fresh();
    }

    protected function readyBurgerItem(): OrderItem
    {
        $item = $this->approvedBurgerItem();
        app(OrderService::class)->startPreparing($item, $this->chef->id);
        app(OrderService::class)->markItemReady($item->fresh());

        return $item->fresh();
    }

    protected function issueInvoiceFor(Order $order): Invoice
    {
        return Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $order->table_session_id,
            'issued_by_user_id' => $this->chef->id,
            'subtotal' => 10, 'discount_total' => 0, 'tax_total' => 0,
            'service_total' => 0, 'delivery_fee' => 0, 'tip' => 0,
            'total' => 10, 'balance' => 10,
            'status' => 'issued', 'issued_at' => now(),
        ]);
    }

    // ─── revert (mis-tap undo) ────────────────────────────────────────

    public function test_revert_item_ready_drops_the_line_and_the_order_back_to_preparing(): void
    {
        $this->actingAs($this->chef);

        $item = $this->readyBurgerItem();
        $this->assertSame(OrderStatus::Ready->value, $item->order->fresh()->status);
        $this->assertNotNull($item->prep_started_at);

        app(OrderService::class)->revertItemReady($item, $this->chef->id);

        $item->refresh();
        $this->assertSame(OrderItemStatus::Preparing->value, $item->status);
        $this->assertNull($item->ready_at, 'Ready stamp was premature — it must clear.');
        $this->assertNotNull($item->prep_started_at, 'The line is still cooking — keep the prep clock.');
        $this->assertSame(OrderStatus::Preparing->value, $item->order->fresh()->status,
            'An order that was Ready drops back to Preparing with the reverted line.');
    }

    public function test_revert_does_not_touch_inventory_and_re_ready_does_not_double_deduct(): void
    {
        $this->actingAs($this->chef);

        $item = $this->readyBurgerItem();
        // Default stage = approve → the patty was already deducted once.
        $this->assertSame(4850.0, (float) $this->patty->fresh()->current_stock);

        app(OrderService::class)->revertItemReady($item, $this->chef->id);
        $this->assertSame(4850.0, (float) $this->patty->fresh()->current_stock,
            'The food is physically cooked — undoing the tap must not return stock.');

        app(OrderService::class)->markItemReady($item->fresh());
        $this->assertSame(4850.0, (float) $this->patty->fresh()->current_stock,
            'ensureDeducted is idempotent — re-readying must not deduct twice.');
        $this->assertSame(1, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)->where('type', 'out')->count());
    }

    public function test_revert_item_ready_is_blocked_once_an_invoice_exists(): void
    {
        $this->actingAs($this->chef);

        $item = $this->readyBurgerItem();
        $this->issueInvoiceFor($item->order);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن تعديل الطلب بعد إصدار الفاتورة');
        app(OrderService::class)->revertItemReady($item, $this->chef->id);
    }

    public function test_revert_item_ready_rejects_a_line_that_is_not_ready(): void
    {
        $this->actingAs($this->chef);

        $item = $this->approvedBurgerItem();

        $this->expectException(\RuntimeException::class);
        app(OrderService::class)->revertItemReady($item, $this->chef->id);
    }

    public function test_revert_item_start_returns_the_line_to_approved_and_keeps_stock_deducted(): void
    {
        $this->actingAs($this->chef);
        Setting::put('inventory_deduction_stage', 'preparing', 'inventory', 'string');

        $item = $this->approvedBurgerItem();
        app(OrderService::class)->startPreparing($item, $this->chef->id);
        $this->assertSame(4850.0, (float) $this->patty->fresh()->current_stock);
        $this->assertSame(OrderStatus::Preparing->value, $item->order->fresh()->status);

        app(OrderService::class)->revertItemStart($item->fresh(), $this->chef->id);

        $item->refresh();
        $this->assertSame(OrderItemStatus::Approved->value, $item->status);
        $this->assertNull($item->prep_started_at, 'Mis-tap must not keep the elapsed clock running.');
        $this->assertSame(OrderStatus::Approved->value, $item->order->fresh()->status,
            'With no line cooking anymore, the order falls back to Approved.');
        $this->assertSame(4850.0, (float) $this->patty->fresh()->current_stock,
            'Stock deducted at the preparing stage stays deducted.');

        // Re-start right after the mis-tap: idempotent deduction, no double dip.
        app(OrderService::class)->startPreparing($item->fresh(), $this->chef->id);
        $this->assertSame(4850.0, (float) $this->patty->fresh()->current_stock);
        $this->assertSame(1, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)->where('type', 'out')->count());
    }

    // ─── auto-ready instant items ─────────────────────────────────────

    public function test_zero_prep_stationless_items_are_auto_readied_on_approve(): void
    {
        $this->actingAs($this->chef);

        $order = $this->placeOrder([$this->netCard]);
        app(OrderService::class)->approve($order, $this->chef->id);

        $card = $order->items()->first()->fresh();
        $this->assertSame(OrderItemStatus::Ready->value, $card->status,
            'No KDS ever shows a stationless zero-prep line — it must self-ready.');
        $this->assertNotNull($card->ready_at);
        $this->assertSame(OrderStatus::Ready->value, $order->fresh()->status,
            'A card-only order goes straight to the ready queue.');
    }

    public function test_mixed_order_auto_readies_only_the_instant_line(): void
    {
        $this->actingAs($this->chef);

        $order = $this->placeOrder([$this->burger, $this->netCard]);
        app(OrderService::class)->approve($order, $this->chef->id);

        $items = $order->items()->get()->keyBy('menu_item_id');
        $this->assertSame(OrderItemStatus::Approved->value, $items[$this->burger->id]->status,
            'The kitchen line still waits for a real cook.');
        $this->assertSame(OrderItemStatus::Ready->value, $items[$this->netCard->id]->status);

        // ORDER-level state must be untouched by the instant line: the order
        // stays Approved (not Preparing), and the customer prep clock/ETA
        // must NOT start at approval — that regression started countdowns
        // before any cook touched the ticket.
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Approved->value, $fresh->status,
            'An instant line must not drag the order into Preparing at approval.');
        $this->assertNull($fresh->prep_started_at,
            'Customer countdown must not start at approval.');
        $this->assertNull($items[$this->netCard->id]->prep_started_at,
            'Instant lines never get a prep clock (this also keeps them off the KDS).');

        // The burger alone now gates order readiness — the wifi card no
        // longer blocks the ticket from ever reaching Ready.
        app(OrderService::class)->startPreparing($items[$this->burger->id], $this->chef->id);
        app(OrderService::class)->markItemReady($items[$this->burger->id]->fresh());
        $this->assertSame(OrderStatus::Ready->value, $order->fresh()->status);
    }

    public function test_mixed_order_with_instant_line_can_still_be_unapproved(): void
    {
        $this->actingAs($this->chef);

        $order = $this->placeOrder([$this->burger, $this->netCard]);
        app(OrderService::class)->approve($order, $this->chef->id);

        // The auto-readied wifi card must not count as "kitchen started" —
        // the waiter's rollback path stays available for the whole class of
        // orders that merely contain an instant line.
        $order = app(OrderService::class)->unapprove($order->fresh(), $this->chef->id);

        $this->assertSame(OrderStatus::Pending->value, $order->status);
        foreach ($order->items as $oi) {
            $this->assertSame(OrderItemStatus::Pending->value, $oi->status);
            $this->assertNull($oi->ready_at);
        }
    }

    public function test_zero_prep_items_with_a_station_are_not_auto_readied(): void
    {
        $this->actingAs($this->chef);

        // Zero prep BUT routed to the kitchen — a real cook still plates it.
        $espresso = MenuItem::create([
            'category_id' => $this->mains->id, 'station_id' => $this->kitchen->id,
            'sku' => 'E-1', 'slug' => 'espresso', 'name' => 'Espresso', 'price' => 3,
            'prep_time_minutes' => 0, 'is_available' => true,
        ]);

        $order = $this->placeOrder([$espresso]);
        app(OrderService::class)->approve($order, $this->chef->id);

        $this->assertSame(OrderItemStatus::Approved->value, $order->items()->first()->fresh()->status);
        $this->assertSame(OrderStatus::Approved->value, $order->fresh()->status);
    }

    // ─── zombie order guard ───────────────────────────────────────────

    public function test_chef_cancelling_the_last_live_line_cancels_the_whole_order(): void
    {
        $this->actingAs($this->chef);

        $item = $this->approvedBurgerItem();
        app(OrderService::class)->startPreparing($item, $this->chef->id);

        app(OrderService::class)->cancelItem(
            item: $item->fresh(),
            userId: $this->chef->id,
            reason: 'الزبون غيّر رأيه',
            disposition: 'return',
        );

        $order = $item->order->fresh();
        $this->assertSame(OrderStatus::Cancelled->value, $order->status,
            'No live lines left — the ticket must leave every board, not float as a zombie.');
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame('الزبون غيّر رأيه', $order->cancelled_reason);
    }

    public function test_cancelling_one_of_two_lines_keeps_the_order_alive(): void
    {
        $this->actingAs($this->chef);

        $order = $this->placeOrder([$this->burger, $this->burger]);
        app(OrderService::class)->approve($order, $this->chef->id);
        [$first, $second] = $order->items()->get()->all();

        app(OrderService::class)->cancelItem($first, $this->chef->id, 'مكرر بالغلط');

        $this->assertNotSame(OrderStatus::Cancelled->value, $order->fresh()->status);

        app(OrderService::class)->cancelItem($second->fresh(), $this->chef->id, 'الزبون مشى');

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->status);
    }

    // ─── broadcast batching ───────────────────────────────────────────

    public function test_transitions_with_broadcast_false_still_move_state_and_recompute(): void
    {
        $this->actingAs($this->chef);

        $item = $this->approvedBurgerItem();

        app(OrderService::class)->startPreparing($item, $this->chef->id, broadcast: false);
        $this->assertSame(OrderItemStatus::Preparing->value, $item->fresh()->status);
        $this->assertSame(OrderStatus::Preparing->value, $item->order->fresh()->status,
            'Muting the sockets must not mute the status recompute.');

        app(OrderService::class)->markItemReady($item->fresh(), broadcast: false);
        $this->assertSame(OrderItemStatus::Ready->value, $item->fresh()->status);
        $this->assertSame(OrderStatus::Ready->value, $item->order->fresh()->status);

        // The single follow-up refresh the board fires after a bulk loop.
        app(OrderService::class)->broadcastOrderRefresh($item->order->fresh());
    }
}
